<?php
/**
 * Paragrafy - Database, Helper, Scheduled Publishing, SMTP & Full-Spec Webhook Logger Core
 */
declare(strict_types=1);

// CalVer: JAHR.MONAT.BUILD - BUILD zaehlt Releases innerhalb des Monats hoch (startet bei 1).
// Siehe CHANGELOG.md fuer die Aenderungen je Version.
define('PARAGRAFY_VERSION', '2026.9.14');
define('PARAGRAFY_DIR', __DIR__);
// Where persistent data (DB, config, backups, .env) lives. Defaults to the
// code directory (bare-metal installs); set PARAGRAFY_DATA_DIR to point this
// at a mounted volume in Docker so rebuilds don't wipe your data.
define('PARAGRAFY_DATA_DIR', rtrim((string)(getenv('PARAGRAFY_DATA_DIR') ?: PARAGRAFY_DIR), '/'));
define('DB_FILE', PARAGRAFY_DATA_DIR . '/paragrafy_data.sqlite');
define('CONFIG_FILE', PARAGRAFY_DATA_DIR . '/config.php');
define('BACKUP_DIR', PARAGRAFY_DATA_DIR . '/backups');
define('BACKUP_RETENTION_DAYS', 7);

if (!is_dir(PARAGRAFY_DATA_DIR)) {
    @mkdir(PARAGRAFY_DATA_DIR, 0755, true);
}

require_once __DIR__ . '/totp.php';

function load_env_file(): array {
    $env = [];
    $candidates = [PARAGRAFY_DATA_DIR . '/.env.local', PARAGRAFY_DATA_DIR . '/.env'];
    foreach ($candidates as $file) {
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (str_contains($line, '=')) {
                        [$k, $v] = explode('=', $line, 2);
                        $k = trim($k);
                        $v = trim($v, " \t\n\r\0\x0B\"'");
                        $env[$k] = $v;
                    }
                }
            }
            break;
        }
    }
    return $env;
}

function is_installed(): bool {
    return file_exists(CONFIG_FILE) && file_exists(DB_FILE);
}

/** Shared in-process cache backing get_config()/write_config() (see write_config() for why). */
function &config_cache_ref(): ?array {
    static $cache = null;
    return $cache;
}

function get_config(): array {
    $cache = &config_cache_ref();
    if ($cache !== null) {
        return $cache;
    }
    if (!file_exists(CONFIG_FILE)) {
        return [];
    }
    $cache = require CONFIG_FILE;
    return $cache;
}

/**
 * Writes config.php and keeps the in-process cache in sync so a get_config()
 * call later in the *same* request immediately sees this write. Without this,
 * two write_config() calls in one request (e.g. TOTP setup persisting the
 * secret, then the recovery codes, in two separate get_config()+write_config()
 * round-trips) can clobber each other: PHP's `require` re-parses the file on
 * every call, but with opcache enabled the bytecode isn't guaranteed to be
 * revalidated against disk within the same request/revalidate_freq window --
 * the second write_config() could start from a get_config() that still
 * reflects the pre-first-write state and overwrite it.
 */
/**
 * Atomic read-modify-write for config.php: holds an exclusive file lock for
 * the entire cycle, so two concurrent requests mutating config.php (e.g. two
 * logins racing to consume the same admin TOTP step/recovery code, or two
 * first-time TOTP setups racing to generate totp_encryption_key) can't
 * interleave their get_config()+write_config() calls and clobber each
 * other's write -- unlike two independent get_config()/write_config() calls,
 * which are each individually consistent but not atomic as a pair.
 *
 * $mutator receives the current config (freshly read from disk under the
 * lock, ignoring the in-process cache to guarantee it reflects any write
 * another process made) and returns the new config to persist, or null to
 * abort without writing (e.g. "the code was already consumed by someone
 * else, don't touch the file"). Returns the written config, or null if the
 * mutator aborted.
 */
function update_config(callable $mutator): ?array {
    if (!is_dir(PARAGRAFY_DATA_DIR)) {
        mkdir(PARAGRAFY_DATA_DIR, 0755, true);
    }
    $lockHandle = fopen(PARAGRAFY_DATA_DIR . '/config.lock', 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        // Fail closed, not open: falling back to an unlocked read-modify-
        // write here would silently reintroduce the exact race this
        // function exists to prevent (e.g. two logins consuming the same
        // admin TOTP step/recovery code) for every caller that trusts a
        // non-null return as proof of atomic, durable consumption. A lock
        // acquisition failure is an environment problem (unwritable data
        // dir, a filesystem without flock support) the operator needs to
        // know about -- not something to paper over with weaker semantics.
        throw new \RuntimeException('Could not acquire config.lock for an atomic config update.');
    }
    try {
        $config = file_exists(CONFIG_FILE) ? (require CONFIG_FILE) : [];
        $config = $mutator($config);
        // A caller like totp_consume_for_admin_login() only trusts the
        // one-time factor as consumed once update_config() returns non-null
        // -- if the write itself fails (disk full, permissions), treat the
        // whole mutation as if it never happened rather than letting the
        // caller finalize a session/state change that never made it to disk
        // (which would make the same code replayable on the next request).
        if ($config === null || !write_config($config)) {
            return null;
        }
        return $config;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/** Returns false (without throwing) if the write itself failed, so callers -- especially update_config() -- can refuse to treat an unpersisted mutation as having happened. */
function write_config(array $config): bool {
    if (!is_dir(PARAGRAFY_DATA_DIR)) {
        mkdir(PARAGRAFY_DATA_DIR, 0755, true);
    }
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents(CONFIG_FILE, $content) === false) {
        return false;
    }
    $cache = &config_cache_ref();
    $cache = $config;
    return true;
}

/** Returns the shared secret for the /api/cron/* endpoints, generating one on first use (self-healing for installs from before this existed). */
function ensure_cron_secret(): string {
    $config = get_config();
    if (!empty($config['cron_secret'])) {
        return $config['cron_secret'];
    }
    return regenerate_cron_secret();
}

function regenerate_cron_secret(): string {
    $secret = bin2hex(random_bytes(32));
    // Goes through update_config() (not a bare get_config()+write_config())
    // so this can't race with a concurrent TOTP config mutation (e.g. an
    // admin login consuming a TOTP step) and silently clobber it or get
    // clobbered by it -- both now serialize on the same config.php lock.
    update_config(function (?array $config) use ($secret): array {
        $config = $config ?? [];
        $config['cron_secret'] = $secret;
        return $config;
    });
    return $secret;
}

function verify_cron_secret(): bool {
    $expected = ensure_cron_secret();
    $given = (string)($_GET['secret'] ?? '');
    return $given !== '' && hash_equals($expected, $given);
}

/**
 * Optional cap on how many rows the `projects` table may hold in this
 * instance, read from config.php's `project_limit` (int|null). Absent by
 * default (existing installs never gained this key) -- returning null means
 * unlimited, so bare self-hosted installs are unaffected. A SaaS layer that
 * provisions one Paragrafy instance per account can set this via
 * write_config() at provisioning time (e.g. 1 for a single-project plan, a
 * configurable ceiling for an agency plan) to prevent an account from
 * bypassing its plan's project limit through this instance's own /admin.
 */
function get_project_limit(): ?int {
    $config = get_config();
    if (!isset($config['project_limit']) || $config['project_limit'] === null || $config['project_limit'] === '') {
        return null;
    }
    return (int)$config['project_limit'];
}

/**
 * Call at the top of a /api/cron/* handler; exits with 403 JSON unless either
 * ?secret= matches or the request comes from an already-logged-in admin
 * session (so in-app buttons like "Prüfbericht jetzt senden" keep working
 * without exposing the secret in front-end JS).
 */
function require_cron_secret(): void {
    if (!empty($_SESSION['paragrafy_admin'])) {
        return;
    }
    if (!verify_cron_secret()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => t('db.cron.invalid_secret')]);
        exit;
    }
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        ensure_schema_migrations($pdo);
    }
    return $pdo;
}

function ensure_schema_migrations(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='projects'");
        if ($stmt && $stmt->fetch()) {
            $cols = $pdo->query("PRAGMA table_info(projects)")->fetchAll();
            $colNames = array_column($cols, 'name');
            $newCols = [
                'deepl_api_key' => "TEXT DEFAULT ''",
                'logo_url' => "TEXT DEFAULT ''",
                'cookie_banner_enabled' => "INTEGER DEFAULT 0",
                'cookie_banner_text' => "TEXT DEFAULT ''",
                'webhook_url' => "TEXT DEFAULT ''",
                'webhook_secret' => "TEXT DEFAULT ''",
                'audit_interval_months' => "INTEGER DEFAULT 12",
                'smtp_host' => "TEXT DEFAULT ''",
                'smtp_port' => "INTEGER DEFAULT 587",
                'smtp_user' => "TEXT DEFAULT ''",
                'smtp_pass' => "TEXT DEFAULT ''",
                'smtp_secure' => "TEXT DEFAULT 'tls'",
                'smtp_from' => "TEXT DEFAULT ''",
                'audit_email_recipient' => "TEXT DEFAULT ''",
                'consent_logging_enabled' => "INTEGER DEFAULT 0",
                'consent_log_retention_days' => "INTEGER DEFAULT 1095",
                'ai_provider' => "TEXT DEFAULT ''",
                'ai_api_key' => "TEXT DEFAULT ''",
                // SQLite lehnt bei ALTER TABLE ... ADD COLUMN einen nicht-konstanten Default
                // (CURRENT_TIMESTAMP) auf Tabellen mit UNIQUE-Constraint bzw. eingehenden
                // Foreign-Key-Referenzen ab ("Cannot add a column with non-constant default")
                // -- genau das ist projects (UNIQUE(domain), von documents/... referenziert).
                // Deshalb hier ein konstanter Platzhalter-Default, Backfill per UPDATE direkt
                // im Anschluss (UPDATE darf CURRENT_TIMESTAMP sehr wohl verwenden).
                'settings_updated_at' => "DATETIME DEFAULT '1970-01-01 00:00:00'",
                'settings_version' => "INTEGER NOT NULL DEFAULT 1"
            ];
            foreach ($newCols as $c => $type) {
                if (!in_array($c, $colNames)) {
                    $pdo->exec("ALTER TABLE projects ADD COLUMN " . $c . " " . $type);
                }
            }
            // Unbedingt (nicht nur direkt nach dem ADD COLUMN) ausfuehren: bricht ein Lauf
            // zwischen ALTER TABLE und diesem UPDATE ab, erkennt der naechste Aufruf die Spalte
            // bereits als vorhanden und wuerde den Backfill sonst fuer immer ueberspringen.
            $pdo->exec("UPDATE projects SET settings_updated_at = CURRENT_TIMESTAMP WHERE settings_updated_at = '1970-01-01 00:00:00'");
        }

        $stmtUsers = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        if ($stmtUsers && $stmtUsers->fetch()) {
            $colsUsers = $pdo->query("PRAGMA table_info(users)")->fetchAll();
            $userColNames = array_column($colsUsers, 'name');
            if (!in_array('locale', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN locale TEXT DEFAULT 'de'");
            }
            if (!in_array('notes', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN notes TEXT DEFAULT ''");
            }
            if (!in_array('invite_token_expires_at', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN invite_token_expires_at DATETIME DEFAULT NULL");
            }
            if (!in_array('totp_secret', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT NULL");
            }
            if (!in_array('totp_enabled_at', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME NULL");
            }
            if (!in_array('totp_recovery_codes', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN totp_recovery_codes TEXT NULL");
            }
            if (!in_array('totp_last_used_step', $userColNames)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN totp_last_used_step INTEGER NULL");
            }
        }

        $stmtTrans = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='translations'");
        if ($stmtTrans && $stmtTrans->fetch()) {
            $colsTrans = $pdo->query("PRAGMA table_info(translations)")->fetchAll();
            $transColNames = array_column($colsTrans, 'name');
            $transNewCols = [
                'change_note' => "TEXT DEFAULT ''",
                'source_hash' => "TEXT DEFAULT ''",
                'previous_content' => "TEXT DEFAULT ''",
                'scheduled_at' => "DATETIME DEFAULT NULL",
                'scheduled_title' => "TEXT DEFAULT ''",
                'scheduled_slug' => "TEXT DEFAULT ''",
                'scheduled_content' => "TEXT DEFAULT ''",
                'scheduled_note' => "TEXT DEFAULT ''"
            ];
            foreach ($transNewCols as $tc => $ttype) {
                if (!in_array($tc, $transColNames)) {
                    $pdo->exec("ALTER TABLE translations ADD COLUMN " . $tc . " " . $ttype);
                }
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sso_nonces (
                nonce TEXT PRIMARY KEY,
                used_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                event_name TEXT NOT NULL,
                url TEXT NOT NULL,
                status_code INTEGER DEFAULT 0,
                request_payload TEXT DEFAULT '',
                response_body TEXT DEFAULT '',
                error_message TEXT DEFAULT '',
                duration_ms INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT DEFAULT '',
                status TEXT DEFAULT 'invited',
                invite_token TEXT DEFAULT '',
                invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                activated_at DATETIME DEFAULT NULL,
                locale TEXT DEFAULT 'de',
                notes TEXT DEFAULT '',
                invite_token_expires_at DATETIME DEFAULT NULL,
                totp_secret TEXT NULL,
                totp_enabled_at DATETIME NULL,
                totp_recovery_codes TEXT NULL,
                totp_last_used_step INTEGER NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER DEFAULT NULL,
                project_name TEXT DEFAULT '',
                user_name TEXT DEFAULT 'Admin',
                action TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS translation_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                lang TEXT NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                content TEXT NOT NULL,
                change_note TEXT DEFAULT '',
                status TEXT DEFAULT 'published',
                user_name TEXT DEFAULT 'Admin',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                event_name TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                attempts INTEGER DEFAULT 0,
                next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_error TEXT DEFAULT '',
                last_status_code INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME DEFAULT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS consent_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                consent_id TEXT NOT NULL,
                action TEXT NOT NULL,
                lang TEXT DEFAULT '',
                banner_text_hash TEXT DEFAULT '',
                ip_anonymized TEXT DEFAULT '',
                user_agent TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_projects (
                user_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, project_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
        ");
    } catch (Throwable $e) {
    }
}

function init_database_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            primary_lang TEXT DEFAULT 'de',
            active_languages TEXT DEFAULT 'de,en',
            brand_color TEXT DEFAULT '#F0A63C',
            logo_url TEXT DEFAULT '',
            deepl_api_key TEXT DEFAULT '',
            webhook_url TEXT DEFAULT '',
            webhook_secret TEXT DEFAULT '',
            audit_interval_months INTEGER DEFAULT 12,
            smtp_host TEXT DEFAULT '',
            smtp_port INTEGER DEFAULT 587,
            smtp_user TEXT DEFAULT '',
            smtp_pass TEXT DEFAULT '',
            smtp_secure TEXT DEFAULT 'tls',
            smtp_from TEXT DEFAULT '',
            audit_email_recipient TEXT DEFAULT '',
            cookie_banner_enabled INTEGER DEFAULT 0,
            cookie_banner_text TEXT DEFAULT '',
            consent_logging_enabled INTEGER DEFAULT 0,
            consent_log_retention_days INTEGER DEFAULT 1095,
            ai_provider TEXT DEFAULT '',
            ai_api_key TEXT DEFAULT '',
            company_name TEXT DEFAULT '',
            address TEXT DEFAULT '',
            email TEXT DEFAULT '',
            phone TEXT DEFAULT '',
            representative TEXT DEFAULT '',
            register_info TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS doc_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT '',
            is_required INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            doc_type_id INTEGER NOT NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (doc_type_id) REFERENCES doc_types(id) ON DELETE CASCADE,
            UNIQUE(project_id, doc_type_id)
        );

        CREATE TABLE IF NOT EXISTS translations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            lang TEXT NOT NULL,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            content TEXT NOT NULL,
            previous_content TEXT DEFAULT '',
            status TEXT DEFAULT 'draft',
            change_note TEXT DEFAULT '',
            source_hash TEXT DEFAULT '',
            scheduled_at DATETIME DEFAULT NULL,
            scheduled_title TEXT DEFAULT '',
            scheduled_slug TEXT DEFAULT '',
            scheduled_content TEXT DEFAULT '',
            scheduled_note TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            UNIQUE(document_id, lang)
        );

        CREATE TABLE IF NOT EXISTS webhook_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            event_name TEXT NOT NULL,
            url TEXT NOT NULL,
            status_code INTEGER DEFAULT 0,
            request_payload TEXT DEFAULT '',
            response_body TEXT DEFAULT '',
            error_message TEXT DEFAULT '',
            duration_ms INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT DEFAULT '',
            status TEXT DEFAULT 'invited',
            invite_token TEXT DEFAULT '',
            invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            activated_at DATETIME DEFAULT NULL,
            locale TEXT DEFAULT 'de',
            notes TEXT DEFAULT '',
            invite_token_expires_at DATETIME DEFAULT NULL,
            totp_secret TEXT NULL,
            totp_enabled_at DATETIME NULL,
            totp_recovery_codes TEXT NULL,
            totp_last_used_step INTEGER NULL
        );

        CREATE TABLE IF NOT EXISTS sso_nonces (
            nonce TEXT PRIMARY KEY,
            used_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS user_projects (
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, project_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER DEFAULT NULL,
            project_name TEXT DEFAULT '',
            user_name TEXT DEFAULT 'Admin',
            action TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS translation_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            lang TEXT NOT NULL,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            content TEXT NOT NULL,
            change_note TEXT DEFAULT '',
            status TEXT DEFAULT 'published',
            user_name TEXT DEFAULT 'Admin',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS webhook_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            event_name TEXT NOT NULL,
            payload TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0,
            next_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_error TEXT DEFAULT '',
            last_status_code INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS consent_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            consent_id TEXT NOT NULL,
            action TEXT NOT NULL,
            lang TEXT DEFAULT '',
            banner_text_hash TEXT DEFAULT '',
            ip_anonymized TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
    ");
}

function check_and_publish_scheduled(PDO $pdo, ?array $project = null): array {
    $publishedCount = 0;
    try {
        $nowStr = date('Y-m-d H:i:s');
        $sql = "
            SELECT t.id, t.document_id, t.lang, t.title, t.slug, t.content, t.scheduled_title, t.scheduled_slug, t.scheduled_content, t.scheduled_note, t.scheduled_at,
                   d.project_id, p.name as project_name, p.domain, p.webhook_url, p.webhook_secret
            FROM translations t
            JOIN documents d ON t.document_id = d.id
            JOIN projects p ON d.project_id = p.id
            WHERE t.scheduled_at IS NOT NULL AND t.scheduled_at <= ?
        ";
        $params = [$nowStr];
        if ($project !== null) {
            $sql .= " AND p.id = ?";
            $params[] = $project['id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $due = $stmt->fetchAll();

        foreach ($due as $row) {
            $finalTitle = $row['scheduled_title'] !== '' ? $row['scheduled_title'] : $row['title'];
            $finalSlug = $row['scheduled_slug'] !== '' ? $row['scheduled_slug'] : $row['slug'];

            $upd = $pdo->prepare("
                UPDATE translations SET
                    title = CASE WHEN scheduled_title != '' THEN scheduled_title ELSE title END,
                    slug = CASE WHEN scheduled_slug != '' THEN scheduled_slug ELSE slug END,
                    previous_content = content,
                    content = CASE WHEN scheduled_content != '' THEN scheduled_content ELSE content END,
                    change_note = scheduled_note,
                    status = 'published',
                    scheduled_at = NULL,
                    scheduled_title = '',
                    scheduled_slug = '',
                    scheduled_content = '',
                    scheduled_note = '',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([$row['id']]);
            $publishedCount++;

            record_translation_version(
                $pdo,
                (int)$row['document_id'],
                $row['lang'],
                $finalTitle,
                $finalSlug,
                $row['scheduled_content'] !== '' ? $row['scheduled_content'] : $row['content'],
                $row['scheduled_note'],
                'published',
                t('db.scheduled_publish_note')
            );

            enqueue_webhook($row, [
                'event_type' => 'legal_text.updated',
                'document_id' => (int)$row['document_id'],
                'slug' => $finalSlug,
                'lang' => $row['lang'],
                'title' => $finalTitle,
                'status' => 'published',
                'change_note' => $row['scheduled_note'],
                'was_scheduled' => true,
                'effective_date' => date('c'),
                'updated_at' => date('c')
            ]);
        }
    } catch (Throwable $e) {
    }
    return ['published_count' => $publishedCount];
}

function svg_icon(string $name, string $extraClass = '', int $size = 16): string {
    $icons = [
        'check' => '<path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>',
        'warning' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'clock' => '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'lightning' => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>',
        'disk' => '<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM17 21v-8H7v8M7 3v5h8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'folder' => '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'link' => '<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'external' => '<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6m4-3h6v6m-11 5L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2"/><path d="M22 6l-10 7L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'print' => '<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'edit' => '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'search' => '<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'sync' => '<path d="M23 4v6h-6M1 20v-6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="19" x2="20" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
    ];
    $path = $icons[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" class="' . $extraClass . '" style="vertical-align:middle; display:inline-block;">' . $path . '</svg>';
}

function help_icon(string $text): string {
    return '<span class="pg-help" tabindex="0" title="' . htmlspecialchars($text, ENT_QUOTES) . '">?</span>';
}

/**
 * Allowlist HTML sanitizer for legal-text content coming from the WYSIWYG
 * editor, the AI import (BETA) and DeepL translation results. Content saved
 * here is later embedded verbatim into customer sites (index.php) and shown
 * raw in editor.php's preview panes, so it must never carry executable
 * markup (script/style/iframe/on*-handlers/javascript: URLs) -- but it does
 * need to stay real HTML (headings, lists, links, tables), so htmlspecialchars()
 * would be wrong here. Disallowed-but-harmless wrapper tags are unwrapped
 * (their content survives as plain text/inline markup); disallowed tags that
 * only make sense as executable/embedded content are dropped entirely.
 */
function sanitize_legal_html(string $html): string {
    if (trim($html) === '') {
        return '';
    }

    $allowedTags = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href', 'target', 'rel'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => [], 'th' => [],
        'span' => ['class'], 'div' => ['class'],
    ];
    $dropEntirely = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'svg', 'math', 'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'select', 'option'];

    $prevErrors = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrors);

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) {
        return '';
    }
    sanitize_html_node($body, $allowedTags, $dropEntirely);

    $out = '';
    foreach (iterator_to_array($body->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

function sanitize_html_node(DOMNode $node, array $allowedTags, array $dropEntirely): void {
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) {
            continue;
        }
        if (!($child instanceof DOMElement)) {
            // Comments, processing instructions, doctypes etc. -- never legitimate in saved content.
            $node->removeChild($child);
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (in_array($tag, $dropEntirely, true)) {
            $node->removeChild($child);
            continue;
        }
        if (!isset($allowedTags[$tag])) {
            // Unwrap: keep the (sanitized) children, drop just this wrapper tag.
            sanitize_html_node($child, $allowedTags, $dropEntirely);
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        $allowedAttrs = $allowedTags[$tag];
        foreach (iterator_to_array($child->attributes ?? []) as $attr) {
            $attrName = strtolower($attr->nodeName);
            if (!in_array($attrName, $allowedAttrs, true)) {
                $child->removeAttribute($attr->nodeName);
                continue;
            }
            if (in_array($attrName, ['href', 'src'], true) && preg_match('/^\s*(javascript|data|vbscript):/i', $attr->nodeValue)) {
                $child->removeAttribute($attr->nodeName);
            }
        }
        if ($tag === 'a') {
            $child->setAttribute('rel', 'noopener noreferrer nofollow');
        }

        sanitize_html_node($child, $allowedTags, $dropEntirely);
    }
}

/**
 * Waehlt aus einer Liste veroeffentlichter Uebersetzungen desselben Dokuments die beste
 * Sprache: zuerst $preferredLang, dann Englisch, dann die Projekt-primary_lang, sonst die
 * erste vorhandene (deckt den Fall "nur eine Sprache existiert" automatisch ab).
 * $primaryLang ist dabei kein verlaesslicher Garant fuer Existenz (frei editierbares Feld),
 * daher nur als Versuch in der Kette, nicht als sicherer Treffer.
 */
function pick_fallback_translation(array $candidates, string $preferredLang, string $primaryLang): ?array {
    if (empty($candidates)) {
        return null;
    }
    foreach ([$preferredLang, 'en', $primaryLang] as $lang) {
        foreach ($candidates as $c) {
            if ($c['lang'] === $lang) {
                return $c;
            }
        }
    }
    return $candidates[0];
}

/**
 * Fuehrt $sql aus und liefert die einzige Ergebniszeile zurueck -- aber nur, wenn GENAU EIN
 * Treffer existiert. Bei 0 oder >=2 Treffern wird bewusst null geliefert statt zu raten (per
 * LIMIT 2 reicht ein zweiter Treffer schon, um "mehrdeutig" zu erkennen, ohne alle Zeilen zu
 * laden). Bei oeffentlichen Rechtstexten ist das falsche Dokument auszuliefern schlimmer als
 * ein 404 -- diese Funktion setzt "nicht eindeutig = kein Treffer" konsequent durch, statt es
 * an jeder Aufrufstelle einzeln (und ggf. inkonsistent) zu pruefen.
 */
function find_unambiguous_match(PDO $db, string $sql, array $params): ?array {
    $stmt = $db->prepare($sql . ' LIMIT 2');
    $stmt->execute($params);
    $matches = $stmt->fetchAll();
    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Findet die oeffentlich auslieferbare Uebersetzung fuer genau die angefragte ($lang, $slug)-
 * Kombination -- der Normalfall, BEVOR ueberhaupt ein Sprach-Fallback in Betracht gezogen wird.
 * Historisch war das eine einzelne Query mit `t.slug = ? OR dt.slug = ?`, die (genau wie der
 * urspruengliche Fallback-Entwurf) bei zwei Dokumenten mit kollidierendem Slug in derselben
 * Sprache per ungeordnetem LIMIT 1 haette das falsche Dokument waehlen koennen.
 *
 * Aufloesung in ZWEI PRIORITAETSSTUFEN (nicht als eine kombinierte Menge -- siehe
 * find_public_translation_with_fallback() fuer die Begruendung, warum dt.slug bewusst Vorrang
 * vor t.slug hat, auch wenn beide vorkommen):
 * 1. dt.slug (kanonisch) -- eindeutigkeitsgeprueft ueber find_unambiguous_match(), gewinnt wenn
 *    eindeutig, UNABHAENGIG davon, ob $slug zufaellig auch als jemandes Custom-Slug auftaucht.
 * 2. Nur falls Stufe 1 nichts liefert: t.slug (benutzerdefiniert), ebenfalls eindeutigkeitsgeprueft.
 */
function find_public_translation(PDO $db, int $projectId, string $lang, string $slug): ?array {
    $doc = find_unambiguous_match(
        $db,
        "SELECT DISTINCT d.id FROM documents d
         JOIN doc_types dt ON d.doc_type_id = dt.id
         JOIN translations t ON t.document_id = d.id AND t.lang = ? AND t.status = 'published'
         WHERE d.project_id = ? AND dt.slug = ?",
        [$lang, $projectId, $slug]
    );

    if (!$doc) {
        $doc = find_unambiguous_match(
            $db,
            "SELECT DISTINCT d.id FROM translations t
             JOIN documents d ON t.document_id = d.id
             WHERE d.project_id = ? AND t.lang = ? AND t.slug = ? AND t.status = 'published'",
            [$projectId, $lang, $slug]
        );
    }

    if (!$doc) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT t.*, d.doc_type_id, dt.title AS default_title, dt.slug AS default_slug
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE t.document_id = ? AND t.lang = ? AND t.status = 'published'
        LIMIT 1
    ");
    $stmt->execute([$doc['id'], $lang]);
    return $stmt->fetch() ?: null;
}

/**
 * Findet eine oeffentlich auslieferbare Uebersetzung fuer $slug, wenn die exakt angefragte
 * $lang keine veroeffentlichte Version hat. Das Dokument wird ueber JEDE Sprache anhand des
 * Slugs identifiziert, in zwei PRIORITAETSSTUFEN statt einer kombinierten Menge:
 *
 * 1. dt.slug (kanonisch): doc_types.slug hat ein UNIQUE-Constraint (der Doc-Type selbst ist
 *    projektweit eindeutig), aber documents(project_id, doc_type_id) ist NICHT UNIQUE --
 *    trotzdem ueber find_unambiguous_match() geprueft statt blindem LIMIT 1. Findet Stufe 1
 *    einen eindeutigen Treffer, WIRD ER VERWENDET, auch wenn derselbe Slug zufaellig auch als
 *    benutzerdefinierter Slug eines VOELLIG ANDEREN Dokuments existiert (Stufe 2 wird dann gar
 *    nicht erst abgefragt). Grund: der kanonische Slug ist ein strukturell garantiertes,
 *    admin-vergebenes Merkmal des Doc-Types -- ein zufaellig gleichlautender Custom-Slug eines
 *    fremden Dokuments ist so gut wie immer ein Tippfehler/Versehen der jeweiligen Redaktion,
 *    nicht ein ernsthafter Anspruch auf denselben Slug. Diese Prioritaet ist zugleich wichtig
 *    fuer render_public_overview(): deren Links zeigen IMMER auf dt.slug (siehe dort) und
 *    duerfen sich nicht durch eine fremde Custom-Slug-Kollision in ein 404 verwandeln lassen,
 *    obwohl die Uebersicht das verlinkte Dokument eindeutig kannte.
 * 2. Nur falls Stufe 1 nichts liefert: t.slug (benutzerdefiniert). Der ist NICHT global eindeutig
 *    (nur UNIQUE(document_id, lang)) -- zwei verschiedene Dokumente KOENNEN denselben eigenen
 *    Slug tragen, daher ebenfalls ueber find_unambiguous_match() statt zu raten.
 */
function find_public_translation_with_fallback(PDO $db, int $projectId, string $lang, string $slug, string $primaryLang): ?array {
    $doc = find_unambiguous_match(
        $db,
        "SELECT DISTINCT d.id FROM documents d JOIN doc_types dt ON d.doc_type_id = dt.id WHERE d.project_id = ? AND dt.slug = ?",
        [$projectId, $slug]
    );

    if (!$doc) {
        $doc = find_unambiguous_match(
            $db,
            "SELECT DISTINCT d.id FROM translations t JOIN documents d ON t.document_id = d.id WHERE d.project_id = ? AND t.slug = ? AND t.status = 'published'",
            [$projectId, $slug]
        );
    }

    if (!$doc) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT t.*, d.doc_type_id, dt.title AS default_title, dt.slug AS default_slug
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE t.document_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$doc['id']]);
    return pick_fallback_translation($stmt->fetchAll(), $lang, $primaryLang);
}

function replace_placeholders(string $content, array $project): string {
    $map = [
        '{{company_name}}' => htmlspecialchars($project['company_name'] ?? ''),
        '{{address}}' => nl2br(htmlspecialchars($project['address'] ?? '')),
        '{{email}}' => htmlspecialchars($project['email'] ?? ''),
        '{{phone}}' => htmlspecialchars($project['phone'] ?? ''),
        '{{representative}}' => htmlspecialchars($project['representative'] ?? ''),
        '{{register_info}}' => htmlspecialchars($project['register_info'] ?? ''),
        '{{year}}' => date('Y'),
    ];
    return str_replace(array_keys($map), array_values($map), $content);
}

function check_unfilled_placeholders(string $content, array $project): array {
    $tokens = ['{{company_name}}', '{{address}}', '{{email}}', '{{phone}}', '{{representative}}', '{{register_info}}'];
    $keys = [
        '{{company_name}}' => 'company_name',
        '{{address}}' => 'address',
        '{{email}}' => 'email',
        '{{phone}}' => 'phone',
        '{{representative}}' => 'representative',
        '{{register_info}}' => 'register_info',
    ];
    $unfilled = [];
    foreach ($tokens as $t) {
        if (str_contains($content, $t)) {
            $prop = $keys[$t];
            if (empty(trim($project[$prop] ?? ''))) {
                $unfilled[] = $t;
            }
        }
    }
    return array_unique($unfilled);
}

/**
 * Referenzstruktur der 6 Standard-Rechtstexte. Wird sowohl vom Install-Wizard
 * als auch vom KI-Einlesemodus (BETA) als Ziel-Vorlage genutzt.
 */
function get_standard_legal_templates(): array {
    return [
        'impressum' => [
            'title' => 'Impressum',
            'slug' => 'impressum',
            'is_required' => 1,
            'checked' => true,
            'content' => "<h2>Angaben gemäß § 5 DDG</h2>\n<p><strong>{{company_name}}</strong><br>{{address}}</p>\n<h3>Vertreten durch:</h3>\n<p>{{representative}}</p>\n<h3>Kontakt:</h3>\n<p>E-Mail: {{email}}<br>Telefon: {{phone}}</p>\n<h3>Registereintrag:</h3>\n<p>{{register_info}}</p>"
        ],
        'privacy' => [
            'title' => 'Datenschutzerklärung',
            'slug' => 'datenschutz',
            'is_required' => 1,
            'checked' => true,
            'content' => "<h2>1. Datenschutz auf einen Blick</h2>\n<h3>Allgemeine Hinweise</h3>\n<p>Die folgenden Hinweise geben einen einfachen Überblick darüber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Website besuchen.</p>\n<h3>Verantwortliche Stelle:</h3>\n<p><strong>{{company_name}}</strong><br>{{address}}<br>E-Mail: {{email}}</p>\n<h2>2. Erfassung von Daten auf dieser Website</h2>\n<p>Die Datenverarbeitung auf dieser Website erfolgt durch den Websitebetreiber.</p>"
        ],
        'terms_b2c' => [
            'title' => 'AGB (Endkunden / B2C)',
            'slug' => 'agb-b2c',
            'is_required' => 0,
            'checked' => false,
            'content' => "<h2>1. Geltungsbereich für Verbraucher (B2C)</h2>\n<p>Für alle Verträge mit Verbrauchern über die Angebote von {{company_name}} gelten nachfolgende Bedingungen.</p>"
        ],
        'terms_b2b' => [
            'title' => 'AGB (Geschäftskunden / B2B)',
            'slug' => 'agb-b2b',
            'is_required' => 0,
            'checked' => false,
            'content' => "<h2>1. Geltungsbereich für Unternehmer (B2B)</h2>\n<p>Diese Geschäftsbedingungen gelten ausschließlich für Geschäftsbeziehungen mit Unternehmern, juristischen Personen des öffentlichen Rechts oder öffentlich-rechtlichen Sondervermögen.</p>"
        ],
        'cookies' => [
            'title' => 'Cookie-Richtlinie',
            'slug' => 'cookie-richtlinie',
            'is_required' => 0,
            'checked' => false,
            'content' => "<h2>Verwendung von Cookies</h2>\n<p>Unsere Website verwendet technisch notwendige Cookies, um grundlegende Funktionen zu gewährleisten.</p>"
        ],
        'revocation' => [
            'title' => 'Widerrufsbelehrung',
            'slug' => 'widerruf',
            'is_required' => 0,
            'checked' => false,
            'content' => "<h2>Widerrufsrecht</h2>\n<p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.</p>"
        ]
    ];
}

/**
 * Findet die passendste Standard-Referenzvorlage für einen gegebenen doc_type-Slug
 * (z.B. 'impressum', 'datenschutz', 'agb-b2c', ...). Fällt auf null zurück,
 * wenn kein Standardtyp passt (Einlesemodus BETA ist nur für Standardtypen gedacht).
 */
function find_reference_template_for_slug(string $docTypeSlug): ?array {
    foreach (get_standard_legal_templates() as $tpl) {
        if ($tpl['slug'] === $docTypeSlug) {
            return $tpl;
        }
    }
    return null;
}

/** Grenzwert für Rohtext-Import (BETA), gegen versehentliche Riesig-Uploads/Fetches. */
const AI_IMPORT_MAX_RAW_BYTES = 200000;

/**
 * Prüft, ob eine per URL angegebene Adresse öffentlich (nicht privat/lokal) auflöst,
 * als SSRF-Schutz für den serverseitigen Fetch im Einlesemodus (BETA).
 */
function is_public_http_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
        return false;
    }
    $host = $parts['host'];
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $resolved = @gethostbynamel($host);
        if ($resolved === false || empty($resolved)) {
            return false;
        }
        $ips = $resolved;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/**
 * Holt den Rohtext für den KI-Einlesemodus (BETA) entweder von einer URL oder aus
 * einer hochgeladenen Datei (.html/.htm/.txt direkt, .pdf via pdftotext falls vorhanden).
 */
function fetch_raw_legal_text(string $source, string $type): array {
    if ($type === 'url') {
        $url = trim($source);
        if (!is_public_http_url($url)) {
            return ['success' => false, 'error' => t('db.ai_import.invalid_or_private_url')];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => t('db.deepl.curl_missing')];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Paragrafy-Import-Bot/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err) {
            return ['success' => false, 'error' => t('db.ai_import.fetch_failed', ['error' => $err])];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => t('db.ai_import.fetch_http_error', ['code' => $httpCode])];
        }
        $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $body)), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", $text)));
        if ($text === '') {
            return ['success' => false, 'error' => t('db.ai_import.empty_content')];
        }
        return ['success' => true, 'text' => substr($text, 0, AI_IMPORT_MAX_RAW_BYTES)];
    }

    if ($type === 'file') {
        $tmpPath = $source;
        if (!is_uploaded_file($tmpPath) && !is_readable($tmpPath)) {
            return ['success' => false, 'error' => t('db.ai_import.upload_failed')];
        }
        if (filesize($tmpPath) > AI_IMPORT_MAX_RAW_BYTES * 4) {
            return ['success' => false, 'error' => t('db.ai_import.file_too_large')];
        }
        $ext = strtolower(pathinfo($_FILES['import_file']['name'] ?? '', PATHINFO_EXTENSION));
        $raw = '';
        if (in_array($ext, ['html', 'htm'], true)) {
            $raw = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', (string)file_get_contents($tmpPath))), ENT_QUOTES, 'UTF-8');
        } elseif ($ext === 'txt') {
            $raw = (string)file_get_contents($tmpPath);
        } elseif ($ext === 'pdf') {
            if (function_exists('shell_exec') && trim((string)@shell_exec('which pdftotext 2>/dev/null')) !== '') {
                $raw = (string)@shell_exec('pdftotext ' . escapeshellarg($tmpPath) . ' - 2>/dev/null');
            }
            if (trim($raw) === '') {
                return ['success' => false, 'error' => t('db.ai_import.pdf_extraction_unavailable')];
            }
        } else {
            return ['success' => false, 'error' => t('db.ai_import.unsupported_file_type')];
        }
        $raw = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", $raw)));
        if ($raw === '') {
            return ['success' => false, 'error' => t('db.ai_import.empty_content')];
        }
        return ['success' => true, 'text' => substr($raw, 0, AI_IMPORT_MAX_RAW_BYTES)];
    }

    return ['success' => false, 'error' => t('db.ai_import.unsupported_file_type')];
}

/**
 * KI-gestützter Einlesemodus (BETA): überführt einen alten Rechtstext in die
 * Paragrafy-Zielstruktur inkl. {{platzhalter}} und liefert die im Text erkannten
 * Firmendaten als Vorschlag zurück (werden NICHT automatisch übernommen).
 */
function import_legal_text_with_ai(string $rawText, string $docTypeSlug, string $provider, string $apiKey): array {
    if (empty(trim($apiKey))) {
        return ['success' => false, 'error' => t('db.ai_import.no_api_key')];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => t('db.deepl.curl_missing')];
    }

    $reference = find_reference_template_for_slug($docTypeSlug);
    if (!$reference) {
        return ['success' => false, 'error' => t('db.ai_import.unsupported_doc_type')];
    }

    $allowedTokens = ['{{company_name}}', '{{address}}', '{{email}}', '{{phone}}', '{{representative}}', '{{register_info}}'];

    $prompt = "Du bekommst den rohen Text einer bestehenden Rechtstext-Seite (z.B. aus einem alten Impressum) sowie eine Ziel-Referenzstruktur im HTML-Format.\n\n"
        . "AUFGABE:\n"
        . "1. Erzeuge daraus HTML im Stil der Referenzstruktur (gleiche Überschriften-Ebenen und Abschnitte), aber mit den inhaltlichen Angaben aus dem Rohtext.\n"
        . "2. Ersetze konkrete Firmendaten im HTML-Fließtext IMMER durch genau diese Platzhalter-Tokens (keine anderen erfinden): " . implode(', ', $allowedTokens) . "\n"
        . "3. Extrahiere zusätzlich die erkannten Rohwerte für diese Felder aus dem Text: company_name, address, email, phone, representative, register_info. Wenn ein Feld nicht im Text vorkommt, liefere einen leeren String.\n"
        . "4. Gib AUSSCHLIESSLICH ein valides JSON-Objekt zurück, keinen weiteren Text, kein Markdown, in exakt dieser Form:\n"
        . '{"content": "<html-string>", "fields": {"company_name": "", "address": "", "email": "", "phone": "", "representative": "", "register_info": ""}}' . "\n\n"
        . "REFERENZSTRUKTUR (" . $reference['title'] . "):\n" . $reference['content'] . "\n\n"
        . "ROHTEXT:\n" . $rawText;

    if ($provider === 'openai') {
        $result = call_openai_chat($prompt, $apiKey);
    } else {
        $result = call_claude_messages($prompt, $apiKey);
    }

    if (!$result['success']) {
        return $result;
    }

    $jsonText = trim($result['text']);
    $jsonText = preg_replace('/^```(?:json)?/i', '', $jsonText);
    $jsonText = preg_replace('/```$/', '', trim($jsonText));
    $decoded = json_decode(trim($jsonText), true);

    if (!is_array($decoded) || !isset($decoded['content'])) {
        return ['success' => false, 'error' => t('db.ai_import.invalid_ai_response')];
    }

    $fields = [];
    foreach (['company_name', 'address', 'email', 'phone', 'representative', 'register_info'] as $f) {
        $fields[$f] = trim((string)($decoded['fields'][$f] ?? ''));
    }

    return ['success' => true, 'content' => (string)$decoded['content'], 'fields' => $fields];
}

function call_claude_messages(string $prompt, string $apiKey): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . trim($apiKey),
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-sonnet-5',
            'max_tokens' => 4096,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err) {
        return ['success' => false, 'error' => t('db.ai_import.request_failed', ['error' => $err])];
    }
    $data = json_decode((string)$body, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($data)) {
        $apiErr = $data['error']['message'] ?? (string)$body;
        return ['success' => false, 'error' => t('db.ai_import.provider_error', ['error' => $apiErr])];
    }
    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') {
        return ['success' => false, 'error' => t('db.ai_import.invalid_ai_response')];
    }
    return ['success' => true, 'text' => $text];
}

function call_openai_chat(string $prompt, string $apiKey): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . trim($apiKey),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
        ]),
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err) {
        return ['success' => false, 'error' => t('db.ai_import.request_failed', ['error' => $err])];
    }
    $data = json_decode((string)$body, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($data)) {
        $apiErr = $data['error']['message'] ?? (string)$body;
        return ['success' => false, 'error' => t('db.ai_import.provider_error', ['error' => $apiErr])];
    }
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($text === '') {
        return ['success' => false, 'error' => t('db.ai_import.invalid_ai_response')];
    }
    return ['success' => true, 'text' => $text];
}

/**
 * Übernimmt bestätigte Firmendaten aus dem Einlesemodus (BETA) in die projects-Tabelle.
 * Nur nicht-leere Felder werden geschrieben, bestehende Werte bleiben sonst erhalten.
 */
function update_project_company_fields(PDO $db, int $projectId, array $fields): void {
    $allowed = ['company_name', 'address', 'email', 'phone', 'representative', 'register_info'];
    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (isset($fields[$f]) && trim((string)$fields[$f]) !== '') {
            $sets[] = "$f = ?";
            $params[] = trim((string)$fields[$f]);
        }
    }
    if (empty($sets)) {
        return;
    }
    // Strikt hochzaehlen statt CURRENT_TIMESTAMP (s. admin.php-Settings-Save fuer die
    // ausfuehrliche Begruendung): verhindert, dass zwei Projekt-Updates in derselben Sekunde
    // (z. B. Einlesemodus direkt gefolgt von einem manuellen Admin-Save) denselben
    // Last-Modified-Wert erzeugen.
    $sets[] = "settings_updated_at = CASE WHEN datetime('now') > settings_updated_at THEN datetime('now') ELSE datetime(settings_updated_at, '+1 second') END";
    $sets[] = "settings_version = settings_version + 1";
    $params[] = $projectId;
    $stmt = $db->prepare("UPDATE projects SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
}

function send_smtp_mail(array $project, string $to, string $subject, string $bodyHtml): array {
    $to = strip_header_injection($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => t('db.smtp.recipient_rejected', ['response' => 'invalid recipient'])];
    }
    $subject = strip_header_injection($subject);
    $projectName = strip_header_injection((string)($project['name'] ?? ''));

    $host = trim($project['smtp_host'] ?? '');
    $port = (int)($project['smtp_port'] ?? 587);
    $user = trim($project['smtp_user'] ?? '');
    $pass = trim($project['smtp_pass'] ?? '');
    $secure = strtolower(trim($project['smtp_secure'] ?? 'tls'));
    $from = strip_header_injection(trim($project['smtp_from'] ?? '') ?: ($project['email'] ?? 'noreply@' . $project['domain']));

    if (empty($host)) {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . $from;
        $sent = @mail($to, $subject, $bodyHtml, $headers);
        return $sent ? ['success' => true] : ['success' => false, 'error' => t('db.smtp.mail_function_failed')];
    }

    $socketHost = ($secure === 'ssl') ? 'ssl://' . $host : $host;
    $socket = @fsockopen($socketHost, $port, $errno, $errstr, 15);
    if (!$socket) {
        return ['success' => false, 'error' => t('db.smtp.connection_failed', ['host' => $host, 'port' => $port, 'error' => $errstr, 'errno' => $errno])];
    }

    $read = function() use ($socket) {
        $res = '';
        while ($str = fgets($socket, 515)) {
            $res .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $res;
    };

    $write = function(string $cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $read();
    $write("EHLO " . gethostname());
    $read();

    if ($secure === 'tls') {
        $write("STARTTLS");
        $tlsRes = $read();
        if (!str_starts_with($tlsRes, '220')) {
            fclose($socket);
            return ['success' => false, 'error' => t('db.smtp.starttls_failed', ['response' => $tlsRes])];
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write("EHLO " . gethostname());
        $read();
    }

    if (!empty($user) && !empty($pass)) {
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $authRes = $read();
        if (!str_starts_with($authRes, '235')) {
            fclose($socket);
            return ['success' => false, 'error' => t('db.smtp.auth_failed', ['response' => $authRes])];
        }
    }

    $write("MAIL FROM: <$from>");
    $read();
    $write("RCPT TO: <$to>");
    $rcptRes = $read();
    if (!str_starts_with($rcptRes, '250')) {
        fclose($socket);
        return ['success' => false, 'error' => t('db.smtp.recipient_rejected', ['response' => $rcptRes])];
    }

    $write("DATA");
    $read();

    $headers = [
        "From: " . $projectName . " <$from>",
        "To: <$to>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Date: " . date('r')
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $bodyHtml . "\r\n.";
    $write($emailData);
    $dataRes = $read();
    $write("QUIT");
    fclose($socket);

    if (str_starts_with($dataRes, '250')) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => t('db.smtp.send_failed', ['response' => $dataRes])];
}

/**
 * Builds the final webhook envelope (event name + ready-to-send JSON payload)
 * from a project and raw event data, applying the same field auto-completion
 * (url/api_url/status/effective_date/was_scheduled) used by the spec.
 */
function build_webhook_payload(array $project, array $eventData): array {
    $url = trim($project['webhook_url'] ?? '');
    $projectId = (int)($project['id'] ?? ($project['project_id'] ?? 1));
    $projectName = $project['name'] ?? ($project['project_name'] ?? 'Paragrafy');
    $projectDomain = $project['domain'] ?? '';
    $eventName = $eventData['event_type'] ?? 'legal_text.updated';

    $lang = $eventData['lang'] ?? ($project['primary_lang'] ?? 'de');
    $slug = $eventData['slug'] ?? '';

    if (!isset($eventData['url']) && !empty($slug) && !empty($projectDomain)) {
        $eventData['url'] = 'https://' . $projectDomain . '/' . $lang . '/' . $slug;
    }
    if (!isset($eventData['api_url']) && !empty($slug) && !empty($projectDomain)) {
        $eventData['api_url'] = 'https://' . $projectDomain . '/api/' . $lang . '/' . $slug;
    }
    if (!isset($eventData['status'])) {
        $eventData['status'] = ($eventName === 'legal_text.scheduled') ? 'scheduled' : 'published';
    }
    if (!isset($eventData['effective_date'])) {
        $eventData['effective_date'] = $eventData['scheduled_at'] ?? ($eventData['updated_at'] ?? date('c'));
    }
    if (!isset($eventData['was_scheduled'])) {
        $eventData['was_scheduled'] = false;
    }

    unset($eventData['event_type']);

    $payload = json_encode([
        'event' => $eventName,
        'timestamp' => date('c'),
        'project' => [
            'id' => $projectId,
            'name' => $projectName,
            'domain' => $projectDomain
        ],
        'data' => $eventData
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'project_id' => $projectId,
        'event_name' => $eventName,
        'url' => $url,
        'payload' => (string)$payload
    ];
}

/** Performs the actual outbound HTTP POST for one webhook payload. */
function send_webhook_http(string $url, string $payload, string $eventName, string $secret, int $timeoutSeconds = 6): array {
    $secret = trim($secret);
    $signature = $secret !== '' ? hash_hmac('sha256', $payload, $secret) : '';

    $headers = [
        'Content-Type: application/json',
        'User-Agent: Paragrafy-Webhook/' . PARAGRAFY_VERSION,
        'X-Paragrafy-Event: ' . $eventName
    ];
    if ($signature !== '') {
        $headers[] = 'X-Paragrafy-Signature: ' . $signature;
    }

    $startTime = microtime(true);
    $statusCode = 0;
    $responseBody = '';
    $errorMessage = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds)
        ]);
        $responseBody = (string)curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMessage = curl_error($ch);
        curl_close($ch);
    } else {
        $errorMessage = t('db.webhook.curl_unavailable');
    }

    $durationMs = (int)round((microtime(true) - $startTime) * 1000);
    $success = ($statusCode >= 200 && $statusCode < 300);

    return [
        'success' => $success,
        'status_code' => $statusCode,
        'duration_ms' => $durationMs,
        'response' => substr($responseBody, 0, 500),
        'error' => $errorMessage ?: ($success ? '' : t('db.webhook.http_status_received', ['status' => $statusCode]))
    ];
}

/**
 * Sends a webhook immediately and logs the result. Used only for the manual
 * "Test-Webhook senden" button, which needs an instant result to show the
 * admin. Real content-change events should use enqueue_webhook() instead so
 * a slow/unreachable customer server can never block saving a legal text.
 */
function dispatch_webhook(array $project, array $eventData): array {
    $built = build_webhook_payload($project, $eventData);

    if (empty($built['url']) || !is_public_http_url($built['url'])) {
        return ['success' => false, 'error' => t('db.webhook.no_url_configured')];
    }

    $result = send_webhook_http($built['url'], $built['payload'], $built['event_name'], $project['webhook_secret'] ?? '', 6);

    try {
        $db = get_db();
        $ins = $db->prepare("
            INSERT INTO webhook_logs (project_id, event_name, url, status_code, request_payload, response_body, error_message, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $built['project_id'],
            $built['event_name'],
            $built['url'],
            $result['status_code'],
            $built['payload'],
            $result['response'],
            $result['error'] ?: '',
            $result['duration_ms']
        ]);
    } catch (Throwable $e) {
    }

    return $result;
}

/**
 * Queues a webhook instead of sending it inline -- this is what every real
 * content-change trigger (publish, schedule, restore, auto-publish) should
 * call. A background worker (process_webhook_queue(), run via cron) does
 * the actual, potentially-slow HTTP delivery later.
 */
function enqueue_webhook(array $project, array $eventData): void {
    try {
        $built = build_webhook_payload($project, $eventData);
        if (empty($built['url']) || !is_public_http_url($built['url'])) {
            return;
        }
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO webhook_queue (project_id, event_name, payload, status, attempts, next_attempt_at) VALUES (?, ?, ?, 'pending', 0, CURRENT_TIMESTAMP)");
        $stmt->execute([$built['project_id'], $built['event_name'], $built['payload']]);
    } catch (Throwable $e) {
    }
}

const WEBHOOK_MAX_ATTEMPTS = 5;
const WEBHOOK_TIMEOUT_SECONDS = 5;

/** Backoff schedule per attempt number: 1min, 5min, 15min, 60min, then 3h. */
function webhook_backoff_seconds(int $attempt): int {
    $steps = [60, 300, 900, 3600, 10800];
    return $steps[$attempt - 1] ?? end($steps);
}

/**
 * Works through pending webhook_queue rows that are due, sending each with a
 * short timeout so one unreachable customer server can't stall the batch.
 * Meant to be triggered every few minutes via an external cron hitting
 * /api/cron/webhooks (same pattern as the audit-mail and backup crons).
 */
function process_webhook_queue(int $limit = 20): array {
    $db = get_db();
    $processed = 0;
    $sent = 0;
    $failed = 0;
    $retried = 0;

    try {
        $stmt = $db->prepare("SELECT * FROM webhook_queue WHERE status = 'pending' AND next_attempt_at <= datetime('now') ORDER BY created_at ASC LIMIT " . (int)$limit);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $processed++;

            $projStmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $projStmt->execute([$row['project_id']]);
            $project = $projStmt->fetch();

            $url = trim($project['webhook_url'] ?? '');
            if (!$project || empty($url) || !is_public_http_url($url)) {
                $upd = $db->prepare("UPDATE webhook_queue SET status = 'failed', last_error = ? WHERE id = ?");
                $upd->execute([t('db.webhook.no_url_configured_queue'), $row['id']]);
                $failed++;
                continue;
            }

            $result = send_webhook_http($url, $row['payload'], $row['event_name'], $project['webhook_secret'] ?? '', WEBHOOK_TIMEOUT_SECONDS);

            try {
                $logStmt = $db->prepare("
                    INSERT INTO webhook_logs (project_id, event_name, url, status_code, request_payload, response_body, error_message, duration_ms)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $logStmt->execute([
                    $row['project_id'], $row['event_name'], $url, $result['status_code'],
                    $row['payload'], $result['response'], $result['error'] ?: '', $result['duration_ms']
                ]);
            } catch (Throwable $e) {
            }

            $attempts = (int)$row['attempts'] + 1;

            if ($result['success']) {
                $upd = $db->prepare("UPDATE webhook_queue SET status = 'sent', attempts = ?, last_status_code = ?, last_error = '', sent_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([$attempts, $result['status_code'], $row['id']]);
                $sent++;
            } elseif ($attempts >= WEBHOOK_MAX_ATTEMPTS) {
                $upd = $db->prepare("UPDATE webhook_queue SET status = 'failed', attempts = ?, last_status_code = ?, last_error = ? WHERE id = ?");
                $upd->execute([$attempts, $result['status_code'], $result['error'], $row['id']]);
                $failed++;
            } else {
                $waitSeconds = webhook_backoff_seconds($attempts);
                $upd = $db->prepare("UPDATE webhook_queue SET attempts = ?, last_status_code = ?, last_error = ?, next_attempt_at = datetime('now', ?) WHERE id = ?");
                $upd->execute([$attempts, $result['status_code'], $result['error'], '+' . $waitSeconds . ' seconds', $row['id']]);
                $retried++;
            }
        }
    } catch (Throwable $e) {
    }

    return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed, 'retried' => $retried];
}

/** Pending/failed queue counts for the current project, shown in Settings. */
function webhook_queue_summary(PDO $db, int $projectId): array {
    try {
        $stmt = $db->prepare("SELECT status, COUNT(*) as c FROM webhook_queue WHERE project_id = ? GROUP BY status");
        $stmt->execute([$projectId]);
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int)$row['c'];
        }
        return $counts;
    } catch (Throwable $e) {
        return ['pending' => 0, 'sent' => 0, 'failed' => 0];
    }
}

function translate_with_deepl(string $text, string $sourceLang, string $targetLang, string $apiKey): array {
    if (empty(trim($apiKey))) {
        return ['success' => false, 'error' => t('db.deepl.no_api_key')];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => t('db.deepl.curl_missing')];
    }

    $isFreeTier = str_ends_with(trim($apiKey), ':fx');
    $endpoint = $isFreeTier ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';

    $targetCode = strtoupper($targetLang);
    if ($targetCode === 'EN') $targetCode = 'EN-US';
    if ($targetCode === 'PT') $targetCode = 'PT-PT';

    $tokens = ['{{company_name}}', '{{address}}', '{{email}}', '{{phone}}', '{{representative}}', '{{register_info}}', '{{year}}'];
    $protectedText = $text;
    foreach ($tokens as $t) {
        $protectedText = str_replace($t, '<span translate="no">' . $t . '</span>', $protectedText);
    }

    $postData = http_build_query([
        'text' => $protectedText,
        'source_lang' => strtoupper($sourceLang),
        'target_lang' => $targetCode,
        'tag_handling' => 'html'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: DeepL-Auth-Key ' . trim($apiKey),
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => t('db.deepl.curl_error', ['error' => $curlError])];
    }

    $data = json_decode((string)$response, true);
    if ($httpCode !== 200 || !isset($data['translations'][0]['text'])) {
        $msg = $data['message'] ?? ("HTTP " . $httpCode . ": " . substr((string)$response, 0, 150));
        return ['success' => false, 'error' => t('db.deepl.api_error', ['message' => $msg])];
    }

    $translated = $data['translations'][0]['text'];
    $translated = preg_replace('/<span translate="no">({{.*?}})<\/span>/i', '$1', $translated);

    return ['success' => true, 'text' => $translated];
}

function get_current_host(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return explode(':', $host)[0];
}

function lang_meta(string $code): array {
    $map = [
        'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
        'en' => ['flag' => '🇬🇧', 'label' => 'English'],
        'es' => ['flag' => '🇪🇸', 'label' => 'Español'],
        'fr' => ['flag' => '🇫🇷', 'label' => 'Français'],
        'it' => ['flag' => '🇮🇹', 'label' => 'Italiano'],
        'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
    ];
    return $map[$code] ?? ['flag' => '', 'label' => strtoupper($code)];
}

/**
 * UI languages Paragrafy's own admin/editor/public chrome is translated into
 * (lang/<code>.php). Separate from lang_meta(), which lists the languages a
 * *document's content* can be translated into.
 */
function ui_locales(): array {
    return [
        'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
        'en' => ['flag' => '🇬🇧', 'label' => 'English'],
    ];
}

/**
 * Resolves the UI language for the current request: explicit ?locale= (public
 * pages, also persisted to a cookie) or the logged-in user's saved
 * preference, falling back to the browser's Accept-Language header, then 'de'.
 */
function current_locale(): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $known = array_keys(ui_locales());

    if (!empty($_GET['locale']) && in_array($_GET['locale'], $known, true)) {
        $resolved = $_GET['locale'];
        if (!headers_sent()) {
            setcookie('paragrafy_locale', $resolved, time() + 60 * 60 * 24 * 30, '/');
        }
        return $resolved;
    }

    if (!empty($_SESSION['paragrafy_user_locale']) && in_array($_SESSION['paragrafy_user_locale'], $known, true)) {
        $resolved = $_SESSION['paragrafy_user_locale'];
        return $resolved;
    }

    if (!empty($_COOKIE['paragrafy_locale']) && in_array($_COOKIE['paragrafy_locale'], $known, true)) {
        $resolved = $_COOKIE['paragrafy_locale'];
        return $resolved;
    }

    $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($accept !== '') {
        foreach (explode(',', $accept) as $part) {
            $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($code, $known, true)) {
                $resolved = $code;
                return $resolved;
            }
        }
    }

    if (is_installed()) {
        $configLocale = get_config()['ui_locale'] ?? null;
        if ($configLocale && in_array($configLocale, $known, true)) {
            $resolved = $configLocale;
            return $resolved;
        }
    }

    $resolved = 'de';
    return $resolved;
}

/**
 * Translates a UI string key using lang/de.php as the exhaustive baseline,
 * overlaid with lang/<locale>.php when available. :placeholder params are
 * interpolated via strtr; pass $count to select a '<key>.plural' variant.
 */
function t(string $key, array $params = [], ?int $count = null): string {
    static $strings = [];
    $locale = current_locale();

    if (!isset($strings[$locale])) {
        $baseline = require PARAGRAFY_DIR . '/lang/de.php';
        if ($locale !== 'de') {
            $override = @include PARAGRAFY_DIR . "/lang/{$locale}.php";
            if (is_array($override)) {
                $baseline = array_merge($baseline, $override);
            }
        }
        $strings[$locale] = $baseline;
    }

    $lookupKey = ($count !== null && $count !== 1) ? $key . '.plural' : $key;
    $raw = $strings[$locale][$lookupKey] ?? $strings[$locale][$key] ?? $key;

    if (!$params) {
        return $raw;
    }
    $replacements = [];
    foreach ($params as $name => $value) {
        $replacements[':' . $name] = (string)$value;
    }
    return strtr($raw, $replacements);
}

/**
 * Der primaere Admin-Login (Legacy-Master-Passwort bzw. SSO) hat keine
 * paragrafy_user_id in der Session -- nur er darf Benutzer verwalten.
 */
function current_user_is_primary_admin(): bool {
    return empty($_SESSION['paragrafy_user_id']);
}

/**
 * Ohne paragrafy_user_id (primaerer Admin) oder ohne jede Zeile in
 * user_projects (Bestandsnutzer vor Einfuehrung der Zuordnung) gilt
 * unbeschraenkter Zugriff -- erst eine explizite Zuordnung schraenkt ein.
 */
function current_user_accessible_project_ids(PDO $db): array {
    $allIds = array_column($db->query("SELECT id FROM projects")->fetchAll(), 'id');
    $userId = (int)($_SESSION['paragrafy_user_id'] ?? 0);
    if ($userId <= 0) {
        return array_map('intval', $allIds);
    }
    $stmt = $db->prepare("SELECT project_id FROM user_projects WHERE user_id = ?");
    $stmt->execute([$userId]);
    $assigned = array_column($stmt->fetchAll(), 'project_id');
    if (empty($assigned)) {
        return array_map('intval', $allIds);
    }
    return array_map('intval', $assigned);
}

function user_can_access_project(PDO $db, int $projectId): bool {
    return in_array($projectId, current_user_accessible_project_ids($db), true);
}

/** Returns (and lazily creates) this session's CSRF token. */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden form field carrying the current session's CSRF token. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Call at the top of any POST-handling section (after the auth/session gate)
 * to reject state-changing requests without a valid CSRF token. Accepts the
 * token either as a POST field (regular forms, FormData-based AJAX) or as the
 * X-CSRF-Token header (fetch calls that don't submit FormData).
 */
function require_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $given = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($given === '' || !hash_equals(csrf_token(), $given)) {
        http_response_code(403);
        echo htmlspecialchars(t('admin.common.access_denied'));
        exit;
    }
}

/** Strips CR/LF from a value before it is embedded in a mail/SMTP header, preventing header/command injection. */
function strip_header_injection(string $v): string {
    return trim(preg_replace('/[\r\n]+/', ' ', $v));
}

/** Escapes a CSV cell against formula/CSV injection (Excel etc. treat a leading =+-@ as a formula). */
function csv_safe(?string $v): string {
    $v = (string)$v;
    return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
}

function log_audit(?int $projectId, string $projectName, string $action): void {
    try {
        $db = get_db();
        $userName = $_SESSION['paragrafy_user_name'] ?? 'Admin';
        $stmt = $db->prepare("INSERT INTO audit_log (project_id, project_name, user_name, action) VALUES (?, ?, ?, ?)");
        $stmt->execute([$projectId, $projectName, $userName, $action]);
    } catch (Throwable $e) {
    }
}

function record_translation_version(PDO $db, int $documentId, string $lang, string $title, string $slug, string $content, string $changeNote, string $status, ?string $userName = null): void {
    try {
        $stmt = $db->prepare("INSERT INTO translation_versions (document_id, lang, title, slug, content, change_note, status, user_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$documentId, $lang, $title, $slug, $content, $changeNote, $status, $userName ?? ($_SESSION['paragrafy_user_name'] ?? 'Admin')]);
    } catch (Throwable $e) {
    }
}

/**
 * Truncates an IP address to network level so it can never be traced back to
 * an individual visitor -- IPv4 loses its last octet, IPv6 keeps only the
 * first 48 bits. This is the same "IP anonymization" approach used by
 * Google Analytics/most consent-management tools: enough to remain a
 * meaningful audit artifact (proves a request came from a given network at a
 * given time) without storing personal data. Unparseable input returns ''.
 */
function anonymize_ip(string $ip): string {
    // IPv4-mapped IPv6 (e.g. "::ffff:203.0.113.5", seen behind some proxies) would otherwise
    // fall through to the IPv6 branch below and get zeroed past its embedded IPv4 bytes --
    // unmask it first so it's anonymized the same way a plain IPv4 address would be.
    if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = substr($ip, 7);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts);
        }
        return '';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            $bin = substr($bin, 0, 6) . str_repeat("\0", 10);
            $anon = inet_ntop($bin);
            return $anon !== false ? $anon : '';
        }
        return '';
    }
    return '';
}

/**
 * Inserts one DSGVO consent-proof record (see /consent.js + /api/consent-log
 * in index.php). Never stores the raw IP -- only anonymize_ip()'s output.
 */
function log_consent(PDO $db, int $projectId, string $consentId, string $action, string $lang, string $textHash, string $ip, string $userAgent): void {
    try {
        $stmt = $db->prepare("INSERT INTO consent_logs (project_id, consent_id, action, lang, banner_text_hash, ip_anonymized, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$projectId, $consentId, $action, $lang, $textHash, anonymize_ip($ip), substr($userAgent, 0, 255)]);
    } catch (Throwable $e) {
    }
}

/**
 * Deletes consent_logs rows past each project's own consent_log_retention_days
 * (0 = keep forever). Called from run_scheduled_backup() so it rides the
 * existing daily /api/cron/backup cadence instead of needing its own cron.
 */
function cleanup_expired_consent_logs(PDO $db): int {
    try {
        $stmt = $db->prepare("
            DELETE FROM consent_logs WHERE id IN (
                SELECT cl.id FROM consent_logs cl
                JOIN projects p ON cl.project_id = p.id
                WHERE p.consent_log_retention_days > 0
                  AND cl.created_at < datetime('now', '-' || p.consent_log_retention_days || ' days')
            )
        ");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

function get_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Seconds to wait, or 0 if login attempts are currently allowed. */
function login_rate_limit_wait(PDO $db, string $identifier): int {
    return rate_limit_wait($db, $identifier, LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_MINUTES);
}

/**
 * Generic reusable rate limiter built on the same login_attempts table as the
 * admin login throttle (identifier is a free-form string, so callers just
 * pick a distinct prefix, e.g. "consent:{project_id}:{ip}" or "api:{ip}").
 * Seconds to wait, or 0 if a request is currently allowed.
 */
function rate_limit_wait(PDO $db, string $identifier, int $maxAttempts, int $windowMinutes): int {
    try {
        $stmt = $db->prepare("SELECT created_at FROM login_attempts WHERE identifier = ? AND created_at >= datetime('now', ?) ORDER BY created_at ASC");
        $stmt->execute([$identifier, '-' . $windowMinutes . ' minutes']);
        $rows = $stmt->fetchAll();
        if (count($rows) < $maxAttempts) {
            return 0;
        }
        $oldest = strtotime($rows[0]['created_at']);
        $waitUntil = $oldest + ($windowMinutes * 60);
        $remaining = $waitUntil - time();
        return max(0, $remaining);
    } catch (Throwable $e) {
        return 0;
    }
}

function record_login_failure(PDO $db, string $identifier): void {
    try {
        $stmt = $db->prepare("INSERT INTO login_attempts (identifier) VALUES (?)");
        $stmt->execute([$identifier]);
        $db->prepare("DELETE FROM login_attempts WHERE created_at < datetime('now', '-1 day')")->execute();
    } catch (Throwable $e) {
    }
}

function clear_login_failures(PDO $db, string $identifier): void {
    try {
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $stmt->execute([$identifier]);
    } catch (Throwable $e) {
    }
}

/**
 * Copies the SQLite database into /backups and prunes anything older than
 * BACKUP_RETENTION_DAYS. Meant to be triggered daily via an external cron
 * hitting /api/cron/backup (the same pattern as the audit-mail cron).
 */
function run_scheduled_backup(): array {
    try {
        cleanup_expired_consent_logs(get_db());
        if (!file_exists(DB_FILE)) {
            return ['success' => false, 'error' => t('db.backup.no_database')];
        }
        if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0755, true) && !is_dir(BACKUP_DIR)) {
            return ['success' => false, 'error' => t('db.backup.dir_creation_failed')];
        }

        $filename = 'paragrafy_backup_' . date('Y-m-d_His') . '.sqlite';
        if (!copy(DB_FILE, BACKUP_DIR . '/' . $filename)) {
            return ['success' => false, 'error' => t('db.backup.copy_failed')];
        }

        $cutoff = time() - (BACKUP_RETENTION_DAYS * 24 * 60 * 60);
        $deleted = 0;
        foreach (glob(BACKUP_DIR . '/paragrafy_backup_*.sqlite') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        return ['success' => true, 'file' => $filename, 'deleted_old' => $deleted];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function run_audit_check(array $project, PDO $db): array {
    $recipient = trim($project['audit_email_recipient'] ?? '') ?: ($project['email'] ?? '');
    if (empty($recipient)) {
        return ['success' => false, 'error' => t('db.audit.no_recipient')];
    }

    $auditMonths = (int)($project['audit_interval_months'] ?? 12);
    $stmt = $db->prepare("
        SELECT t.title, t.lang, t.updated_at, dt.title as type_title
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE d.project_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$project['id']]);
    $docs = $stmt->fetchAll();

    $now = new DateTime();
    $overdue = [];
    foreach ($docs as $d) {
        if (!empty($d['updated_at'])) {
            $updated = new DateTime($d['updated_at']);
            $diffMonths = (($now->format('Y') - $updated->format('Y')) * 12) + ($now->format('m') - $updated->format('m'));
            if ($diffMonths >= $auditMonths) {
                $days = $now->diff($updated)->days;
                $overdue[] = t('db.audit.overdue_item', ['title' => htmlspecialchars($d['title']), 'lang' => strtoupper($d['lang']), 'days' => $days, 'date' => date('d.m.Y', strtotime($d['updated_at']))]);
            }
        }
    }

    if (empty($overdue)) {
        return ['success' => true, 'message' => t('db.audit.all_current')];
    }

    $html = "<h2>" . htmlspecialchars(t('db.audit.mail_heading')) . "</h2>";
    $html .= "<p>" . t('db.audit.mail_intro', ['project' => htmlspecialchars($project['name']), 'domain' => htmlspecialchars($project['domain']), 'months' => $auditMonths]) . "</p>";
    $html .= "<ul>" . implode('', $overdue) . "</ul>";
    $html .= "<p><a href='https://" . htmlspecialchars($project['domain']) . "/admin' style='background:#F0A63C;color:#fff;padding:0.6rem 1.2rem;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold;'>Zum Admin-Dashboard</a></p>";

    return send_smtp_mail($project, $recipient, t('db.audit.mail_subject', ['count' => count($overdue)]), $html);
}

/** Returns rolling backups newest-first as [filename, size_bytes, created_at]. */
function list_backups(): array {
    $files = glob(BACKUP_DIR . '/paragrafy_backup_*.sqlite') ?: [];
    $backups = [];
    foreach ($files as $file) {
        $backups[] = [
            'filename' => basename($file),
            'size' => filesize($file),
            'created_at' => filemtime($file)
        ];
    }
    usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    return $backups;
}

/** Grenzwert für hochgeladene Backup-Dateien beim Restore. */
const BACKUP_UPLOAD_MAX_BYTES = 200 * 1024 * 1024;

/**
 * Prüft, ob eine hochgeladene Datei eine valide Paragrafy-SQLite-Datenbank ist, bevor sie beim
 * Restore die aktuelle Datenbank ersetzt. Öffnet dazu eine separate PDO-Instanz, um die
 * Haupt-Connection (get_db()) nicht zu berühren.
 */
function is_valid_sqlite_signature(string $path): bool {
    $handle = @fopen($path, 'rb');
    $header = $handle ? fread($handle, 16) : '';
    if ($handle) fclose($handle);
    return $header === "SQLite format 3\000";
}

function validate_backup_upload(string $tmpPath): array {
    if (!is_uploaded_file($tmpPath) && !is_readable($tmpPath)) {
        return ['success' => false, 'error' => t('db.restore.upload_failed')];
    }
    if (filesize($tmpPath) > BACKUP_UPLOAD_MAX_BYTES) {
        return ['success' => false, 'error' => t('db.restore.file_too_large')];
    }
    if (!is_valid_sqlite_signature($tmpPath)) {
        return ['success' => false, 'error' => t('db.restore.not_sqlite')];
    }

    try {
        $testPdo = new PDO('sqlite:' . $tmpPath);
        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $testPdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $required = ['projects', 'doc_types', 'documents', 'translations'];
        $missing = array_diff($required, $tables);
        if (!empty($missing)) {
            return ['success' => false, 'error' => t('db.restore.missing_tables', ['tables' => implode(', ', $missing)])];
        }
        $projectCount = (int)$testPdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        $testPdo = null;
        return ['success' => true, 'project_count' => $projectCount];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => t('db.restore.invalid_database')];
    }
}

/**
 * Ersetzt die aktuelle Datenbank durch eine hochgeladene Backup-Datei. Legt vorher über
 * run_scheduled_backup() eine Sicherheitskopie der bisherigen Datenbank an und bringt die neue
 * Datenbank direkt im selben Request per ensure_schema_migrations() auf den aktuellen Schema-Stand
 * — auch wenn das Backup aus einer älteren Paragrafy-Version stammt und ihm neuere Spalten/Tabellen
 * fehlen.
 */
function restore_database_from_upload(string $tmpPath): array {
    $validation = validate_backup_upload($tmpPath);
    if (!$validation['success']) {
        return $validation;
    }

    $safetyBackup = run_scheduled_backup();

    if (!copy($tmpPath, DB_FILE)) {
        return ['success' => false, 'error' => t('db.restore.copy_failed')];
    }
    @unlink($tmpPath);

    try {
        $newPdo = new PDO('sqlite:' . DB_FILE);
        $newPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $newPdo->exec("PRAGMA foreign_keys = ON;");
        ensure_schema_migrations($newPdo);
        $newPdo = null;
    } catch (Throwable $e) {
        return ['success' => false, 'error' => t('db.restore.migration_failed', ['error' => $e->getMessage()])];
    }

    $projectLimit = get_project_limit();
    $overLimit = $projectLimit !== null && $validation['project_count'] > $projectLimit;

    return [
        'success' => true,
        'project_count' => $validation['project_count'],
        'safety_backup' => $safetyBackup['success'] ?? false,
        'over_limit' => $overLimit,
        'project_limit' => $projectLimit,
    ];
}

/**
 * Exportiert nur die Rechtsinhalte eines einzelnen Projekts (projects-Zeile, doc_types,
 * documents, translations, translation_versions) als eigenständige SQLite-Datei — im Gegensatz
 * zu restore_database_from_upload() betrifft ein späterer Import damit gezielt nur dieses eine
 * Projekt, nicht die ganze Zielinstanz.
 */
function export_project_backup(PDO $db, int $projectId): array {
    $tmpFile = tempnam(sys_get_temp_dir(), 'pgexp_');
    if ($tmpFile === false) {
        return ['success' => false, 'error' => t('db.project_export.tmp_failed')];
    }
    @unlink($tmpFile);

    try {
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $projectRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$projectRow) {
            return ['success' => false, 'error' => t('db.project_export.project_not_found')];
        }

        $docTypeStmt = $db->prepare("SELECT DISTINCT dt.* FROM doc_types dt JOIN documents d ON d.doc_type_id = dt.id WHERE d.project_id = ?");
        $docTypeStmt->execute([$projectId]);
        $docTypeRows = $docTypeStmt->fetchAll(PDO::FETCH_ASSOC);

        $docStmt = $db->prepare("SELECT * FROM documents WHERE project_id = ?");
        $docStmt->execute([$projectId]);
        $documentRows = $docStmt->fetchAll(PDO::FETCH_ASSOC);
        $documentIds = array_column($documentRows, 'id');

        $translationRows = [];
        $versionRows = [];
        if (!empty($documentIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($documentIds), '?'));
            $trStmt = $db->prepare("SELECT * FROM translations WHERE document_id IN ($inPlaceholders)");
            $trStmt->execute($documentIds);
            $translationRows = $trStmt->fetchAll(PDO::FETCH_ASSOC);

            $vStmt = $db->prepare("SELECT * FROM translation_versions WHERE document_id IN ($inPlaceholders)");
            $vStmt->execute($documentIds);
            $versionRows = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Ab hier eine komplett unabhängige Connection auf die neue, leere Export-Datei —
        // bewusst KEIN ATTACH DATABASE auf DB_FILE, das kollidiert mit der bereits offenen
        // Haupt-Connection ($db) und führt zu "database is locked".
        $exportPdo = new PDO('sqlite:' . $tmpFile);
        $exportPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $exportPdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY, domain TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
                primary_lang TEXT DEFAULT 'de', active_languages TEXT DEFAULT 'de,en',
                brand_color TEXT DEFAULT '#F0A63C', logo_url TEXT DEFAULT '',
                deepl_api_key TEXT DEFAULT '', webhook_url TEXT DEFAULT '', webhook_secret TEXT DEFAULT '',
                audit_interval_months INTEGER DEFAULT 12, smtp_host TEXT DEFAULT '', smtp_port INTEGER DEFAULT 587,
                smtp_user TEXT DEFAULT '', smtp_pass TEXT DEFAULT '', smtp_secure TEXT DEFAULT 'tls',
                smtp_from TEXT DEFAULT '', audit_email_recipient TEXT DEFAULT '',
                cookie_banner_enabled INTEGER DEFAULT 0, cookie_banner_text TEXT DEFAULT '',
                consent_logging_enabled INTEGER DEFAULT 0, consent_log_retention_days INTEGER DEFAULT 1095,
                ai_provider TEXT DEFAULT '', ai_api_key TEXT DEFAULT '',
                company_name TEXT DEFAULT '', address TEXT DEFAULT '', email TEXT DEFAULT '',
                phone TEXT DEFAULT '', representative TEXT DEFAULT '', register_info TEXT DEFAULT '',
                settings_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                settings_version INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE doc_types (
                id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
                description TEXT DEFAULT '', is_required INTEGER DEFAULT 1
            );
            CREATE TABLE documents (
                id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, doc_type_id INTEGER NOT NULL
            );
            CREATE TABLE translations (
                id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL, lang TEXT NOT NULL,
                title TEXT NOT NULL, slug TEXT NOT NULL, content TEXT NOT NULL,
                previous_content TEXT DEFAULT '', status TEXT DEFAULT 'draft', change_note TEXT DEFAULT '',
                source_hash TEXT DEFAULT '', scheduled_at DATETIME DEFAULT NULL, scheduled_title TEXT DEFAULT '',
                scheduled_slug TEXT DEFAULT '', scheduled_content TEXT DEFAULT '', scheduled_note TEXT DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE translation_versions (
                id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL, lang TEXT NOT NULL,
                title TEXT NOT NULL, slug TEXT NOT NULL, content TEXT NOT NULL,
                change_note TEXT DEFAULT '', status TEXT DEFAULT 'published', user_name TEXT DEFAULT 'Admin',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE paragrafy_export_meta (key TEXT PRIMARY KEY, value TEXT);
        ");

        $insertRow = function (PDO $pdo, string $table, array $row) {
            $cols = array_keys($row);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($placeholders)")->execute(array_values($row));
        };

        // Secrets nie in eine herunterladbare Export-Datei schreiben -- der Export kann per Mail/
        // Cloud-Speicher/USB-Stick geteilt werden, weit ausserhalb des durch Admin-Login
        // geschuetzten Kontexts. Muessen nach einem Re-Import in den Projekteinstellungen neu
        // gesetzt werden.
        foreach (['smtp_pass', 'webhook_secret', 'ai_api_key', 'deepl_api_key'] as $secretCol) {
            if (array_key_exists($secretCol, $projectRow)) {
                $projectRow[$secretCol] = '';
            }
        }
        $insertRow($exportPdo, 'projects', $projectRow);
        foreach ($docTypeRows as $row) $insertRow($exportPdo, 'doc_types', $row);
        foreach ($documentRows as $row) $insertRow($exportPdo, 'documents', $row);
        foreach ($translationRows as $row) $insertRow($exportPdo, 'translations', $row);
        foreach ($versionRows as $row) $insertRow($exportPdo, 'translation_versions', $row);

        $meta = [
            'export_type' => 'project',
            'source_project_id' => (string)$projectId,
            'exported_at' => date('c'),
            'paragrafy_version' => PARAGRAFY_VERSION,
        ];
        foreach ($meta as $k => $v) {
            $insertRow($exportPdo, 'paragrafy_export_meta', ['key' => $k, 'value' => $v]);
        }
        $exportPdo = null;

        $domainSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($projectRow['domain'])), '-');
        $filename = 'paragrafy_project_' . $domainSlug . '_' . date('Y-m-d_His') . '.sqlite';

        return ['success' => true, 'path' => $tmpFile, 'filename' => $filename];
    } catch (Throwable $e) {
        @unlink($tmpFile);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Prüft eine hochgeladene Datei für den Projekt-Import: valides SQLite-Format, vorhandene
 * Kerntabellen, und GENAU EIN Projekt darin (mehr wäre ein Voll-Instanz-Export und gehört zu
 * restore_database_from_upload(), nicht hierher).
 */
function validate_project_upload(string $tmpPath): array {
    if (!is_uploaded_file($tmpPath) && !is_readable($tmpPath)) {
        return ['success' => false, 'error' => t('db.restore.upload_failed')];
    }
    if (filesize($tmpPath) > BACKUP_UPLOAD_MAX_BYTES) {
        return ['success' => false, 'error' => t('db.restore.file_too_large')];
    }
    if (!is_valid_sqlite_signature($tmpPath)) {
        return ['success' => false, 'error' => t('db.restore.not_sqlite')];
    }

    try {
        $testPdo = new PDO('sqlite:' . $tmpPath);
        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $testPdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $required = ['projects', 'doc_types', 'documents', 'translations'];
        $missing = array_diff($required, $tables);
        if (!empty($missing)) {
            return ['success' => false, 'error' => t('db.restore.missing_tables', ['tables' => implode(', ', $missing)])];
        }
        $projectCount = (int)$testPdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        if ($projectCount !== 1) {
            return ['success' => false, 'error' => t('db.project_import.expects_single_project', ['count' => $projectCount])];
        }
        $testPdo = null;
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => t('db.restore.invalid_database')];
    }
}

/**
 * Führt ein hochgeladenes Projekt-Export in ein explizit ausgewähltes, bereits bestehendes
 * Zielprojekt zusammen (Merge) — kein automatisches Domain-Raten und kein Anlegen neuer
 * Projekte. Nur Dokumente/Übersetzungen des Zielprojekts werden aktualisiert; dessen
 * Firmendaten/Settings sowie alle anderen Projekte der Instanz bleiben unverändert. doc_types
 * werden per slug gegen bestehende Einträge abgeglichen (instanzweit geteilt, keine Duplikate).
 */
function import_project_backup(PDO $db, string $tmpPath, int $targetProjectId): array {
    $validation = validate_project_upload($tmpPath);
    if (!$validation['success']) {
        return $validation;
    }

    $stmtCheck = $db->prepare("SELECT id FROM projects WHERE id = ?");
    $stmtCheck->execute([$targetProjectId]);
    if (!$stmtCheck->fetchColumn()) {
        return ['success' => false, 'error' => t('db.project_import.target_not_found')];
    }

    run_scheduled_backup();

    // Unabhängige Read-Only-Connection auf die hochgeladene Datei — bewusst KEIN
    // ATTACH DATABASE auf $db, das kollidiert mit dessen bereits offener Connection.
    $importPdo = new PDO('sqlite:' . $tmpPath);
    $importPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $db->beginTransaction();

        $docTypeIdMap = [];
        $importDocTypes = $importPdo->query("SELECT * FROM doc_types")->fetchAll(PDO::FETCH_ASSOC);
        $findDocType = $db->prepare("SELECT id FROM doc_types WHERE slug = ?");
        $insertDocType = $db->prepare("INSERT INTO doc_types (slug, title, description, is_required) VALUES (?, ?, ?, ?)");
        foreach ($importDocTypes as $dt) {
            $findDocType->execute([$dt['slug']]);
            $existingDtId = $findDocType->fetchColumn();
            if ($existingDtId) {
                $docTypeIdMap[$dt['id']] = (int)$existingDtId;
            } else {
                $insertDocType->execute([$dt['slug'], $dt['title'], $dt['description'], $dt['is_required']]);
                $docTypeIdMap[$dt['id']] = (int)$db->lastInsertId();
            }
        }

        $documentIdMap = [];
        $importDocuments = $importPdo->query("SELECT * FROM documents")->fetchAll(PDO::FETCH_ASSOC);
        $findDoc = $db->prepare("SELECT id FROM documents WHERE project_id = ? AND doc_type_id = ?");
        $insertDoc = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
        foreach ($importDocuments as $doc) {
            $mappedDocTypeId = $docTypeIdMap[$doc['doc_type_id']] ?? null;
            if ($mappedDocTypeId === null) continue;
            $findDoc->execute([$targetProjectId, $mappedDocTypeId]);
            $existingDocId = $findDoc->fetchColumn();
            if ($existingDocId) {
                $documentIdMap[$doc['id']] = (int)$existingDocId;
            } else {
                $insertDoc->execute([$targetProjectId, $mappedDocTypeId]);
                $documentIdMap[$doc['id']] = (int)$db->lastInsertId();
            }
        }

        $importTranslations = $importPdo->query("SELECT * FROM translations")->fetchAll(PDO::FETCH_ASSOC);
        $upsertTranslation = $db->prepare("
            INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, change_note, source_hash, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(document_id, lang) DO UPDATE SET
                title=excluded.title, slug=excluded.slug, content=excluded.content,
                previous_content=excluded.previous_content, status=excluded.status,
                change_note=excluded.change_note, source_hash=excluded.source_hash,
                updated_at=excluded.updated_at
        ");
        $translationsMerged = 0;
        foreach ($importTranslations as $tr) {
            $mappedDocId = $documentIdMap[$tr['document_id']] ?? null;
            if ($mappedDocId === null) continue;
            $upsertTranslation->execute([
                $mappedDocId, $tr['lang'], $tr['title'], $tr['slug'], $tr['content'],
                $tr['previous_content'], $tr['status'], $tr['change_note'], $tr['source_hash'],
            ]);
            $translationsMerged++;
        }

        $importVersions = $importPdo->query("SELECT * FROM translation_versions")->fetchAll(PDO::FETCH_ASSOC);
        $insertVersion = $db->prepare("
            INSERT INTO translation_versions (document_id, lang, title, slug, content, change_note, status, user_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($importVersions as $v) {
            $mappedDocId = $documentIdMap[$v['document_id']] ?? null;
            if ($mappedDocId === null) continue;
            $insertVersion->execute([
                $mappedDocId, $v['lang'], $v['title'], $v['slug'], $v['content'],
                $v['change_note'], $v['status'], $v['user_name'], $v['created_at'],
            ]);
        }

        $db->commit();
        $importPdo = null;
        @unlink($tmpPath);

        return [
            'success' => true,
            'target_project_id' => $targetProjectId,
            'documents_merged' => count($documentIdMap),
            'translations_merged' => $translationsMerged,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => t('db.project_import.merge_failed', ['error' => $e->getMessage()])];
    }
}

/**
 * Shared visual theme (fonts, base tokens, admin shell) for the redesigned UI.
 * $accent is the project's brand_color (hex) and drives primary buttons/links.
 */
function theme_head_tags(): string {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">'
        . '<script>(function(){try{var t=localStorage.getItem("paragrafy_theme");if(t==="light"||t==="dark"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>';
}

function theme_base_css(string $accent = '#F0A63C', bool $enableDarkMode = true): string {
    $accent = htmlspecialchars($accent, ENT_QUOTES);
    $lightAccent = $enableDarkMode ? "color-mix(in srgb, {$accent} 82%, black)" : $accent;
    $lightVars = <<<VARS
                --accent: {$lightAccent};
                --accent-bg: color-mix(in srgb, {$accent} 12%, white);
                --bg: #FAF9F6; --card: #ffffff; --border: #E8E4DC; --border-strong: #D8D2C6; --border-soft: #F1EEE7;
                --text: #201C24; --text-muted: #746E78; --text-faint: #9C96A0; --text-faintest: #B8B2AA;
                --green: #16814f; --green-bg: #E8F6EE; --amber: #92601a; --amber-bg: #FBF0DC; --red: #b3223a;
                color-scheme: light;
    VARS;
    $darkVars = <<<VARS
                --accent: {$accent};
                --accent-bg: color-mix(in srgb, {$accent} 22%, black);
                --bg: #18151d; --card: #221f27; --border: #34303b; --border-strong: #45404d; --border-soft: #2b2830;
                --text: #F0EDE9; --text-muted: #ACA6B2; --text-faint: #837d8a; --text-faintest: #5c5763;
                --green: #3ecf8e; --green-bg: #1c3229; --amber: #e0a950; --amber-bg: #3a2e1a; --red: #ea7b8d;
                color-scheme: dark;
    VARS;
    if (!$enableDarkMode) {
        $rootVars = $lightVars;
        $themeBlock = '';
    } else {
        $rootVars = $darkVars;
        $themeBlock = <<<CSS
        @media (prefers-color-scheme: light) {
            :root:not([data-theme="dark"]) {
                {$lightVars}
            }
        }
        :root[data-theme="light"] {
            {$lightVars}
        }
    CSS;
    }
    return <<<CSS
    <style>
        :root {
            {$rootVars}
        }
        {$themeBlock}
        * { box-sizing: border-box; }
        body { font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
        h1, h2, h3, .heading-font { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -0.01em; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { opacity: 0.85; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 4px; }

        .pg-topbar { height: 56px; background: var(--card); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; flex-shrink: 0; }
        .pg-crumb { font-size: 13.5px; color: var(--text-faint); }
        .pg-crumb strong { color: var(--text); font-weight: 600; }

        .pg-shell { display: flex; min-height: 100vh; }
        .pg-sidebar { width: 236px; flex-shrink: 0; background: var(--card); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 20px 14px; box-sizing: border-box; position: sticky; top: 0; height: 100vh; }
        .pg-logo-row { display: flex; align-items: center; gap: 9px; padding: 4px 8px 18px; }
        .pg-logo-badge { width: 28px; height: 28px; border-radius: 7px; overflow: hidden; flex-shrink: 0; }
        .pg-logo-badge img { width: 100%; height: 100%; display: block; }
        .pg-logo-text { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 16.5px; letter-spacing: -0.01em; color: var(--text); }
        .pg-proj-select { width: 100%; box-sizing: border-box; border: 1px solid var(--border); background: var(--bg); border-radius: 8px; padding: 8px 10px; font-size: 13px; font-weight: 600; color: var(--text); font-family: 'IBM Plex Sans', sans-serif; cursor: pointer; margin-bottom: 16px; }
        .pg-nav { display: flex; flex-direction: column; gap: 2px; padding: 0 4px; }
        .pg-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; cursor: pointer; font-size: 13.5px; font-weight: 600; color: var(--text-muted); }
        .pg-nav a:hover { background: var(--bg); opacity: 1; }
        .pg-nav a.active { background: var(--accent-bg); color: var(--accent); }
        .pg-nav-dot { width: 13px; height: 13px; border-radius: 50%; border: 2px solid currentColor; box-sizing: border-box; flex-shrink: 0; opacity: .85; }
        .pg-nav-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px; width: 13px; height: 13px; flex-shrink: 0; }
        .pg-nav-grid span { background: currentColor; border-radius: 2px; opacity: .85; }
        .pg-sidebar-spacer { flex: 1; }
        .pg-viewer-link { display: flex; align-items: center; justify-content: space-between; padding: 9px 10px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-decoration: none; }
        .pg-viewer-link:hover { background: var(--bg); opacity: 1; }
        .pg-settings-link { margin-bottom: 0; }
        .pg-settings-link.active { background: var(--accent-bg); color: var(--accent); }
        .pg-user-row { display: flex; align-items: center; gap: 9px; padding: 6px 8px; }
        .pg-user-avatar { width: 26px; height: 26px; border-radius: 50%; background: #2b2732; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
        .pg-user-name { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pg-logout { color: var(--text-faint); font-size: 12px; }

        .pg-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .pg-content { padding: 32px 32px 80px; }
        .pg-footer-note { padding: 14px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text-faintest); line-height: 1.5; }

        .pg-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; margin-bottom: 18px; }
        .pg-card-pad { padding: 22px; }
        .pg-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 5px; }
        .pg-card .pg-card-sub { font-size: 12.5px; color: var(--text-muted); margin: 0; }

        .pg-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
        @media (max-width: 980px) { .pg-kpi-grid { grid-template-columns: 1fr 1fr; } }
        .pg-kpi { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
        .pg-kpi-label { font-size: 11px; font-weight: 700; letter-spacing: .06em; color: var(--text-faint); text-transform: uppercase; margin-bottom: 10px; }
        .pg-kpi-val { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 26px; font-weight: 800; }
        .pg-kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        table.pg-table { width: 100%; border-collapse: collapse; }
        .pg-table th { text-align: left; padding: 9px 22px; font-size: 10.5px; font-weight: 700; letter-spacing: .05em; color: var(--text-faint); text-transform: uppercase; background: var(--bg); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .pg-table td { padding: 13px 22px; border-bottom: 1px solid var(--border-soft); font-size: 13.5px; vertical-align: middle; }
        .pg-table tr:hover td { background: var(--bg); }

        .pg-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        .pg-pill-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .pg-pill-green { color: var(--green); background: var(--green-bg); }
        .pg-pill-green .pg-pill-dot { background: #2fa06a; }
        .pg-pill-amber { color: var(--amber); background: var(--amber-bg); }
        .pg-pill-amber .pg-pill-dot { background: #d9a441; }
        .pg-pill-red { color: var(--red); background: #FBE7EA; }
        .pg-pill-red .pg-pill-dot { background: #d9536b; }
        .pg-pill-muted { color: var(--text-faint); background: var(--border-soft); font-weight: 500; }
        .pg-req-label { font-size: 12px; font-weight: 700; color: var(--accent); }
        .pg-opt-label { font-size: 12px; font-weight: 500; color: var(--text-faint); }

        .pg-icon-btn { border: 1px solid var(--border); background: var(--card); border-radius: 7px; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); }
        .pg-icon-btn:hover { border-color: var(--border-strong); color: var(--text); }
        .pg-icon-btn.danger:hover { border-color: #d99; color: var(--red); }
        .pg-copy-btn { border: none; background: transparent; padding: 2px; cursor: pointer; color: var(--text-faint); display: inline-flex; align-items: center; }
        .pg-copy-btn:hover { color: var(--text-muted); }

        input[type=text], input[type=password], input[type=email], input[type=number], input[type=datetime-local], textarea, select {
            font-family: 'IBM Plex Sans', sans-serif; border: 1px solid var(--border-strong); border-radius: 9px; padding: 9px 12px; font-size: 13px; background: var(--card); color: var(--text);
        }
        label.pg-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; margin-top: 14px; }
        .pg-hint { font-size: 11.5px; color: var(--text-faint); margin-top: 6px; }
        .pg-help { display: inline-flex; align-items: center; justify-content: center; width: 15px; height: 15px; border-radius: 50%; background: var(--border-soft); color: var(--text-faint); font-size: 10px; font-weight: 700; font-style: normal; cursor: help; margin-left: 5px; flex-shrink: 0; vertical-align: middle; }
        .pg-help:hover { background: var(--border); color: var(--text-muted); }

        .pg-btn { border: none; border-radius: 9px; padding: 10px 18px; background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 65%, white)); box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 35%, transparent); color: #fff; font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: 'IBM Plex Sans', sans-serif; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn:hover { filter: brightness(1.08); }
        .pg-btn-secondary { border: 1px solid var(--border-strong); background: var(--card); border-radius: 9px; padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--text); display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn-secondary:hover { border-color: var(--accent); }
        .pg-btn-dark { border: none; background: #17141b; color: #fff; border-radius: 7px; padding: 6px 10px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn-dark:hover { background: #2b2732; }
        .pg-btn-danger { border: none; background: #7f1d1d; color: #fecaca; border-radius: 8px; padding: 9px 15px; font-size: 13px; font-weight: 700; cursor: pointer; }
        .pg-btn-danger:hover { background: #991b1b; }

        .pg-alert { border-radius: 10px; padding: 13px 16px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 10px; font-size: 13px; }
        .pg-alert-amber { background: #FCF3E1; border: 1px solid #EAD2A0; color: #6b4a13; }
        .pg-alert-red { background: #FBE9EB; border: 1px solid #E9B7BE; color: #7a2431; }
        .pg-alert ul { margin: 6px 0 0 18px; padding: 0; }

        .pg-modal-backdrop { position: fixed; inset: 0; background: rgba(20,16,22,.5); display: none; align-items: center; justify-content: center; z-index: 300; padding: 1rem; }
        .pg-modal-backdrop.show { display: flex; }
        .pg-modal { background: var(--card); border-radius: 16px; padding: 32px; width: 480px; max-width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.25); box-sizing: border-box; }

        .pg-toast { position: fixed; bottom: 2rem; right: 2rem; background: #17141b; color: #fff; padding: 0.85rem 1.5rem; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 99999; font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; transform: translateY(100px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .pg-toast.show { transform: translateY(0); opacity: 1; }
    </style>
    CSS;
}

/**
 * Relative luminance (WCAG) of a hex color, used to pick readable button text
 * against an arbitrary customer-chosen brand_color (unlike the fixed marketing
 * amber, this accent can be any hue/lightness, so text color can't be hardcoded).
 */
function theme_contrast_text(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#17130d';
    }
    $lin = function (int $c): float {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $l = 0.2126 * $lin((int)hexdec(substr($hex, 0, 2)))
        + 0.7152 * $lin((int)hexdec(substr($hex, 2, 2)))
        + 0.0722 * $lin((int)hexdec(substr($hex, 4, 2)));
    return $l > 0.45 ? '#17130d' : '#f5f1e8';
}

/**
 * "§ — Ink & Paper, quiet" head tags for admin.php/editor.php ONLY.
 * Self-hosted webfonts (no external font CDN in a consent-management product).
 * index.php (the embedded public banner) keeps using theme_head_tags() unchanged.
 */
function theme_head_tags_admin(): string {
    return '<style>'
        . '@font-face { font-family: "Fraunces"; src: url("/assets/fonts/fraunces-variable.woff2") format("woff2-variations"); font-weight: 300 900; font-display: swap; }'
        . '@font-face { font-family: "Inter"; src: url("/assets/fonts/inter-variable.woff2") format("woff2-variations"); font-weight: 300 900; font-display: swap; }'
        . '@font-face { font-family: "JetBrains Mono"; src: url("/assets/fonts/jetbrainsmono-variable.woff2") format("woff2-variations"); font-weight: 300 800; font-display: swap; }'
        . '</style>'
        . '<script>(function(){try{var t=localStorage.getItem("paragrafy_theme");if(t==="light"||t==="dark"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>';
}

/**
 * "§ — Ink & Paper, quiet" base CSS for admin.php/editor.php ONLY.
 * $accent stays the project's own brand_color (customer-controlled) and drives
 * --accent/--accent-bg exactly as before -- only the surrounding palette,
 * radii, typography and component styling move to the new design system.
 * index.php keeps calling theme_base_css() unchanged, so the public banner
 * widget's look is completely unaffected by this function.
 */
function theme_base_css_admin(string $accent = '#F0A63C', bool $enableDarkMode = true): string {
    $accent = htmlspecialchars($accent, ENT_QUOTES);
    $btnInk = theme_contrast_text($accent);
    $lightAccent = $enableDarkMode ? "color-mix(in srgb, {$accent} 82%, black)" : $accent;
    $lightVars = <<<VARS
                --accent: {$lightAccent};
                --accent-bg: color-mix(in srgb, {$accent} 10%, white);
                --btn-ink: {$btnInk};
                --bg: #f7f3ea; --card: #fffdf8; --input-bg: #fbf8f1;
                --border: rgba(28,23,18,.12); --border-strong: rgba(28,23,18,.24); --border-soft: rgba(28,23,18,.06);
                --text: #1c1712; --text-muted: rgba(28,23,18,.65); --text-faint: rgba(28,23,18,.46); --text-faintest: rgba(28,23,18,.32);
                --green: #1a8f63; --green-bg: rgba(26,143,99,.1); --amber: #a86a1c; --amber-bg: rgba(168,106,28,.1); --red: #b3382e; --red-bg: rgba(179,56,46,.1);
                color-scheme: light;
    VARS;
    $darkVars = <<<VARS
                --accent: {$accent};
                --accent-bg: color-mix(in srgb, {$accent} 20%, black);
                --btn-ink: {$btnInk};
                --bg: #0b0a08; --card: #16130f; --input-bg: #0e0c09;
                --border: rgba(245,241,232,.14); --border-strong: rgba(245,241,232,.28); --border-soft: rgba(245,241,232,.08);
                --text: #f5f1e8; --text-muted: rgba(245,241,232,.68); --text-faint: rgba(245,241,232,.5); --text-faintest: rgba(245,241,232,.35);
                --green: #34d399; --green-bg: rgba(52,211,153,.12); --amber: #f0a63c; --amber-bg: rgba(240,166,60,.12); --red: #e04b3f; --red-bg: rgba(224,75,63,.12);
                color-scheme: dark;
    VARS;
    if (!$enableDarkMode) {
        $rootVars = $lightVars;
        $themeBlock = '';
    } else {
        $rootVars = $darkVars;
        $themeBlock = <<<CSS
        @media (prefers-color-scheme: light) {
            :root:not([data-theme="dark"]) {
                {$lightVars}
            }
        }
        :root[data-theme="light"] {
            {$lightVars}
        }
    CSS;
    }
    return <<<CSS
    <style>
        :root {
            --radius: 3px; --radius-sm: 2px;
            {$rootVars}
        }
        {$themeBlock}
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
        h2, h3, .heading-font { font-family: 'Inter', sans-serif; font-weight: 700; letter-spacing: -0.01em; }
        h1, .pg-display { font-family: 'Fraunces', serif; font-weight: 600; letter-spacing: -0.01em; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { opacity: 0.85; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 4px; }

        .pg-topbar { height: 56px; background: var(--card); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; flex-shrink: 0; }
        .pg-crumb { font-size: 13.5px; color: var(--text-faint); }
        .pg-crumb strong { color: var(--text); font-weight: 600; }

        .pg-shell { display: flex; min-height: 100vh; }
        .pg-sidebar { width: 236px; flex-shrink: 0; background: var(--card); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 20px 14px; box-sizing: border-box; position: sticky; top: 0; height: 100vh; }
        .pg-logo-row { display: flex; align-items: center; gap: 9px; padding: 4px 8px 18px; }
        .pg-logo-badge { width: 28px; height: 28px; border-radius: var(--radius); overflow: hidden; flex-shrink: 0; }
        .pg-logo-badge img { width: 100%; height: 100%; display: block; }
        .pg-logo-text { font-family: 'Fraunces', serif; font-weight: 600; font-size: 16.5px; letter-spacing: -0.01em; color: var(--text); }
        .pg-proj-select { width: 100%; box-sizing: border-box; border: 1px solid var(--border); background: var(--bg); border-radius: var(--radius); padding: 8px 10px; font-size: 13px; font-weight: 600; color: var(--text); font-family: 'Inter', sans-serif; cursor: pointer; margin-bottom: 16px; }
        .pg-nav { display: flex; flex-direction: column; gap: 2px; padding: 0 4px; }
        .pg-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius); cursor: pointer; font-size: 13.5px; font-weight: 600; color: var(--text-muted); background-image: linear-gradient(currentColor, currentColor); background-size: 0 1px; background-position: 32px 100%; background-repeat: no-repeat; transition: background-size .15s ease; }
        .pg-nav a:hover { background-color: var(--bg); opacity: 1; background-size: calc(100% - 42px) 1px; }
        .pg-nav a.active { background-color: var(--accent-bg); color: var(--accent); background-size: 0 1px; }
        .pg-nav-dot { width: 13px; height: 13px; border-radius: 50%; border: 2px solid currentColor; box-sizing: border-box; flex-shrink: 0; opacity: .85; }
        .pg-nav-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px; width: 13px; height: 13px; flex-shrink: 0; }
        .pg-nav-grid span { background: currentColor; border-radius: 2px; opacity: .85; }
        .pg-sidebar-spacer { flex: 1; }
        .pg-viewer-link { display: flex; align-items: center; justify-content: space-between; padding: 9px 10px; border-radius: var(--radius); font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-decoration: none; }
        .pg-viewer-link:hover { background: var(--bg); opacity: 1; }
        .pg-settings-link { margin-bottom: 0; }
        .pg-settings-link.active { background: var(--accent-bg); color: var(--accent); }
        .pg-user-row { display: flex; align-items: center; gap: 9px; padding: 6px 8px; }
        .pg-user-avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--text); color: var(--bg); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
        .pg-user-name { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pg-logout { color: var(--text-faint); font-size: 12px; }

        .pg-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .pg-content { padding: 32px 32px 80px; }
        .pg-footer-note { padding: 14px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text-faintest); line-height: 1.5; }

        .pg-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 18px; }
        .pg-card-pad { padding: 22px; }
        .pg-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 5px; }
        .pg-card .pg-card-sub { font-size: 12.5px; color: var(--text-muted); margin: 0; }

        .pg-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
        @media (max-width: 980px) { .pg-kpi-grid { grid-template-columns: 1fr 1fr; } }
        .pg-kpi { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; }
        .pg-kpi-label { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 600; letter-spacing: .05em; color: var(--text-faint); text-transform: uppercase; margin-bottom: 10px; }
        .pg-kpi-val { font-family: 'JetBrains Mono', monospace; font-size: 24px; font-weight: 700; }
        .pg-kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        table.pg-table { width: 100%; border-collapse: collapse; }
        .pg-table th { text-align: left; padding: 9px 22px; font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 600; letter-spacing: .05em; color: var(--text-faint); text-transform: uppercase; background: var(--bg); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .pg-table td { padding: 13px 22px; border-bottom: 1px solid var(--border-soft); font-size: 13.5px; vertical-align: middle; }
        .pg-table tr:hover td { background: var(--bg); }

        .pg-pill { display: inline-flex; align-items: center; gap: 5px; font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; padding: 3px 8px; border-radius: var(--radius-sm); border: 1px solid currentColor; background: transparent; }
        .pg-pill-dot { display: none; }
        .pg-pill-green { color: var(--green); }
        .pg-pill-amber { color: var(--amber); }
        .pg-pill-red { color: var(--red); }
        .pg-pill-muted { color: var(--text-faint); border-color: var(--border-strong); font-weight: 500; text-transform: none; letter-spacing: normal; font-family: 'Inter', sans-serif; }
        .pg-req-label { font-size: 12px; font-weight: 700; color: var(--accent); }
        .pg-opt-label { font-size: 12px; font-weight: 500; color: var(--text-faint); }

        .pg-icon-btn { border: 1px solid var(--border); background: var(--card); border-radius: var(--radius); width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); }
        .pg-icon-btn:hover { border-color: var(--border-strong); color: var(--text); }
        .pg-icon-btn.danger:hover { border-color: var(--red); color: var(--red); }
        .pg-copy-btn { border: none; background: transparent; padding: 2px; cursor: pointer; color: var(--text-faint); display: inline-flex; align-items: center; }
        .pg-copy-btn:hover { color: var(--text-muted); }

        input[type=text], input[type=password], input[type=email], input[type=number], input[type=datetime-local], textarea, select {
            font-family: 'Inter', sans-serif; background: var(--input-bg); border: 1px solid var(--border-strong); border-radius: var(--radius); padding: 9px 12px; font-size: 13px; color: var(--text);
        }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--accent); }
        label.pg-label { display: block; font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--text-faint); margin-bottom: 6px; margin-top: 14px; }
        .pg-hint { font-size: 11.5px; color: var(--text-faint); margin-top: 6px; }
        .pg-help { display: inline-flex; align-items: center; justify-content: center; width: 15px; height: 15px; border-radius: 50%; background: var(--border-soft); color: var(--text-faint); font-size: 10px; font-weight: 700; font-style: normal; cursor: help; margin-left: 5px; flex-shrink: 0; vertical-align: middle; }
        .pg-help:hover { background: var(--border); color: var(--text-muted); }

        .pg-btn { border: none; border-radius: var(--radius); padding: 10px 18px; background: var(--accent); color: var(--btn-ink); font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: 'Inter', sans-serif; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; transition: transform .1s ease; }
        .pg-btn:hover { background: color-mix(in srgb, var(--accent) 85%, white); }
        .pg-btn:active { transform: scale(.98); }
        .pg-btn-secondary { border: 1px solid var(--border-strong); background: transparent; border-radius: var(--radius); padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--text); display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn-secondary:hover { border-color: var(--accent); color: var(--accent); }
        .pg-btn-dark { border: 1px solid var(--border-strong); background: transparent; color: var(--text); border-radius: var(--radius-sm); padding: 6px 10px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn-dark:hover { border-color: var(--accent); color: var(--accent); }
        .pg-btn-danger { border: 1px solid var(--red); background: transparent; color: var(--red); border-radius: var(--radius); padding: 9px 15px; font-size: 13px; font-weight: 700; cursor: pointer; }
        .pg-btn-danger:hover { background: var(--red-bg); }

        .pg-alert { border-radius: var(--radius); padding: 13px 16px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 10px; font-size: 13px; border: 1px solid; }
        .pg-alert-amber { background: var(--amber-bg); border-color: var(--amber); color: var(--amber); }
        .pg-alert-red { background: var(--red-bg); border-color: var(--red); color: var(--red); }
        .pg-alert ul { margin: 6px 0 0 18px; padding: 0; }

        .pg-modal-backdrop { position: fixed; inset: 0; background: rgba(20,16,22,.5); display: none; align-items: center; justify-content: center; z-index: 300; padding: 1rem; }
        .pg-modal-backdrop.show { display: flex; }
        .pg-modal { background: var(--card); border-radius: var(--radius); padding: 32px; width: 480px; max-width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.25); box-sizing: border-box; }

        .pg-toast { position: fixed; bottom: 2rem; right: 2rem; background: var(--text); color: var(--bg); padding: 0.85rem 1.5rem; border-radius: var(--radius); box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 99999; font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; transform: translateY(100px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .pg-toast.show { transform: translateY(0); opacity: 1; }
    </style>
    CSS;
}

function render_demo_countdown_banner(): string {
    if (empty(get_config()['is_demo'])) {
        return '';
    }
    ob_start();
    ?>
    <div class="pg-alert pg-alert-amber" style="margin-bottom:14px;font-size:12px;padding:10px 12px;" data-demo-server-time-ms="<?= (int) round(microtime(true) * 1000) ?>">
        <span>
            <?= t('admin.common.demo_banner.text', ['time' => '<span class="pg-demo-countdown-value">10:00</span>']) ?>
        </span>
    </div>
    <script>
        (function () {
            var banners = document.querySelectorAll('[data-demo-server-time-ms]');
            banners.forEach(function (banner) {
                var serverTimeMs = parseInt(banner.getAttribute('data-demo-server-time-ms'), 10);
                var clockOffsetMs = serverTimeMs - Date.now();
                function render() {
                    var msPerReset = 10 * 60 * 1000;
                    var now = Date.now() + clockOffsetMs;
                    var remaining = msPerReset - (now % msPerReset);
                    var totalSeconds = Math.ceil(remaining / 1000);
                    var mm = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
                    var ss = String(totalSeconds % 60).padStart(2, '0');
                    banner.querySelectorAll('.pg-demo-countdown-value').forEach(function (el) {
                        el.textContent = mm + ':' + ss;
                    });
                }
                render();
                setInterval(render, 1000);
            });
        })();
    </script>
    <?php
    return (string) ob_get_clean();
}

function render_sidebar(string $active, array $project, array $projects): string {
    $items = [
        'dashboard' => ['/admin', t('admin.common.nav.dashboard'), 'grid'],
    ];
    if (current_user_is_primary_admin()) {
        $items['users'] = ['/admin/users', t('admin.common.nav.users'), 'users'];
    }
    if (!current_user_is_primary_admin() || admin_totp_available()) {
        $items['security'] = ['/admin/security?project_id=' . $project['id'], t('admin.common.nav.security'), 'shield'];
    }
    $items['audit'] = ['/admin/audit?project_id=' . $project['id'], t('admin.common.nav.audit'), 'clock'];
    $items['consent_log'] = ['/admin/consent-log?project_id=' . $project['id'], t('admin.common.nav.consent_log'), 'shield'];
    $currentUserName = $_SESSION['paragrafy_user_name'] ?? 'Admin';
    $initials = '';
    foreach (preg_split('/\s+/', trim($currentUserName)) as $part) {
        if ($part !== '') { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
        if (mb_strlen($initials) >= 2) break;
    }
    if ($initials === '') { $initials = 'A'; }
    $isManagedCloud = !empty(get_config()['managed_cloud']);
    ob_start();
    ?>
    <aside class="pg-sidebar">
        <div class="pg-logo-row">
            <div class="pg-logo-badge"><img src="/paragrafy.svg" alt="Paragrafy"></div>
            <span class="pg-logo-text">Paragrafy</span>
        </div>

        <?= render_demo_countdown_banner() ?>

        <select class="pg-proj-select" onchange="location.href='/admin?project_id=' + this.value">
            <?php foreach ($projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === (int)$project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <nav class="pg-nav">
            <?php foreach ($items as $key => [$href, $label, $icon]): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="<?= $key === $active ? 'active' : '' ?>">
                    <?php if ($icon === 'grid'): ?>
                        <span class="pg-nav-grid"><span></span><span></span><span></span><span></span></span>
                    <?php elseif ($icon === 'users'): ?>
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><circle cx="6" cy="5.2" r="2.2"/><path d="M1.6 13c.5-2.6 2.4-4 4.4-4s3.9 1.4 4.4 4"/><circle cx="11.3" cy="5.8" r="1.7"/><path d="M10.5 9.3c1.7.2 3 1.4 3.4 3.7"/></svg>
                    <?php elseif ($icon === 'clock'): ?>
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><circle cx="8" cy="8" r="6.3"/><path d="M8 4.6V8l2.6 1.6"/></svg>
                    <?php elseif ($icon === 'gear'): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <?php elseif ($icon === 'shield'): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?php else: ?>
                        <span class="pg-nav-dot"></span>
                    <?php endif; ?>
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="pg-sidebar-spacer"></div>

        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:13px;margin-bottom:10px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--text-faint);display:inline-block"></span>
                <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em"><?= $isManagedCloud ? t('admin.common.sidebar.managed_cloud', ['version' => PARAGRAFY_VERSION]) : t('admin.common.sidebar.self_hosted', ['version' => PARAGRAFY_VERSION]) ?></span>
            </div>
            <p style="font-size:12px;color:var(--text-faint);margin:0;line-height:1.5"><?= $isManagedCloud ? t('admin.common.sidebar.hosted_by_cloud') : t('admin.common.sidebar.open_source') ?></p>
            <a href="/CHANGELOG.md" target="_blank" style="display:inline-block;margin-top:6px;font-size:11px;font-weight:600;color:var(--text-faint);text-decoration:none"><?= t('admin.common.sidebar.whats_new') ?></a>
        </div>

        <?php if ($isManagedCloud): ?>
            <a href="https://app.paragrafy.cloud/dashboard" class="pg-viewer-link"><?= t('admin.common.sidebar.back_to_portal') ?></a>
        <?php endif; ?>

        <a href="https://<?= htmlspecialchars($project['domain']) ?>" target="_blank" class="pg-viewer-link"><?= htmlspecialchars(t('admin.common.sidebar.view_public_site')) ?><span>↗</span></a>

        <a href="/admin/settings?project_id=<?= $project['id'] ?>" class="pg-viewer-link pg-settings-link <?= $active === 'settings' ? 'active' : '' ?>">
            <span style="display:flex;align-items:center;gap:10px">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <?= htmlspecialchars(t('admin.common.nav.settings')) ?>
            </span>
        </a>

        <div style="display:flex;gap:6px;padding:0 2px 8px;font-size:11px">
            <?php $curLocale = current_locale(); ?>
            <?php foreach (ui_locales() as $localeCode => $localeMeta): ?>
                <a href="/admin/settings?project_id=<?= $project['id'] ?>&locale=<?= htmlspecialchars($localeCode) ?>" style="text-decoration:none;color:var(--text-faint);<?= $localeCode === $curLocale ? 'font-weight:700;color:var(--text)' : '' ?>" title="<?= htmlspecialchars($localeMeta['label'] ?? strtoupper($localeCode)) ?>"><?= htmlspecialchars($localeMeta['flag'] ?? strtoupper($localeCode)) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="pg-user-row">
            <div class="pg-user-avatar"><?= htmlspecialchars($initials) ?></div>
            <div style="flex:1;min-width:0">
                <div class="pg-user-name"><?= htmlspecialchars($currentUserName) ?></div>
            </div>
            <a href="/admin?logout=1" class="pg-logout" title="<?= htmlspecialchars(t('admin.common.sidebar.logout_title')) ?>">⏻</a>
        </div>
    </aside>
    <?php
    return ob_get_clean();
}
