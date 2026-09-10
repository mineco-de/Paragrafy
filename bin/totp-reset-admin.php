<?php
declare(strict_types=1);

/**
 * Emergency, server-access-only reset of the primary admin account's TOTP
 * secret (self-hosted instances only).
 *
 * There is no web-UI reset for the admin account by design: unlike a
 * regular user account (which the primary admin can reset from
 * /admin/users), nobody stands above the admin account inside Core, so a
 * web-reachable reset for it would give anyone with a stolen/expired admin
 * session (but not the second factor) a way to strip their own TOTP without
 * proving server access. This script is the intended way out if the admin
 * loses both their authenticator device and all 10 recovery codes -- it
 * requires shell access to the box config.php lives on, which is the same
 * trust level the admin password itself already depends on.
 *
 * Usage: php bin/totp-reset-admin.php
 * (optionally: PARAGRAFY_DATA_DIR=/path/to/data php bin/totp-reset-admin.php,
 * matching whatever PARAGRAFY_DATA_DIR the running instance uses)
 */

require_once __DIR__ . '/../db.php';

if (!is_installed()) {
    fwrite(STDERR, "Paragrafy is not installed yet (no config.php/paragrafy_data.sqlite found under " . PARAGRAFY_DATA_DIR . ").\n");
    exit(1);
}

$config = get_config();

if (!empty($config['managed_cloud'])) {
    fwrite(STDERR, "This instance is a Managed Cloud instance -- the admin account never has TOTP here (access is enforced via SSO instead). Nothing to reset.\n");
    exit(1);
}

if (empty($config['admin_totp_enabled_at'])) {
    fwrite(STDOUT, "Admin TOTP is not currently enabled on this instance -- nothing to reset.\n");
    exit(0);
}

if (!totp_identity_disable(get_db(), ['type' => 'admin'])) {
    fwrite(STDERR, "Failed to write config.php -- check that " . PARAGRAFY_DATA_DIR . " is writable by this process and try again. Nothing was changed.\n");
    exit(1);
}

log_audit(null, '', 'Admin-TOTP per CLI-Notfallzugriff zurueckgesetzt (bin/totp-reset-admin.php)');

fwrite(STDOUT, "Admin TOTP has been reset. The admin account can log in with just the password again; 2FA can be set up again under Admin -> Security once logged in.\n");
exit(0);
