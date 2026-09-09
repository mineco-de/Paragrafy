<?php
/**
 * Paragrafy - TOTP (RFC 6238) two-factor authentication helpers.
 *
 * Covers regular user accounts (users.totp_*) and, on self-hosted instances
 * only (no sso_secret / managed_cloud), the single admin account (whose
 * fields live in config.php next to admin_password_hash, since the admin
 * account has no row of its own -- see admin_totp_*() below).
 *
 * Requires spomky-labs/otphp + endroid/qr-code (composer). All functions in
 * here degrade gracefully via totp_vendor_available() when composer hasn't
 * been run yet (e.g. right after a bare-metal `git pull`, before
 * `composer install`) so the rest of the app keeps working -- TOTP setup is
 * simply unavailable until then.
 */
declare(strict_types=1);

// Loaded unconditionally (not lazily inside totp_vendor_available()) so every
// function below can safely reference \OTPHP\... / \Endroid\... regardless of
// call order -- a function calling e.g. totp_generate_secret() without having
// called totp_vendor_available() first must not fatal-error just because the
// autoloader hadn't been pulled in yet as a side effect.
$totpAutoload = PARAGRAFY_DIR . '/vendor/autoload.php';
if (file_exists($totpAutoload)) {
    require_once $totpAutoload;
}
unset($totpAutoload);

function totp_vendor_available(): bool {
    static $available = null;
    if ($available === null) {
        $available = class_exists(\OTPHP\TOTP::class) && class_exists(\Endroid\QrCode\Builder\Builder::class) && function_exists('sodium_crypto_secretbox');
    }
    return $available;
}

/**
 * Self-healing 32-byte secretbox key for encrypting totp_secret at rest,
 * stored in config.php exactly like ensure_cron_secret() does for the cron
 * secret -- generated once on first use, never needs manual setup.
 */
function ensure_totp_encryption_key(): string {
    $config = get_config();
    if (!empty($config['totp_encryption_key'])) {
        return $config['totp_encryption_key'];
    }
    // Slow path only: two concurrent first-ever TOTP setups could otherwise
    // both generate a different key and race to write config.php, leaving
    // whichever secret was encrypted under the losing key undecryptable.
    // update_config() re-checks emptiness *inside* the lock (double-checked
    // locking) so the second racer just reuses the first one's key instead
    // of generating and writing its own.
    $result = update_config(function (?array $config): array {
        $config = $config ?? [];
        if (empty($config['totp_encryption_key'])) {
            $config['totp_encryption_key'] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }
        return $config;
    });
    return $result['totp_encryption_key'];
}

/** Encrypts a plaintext Base32 TOTP secret for storage (totp_secret column / config field). */
function totp_encrypt_secret(string $plainSecret): string {
    $key = base64_decode(ensure_totp_encryption_key(), true) ?: '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plainSecret, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

/** Reverses totp_encrypt_secret(); returns null if empty, malformed, or the key doesn't match. */
function totp_decrypt_secret(?string $encoded): ?string {
    if ($encoded === null || $encoded === '') {
        return null;
    }
    $key = base64_decode(ensure_totp_encryption_key(), true) ?: '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? null : $plain;
}

/** Fresh random Base32 secret for a new TOTP enrollment (not yet persisted). */
function totp_generate_secret(): string {
    return \OTPHP\TOTP::create()->getSecret();
}

function totp_object_for_secret(string $plainSecret, string $label, string $issuer): \OTPHP\TOTP {
    $totp = \OTPHP\TOTP::createFromSecret($plainSecret);
    $totp->setLabel($label);
    $totp->setIssuer($issuer);
    return $totp;
}

/** otpauth:// URI for the QR code / manual entry. */
function totp_provisioning_uri(string $plainSecret, string $label, string $issuer): string {
    return totp_object_for_secret($plainSecret, $label, $issuer)->getProvisioningUri();
}

/** Renders the QR code for an otpauth:// URI as an inline data: URI (no temp files, no public asset needed). */
function totp_qr_data_uri(string $otpauthUri): string {
    $result = \Endroid\QrCode\Builder\Builder::create()
        ->writer(new \Endroid\QrCode\Writer\PngWriter())
        ->data($otpauthUri)
        ->size(240)
        ->margin(8)
        ->build();
    return $result->getDataUri();
}

/**
 * Verifies a 6-digit TOTP code against a plaintext secret with a +/-1 step
 * (30s) clock-drift window, enforcing replay protection via $lastUsedStep
 * (the previously accepted step; null if 2FA was never used before / during
 * initial setup). Returns the matched step to persist as the new
 * totp_last_used_step on success, or null if the code is invalid, doesn't
 * match any step in the window, or replays an already-used step.
 */
function totp_verify_and_consume(string $plainSecret, string $code, ?int $lastUsedStep): ?int {
    if (!totp_vendor_available()) {
        return null;
    }
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!is_string($code) || !preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $totp = \OTPHP\TOTP::createFromSecret($plainSecret);
    $now = time();
    for ($window = -1; $window <= 1; $window++) {
        $timestamp = $now + ($window * 30);
        $step = intdiv($timestamp, 30);
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }
        if (hash_equals($totp->at($timestamp), $code)) {
            return $step;
        }
    }
    return null;
}

/** 10 recovery codes as "xxxx-xxxx-xx" (uppercase hex, 40 bits entropy each). Plaintext -- caller must hash before persisting and only ever display once. */
function totp_generate_recovery_codes(): array {
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 2);
    }
    return $codes;
}

/** JSON array of password_hash()-hashed recovery codes, ready for the totp_recovery_codes column. */
function totp_hash_recovery_codes(array $plainCodes): string {
    return json_encode(array_values(array_map(fn(string $c): string => password_hash($c, PASSWORD_DEFAULT), $plainCodes)));
}

/**
 * Checks $input against the stored recovery-code hashes; on success returns
 * the remaining hashes as a JSON string (the matched code removed -- each
 * recovery code is single-use) for the caller to persist. Returns null if
 * $input matches nothing (also when $storedJson is empty/malformed).
 */
function totp_consume_recovery_code(?string $storedJson, string $input): ?string {
    if (empty($storedJson)) {
        return null;
    }
    $hashes = json_decode($storedJson, true);
    if (!is_array($hashes)) {
        return null;
    }
    $normalized = strtoupper(trim($input));
    foreach ($hashes as $i => $hash) {
        if (is_string($hash) && password_verify($normalized, $hash)) {
            unset($hashes[$i]);
            return json_encode(array_values($hashes));
        }
    }
    return null;
}

/**
 * The single admin account has no row of its own (no email/username -- see
 * account structure in the README), so its optional TOTP fields live next
 * to admin_password_hash in config.php instead of the users table. Only
 * offered on self-hosted instances (admin_totp_available()) -- on managed
 * cloud the admin's password path is locked by admin_password_login_disabled
 * and access goes through SSO instead, so an admin TOTP secret would guard a
 * path nobody is meant to use.
 */
function admin_totp_available(): bool {
    return empty(get_config()['managed_cloud']);
}

function admin_totp_enabled(): bool {
    return !empty(get_config()['admin_totp_enabled_at']);
}

/**
 * Resolves "who is currently logged in" to a uniform shape the rest of the
 * TOTP self-service flow (/admin/security) can work with regardless of
 * whether it's a regular user account (users table row) or the one primary
 * admin account (fields in config.php, only when self-hosted). Returns null
 * when TOTP simply isn't offered here (primary admin on managed cloud).
 */
function totp_current_identity(PDO $db): ?array {
    $userId = (int)($_SESSION['paragrafy_user_id'] ?? 0);
    if ($userId > 0) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ? ['type' => 'user', 'row' => $user] : null;
    }
    return admin_totp_available() ? ['type' => 'admin'] : null;
}

function totp_identity_enabled(array $identity): bool {
    return $identity['type'] === 'user' ? !empty($identity['row']['totp_enabled_at']) : admin_totp_enabled();
}

function totp_identity_label(array $identity, array $project): string {
    // otphp rejects a colon in the label (it's the separator it inserts
    // itself between issuer and label in the otpauth:// URI) -- the email
    // alone is already a unique, recognizable label once combined with the
    // issuer set via totp_identity_issuer().
    return $identity['type'] === 'user' ? $identity['row']['email'] : ('Paragrafy Admin (' . $project['domain'] . ')');
}

function totp_identity_issuer(array $identity, array $project): string {
    return $identity['type'] === 'user' ? ('Paragrafy (' . $project['name'] . ')') : 'Paragrafy Admin';
}

function totp_identity_password_hash(array $identity, array $config): string {
    return $identity['type'] === 'user' ? (string)($identity['row']['password_hash'] ?? '') : (string)($config['admin_password_hash'] ?? '');
}

/**
 * Persists a freshly confirmed TOTP enrollment -- secret AND recovery codes
 * together, in one write. Doing this as two separate writes (as an earlier
 * version of this code did) leaves a window where a crashed/conflicting
 * request in between them has already set totp_enabled_at (TOTP now
 * mandatory at login) with no recovery codes saved yet, locking the account
 * out of its own promised fallback.
 */
/**
 * Returns whether the enrollment was actually, durably persisted. For the
 * admin (config.php) branch this matters concretely: update_config()
 * returns null if the write itself failed, and a caller that ignored that
 * would show the user their recovery codes and log "TOTP enabled" even
 * though the secret never made it to disk -- falsely telling them the
 * account is protected.
 */
function totp_identity_complete_setup(PDO $db, array $identity, string $encryptedSecret, string $hashedRecoveryCodesJson): bool {
    if ($identity['type'] === 'user') {
        $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled_at = CURRENT_TIMESTAMP, totp_last_used_step = NULL, totp_recovery_codes = ? WHERE id = ?");
        $stmt->execute([$encryptedSecret, $hashedRecoveryCodesJson, $identity['row']['id']]);
        return $stmt->rowCount() === 1;
    }
    $result = update_config(function (?array $config) use ($encryptedSecret, $hashedRecoveryCodesJson): array {
        $config = $config ?? [];
        $config['admin_totp_secret'] = $encryptedSecret;
        $config['admin_totp_enabled_at'] = date('c');
        $config['admin_totp_last_used_step'] = null;
        $config['admin_totp_recovery_codes'] = $hashedRecoveryCodesJson;
        return $config;
    });
    return $result !== null;
}

/** Standalone recovery-code regeneration for an already-enabled identity (no partial-enrollment window since TOTP is already fully set up either way). Returns whether the write actually persisted. */
function totp_identity_replace_recovery_codes(PDO $db, array $identity, string $hashedJson): bool {
    if ($identity['type'] === 'user') {
        $stmt = $db->prepare("UPDATE users SET totp_recovery_codes = ? WHERE id = ?");
        $stmt->execute([$hashedJson, $identity['row']['id']]);
        return $stmt->rowCount() === 1;
    }
    $result = update_config(function (?array $config) use ($hashedJson): array {
        $config = $config ?? [];
        $config['admin_totp_recovery_codes'] = $hashedJson;
        return $config;
    });
    return $result !== null;
}

/** Returns whether the write actually persisted (see totp_identity_complete_setup()). */
function totp_identity_disable(PDO $db, array $identity): bool {
    if ($identity['type'] === 'user') {
        $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery_codes = NULL, totp_last_used_step = NULL WHERE id = ?");
        $stmt->execute([$identity['row']['id']]);
        return $stmt->rowCount() === 1;
    }
    $result = update_config(function (?array $config): array {
        $config = $config ?? [];
        unset($config['admin_totp_secret'], $config['admin_totp_enabled_at'], $config['admin_totp_recovery_codes'], $config['admin_totp_last_used_step']);
        return $config;
    });
    return $result !== null;
}

/**
 * Atomically consumes one TOTP step or recovery code for a user account's
 * login, returning true only if this request actually won the race to
 * consume it. Uses a compare-and-swap UPDATE -- the WHERE clause requires
 * totp_last_used_step to still equal the exact snapshot this request read
 * ($lastStep), not merely "less than the new step" -- so the write only
 * goes through if nothing else touched the row since this request's read.
 * A plain "< new step" comparison would (at least in theory) let two
 * concurrent requests that resolve the *same* submitted code to two
 * different adjacent steps both win, since each new step is still greater
 * than the other's; tying the swap to full snapshot equality closes that
 * regardless of whether such a resolution can actually happen. The loser's
 * UPDATE affects zero rows and is treated as an invalid code.
 */
function totp_consume_for_user_login(PDO $db, array $user, string $input): bool {
    $plainSecret = totp_decrypt_secret($user['totp_secret'] ?? null);
    if ($plainSecret !== null) {
        $lastStep = $user['totp_last_used_step'] !== null ? (int)$user['totp_last_used_step'] : null;
        $step = totp_verify_and_consume($plainSecret, $input, $lastStep);
        if ($step !== null) {
            if ($lastStep === null) {
                $stmt = $db->prepare("UPDATE users SET totp_last_used_step = ? WHERE id = ? AND totp_last_used_step IS NULL");
                $stmt->execute([$step, $user['id']]);
            } else {
                $stmt = $db->prepare("UPDATE users SET totp_last_used_step = ? WHERE id = ? AND totp_last_used_step = ?");
                $stmt->execute([$step, $user['id'], $lastStep]);
            }
            if ($stmt->rowCount() === 1) {
                return true;
            }
            // Lost the race (the row changed since our read, whatever the
            // reason) -- fall through and try the recovery-code path below,
            // exactly as if the TOTP code itself hadn't matched.
        }
    }

    $stored = $user['totp_recovery_codes'] ?? null;
    $remaining = totp_consume_recovery_code($stored, $input);
    if ($remaining !== null) {
        $stmt = $db->prepare("UPDATE users SET totp_recovery_codes = ? WHERE id = ? AND totp_recovery_codes = ?");
        $stmt->execute([$remaining, $user['id'], $stored]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Admin-account equivalent of totp_consume_for_user_login() -- the whole
 * check-and-set happens inside one update_config() lock instead of a CAS
 * retry, since config.php has no per-row compare-and-swap primitive the way
 * a SQL UPDATE ... WHERE does. Returns true only if this call actually
 * consumed the step/code.
 */
function totp_consume_for_admin_login(string $input): bool {
    $accepted = false;
    $result = update_config(function (?array $config) use ($input, &$accepted): ?array {
        $config = $config ?? [];
        $plainSecret = totp_decrypt_secret($config['admin_totp_secret'] ?? null);
        if ($plainSecret !== null) {
            $lastStep = isset($config['admin_totp_last_used_step']) ? (int)$config['admin_totp_last_used_step'] : null;
            $step = totp_verify_and_consume($plainSecret, $input, $lastStep);
            if ($step !== null) {
                $config['admin_totp_last_used_step'] = $step;
                $accepted = true;
                return $config;
            }
        }
        $remaining = totp_consume_recovery_code($config['admin_totp_recovery_codes'] ?? null, $input);
        if ($remaining !== null) {
            $config['admin_totp_recovery_codes'] = $remaining;
            $accepted = true;
            return $config;
        }
        return null; // nothing matched -- abort, don't touch the file
    });
    // update_config() returns null both when the mutator declined (nothing
    // matched -- $accepted stays false, consistent) and when it matched but
    // the write itself failed to reach disk -- in that second case $accepted
    // was already flipped true above, so it alone isn't a safe signal that
    // the one-time factor was actually, durably consumed. Require both: a
    // match AND a confirmed write, otherwise the caller must not finalize an
    // authenticated session for a "consumption" that never persisted (the
    // same code would still validate again on the next attempt).
    return $accepted && $result !== null;
}
