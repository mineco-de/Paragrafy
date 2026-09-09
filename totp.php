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
    $config['totp_encryption_key'] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    write_config($config);
    return $config['totp_encryption_key'];
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

function totp_identity_persist_secret(PDO $db, array $identity, string $encryptedSecret): void {
    if ($identity['type'] === 'user') {
        $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled_at = CURRENT_TIMESTAMP, totp_last_used_step = NULL WHERE id = ?")
            ->execute([$encryptedSecret, $identity['row']['id']]);
        return;
    }
    $config = get_config();
    $config['admin_totp_secret'] = $encryptedSecret;
    $config['admin_totp_enabled_at'] = date('c');
    $config['admin_totp_last_used_step'] = null;
    write_config($config);
}

function totp_identity_persist_recovery_codes(PDO $db, array $identity, string $hashedJson): void {
    if ($identity['type'] === 'user') {
        $db->prepare("UPDATE users SET totp_recovery_codes = ? WHERE id = ?")->execute([$hashedJson, $identity['row']['id']]);
        return;
    }
    $config = get_config();
    $config['admin_totp_recovery_codes'] = $hashedJson;
    write_config($config);
}

function totp_identity_disable(PDO $db, array $identity): void {
    if ($identity['type'] === 'user') {
        $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery_codes = NULL, totp_last_used_step = NULL WHERE id = ?")
            ->execute([$identity['row']['id']]);
        return;
    }
    $config = get_config();
    unset($config['admin_totp_secret'], $config['admin_totp_enabled_at'], $config['admin_totp_recovery_codes'], $config['admin_totp_last_used_step']);
    write_config($config);
}
