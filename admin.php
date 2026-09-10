<?php
/**
 * Paragrafy - Admin Command Center, Multi-Project Manager, Compliance Engine & Webhook Logger
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/db.php';

if (!is_installed()) {
    header('Location: /install.php');
    exit;
}

$config = get_config();
$db = get_db();
$error = null;

$earlyRoute = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (str_starts_with($earlyRoute, '/admin/accept-invite')) {
    handle_accept_invite($db);
    exit;
}

if (str_starts_with($earlyRoute, '/admin/forgot-password')) {
    handle_forgot_password($db);
    exit;
}

if (str_starts_with($earlyRoute, '/admin/reset-password')) {
    handle_reset_password($db);
    exit;
}

if (str_starts_with($earlyRoute, '/admin/sso')) {
    handle_sso_login($config);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $clientIp = get_client_ip();
    $emailForRateLimit = trim(strtolower($_POST['email'] ?? ''));
    $acctIdentifier = $emailForRateLimit !== '' ? 'acct:' . $emailForRateLimit : null;
    $waitSeconds = max(
        login_rate_limit_wait($db, $clientIp),
        $acctIdentifier !== null ? login_rate_limit_wait($db, $acctIdentifier) : 0
    );

    if ($waitSeconds > 0) {
        $error = t('admin.login.rate_limited', ['minutes' => (int)ceil($waitSeconds / 60)]);
    } else {
        $email = $emailForRateLimit;
        $pass = $_POST['password'] ?? '';
        $loggedIn = false;
        $totpPending = null;

        if ($email !== '') {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && !empty($user['password_hash']) && password_verify($pass, $user['password_hash'])) {
                if (!empty($user['totp_enabled_at'])) {
                    $totpPending = ['type' => 'user', 'user_id' => (int)$user['id'], 'started_at' => time()];
                } else {
                    finalize_user_session($user);
                    $loggedIn = true;
                }
            }
        } elseif (empty($config['admin_password_login_disabled']) && password_verify($pass, $config['admin_password_hash'] ?? '')) {
            if (admin_totp_available() && admin_totp_enabled()) {
                $totpPending = ['type' => 'admin', 'started_at' => time()];
            } else {
                finalize_admin_session();
                $loggedIn = true;
            }
        }

        if ($loggedIn || $totpPending !== null) {
            // Passwort war in beiden Faellen korrekt -- ein evtl. anschliessend
            // falscher TOTP-Code zaehlt separat unter dem totp:-Praefix (siehe
            // handle_totp_verify()), nicht hier gegen den Passwort-Zaehler.
            clear_login_failures($db, $clientIp);
            if ($acctIdentifier !== null) {
                clear_login_failures($db, $acctIdentifier);
            }
            if ($totpPending !== null) {
                $_SESSION['totp_pending'] = $totpPending;
            }
            header('Location: /admin');
            exit;
        }
        record_login_failure($db, $clientIp);
        if ($acctIdentifier !== null) {
            record_login_failure($db, $acctIdentifier);
        }
        $error = ($email === '' && !empty($config['admin_password_login_disabled']))
            ? t('admin.login.admin_password_login_disabled')
            : t('admin.login.invalid_credentials');
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'totp_verify') {
    $error = handle_totp_verify($db);
}

if (isset($_GET['totp_cancel'])) {
    unset($_SESSION['totp_pending']);
    header('Location: /admin');
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['paragrafy_admin'], $_SESSION['paragrafy_user_id'], $_SESSION['paragrafy_user_name'], $_SESSION['paragrafy_user_email'], $_SESSION['paragrafy_user_locale'], $_SESSION['totp_pending']);
    header('Location: /admin');
    exit;
}

if (empty($_SESSION['paragrafy_admin'])) {
    $totpPending = $_SESSION['totp_pending'] ?? null;
    if (is_array($totpPending) && ($totpPending['started_at'] ?? 0) > time() - 300) {
        render_totp_view($error);
    } else {
        unset($_SESSION['totp_pending']);
        render_login_view($error);
    }
    exit;
}

require_csrf();

$allProjects = $db->query("SELECT * FROM projects ORDER BY name ASC")->fetchAll();
$accessibleProjectIds = current_user_accessible_project_ids($db);
$projects = array_values(array_filter($allProjects, fn($p) => in_array((int)$p['id'], $accessibleProjectIds, true)));

$requestedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$projectId = (int)($requestedProjectId ?? $_SESSION['admin_project_id'] ?? ($projects[0]['id'] ?? 0));

if ($projectId > 0 && !in_array($projectId, $accessibleProjectIds, true)) {
    if ($requestedProjectId !== null) {
        http_response_code(403);
        echo htmlspecialchars(t('admin.common.access_denied'));
        exit;
    }
    $projectId = (int)($projects[0]['id'] ?? 0);
}
$_SESSION['admin_project_id'] = $projectId;

$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    render_no_access_view();
    exit;
}

// Diese Aktionen wirken auf die gesamte Instanz (alle Projekte/Mandanten), nicht nur auf das
// aktuell gewaehlte Projekt -- nur der primaere Admin-Login darf sie ausloesen, sonst koennte ein
// auf ein einzelnes Projekt beschraenkter Multi-User Daten anderer Mandanten abziehen/ueberschreiben.
$instanceWideAction = ($_GET['action'] ?? $_POST['action'] ?? '');
if (in_array($instanceWideAction, ['download_backup', 'download_backup_file', 'run_backup_now', 'restore_backup_upload', 'run_webhook_queue_now', 'regenerate_cron_secret'], true) && !current_user_is_primary_admin()) {
    http_response_code(403);
    echo htmlspecialchars(t('admin.common.access_denied'));
    exit;
}

// 1. SQLite Datenbank-Backup herunterladen
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    if (file_exists(DB_FILE)) {
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="paragrafy_backup_' . date('Y-m-d_His') . '.sqlite"');
        header('Content-Length: ' . filesize(DB_FILE));
        readfile(DB_FILE);
        exit;
    }
}

// 1b. Rollierendes Backup herunterladen (7-Tage-Verlauf)
if (isset($_GET['action']) && $_GET['action'] === 'download_backup_file') {
    $requested = basename((string)($_GET['file'] ?? ''));
    $path = BACKUP_DIR . '/' . $requested;
    if (preg_match('/^paragrafy_backup_[0-9_-]+\.sqlite$/', $requested) && file_exists($path)) {
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="' . $requested . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo htmlspecialchars(t('admin.common.backup_not_found'));
    exit;
}

// 1b2. Einzelnes Projekt exportieren (nur Rechtsinhalte, kein Voll-Instanz-Export)
if (isset($_GET['action']) && $_GET['action'] === 'export_project') {
    $exportResult = export_project_backup($db, $projectId);
    if ($exportResult['success']) {
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="' . $exportResult['filename'] . '"');
        header('Content-Length: ' . filesize($exportResult['path']));
        readfile($exportResult['path']);
        @unlink($exportResult['path']);
        exit;
    }
    http_response_code(500);
    echo htmlspecialchars($exportResult['error'] ?? '');
    exit;
}

// 1c. Rollierendes Backup manuell anstoßen
if (isset($_POST['action']) && $_POST['action'] === 'run_backup_now') {
    $result = run_scheduled_backup();
    log_audit(null, '', $result['success'] ? t('admin.common.audit.backup_triggered') : t('admin.common.audit.backup_failed', ['error' => $result['error'] ?? '']));
    header("Location: /admin/settings?project_id=$projectId&msg=" . ($result['success'] ? 'backup_created' : 'backup_failed'));
    exit;
}

// 1e. Backup hochladen & Datenbank wiederherstellen
if (isset($_POST['action']) && $_POST['action'] === 'restore_backup_upload') {
    if (empty($_FILES['backup_file']['tmp_name'])) {
        header("Location: /admin/settings?project_id=$projectId&msg=restore_failed&restore_error=" . urlencode(t('db.restore.upload_failed')));
        exit;
    }
    $result = restore_database_from_upload($_FILES['backup_file']['tmp_name']);
    if ($result['success']) {
        log_audit(null, '', t('admin.common.audit.restore_triggered', ['count' => $result['project_count'] ?? 0]));
        $redirect = "/admin/settings?msg=restore_success&restore_count=" . (int)($result['project_count'] ?? 0);
        if (!empty($result['over_limit'])) {
            $redirect .= "&restore_over_limit=1&restore_limit=" . (int)($result['project_limit'] ?? 0);
        }
        header("Location: $redirect");
    } else {
        log_audit(null, '', t('admin.common.audit.restore_failed', ['error' => $result['error'] ?? '']));
        header("Location: /admin/settings?project_id=$projectId&msg=restore_failed&restore_error=" . urlencode($result['error'] ?? ''));
    }
    exit;
}

// 1f. Einzelnes Projekt in ein explizit gewähltes, bestehendes Projekt importieren (Merge,
// andere Projekte dieser Instanz bleiben unberührt)
if (isset($_POST['action']) && $_POST['action'] === 'import_project_upload') {
    $targetProjectId = (int)($_POST['target_project_id'] ?? 0);
    if (empty($_FILES['project_file']['tmp_name']) || $targetProjectId <= 0 || !in_array($targetProjectId, $accessibleProjectIds, true)) {
        header("Location: /admin/settings?project_id=$projectId&msg=project_import_failed&import_error=" . urlencode(t('db.restore.upload_failed')));
        exit;
    }
    $result = import_project_backup($db, $_FILES['project_file']['tmp_name'], $targetProjectId);
    if ($result['success']) {
        log_audit($result['target_project_id'], '', t('admin.common.audit.project_import_merged', ['docs' => $result['documents_merged'], 'translations' => $result['translations_merged']]));
        header("Location: /admin/settings?project_id=" . (int)$result['target_project_id'] . "&msg=project_import_success&import_docs=" . (int)$result['documents_merged']);
    } else {
        log_audit(null, '', t('admin.common.audit.project_import_failed', ['error' => $result['error'] ?? '']));
        header("Location: /admin/settings?project_id=$projectId&msg=project_import_failed&import_error=" . urlencode($result['error'] ?? ''));
    }
    exit;
}

// 1d. Webhook-Warteschlange manuell abarbeiten
if (isset($_POST['action']) && $_POST['action'] === 'run_webhook_queue_now') {
    process_webhook_queue();
    header("Location: /admin/settings?project_id=$projectId&msg=queue_processed");
    exit;
}

// 1e. Cron-Secret neu generieren (macht bestehende Cron-URLs ungültig)
if (isset($_POST['action']) && $_POST['action'] === 'regenerate_cron_secret') {
    if (!empty(get_config()['managed_cloud']) || !empty(get_config()['is_demo'])) {
        header('Location: /admin/settings?project_id=' . $projectId . '&msg=managed_cloud_blocked');
        exit;
    }
    regenerate_cron_secret();
    log_audit(null, '', t('admin.common.audit.cron_secret_regenerated'));
    header("Location: /admin/settings?project_id=$projectId&msg=cron_secret_regenerated");
    exit;
}

// 2. Markdown Export herunterladen
if (isset($_GET['action']) && $_GET['action'] === 'export_markdown') {
    $stmt = $db->prepare("
        SELECT t.title, t.slug, t.lang, t.content, t.updated_at, dt.title as type_title
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE d.project_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$projectId]);
    $docs = $stmt->fetchAll();

    if (class_exists('ZipArchive')) {
        $zipFile = tempnam(sys_get_temp_dir(), 'pg_md_');
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($docs as $d) {
            $filename = $d['lang'] . '/' . $d['slug'] . '.md';
            $md = "# " . $d['title'] . "\n\n" . t('admin.matrix.export.md_stand', ['date' => $d['updated_at']]) . "\n\n" . strip_tags($d['content']);
            $zip->addFromString($filename, $md);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $project['name']) . '_legal_markdown.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        @unlink($zipFile);
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $project['name']) . '_legal_export.txt"');
        foreach ($docs as $d) {
            echo "========================================\n";
            echo t('admin.matrix.export.txt_document', ['title' => $d['title'], 'lang' => strtoupper($d['lang']), 'slug' => $d['slug']]) . "\n";
            echo t('admin.matrix.export.txt_stand', ['date' => $d['updated_at']]) . "\n";
            echo "========================================\n\n";
            echo strip_tags($d['content']) . "\n\n\n";
        }
        exit;
    }
}

// 2b. Änderungsprotokoll als CSV herunterladen
if (isset($_GET['action']) && $_GET['action'] === 'export_audit_csv') {
    if (current_user_is_primary_admin()) {
        $stmt = $db->prepare("SELECT * FROM audit_log WHERE project_id = ? OR project_id IS NULL ORDER BY created_at DESC LIMIT 1000");
    } else {
        $stmt = $db->prepare("SELECT * FROM audit_log WHERE project_id = ? ORDER BY created_at DESC LIMIT 1000");
    }
    $stmt->execute([$projectId]);
    $entries = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $project['name']) . '_protokoll.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, [t('admin.audit.col_time'), t('admin.audit.col_user'), t('admin.audit.col_action'), t('admin.audit.col_project')]);
    foreach ($entries as $e) {
        fputcsv($out, [csv_safe($e['created_at']), csv_safe($e['user_name']), csv_safe($e['action']), csv_safe($e['project_name'])]);
    }
    fclose($out);
    exit;
}

// 2c. Consent-Nachweise als CSV herunterladen (DSGVO-Prüfbericht)
if (isset($_GET['action']) && $_GET['action'] === 'export_consent_log_csv') {
    $stmt = $db->prepare("SELECT * FROM consent_logs WHERE project_id = ? ORDER BY created_at DESC LIMIT 5000");
    $stmt->execute([$projectId]);
    $entries = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $project['name']) . '_consent_nachweise.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        t('admin.consent_log.col_time'), t('admin.consent_log.col_consent_id'), t('admin.consent_log.col_action'),
        t('admin.consent_log.col_lang'), t('admin.consent_log.col_ip'), t('admin.consent_log.col_text_hash'), t('admin.consent_log.col_useragent')
    ]);
    foreach ($entries as $e) {
        $actionLabel = $e['action'] === 'accepted' ? t('admin.consent_log.action_accepted') : t('admin.consent_log.action_declined');
        fputcsv($out, [csv_safe($e['created_at']), csv_safe($e['consent_id']), $actionLabel, csv_safe($e['lang']), csv_safe($e['ip_anonymized']), csv_safe($e['banner_text_hash']), csv_safe($e['user_agent'])]);
    }
    fclose($out);
    exit;
}

// 3. Webhook Test Trigger mit detailliertem Protokoll
if (isset($_POST['action']) && $_POST['action'] === 'test_webhook') {
    header('Content-Type: application/json');
    $result = dispatch_webhook($project, [
        'test' => true,
        'message' => t('admin.settings.webhook_test_message'),
        'triggered_at' => date('c')
    ]);
    echo json_encode($result);
    exit;
}

// 4. SMTP Test-Mail Trigger
if (isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
    header('Content-Type: application/json');
    $recipient = trim($project['audit_email_recipient'] ?? '') ?: ($project['email'] ?? '');
    if (empty($recipient)) {
        echo json_encode(['success' => false, 'error' => t('admin.settings.smtp_test_missing_recipient')]);
        exit;
    }
    $html = "<h2>" . htmlspecialchars(t('admin.settings.smtp_test_heading')) . "</h2><p>" . t('admin.settings.smtp_test_body', ['project' => htmlspecialchars($project['name'])]) . "</p>";
    $res = send_smtp_mail($project, $recipient, t('admin.settings.smtp_test_subject'), $html);
    echo json_encode($res);
    exit;
}

// 5. Webhook Logs leeren
if (isset($_POST['action']) && $_POST['action'] === 'clear_webhook_logs') {
    $del = $db->prepare("DELETE FROM webhook_logs WHERE project_id = ?");
    $del->execute([$projectId]);
    header("Location: /admin/settings?project_id=$projectId&msg=logs_cleared");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Admin-UI-Sprache des eingeloggten Benutzers speichern
    if ($action === 'save_locale') {
        $newLocale = $_POST['locale'] ?? '';
        if (isset(ui_locales()[$newLocale]) && !empty($_SESSION['paragrafy_user_id'])) {
            $upd = $db->prepare("UPDATE users SET locale = ? WHERE id = ?");
            $upd->execute([$newLocale, (int)$_SESSION['paragrafy_user_id']]);
            $_SESSION['paragrafy_user_locale'] = $newLocale;
        }
        header("Location: /admin/settings?project_id=$projectId&msg=locale_saved");
        exit;
    }

    // Neues Projekt anlegen
    if ($action === 'create_project') {
        if (!empty(get_config()['managed_cloud'])) {
            header('Location: /admin?msg=managed_cloud_blocked');
            exit;
        }
        $newName = trim($_POST['new_project_name'] ?? '');
        $newDomain = trim($_POST['new_project_domain'] ?? '');
        $newLang = trim($_POST['new_primary_lang'] ?? 'de');
        $newColor = trim($_POST['new_brand_color'] ?? '#F0A63C');
        if (!str_starts_with($newColor, '#')) $newColor = '#' . $newColor;

        $projectLimit = get_project_limit();
        if ($projectLimit !== null && count($projects) >= $projectLimit) {
            header("Location: /admin?msg=project_limit_reached");
            exit;
        }

        if (!empty($newName) && !empty($newDomain)) {
            $stmt = $db->prepare("INSERT INTO projects (name, domain, brand_color, primary_lang, active_languages) VALUES (?, ?, ?, ?, 'de,en')");
            $stmt->execute([$newName, $newDomain, $newColor, $newLang]);
            $newProjectId = (int)$db->lastInsertId();

            $docTypes = $db->query("SELECT id FROM doc_types")->fetchAll();
            foreach ($docTypes as $dt) {
                $insDoc = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $insDoc->execute([$newProjectId, $dt['id']]);
            }

            log_audit($newProjectId, $newName, t('admin.common.audit.project_created'));
            header("Location: /admin?project_id=$newProjectId&msg=project_created");
            exit;
        }
    }

    // Projekt löschen
    if ($action === 'delete_project') {
        $delId = (int)$_POST['delete_project_id'];
        if (count($projects) > 1 && in_array($delId, $accessibleProjectIds, true)) {
            $delName = '';
            foreach ($projects as $p) { if ((int)$p['id'] === $delId) { $delName = $p['name']; break; } }
            $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$delId]);
            log_audit(null, $delName, t('admin.common.audit.project_deleted'));
            header("Location: /admin?msg=project_deleted");
            exit;
        }
    }

    // Dokumenttypen sind instanzweit (gelten fuer alle Projekte/Mandanten) -- nur der primaere
    // Admin darf sie anlegen/aendern/loeschen, sonst koennte ein auf ein Projekt beschraenkter
    // Multi-User die Dokumentstruktur aller anderen Mandanten manipulieren.
    if (in_array($action, ['toggle_required', 'create_doc_type', 'delete_doc_type'], true) && !current_user_is_primary_admin()) {
        http_response_code(403);
        echo htmlspecialchars(t('admin.common.access_denied'));
        exit;
    }

    // AJAX / Post Toggle für Pflichtstatus
    if ($action === 'toggle_required') {
        $typeId = (int)$_POST['doc_type_id'];
        $stmt = $db->prepare("UPDATE doc_types SET is_required = CASE WHEN is_required = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([$typeId]);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            $stmtNew = $db->prepare("SELECT is_required FROM doc_types WHERE id = ?");
            $stmtNew->execute([$typeId]);
            echo json_encode(['success' => true, 'is_required' => (int)$stmtNew->fetchColumn()]);
            exit;
        }
        header("Location: /admin?project_id=$projectId&msg=toggled");
        exit;
    }

    if ($action === 'save_project') {
        $activeLangs = implode(',', array_filter(array_map('trim', explode(',', $_POST['active_languages'] ?? 'de,en'))));
        $brandColor = trim($_POST['brand_color'] ?? '#F0A63C');
        // Secret-Felder werden im Formular nie mit ihrem echten Wert vorbefuellt (siehe Rendering
        // weiter unten) -- ein leer abgeschicktes Feld heisst also "unveraendert lassen", nicht
        // "loeschen", sonst wuerde jedes Speichern ohne Neueingabe die Secrets wegloeschen.
        $deeplKey = trim($_POST['deepl_api_key'] ?? '') ?: (string)($project['deepl_api_key'] ?? '');
        $aiProvider = in_array($_POST['ai_provider'] ?? '', ['claude', 'openai'], true) ? $_POST['ai_provider'] : '';
        $aiApiKey = trim($_POST['ai_api_key'] ?? '') ?: (string)($project['ai_api_key'] ?? '');
        $logoUrl = trim($_POST['logo_url'] ?? '');
        $webhookUrl = trim($_POST['webhook_url'] ?? '');
        $webhookSecret = trim($_POST['webhook_secret'] ?? '') ?: (string)($project['webhook_secret'] ?? '');
        $auditMonths = max(1, (int)($_POST['audit_interval_months'] ?? 12));

        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $smtpPass = trim($_POST['smtp_pass'] ?? '') ?: (string)($project['smtp_pass'] ?? '');
        $smtpSecure = trim($_POST['smtp_secure'] ?? 'tls');
        $smtpFrom = trim($_POST['smtp_from'] ?? '');
        $auditRecipient = trim($_POST['audit_email_recipient'] ?? '');

        $cookieBanner = !empty($_POST['cookie_banner_enabled']) ? 1 : 0;
        $cookieBannerText = trim($_POST['cookie_banner_text'] ?? '');
        $consentLogging = !empty($_POST['consent_logging_enabled']) ? 1 : 0;
        $consentLogRetentionDays = max(0, (int)($_POST['consent_log_retention_days'] ?? 1095));
        if (!str_starts_with($brandColor, '#')) {
            $brandColor = '#' . $brandColor;
        }

        $isLockedInstance = !empty(get_config()['managed_cloud']) || !empty(get_config()['is_demo']);
        $domainToSave = $isLockedInstance ? $project['domain'] : $_POST['domain'];

        // settings_updated_at wird bewusst nicht per CURRENT_TIMESTAMP gesetzt, sondern strikt
        // hochgezaehlt (+1s statt Gleichstand), damit Last-Modified/If-Modified-Since bei zwei
        // Aenderungen innerhalb derselben Sekunde nicht auf denselben Wert kollabiert -- siehe
        // cache.php (build_public_last_modified) und update_project_company_fields() in db.php,
        // die dasselbe Muster fahren.
        $stmt = $db->prepare("
            UPDATE projects SET
                name=?, domain=?, brand_color=?, primary_lang=?, active_languages=?,
                deepl_api_key=?, ai_provider=?, ai_api_key=?, logo_url=?, webhook_url=?, webhook_secret=?, audit_interval_months=?,
                smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, smtp_secure=?, smtp_from=?, audit_email_recipient=?,
                cookie_banner_enabled=?, cookie_banner_text=?, consent_logging_enabled=?, consent_log_retention_days=?,
                company_name=?, address=?, email=?, phone=?, representative=?, register_info=?,
                settings_updated_at=CASE WHEN datetime('now') > settings_updated_at THEN datetime('now') ELSE datetime(settings_updated_at, '+1 second') END,
                settings_version=settings_version + 1
            WHERE id=?
        ");
        $stmt->execute([
            $_POST['name'], $domainToSave, $brandColor, $_POST['primary_lang'], $activeLangs,
            $deeplKey, $aiProvider, $aiApiKey, $logoUrl, $webhookUrl, $webhookSecret, $auditMonths,
            $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpFrom, $auditRecipient,
            $cookieBanner, $cookieBannerText, $consentLogging, $consentLogRetentionDays,
            $_POST['company_name'], $_POST['address'], $_POST['email'], $_POST['phone'], $_POST['representative'], $_POST['register_info'],
            $projectId
        ]);
        log_audit($projectId, $_POST['name'], t('admin.common.audit.settings_updated'));
        header("Location: /admin/settings?project_id=$projectId&msg=saved");
        exit;
    }

    if ($action === 'create_doc_type') {
        $title = trim($_POST['doc_title'] ?? '');
        $slug = trim($_POST['doc_slug'] ?? '');
        $isReq = !empty($_POST['is_required']) ? 1 : 0;

        if ($slug === '' && $title !== '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        }

        if ($title !== '' && $slug !== '') {
            $stmt = $db->prepare("INSERT INTO doc_types (title, slug, is_required) VALUES (?, ?, ?)");
            $stmt->execute([$title, $slug, $isReq]);
            $newTypeId = (int)$db->lastInsertId();

            foreach ($projects as $p) {
                $docStmt = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $docStmt->execute([$p['id'], $newTypeId]);
            }

            log_audit(null, '', t('admin.matrix.audit.doctype_created', ['title' => $title]));
        }
        header("Location: /admin?project_id=$projectId&msg=type_created");
        exit;
    }

    if ($action === 'delete_doc_type') {
        $typeId = (int)$_POST['doc_type_id'];
        $typeTitleStmt = $db->prepare("SELECT title FROM doc_types WHERE id = ?");
        $typeTitleStmt->execute([$typeId]);
        $typeTitle = (string)($typeTitleStmt->fetchColumn() ?: '');
        $stmt = $db->prepare("DELETE FROM doc_types WHERE id = ?");
        $stmt->execute([$typeId]);
        log_audit(null, '', t('admin.matrix.audit.doctype_deleted', ['title' => $typeTitle]));
        header("Location: /admin?project_id=$projectId&msg=type_deleted");
        exit;
    }

    // Nutzerverwaltung ist ausschliesslich dem primaeren Admin-Login vorbehalten -- ein
    // ueber die users-Tabelle eingeloggter Benutzer darf nie selbst Benutzer verwalten.
    if (in_array($action, ['invite_user', 'resend_invite', 'delete_user', 'update_user_projects'], true) && !current_user_is_primary_admin()) {
        http_response_code(403);
        echo htmlspecialchars(t('admin.common.access_denied'));
        exit;
    }

    // Benutzer einladen
    if ($action === 'invite_user') {
        if (!empty(get_config()['managed_cloud'])) {
            header('Location: /admin/users?msg=managed_cloud_blocked');
            exit;
        }
        $name = trim($_POST['invite_name'] ?? '');
        $email = trim(strtolower($_POST['invite_email'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: /admin/users?msg=invalid");
            exit;
        }

        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            header("Location: /admin/users?msg=email_exists");
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $ins = $db->prepare("INSERT INTO users (name, email, status, invite_token, invite_token_expires_at) VALUES (?, ?, 'invited', ?, ?)");
        $ins->execute([$name, $email, $token, $expiresAt]);
        log_audit(null, '', t('admin.users.audit.invited', ['name' => $name, 'email' => $email]));

        $res = send_invite_mail($project, $name, $email, $token);
        header("Location: /admin/users?msg=" . ($res['success'] ? 'invited' : 'invite_mail_failed'));
        exit;
    }

    // Einladung erneut senden
    if ($action === 'resend_invite') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'invited'");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();

        if ($u) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
            $upd = $db->prepare("UPDATE users SET invite_token = ?, invite_token_expires_at = ? WHERE id = ?");
            $upd->execute([$token, $expiresAt, $uid]);
            $res = send_invite_mail($project, $u['name'], $u['email'], $token);
            header("Location: /admin/users?msg=" . ($res['success'] ? 'invited' : 'invite_mail_failed'));
            exit;
        }
        header("Location: /admin/users");
        exit;
    }

    // Benutzer entfernen
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== (int)($_SESSION['paragrafy_user_id'] ?? 0)) {
            $delUserNameStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $delUserNameStmt->execute([$uid]);
            $delUserName = (string)($delUserNameStmt->fetchColumn() ?: '');
            $del = $db->prepare("DELETE FROM users WHERE id = ?");
            $del->execute([$uid]);
            log_audit(null, '', t('admin.users.audit.deleted', ['name' => $delUserName]));
        }
        header("Location: /admin/users?msg=user_deleted");
        exit;
    }

    // Projekt-Zuordnung eines Benutzers speichern (Berechtigungsmatrix)
    if ($action === 'update_user_projects') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $projectIds = array_map('intval', $_POST['project_ids'] ?? []);
        $userRow = $db->prepare("SELECT name FROM users WHERE id = ?");
        $userRow->execute([$uid]);
        $uName = (string)($userRow->fetchColumn() ?: '');

        if ($uid > 0 && $uName !== '') {
            $db->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([$uid]);
            if (!empty($projectIds)) {
                $insUp = $db->prepare("INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)");
                foreach (array_unique($projectIds) as $pid) {
                    $insUp->execute([$uid, $pid]);
                }
                log_audit(null, '', t('admin.users.audit.access_restricted', ['name' => $uName, 'count' => count(array_unique($projectIds))]));
            } else {
                log_audit(null, '', t('admin.users.audit.access_unrestricted', ['name' => $uName]));
            }
        }
        header("Location: /admin/users?msg=access_updated");
        exit;
    }
}

function send_invite_mail(array $project, string $name, string $email, string $token): array {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $project['domain'];
    $inviteLink = $scheme . '://' . $host . '/admin/accept-invite?token=' . $token;

    $html = "<h2>" . htmlspecialchars(t('admin.login.invite_mail_heading')) . "</h2>"
        . "<p>" . htmlspecialchars(t('admin.login.invite_mail_greeting', ['name' => $name])) . "</p>"
        . "<p>" . htmlspecialchars(t('admin.login.invite_mail_body')) . "</p>"
        . "<p><a href='" . htmlspecialchars($inviteLink) . "' style='background:#F0A63C;color:#fff;padding:0.6rem 1.2rem;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold;'>" . htmlspecialchars(t('admin.login.invite_mail_button')) . "</a></p>"
        . "<p>" . htmlspecialchars(t('admin.login.invite_mail_link_hint')) . "<br>" . htmlspecialchars($inviteLink) . "</p>";

    return send_smtp_mail($project, $email, t('admin.login.invite_mail_subject'), $html);
}

function handle_accept_invite(PDO $db): void {
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    $stmt = $db->prepare("SELECT * FROM users WHERE invite_token = ? AND invite_token != '' AND status = 'invited' AND (invite_token_expires_at IS NULL OR invite_token_expires_at > CURRENT_TIMESTAMP)");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    $error = null;
    if ($user && isset($_POST['action']) && $_POST['action'] === 'set_password') {
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($pass) < 8) {
            $error = t('admin.login.password_too_short');
        } elseif ($pass !== $confirm) {
            $error = t('admin.login.passwords_mismatch');
        } else {
            $upd = $db->prepare("UPDATE users SET password_hash = ?, status = 'active', invite_token = '', activated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);

            session_regenerate_id(true);
            $_SESSION['paragrafy_admin'] = true;
            $_SESSION['paragrafy_user_id'] = (int)$user['id'];
            $_SESSION['paragrafy_user_name'] = $user['name'];
            $_SESSION['paragrafy_user_email'] = $user['email'];
            header('Location: /admin');
            exit;
        }
    }

    render_accept_invite_view($user, $error);
}

function render_accept_invite_view(?array $user, ?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.login.accept_invite.page_title')) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: var(--card); padding: 2.25rem; border-radius: var(--radius); width: 360px; box-shadow: none; border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: var(--radius); }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
            .err { color: var(--red); font-size: 0.8125rem; margin-bottom: 0.5rem; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2><?= htmlspecialchars(t('admin.login.accept_invite.heading')) ?></h2>
            </div>
            <?php if (!$user): ?>
                <p style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars(t('admin.login.accept_invite.invalid_token')) ?></p>
            <?php else: ?>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 6px"><?= t('admin.login.accept_invite.welcome', ['name' => '<strong style="color:var(--text)">' . htmlspecialchars($user['name']) . '</strong>', 'email' => '<strong style="color:var(--text)">' . htmlspecialchars($user['email']) . '</strong>']) ?></p>
                <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="set_password">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">
                    <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.accept_invite.password_label')) ?></label>
                    <input type="password" name="password" required style="width:100%;margin-bottom:10px;">
                    <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.accept_invite.password_confirm_label')) ?></label>
                    <input type="password" name="password_confirm" required style="width:100%;margin-bottom:1rem;">
                    <button type="submit" class="pg-btn" style="width:100%;justify-content:center;"><?= t('admin.login.accept_invite.submit_button') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function handle_forgot_password(PDO $db): void {
    $sent = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim(strtolower($_POST['email'] ?? ''));
        if ($email !== '') {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $upd = $db->prepare("UPDATE users SET invite_token = ?, invite_token_expires_at = ? WHERE id = ?");
                $upd->execute([$token, $expiresAt, $user['id']]);

                $project = $db->query("SELECT * FROM projects ORDER BY id ASC LIMIT 1")->fetch();
                if ($project) {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? $project['domain'];
                    $resetLink = $scheme . '://' . $host . '/admin/reset-password?token=' . $token;
                    $html = "<h2>" . htmlspecialchars(t('admin.login.forgot.mail_heading')) . "</h2>"
                        . "<p>" . htmlspecialchars(t('admin.login.forgot.mail_greeting', ['name' => $user['name']])) . "</p>"
                        . "<p>" . htmlspecialchars(t('admin.login.forgot.mail_body')) . "</p>"
                        . "<p><a href='" . htmlspecialchars($resetLink) . "' style='background:#F0A63C;color:#fff;padding:0.6rem 1.2rem;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold;'>" . htmlspecialchars(t('admin.login.forgot.mail_button')) . "</a></p>"
                        . "<p>" . htmlspecialchars(t('admin.login.forgot.mail_ignore_hint')) . "</p>";
                    send_smtp_mail($project, $user['email'], t('admin.login.forgot.mail_subject'), $html);
                }
            }
        }
        $sent = true;
    }

    render_forgot_password_view($sent);
}

function render_forgot_password_view(bool $sent): void {
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.login.forgot.page_title')) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: var(--card); padding: 2.25rem; border-radius: var(--radius); width: 360px; box-shadow: none; border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: var(--radius); }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2><?= htmlspecialchars(t('admin.login.forgot.heading')) ?></h2>
            </div>
            <?php if ($sent): ?>
                <p style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars(t('admin.login.forgot.sent_notice')) ?></p>
                <div style="text-align:center;margin-top:14px">
                    <a href="/admin" style="font-size:12.5px;color:var(--text-faint)"><?= t('admin.login.forgot.back_to_login') ?></a>
                </div>
            <?php else: ?>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 6px"><?= htmlspecialchars(t('admin.login.forgot.intro')) ?></p>
                <form method="post">
                    <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.forgot.email_label')) ?></label>
                    <input type="email" name="email" placeholder="<?= htmlspecialchars(t('admin.login.email_placeholder')) ?>" required autofocus style="width:100%;margin-bottom:1rem;">
                    <button type="submit" class="pg-btn" style="width:100%;justify-content:center;"><?= t('admin.login.forgot.submit_button') ?></button>
                </form>
                <div style="text-align:center;margin-top:14px">
                    <a href="/admin" style="font-size:12.5px;color:var(--text-faint)"><?= t('admin.login.forgot.back_to_login') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function handle_reset_password(PDO $db): void {
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    $stmt = $db->prepare("SELECT * FROM users WHERE invite_token = ? AND invite_token != '' AND status = 'active' AND (invite_token_expires_at IS NULL OR invite_token_expires_at > CURRENT_TIMESTAMP)");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    $error = null;
    if ($user && isset($_POST['action']) && $_POST['action'] === 'set_new_password') {
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($pass) < 8) {
            $error = t('admin.login.password_too_short');
        } elseif ($pass !== $confirm) {
            $error = t('admin.login.passwords_mismatch');
        } else {
            $upd = $db->prepare("UPDATE users SET password_hash = ?, invite_token = '' WHERE id = ?");
            $upd->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
            log_audit(null, '', t('admin.login.reset.audit_note', ['name' => $user['name']]));

            session_regenerate_id(true);
            $_SESSION['paragrafy_admin'] = true;
            $_SESSION['paragrafy_user_id'] = (int)$user['id'];
            $_SESSION['paragrafy_user_name'] = $user['name'];
            $_SESSION['paragrafy_user_email'] = $user['email'];
            header('Location: /admin');
            exit;
        }
    }

    render_reset_password_view($user, $error);
}

function render_reset_password_view(?array $user, ?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.login.reset.page_title')) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: var(--card); padding: 2.25rem; border-radius: var(--radius); width: 360px; box-shadow: none; border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: var(--radius); }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
            .err { color: var(--red); font-size: 0.8125rem; margin-bottom: 0.5rem; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2><?= htmlspecialchars(t('admin.login.reset.heading')) ?></h2>
            </div>
            <?php if (!$user): ?>
                <p style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars(t('admin.login.reset.invalid_link')) ?></p>
                <div style="text-align:center;margin-top:14px">
                    <a href="/admin/forgot-password" style="font-size:12.5px;color:var(--text-faint)"><?= t('admin.login.reset.request_new_link') ?></a>
                </div>
            <?php else: ?>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 6px"><?= t('admin.login.reset.intro', ['email' => '<strong style="color:var(--text)">' . htmlspecialchars($user['email']) . '</strong>']) ?></p>
                <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="set_new_password">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">
                    <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.reset.password_label')) ?></label>
                    <input type="password" name="password" required style="width:100%;margin-bottom:10px;">
                    <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.reset.password_confirm_label')) ?></label>
                    <input type="password" name="password_confirm" required style="width:100%;margin-bottom:1rem;">
                    <button type="submit" class="pg-btn" style="width:100%;justify-content:center;"><?= t('admin.login.reset.submit_button') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function handle_sso_login(array $config): void {
    $ssoSecret = $config['sso_secret'] ?? '';
    if ($ssoSecret === '') {
        header('Location: /admin');
        return;
    }

    $token = $_GET['token'] ?? '';
    $parts = explode('.', $token);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        header('Location: /admin');
        return;
    }
    [$payloadB64Url, $signature] = $parts;

    $expectedSignature = hash_hmac('sha256', $payloadB64Url, $ssoSecret);
    if (!hash_equals($expectedSignature, $signature)) {
        header('Location: /admin');
        return;
    }

    $base64 = strtr($payloadB64Url, '-_', '+/');
    $padded = $base64 . str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $json = base64_decode($padded, true);
    $payload = $json === false ? null : json_decode($json, true);

    if (!is_array($payload) || !isset($payload['exp']) || !is_int($payload['exp']) || !isset($payload['n']) || !is_string($payload['n']) || $payload['n'] === '') {
        header('Location: /admin');
        return;
    }

    if ($payload['exp'] < time()) {
        header('Location: /admin');
        return;
    }

    // Signatur und Ablauf sind bereits geprueft -- ab hier ist das Token
    // "echt" und darf die Nonce-Tabelle beruehren. Die Reihenfolge ist
    // wichtig: wuerde die Nonce-Pruefung vor der Signaturpruefung laufen,
    // koennte ein beliebiges, nicht signiertes Token die Tabelle befuellen
    // (kleiner DoS-Vektor).
    $db = get_db();
    try {
        $db->prepare('INSERT INTO sso_nonces (nonce) VALUES (?)')->execute([$payload['n']]);
    } catch (\PDOException $e) {
        // UNIQUE-Constraint-Verletzung (SQLite Code 19) = Nonce wurde bereits
        // eingeloest -- Replay ablehnen. Das INSERT ist selbst der atomare
        // "pruefen + als benutzt markieren"-Schritt, SQLite serialisiert
        // Writes ohnehin, kein zusaetzliches Locking noetig.
        http_response_code(403);
        exit(t('admin.sso.token_already_used'));
    }

    // Gelegentliches, beilaeufiges Aufraeumen abgelaufener Nonces -- diese
    // sind laengst nicht mehr einloesbar, die Zeile dient nur noch der
    // Replay-Erkennung waehrend der TTL. Retention ist unkritisch (keine
    // personenbezogenen Daten, nur Zufallsstrings + Zeitstempel).
    if (random_int(1, 20) === 1) {
        try {
            $db->exec("DELETE FROM sso_nonces WHERE used_at < datetime('now', '-1 hour')");
        } catch (\PDOException $e) {
        }
    }

    session_regenerate_id(true);
    $_SESSION['paragrafy_admin'] = true;
    $_SESSION['paragrafy_user_name'] = 'Admin';
    unset($_SESSION['paragrafy_user_id'], $_SESSION['paragrafy_user_email']);
    $projectId = (int)($_GET['project_id'] ?? 0);
    header('Location: /admin' . ($projectId > 0 ? '?project_id=' . $projectId : ''));
    exit;
}

function finalize_user_session(array $user): void {
    session_regenerate_id(true);
    $_SESSION['paragrafy_admin'] = true;
    $_SESSION['paragrafy_user_id'] = (int)$user['id'];
    $_SESSION['paragrafy_user_name'] = $user['name'];
    $_SESSION['paragrafy_user_email'] = $user['email'];
    $_SESSION['paragrafy_user_locale'] = $user['locale'] ?? 'de';
}

function finalize_admin_session(): void {
    session_regenerate_id(true);
    $_SESSION['paragrafy_admin'] = true;
    $_SESSION['paragrafy_user_name'] = 'Admin';
    unset($_SESSION['paragrafy_user_id'], $_SESSION['paragrafy_user_email'], $_SESSION['paragrafy_user_locale']);
}

/**
 * Second step of the login flow once a user's or the admin's password has
 * already been verified and totp_pending was stashed in the session. Tries
 * the 6-digit TOTP code first, falls back to a recovery code. Uses the same
 * rate_limit_wait()/login_attempts machinery as the password step, under a
 * "totp:"-prefixed identifier so a flood of bad codes can't be used to
 * bypass the password throttle (and vice versa).
 */
function handle_totp_verify(PDO $db): ?string {
    $pending = $_SESSION['totp_pending'] ?? null;
    if (!is_array($pending) || ($pending['started_at'] ?? 0) < time() - 300) {
        unset($_SESSION['totp_pending']);
        header('Location: /admin');
        exit;
    }

    $clientIp = get_client_ip();
    $type = $pending['type'] ?? '';
    $acctIdentifier = $type === 'user' ? ('totp:acct:user:' . (int)($pending['user_id'] ?? 0)) : 'totp:acct:admin';
    $waitSeconds = max(
        rate_limit_wait($db, 'totp:' . $clientIp, LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_MINUTES),
        rate_limit_wait($db, $acctIdentifier, LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_MINUTES)
    );
    if ($waitSeconds > 0) {
        return t('admin.login.rate_limited', ['minutes' => (int)ceil($waitSeconds / 60)]);
    }

    $input = trim($_POST['totp_code'] ?? '');
    $success = false;

    if ($type === 'user') {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([(int)($pending['user_id'] ?? 0)]);
        $user = $stmt->fetch();
        if ($user) {
            $success = totp_finish_user_login($db, $user, $input);
        }
    } elseif ($type === 'admin' && admin_totp_available()) {
        $success = totp_finish_admin_login($input);
    }

    if ($success) {
        clear_login_failures($db, $clientIp);
        clear_login_failures($db, $acctIdentifier);
        unset($_SESSION['totp_pending']);
        header('Location: /admin');
        exit;
    }

    record_login_failure($db, $clientIp);
    record_login_failure($db, $acctIdentifier);
    return t('admin.login.totp_invalid');
}

function totp_finish_user_login(PDO $db, array $user, string $input): bool {
    if (totp_consume_for_user_login($db, $user, $input)) {
        finalize_user_session($user);
        return true;
    }
    return false;
}

function totp_finish_admin_login(string $input): bool {
    if (totp_consume_for_admin_login($input)) {
        finalize_admin_session();
        return true;
    }
    return false;
}

// TOTP Self-Service (/admin/security) -- gilt fuer den eingeloggten Account
// (regulaerer User oder, nur self-hosted, der primaere Admin). totp_current_identity()
// liefert null, wenn TOTP hier gar nicht angeboten wird (Admin auf Managed Cloud).
if (isset($_POST['action']) && $_POST['action'] === 'totp_setup_start') {
    $identity = totp_current_identity($db);
    if ($identity !== null && !totp_identity_enabled($identity) && totp_vendor_available()) {
        $_SESSION['totp_setup'] = ['secret' => totp_generate_secret()];
    }
    header("Location: /admin/security?project_id=$projectId");
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'totp_setup_cancel') {
    unset($_SESSION['totp_setup']);
    header("Location: /admin/security?project_id=$projectId");
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'totp_setup_confirm') {
    $identity = totp_current_identity($db);
    $pendingSecret = $_SESSION['totp_setup']['secret'] ?? null;
    $code = trim($_POST['totp_code'] ?? '');
    if ($identity === null || totp_identity_enabled($identity) || !is_string($pendingSecret)) {
        header("Location: /admin/security?project_id=$projectId");
        exit;
    }
    if (totp_verify_and_consume($pendingSecret, $code, null) === null) {
        header("Location: /admin/security?project_id=$projectId&msg=totp_setup_invalid_code");
        exit;
    }
    $recoveryCodes = totp_generate_recovery_codes();
    try {
        // totp_encrypt_secret() can itself throw if the totp_encryption_key
        // couldn't be persisted on its first-ever use (see
        // ensure_totp_encryption_key()) -- treat that exactly like
        // totp_identity_complete_setup() returning false below: do NOT show
        // recovery codes or log "enabled", nothing was actually saved, and
        // telling the user otherwise would make them believe the account is
        // protected when it isn't. Leave $_SESSION['totp_setup']['secret']
        // in place so they can simply retry the confirmation.
        $persisted = totp_identity_complete_setup($db, $identity, totp_encrypt_secret($pendingSecret), totp_hash_recovery_codes($recoveryCodes));
    } catch (\RuntimeException $e) {
        $persisted = false;
    }
    if (!$persisted) {
        header("Location: /admin/security?project_id=$projectId&msg=totp_setup_persist_failed");
        exit;
    }
    $_SESSION['totp_setup'] = ['recovery_codes' => $recoveryCodes];
    log_audit(null, '', t('admin.security.audit.totp_enabled', ['who' => $identity['type'] === 'user' ? $identity['row']['email'] : 'Admin']));
    header("Location: /admin/security?project_id=$projectId");
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'totp_setup_ack') {
    unset($_SESSION['totp_setup']);
    header("Location: /admin/security?project_id=$projectId&msg=totp_enabled");
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'totp_regenerate_codes') {
    $identity = totp_current_identity($db);
    if ($identity === null || !totp_identity_enabled($identity)) {
        header("Location: /admin/security?project_id=$projectId");
        exit;
    }
    $recoveryCodes = totp_generate_recovery_codes();
    if (!totp_identity_replace_recovery_codes($db, $identity, totp_hash_recovery_codes($recoveryCodes))) {
        header("Location: /admin/security?project_id=$projectId&msg=totp_setup_persist_failed");
        exit;
    }
    $_SESSION['totp_setup'] = ['recovery_codes' => $recoveryCodes];
    log_audit(null, '', t('admin.security.audit.totp_codes_regenerated', ['who' => $identity['type'] === 'user' ? $identity['row']['email'] : 'Admin']));
    header("Location: /admin/security?project_id=$projectId");
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'totp_disable') {
    $identity = totp_current_identity($db);
    $pass = $_POST['confirm_password'] ?? '';
    if ($identity !== null && totp_identity_enabled($identity) && $pass !== '' && password_verify($pass, totp_identity_password_hash($identity, $config))) {
        if (!totp_identity_disable($db, $identity)) {
            header("Location: /admin/security?project_id=$projectId&msg=totp_setup_persist_failed");
            exit;
        }
        unset($_SESSION['totp_setup']);
        log_audit(null, '', t('admin.security.audit.totp_disabled', ['who' => $identity['type'] === 'user' ? $identity['row']['email'] : 'Admin']));
        header("Location: /admin/security?project_id=$projectId&msg=totp_disabled");
        exit;
    }
    header("Location: /admin/security?project_id=$projectId&msg=totp_disable_wrong_password");
    exit;
}

// Admin-seitiger TOTP-Reset fuer einen regulaeren User-Account (Geraet/Recovery-Codes
// verloren). Nur der primaere Admin darf das; der betroffene User bekommt eine
// Benachrichtigungsmail, ausserdem ein Audit-Log-Eintrag.
if (isset($_POST['action']) && $_POST['action'] === 'reset_user_totp') {
    if (!current_user_is_primary_admin()) {
        http_response_code(403);
        echo htmlspecialchars(t('admin.common.access_denied'));
        exit;
    }
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch();
    if ($targetUser) {
        $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery_codes = NULL, totp_last_used_step = NULL WHERE id = ?")
            ->execute([$targetUserId]);
        send_smtp_mail(
            $project,
            $targetUser['email'],
            t('admin.security.reset_mail.subject'),
            '<p>' . htmlspecialchars(t('admin.security.reset_mail.body', ['name' => $targetUser['name']])) . '</p>'
        );
        log_audit(null, '', t('admin.users.audit.totp_reset', ['name' => $targetUser['name'], 'email' => $targetUser['email']]));
    }
    header('Location: /admin/users?msg=totp_reset');
    exit;
}

$subRoute = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (str_starts_with($subRoute, '/admin/edit')) {
    require_once __DIR__ . '/editor.php';
    exit;
}

if (str_starts_with($subRoute, '/admin/settings')) {
    render_settings_view($db, $project, $projects);
    exit;
}

if (str_starts_with($subRoute, '/admin/security')) {
    render_security_view($db, $project, $projects);
    exit;
}

if (str_starts_with($subRoute, '/admin/users')) {
    if (!current_user_is_primary_admin()) {
        http_response_code(403);
        echo htmlspecialchars(t('admin.common.access_denied'));
        exit;
    }
    render_users_view($db, $project, $projects);
    exit;
}

if (str_starts_with($subRoute, '/admin/audit')) {
    render_audit_view($db, $project, $projects);
    exit;
}

if (str_starts_with($subRoute, '/admin/consent-log')) {
    render_consent_log_view($db, $project, $projects);
    exit;
}

render_matrix_view($db, $project, $projects);

function render_login_view(?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.login.page_title')) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: var(--card); padding: 2.25rem; border-radius: var(--radius); width: 340px; box-shadow: none; border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: var(--radius); }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
            .err { color: var(--red); font-size: 0.8125rem; margin-bottom: 0.5rem; }
            .login-disclaimer { max-width: 340px; text-align: center; font-size: 11px; color: var(--text-faintest); line-height: 1.5; }
        </style>
    </head>
    <body>
        <?php $demoBanner = render_demo_countdown_banner(); ?>
        <?php if ($demoBanner !== ''): ?>
            <div style="width:340px;box-sizing:border-box;"><?= $demoBanner ?></div>
        <?php endif; ?>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2><?= htmlspecialchars(t('admin.login.heading')) ?></h2>
            </div>
            <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.email_label')) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.login.email_hint')) ?></span></label>
                <input type="email" name="email" placeholder="<?= htmlspecialchars(t('admin.login.email_placeholder')) ?>" style="width:100%;margin-bottom:10px;">
                <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.password_label')) ?></label>
                <input type="password" name="password" placeholder="<?= htmlspecialchars(t('admin.login.password_placeholder')) ?>" autofocus required style="width:100%;margin-bottom:1rem;">
                <button type="submit" class="pg-btn" style="width:100%;justify-content:center;"><?= t('admin.login.submit_button') ?></button>
            </form>
            <div style="text-align:center;margin-top:14px">
                <a href="/admin/forgot-password" style="font-size:12.5px;color:var(--text-faint)"><?= htmlspecialchars(t('admin.login.forgot_password')) ?></a>
            </div>
        </div>
        <div class="login-disclaimer"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
    </body>
    </html>
    <?php
}

function render_totp_view(?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.login.totp.page_title')) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: var(--card); padding: 2.25rem; border-radius: var(--radius); width: 340px; box-shadow: none; border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: var(--radius); }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
            .err { color: var(--red); font-size: 0.8125rem; margin-bottom: 0.5rem; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2><?= htmlspecialchars(t('admin.login.totp.heading')) ?></h2>
            </div>
            <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 14px"><?= htmlspecialchars(t('admin.login.totp.hint')) ?></p>
            <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="totp_verify">
                <label class="pg-label" style="margin-top:0;"><?= htmlspecialchars(t('admin.login.totp.code_label')) ?></label>
                <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus required style="width:100%;margin-bottom:1rem;letter-spacing:2px;font-family:'JetBrains Mono',monospace">
                <button type="submit" class="pg-btn" style="width:100%;justify-content:center;"><?= t('admin.login.totp.submit_button') ?></button>
            </form>
            <div style="text-align:center;margin-top:14px">
                <a href="/admin?totp_cancel=1" style="font-size:12.5px;color:var(--text-faint)"><?= htmlspecialchars(t('admin.login.totp.back_link')) ?></a>
            </div>
        </div>
        <div class="login-disclaimer"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
    </body>
    </html>
    <?php
}

function render_no_access_view(): void {
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.no_access.page_title')) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .no-access-card { background: var(--card); padding: 2.25rem; border-radius: var(--radius); width: 360px; text-align: center; border: 1px solid var(--border); }
        </style>
    </head>
    <body>
        <div class="no-access-card">
            <h2 style="margin:0 0 10px"><?= htmlspecialchars(t('admin.no_access.heading')) ?></h2>
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 18px"><?= htmlspecialchars(t('admin.no_access.body')) ?></p>
            <a href="/admin?logout=1" class="pg-btn-secondary" style="display:inline-block"><?= htmlspecialchars(t('admin.common.sidebar.logout_title')) ?></a>
        </div>
    </body>
    </html>
    <?php
}

function render_matrix_view(PDO $db, array $project, array $projects): void {
    $docTypes = $db->query("SELECT * FROM doc_types ORDER BY is_required DESC, title ASC")->fetchAll();
    $activeLangs = array_filter(array_map('trim', explode(',', $project['active_languages'] ?? 'de,en')));
    $primaryLang = $project['primary_lang'] ?: 'de';
    $auditIntervalMonths = (int)($project['audit_interval_months'] ?? 12);

    $unfilledWarnings = [];
    $auditWarnings = [];
    $totalRequired = 0;
    $publishedRequired = 0;

    $stmt = $db->prepare("
        SELECT t.content, dt.title, t.lang, t.updated_at, t.source_hash, dt.is_required, t.document_id
        FROM translations t 
        JOIN documents d ON t.document_id = d.id 
        JOIN doc_types dt ON d.doc_type_id = dt.id 
        WHERE d.project_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$project['id']]);
    $allPublished = $stmt->fetchAll();

    $now = new DateTime();
    foreach ($allPublished as $row) {
        $unfilled = check_unfilled_placeholders($row['content'], $project);
        if (!empty($unfilled)) {
            $unfilledWarnings[] = t('admin.matrix.unfilled_warning_line', ['title' => $row['title'], 'lang' => strtoupper($row['lang']), 'placeholders' => implode(', ', $unfilled)]);
        }

        if (!empty($row['updated_at'])) {
            $updated = new DateTime($row['updated_at']);
            $diffMonths = (($now->format('Y') - $updated->format('Y')) * 12) + ($now->format('m') - $updated->format('m'));
            if ($diffMonths >= $auditIntervalMonths) {
                $days = $now->diff($updated)->days;
                $auditWarnings[] = t('admin.matrix.audit_warning_line', ['title' => $row['title'], 'lang' => strtoupper($row['lang']), 'days' => $days]);
            }
        }
    }

    foreach ($docTypes as $t) {
        if ($t['is_required']) {
            $totalRequired++;
            $chk = $db->prepare("SELECT COUNT(*) FROM translations t JOIN documents d ON t.document_id = d.id WHERE d.project_id = ? AND d.doc_type_id = ? AND t.lang = ? AND t.status = 'published'");
            $chk->execute([$project['id'], $t['id'], $primaryLang]);
            if ((int)$chk->fetchColumn() > 0) {
                $publishedRequired++;
            }
        }
    }
    $complianceScore = $totalRequired > 0 ? (int)round(($publishedRequired / $totalRequired) * 100) : 100;
    $isManagedCloud = !empty(get_config()['managed_cloud']);
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.matrix.page_title', ['project' => $project['name']])) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin($project['brand_color'] ?: '#F0A63C') ?>
        <style>
            .grid-add { display: grid; grid-template-columns: 2fr 1.5fr auto auto; gap: 0.75rem; align-items: center; margin-top: 10px; }
            .api-pill { background: var(--border-soft); padding: 2px 6px; border-radius: var(--radius-sm); font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('dashboard', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('admin.matrix.crumb')) ?></strong></div>
                </div>

                <div class="pg-content">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;gap:20px;flex-wrap:wrap">
                        <div>
                            <h1 style="font-size:26px;font-weight:800;margin:0 0 6px;"><?= htmlspecialchars($project['name']) ?></h1>
                            <div style="font-size:13px;color:var(--text-muted);display:flex;gap:14px;flex-wrap:wrap">
                                <span><?= htmlspecialchars(t('admin.matrix.domain_label')) ?> <strong style="color:var(--text);font-weight:600"><?= htmlspecialchars($project['domain']) ?></strong></span>
                                <span style="color:var(--border-strong)">&middot;</span>
                                <span><?= htmlspecialchars(t('admin.matrix.api_label')) ?> <span class="api-pill">/api/:lang/:slug</span></span>
                            </div>
                        </div>
                        <?php $projectLimitReached = get_project_limit() !== null && count($projects) >= get_project_limit(); ?>
                        <?php if ($isManagedCloud): ?>
                            <a href="https://app.paragrafy.cloud/dashboard" class="pg-viewer-link"><?= t('admin.matrix.cloud_new_project_link') ?></a>
                        <?php elseif ($projectLimitReached): ?>
                            <span class="pg-pill pg-pill-muted" title="<?= htmlspecialchars(t('admin.matrix.project_limit_reached_title', ['count' => (int)get_project_limit(), 'suffix' => get_project_limit() === 1 ? '' : 'e'])) ?>"><?= htmlspecialchars(t('admin.matrix.project_limit_reached_pill')) ?></span>
                        <?php else: ?>
                            <button type="button" class="pg-btn" onclick="openNewProjectModal()"><?= htmlspecialchars(t('admin.matrix.new_project_button')) ?></button>
                        <?php endif; ?>
                    </div>

                    <?php if (($_GET['msg'] ?? '') === 'project_limit_reached'): ?>
                        <div class="pg-alert pg-alert-red">
                            <div><?= t('admin.matrix.project_limit_reached_alert', ['count' => (int)get_project_limit(), 'suffix' => get_project_limit() === 1 ? '' : 'e']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (($_GET['msg'] ?? '') === 'managed_cloud_blocked'): ?>
                        <div class="pg-alert pg-alert-red">
                            <div><?= htmlspecialchars(t('admin.matrix.managed_cloud_blocked_alert')) ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Health KPI Cards -->
                    <div class="pg-kpi-grid">
                        <div class="pg-kpi">
                            <div class="pg-kpi-label"><?= htmlspecialchars(t('admin.matrix.kpi.completeness_label')) ?><?= help_icon(t('admin.matrix.kpi.completeness_help')) ?></div>
                            <div class="pg-kpi-val" style="color: <?= $complianceScore === 100 ? 'var(--green)' : 'var(--accent)' ?>;"><?= $complianceScore ?>%</div>
                            <div class="pg-kpi-sub"><?= htmlspecialchars(t('admin.matrix.kpi.completeness_sub', ['published' => $publishedRequired, 'total' => $totalRequired])) ?></div>
                        </div>
                        <div class="pg-kpi">
                            <div class="pg-kpi-label"><?= htmlspecialchars(t('admin.matrix.kpi.languages_label')) ?></div>
                            <div class="pg-kpi-val"><?= count($activeLangs) ?></div>
                            <div class="pg-kpi-sub"><?= strtoupper(implode(', ', $activeLangs)) ?></div>
                        </div>
                        <div class="pg-kpi">
                            <div class="pg-kpi-label"><?= htmlspecialchars(t('admin.matrix.kpi.sync_label')) ?><?= help_icon(t('admin.matrix.kpi.sync_help')) ?></div>
                            <div class="pg-kpi-val" style="color:var(--green);display:flex;align-items:center;gap:6px;font-size:20px;">
                                <span style="width:8px;height:8px;border-radius:50%;background:#2fa06a;display:inline-block"></span><?= htmlspecialchars(t('admin.matrix.kpi.sync_active')) ?>
                            </div>
                            <div class="pg-kpi-sub"><?= htmlspecialchars(t('admin.matrix.kpi.sync_sub')) ?></div>
                        </div>
                        <div class="pg-kpi">
                            <div class="pg-kpi-label"><?= htmlspecialchars(t('admin.matrix.kpi.audit_status_label')) ?></div>
                            <div class="pg-kpi-val" style="font-size:20px;color: <?= empty($auditWarnings) ? 'var(--green)' : '#b4650f' ?>;">
                                <?= empty($auditWarnings) ? htmlspecialchars(t('admin.matrix.kpi.audit_status_current')) : htmlspecialchars(t('admin.matrix.kpi.audit_status_due', ['count' => count($auditWarnings)])) ?>
                            </div>
                            <div class="pg-kpi-sub"><?= htmlspecialchars(t('admin.matrix.kpi.audit_status_sub', ['months' => $auditIntervalMonths])) ?></div>
                        </div>
                    </div>

                    <?php if (!empty($auditWarnings)): ?>
                        <div class="pg-alert pg-alert-red">
                            <div><?= t('admin.matrix.audit_due_alert', ['months' => $auditIntervalMonths]) ?>
                                <ul>
                                    <?php foreach (array_slice($auditWarnings, 0, 3) as $aw): ?>
                                        <li><?= htmlspecialchars($aw) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($unfilledWarnings)): ?>
                        <div class="pg-alert pg-alert-amber">
                            <div><?= t('admin.matrix.unfilled_alert') ?>
                                <ul>
                                    <?php foreach (array_slice($unfilledWarnings, 0, 3) as $w): ?>
                                        <li><?= htmlspecialchars($w) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Matrix -->
                    <div class="pg-card">
                        <div style="padding:20px 22px 14px">
                            <h2><?= htmlspecialchars(t('admin.matrix.table.heading')) ?></h2>
                            <p class="pg-card-sub"><?= htmlspecialchars(t('admin.matrix.table.subtitle')) ?></p>
                        </div>
                        <table class="pg-table">
                            <thead>
                                <tr>
                                    <th><?= htmlspecialchars(t('admin.matrix.table.col_doctype')) ?></th>
                                    <th><?= htmlspecialchars(t('admin.matrix.table.col_required')) ?></th>
                                    <?php foreach ($activeLangs as $lang): ?>
                                        <th><?= strtoupper(htmlspecialchars($lang)) ?></th>
                                    <?php endforeach; ?>
                                    <th style="text-align:right"><?= htmlspecialchars(t('admin.matrix.table.col_actions')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($docTypes as $type): ?>
                                    <?php
                                    $stmt = $db->prepare("SELECT id FROM documents WHERE project_id = ? AND doc_type_id = ?");
                                    $stmt->execute([$project['id'], $type['id']]);
                                    $doc = $stmt->fetch();
                                    if (!$doc) {
                                        $ins = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                                        $ins->execute([$project['id'], $type['id']]);
                                        $docId = (int)$db->lastInsertId();
                                    } else {
                                        $docId = (int)$doc['id'];
                                    }

                                    $stmt = $db->prepare("SELECT source_hash, content FROM translations WHERE document_id = ? AND lang = ?");
                                    $stmt->execute([$docId, $primaryLang]);
                                    $sourceRow = $stmt->fetch();
                                    $currentSourceHash = $sourceRow ? md5($sourceRow['content']) : '';

                                    $primaryUrl = "https://" . $project['domain'] . "/" . $type['slug'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;font-size:13.5px"><?= htmlspecialchars($type['title']) ?></div>
                                            <div style="display:flex;align-items:center;gap:6px;margin-top:2px;white-space:nowrap">
                                                <a href="/<?= htmlspecialchars($type['slug']) ?>" target="_blank" style="font-size:11.5px;color:var(--text-faint);font-family:'JetBrains Mono',monospace;" title="<?= htmlspecialchars(t('admin.matrix.table.primary_link_title')) ?>">/<?= htmlspecialchars($type['slug']) ?></a>
                                                <button type="button" class="pg-copy-btn" title="<?= htmlspecialchars(t('admin.matrix.table.copy_link_title')) ?>" onclick="copyToClipboard('<?= htmlspecialchars($primaryUrl) ?>', '<?= htmlspecialchars(t('admin.matrix.table.copy_link_success'), ENT_QUOTES) ?>')"><?= svg_icon('link', '', 13) ?></button>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" style="background:none;border:none;cursor:pointer;padding:0;" id="toggle_btn_<?= $type['id'] ?>" onclick="ajaxToggleRequired(<?= $type['id'] ?>)" title="<?= htmlspecialchars(t('admin.matrix.table.toggle_title')) ?>">
                                                <?php if ($type['is_required']): ?>
                                                    <span class="pg-req-label" id="span_req_<?= $type['id'] ?>"><?= htmlspecialchars(t('admin.matrix.table.required_label')) ?></span>
                                                <?php else: ?>
                                                    <span class="pg-opt-label" id="span_req_<?= $type['id'] ?>"><?= htmlspecialchars(t('admin.matrix.table.optional_label')) ?></span>
                                                <?php endif; ?>
                                            </button>
                                        </td>
                                        <?php foreach ($activeLangs as $lang): ?>
                                            <?php
                                            $stmt = $db->prepare("SELECT * FROM translations WHERE document_id = ? AND lang = ?");
                                            $stmt->execute([$docId, $lang]);
                                            $trans = $stmt->fetch();

                                            $isOutdated = false;
                                            if ($trans && $lang !== $primaryLang && !empty($trans['source_hash']) && !empty($currentSourceHash)) {
                                                if ($trans['source_hash'] !== $currentSourceHash) {
                                                    $isOutdated = true;
                                                }
                                            }
                                            ?>
                                            <td>
                                                <?php if ($trans && $trans['status'] === 'published'): ?>
                                                    <?php if ($isOutdated): ?>
                                                        <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-amber" title="<?= htmlspecialchars(t('admin.matrix.table.outdated_title')) ?>"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.matrix.table.outdated_label')) ?></a>
                                                    <?php else: ?>
                                                        <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-green" title="<?= htmlspecialchars(t('admin.matrix.table.edit_title', ['title' => $trans['title']])) ?>"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.matrix.table.live_label')) ?></a>
                                                    <?php endif; ?>
                                                <?php elseif ($trans && $trans['status'] === 'draft'): ?>
                                                    <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-amber"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.matrix.table.draft_label')) ?></a>
                                                <?php else: ?>
                                                    <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-red"><?= htmlspecialchars(t('admin.matrix.table.create_label')) ?></a>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td style="text-align:right">
                                            <div style="display:flex;justify-content:flex-end;gap:4px">
                                                <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= htmlspecialchars($primaryLang) ?>" class="pg-icon-btn" title="<?= htmlspecialchars(t('admin.matrix.table.edit_icon_title')) ?>">
                                                    <?= svg_icon('edit', '', 14) ?>
                                                </a>
                                                <?php if (!in_array($type['slug'], ['impressum', 'privacy'])): ?>
                                                    <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('admin.matrix.table.confirm_delete_doctype'), ENT_QUOTES) ?>');" style="margin:0;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_doc_type">
                                                        <input type="hidden" name="doc_type_id" value="<?= $type['id'] ?>">
                                                        <button type="submit" class="pg-icon-btn danger" title="<?= htmlspecialchars(t('admin.matrix.table.delete_title')) ?>">
                                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 4.5h9"/><path d="M6.2 4.5V3.2c0-.4.3-.7.7-.7h2.2c.4 0 .7.3.7.7v1.3"/><path d="M4.8 4.5 5.2 13c0 .4.3.7.7.7h4c.4 0 .7-.3.7-.7l.4-8.5"/><path d="M6.7 7.3v3.6"/><path d="M9.3 7.3v3.6"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Neuer Rechtstext hinzufügen -->
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <h2><?= htmlspecialchars(t('admin.matrix.add.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:14px"><?= htmlspecialchars(t('admin.matrix.add.subtitle')) ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_doc_type">
                            <div class="grid-add">
                                <input type="text" name="doc_title" placeholder="<?= htmlspecialchars(t('admin.matrix.add.title_placeholder')) ?>" required>
                                <input type="text" name="doc_slug" placeholder="<?= htmlspecialchars(t('admin.matrix.add.slug_placeholder')) ?>" required>
                                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--text-muted);white-space:nowrap;cursor:pointer;">
                                    <input type="checkbox" name="is_required" value="1"> <?= htmlspecialchars(t('admin.matrix.add.required_label')) ?>
                                </label>
                                <button type="submit" class="pg-btn"><?= htmlspecialchars(t('admin.matrix.add.submit_button')) ?></button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
            </div>
        </div>

        <!-- Modal: Neues Projekt anlegen -->
        <div id="newProjectModal" class="pg-modal-backdrop" onclick="if(event.target === this) closeNewProjectModal()">
            <div class="pg-modal">
                <h2 style="font-size:19px;font-weight:800;margin:0 0 6px"><?= htmlspecialchars(t('admin.matrix.new_project_modal.heading')) ?></h2>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px"><?= htmlspecialchars(t('admin.matrix.new_project_modal.desc')) ?></p>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_project">

                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.matrix.new_project_modal.name_label')) ?></label>
                    <input type="text" name="new_project_name" placeholder="<?= htmlspecialchars(t('admin.matrix.new_project_modal.name_placeholder')) ?>" required style="width:100%">

                    <label class="pg-label"><?= htmlspecialchars(t('admin.matrix.new_project_modal.domain_label')) ?></label>
                    <input type="text" name="new_project_domain" placeholder="<?= htmlspecialchars(t('admin.matrix.new_project_modal.domain_placeholder')) ?>" required style="width:100%">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div>
                            <label class="pg-label"><?= htmlspecialchars(t('admin.matrix.new_project_modal.primary_lang_label')) ?></label>
                            <select name="new_primary_lang" style="width:100%">
                                <option value="de" selected><?= htmlspecialchars(t('install.section2.lang_de')) ?></option>
                                <option value="en"><?= htmlspecialchars(t('install.section2.lang_en')) ?></option>
                                <option value="es"><?= htmlspecialchars(t('install.section2.lang_es')) ?></option>
                                <option value="fr"><?= htmlspecialchars(t('install.section2.lang_fr')) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="pg-label"><?= htmlspecialchars(t('admin.matrix.new_project_modal.color_label')) ?></label>
                            <input type="text" name="new_brand_color" value="#F0A63C" style="width:100%;font-family:'JetBrains Mono',monospace">
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:26px">
                        <button type="button" class="pg-btn-secondary" onclick="closeNewProjectModal()"><?= htmlspecialchars(t('admin.matrix.new_project_modal.cancel_button')) ?></button>
                        <button type="submit" class="pg-btn"><?= t('admin.matrix.new_project_modal.submit_button') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div id="toastNotification" class="pg-toast">
            <?= svg_icon('check', '', 16) ?> <span id="toastMsg"><?= htmlspecialchars(t('admin.matrix.toast.status_updated')) ?></span>
        </div>

        <script>
            const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
            const i18n = {
                copyFailed: <?= json_encode(t('admin.matrix.js.copy_failed')) ?>,
                markedRequired: <?= json_encode(t('admin.matrix.js.marked_required')) ?>,
                markedOptional: <?= json_encode(t('admin.matrix.js.marked_optional')) ?>,
                requiredLabel: <?= json_encode(t('admin.matrix.table.required_label')) ?>,
                optionalLabel: <?= json_encode(t('admin.matrix.table.optional_label')) ?>,
                copySuccess: <?= json_encode(t('admin.matrix.js.copy_success')) ?>
            };

            function openNewProjectModal() {
                document.getElementById('newProjectModal').style.display = 'flex';
            }

            function closeNewProjectModal() {
                document.getElementById('newProjectModal').style.display = 'none';
            }

            function showToast(msg) {
                const toast = document.getElementById('toastNotification');
                document.getElementById('toastMsg').innerText = msg;
                toast.classList.add('show');
                setTimeout(() => { toast.classList.remove('show'); }, 2500);
            }

            function copyToClipboard(text, successMsg = i18n.copySuccess) {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(() => {
                        showToast(successMsg);
                    }).catch(() => {
                        fallbackCopy(text, successMsg);
                    });
                } else {
                    fallbackCopy(text, successMsg);
                }
            }

            function fallbackCopy(text, successMsg) {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast(successMsg);
                } catch (err) {
                    alert(i18n.copyFailed);
                }
                document.body.removeChild(textArea);
            }

            async function ajaxToggleRequired(id) {
                const fd = new FormData();
                fd.append('action', 'toggle_required');
                fd.append('doc_type_id', id);
                fd.append('csrf_token', CSRF_TOKEN);

                try {
                    const res = await fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();
                    if (data.success) {
                        const span = document.getElementById('span_req_' + id);
                        if (data.is_required === 1) {
                            span.className = 'pg-req-label';
                            span.innerHTML = i18n.requiredLabel;
                            showToast(i18n.markedRequired);
                        } else {
                            span.className = 'pg-opt-label';
                            span.innerHTML = i18n.optionalLabel;
                            showToast(i18n.markedOptional);
                        }
                    }
                } catch(e) {
                    location.reload();
                }
            }

        </script>
    </body>
    </html>
    <?php
}

function render_settings_view(PDO $db, array $project, array $projects): void {
    $env = load_env_file();
    $envDeepl = $env['DEEPL_API_KEY'] ?? '';
    $envAiKey = !empty($project['ai_api_key']) ? '' : (($project['ai_provider'] ?? '') === 'openai' ? ($env['OPENAI_API_KEY'] ?? '') : ($env['CLAUDE_API_KEY'] ?? ($env['OPENAI_API_KEY'] ?? '')));

    // Letzte Webhook-Logs für dieses Projekt laden
    $stmtLogs = $db->prepare("SELECT * FROM webhook_logs WHERE project_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmtLogs->execute([$project['id']]);
    $logs = $stmtLogs->fetchAll();
    $backups = list_backups();
    $backupMsg = $_GET['msg'] ?? '';
    $cronSecret = ensure_cron_secret();
    $cronScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $cronHost = $_SERVER['HTTP_HOST'] ?? $project['domain'];
    $cronBase = $cronScheme . '://' . $cronHost;
    $queueSummary = webhook_queue_summary($db, $project['id']);
    $isManagedCloud = !empty(get_config()['managed_cloud']);
    $isDemo = !empty(get_config()['is_demo']);
    $isLockedInstance = $isManagedCloud || $isDemo;
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.settings.page_title', ['project' => $project['name']])) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin($project['brand_color'] ?: '#F0A63C') ?>
        <style>
            .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
            label.pg-label { margin-top: 14px; }
            hr.pg-sep { margin: 24px 0 18px; border: none; border-top: 1px solid var(--border-soft); }
            .btn-test { background: var(--text); color: var(--bg); border: none; padding: 0.5rem 1rem; border-radius: var(--radius); font-size: 0.8125rem; cursor: pointer; font-weight: 700; margin-top: 0.5rem; display: inline-flex; align-items: center; gap: 0.35rem; }
            .btn-test:hover { opacity: .85; }
            .beta-badge-inline { display: inline-block; background: var(--amber-bg); color: var(--amber); font-size: 10px; font-weight: 800; letter-spacing: 0.04em; padding: 2px 6px; border-radius: 999px; vertical-align: middle; margin-left: 4px; }

            /* Webhook Log Table */
            .log-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 13px; }
            .log-table th, .log-table td { padding: 10px 0; border-bottom: 1px solid var(--border-soft); text-align: left; }
            .log-table th { color: var(--text-faint); font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; }
            .status-badge { padding: 3px 8px; border-radius: var(--radius-sm); border: 1px solid currentColor; font-weight: 600; font-family: 'JetBrains Mono', monospace; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
            .status-200 { background: transparent; color: var(--green); }
            .status-err { background: transparent; color: var(--red); }
            .payload-preview { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-muted); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

            .pg-theme-toggle { display: inline-flex; border: 1px solid var(--border-strong); border-radius: var(--radius); overflow: hidden; }
            .pg-theme-toggle button { border: none; background: var(--card); color: var(--text-muted); padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; }
            .pg-theme-toggle button + button { border-left: 1px solid var(--border-strong); }
            .pg-theme-toggle button.active { background: var(--accent-bg); color: var(--accent); }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('settings', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('admin.settings.crumb')) ?></strong></div>
                </div>

                <div class="pg-content" style="max-width:840px">
                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.appearance.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= htmlspecialchars(t('admin.settings.appearance.subtitle')) ?></p>
                        <div class="pg-theme-toggle" id="themeToggle">
                            <button type="button" data-theme-choice="light"><?= htmlspecialchars(t('admin.settings.appearance.light')) ?></button>
                            <button type="button" data-theme-choice="dark"><?= htmlspecialchars(t('admin.settings.appearance.dark')) ?></button>
                            <button type="button" data-theme-choice="auto"><?= htmlspecialchars(t('admin.settings.appearance.auto')) ?></button>
                        </div>
                    </div>

                    <?php if (!empty($_SESSION['paragrafy_user_id'])): ?>
                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.locale.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= htmlspecialchars(t('admin.settings.locale.subtitle')) ?></p>
                        <?php if (($_GET['msg'] ?? '') === 'locale_saved'): ?>
                            <p style="font-size:12px;color:var(--green);margin:0 0 12px"><?= htmlspecialchars(t('admin.settings.locale.saved_msg')) ?></p>
                        <?php endif; ?>
                        <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_locale">
                            <label class="pg-label" style="margin:0"><?= htmlspecialchars(t('admin.settings.locale.label')) ?></label>
                            <select name="locale">
                                <?php foreach (ui_locales() as $code => $meta): ?>
                                    <option value="<?= htmlspecialchars($code) ?>" <?= current_locale() === $code ? 'selected' : '' ?>><?= htmlspecialchars(($meta['flag'] ?? '') . ' ' . ($meta['label'] ?? strtoupper($code))) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="pg-btn-secondary"><?= htmlspecialchars(t('admin.settings.locale.save_button')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="pg-card pg-card-pad">
                        <?php if ($isManagedCloud): ?>
                            <h2><?= htmlspecialchars(t('admin.settings.automation.cloud_heading')) ?></h2>
                            <p class="pg-card-sub"><?= htmlspecialchars(t('admin.settings.automation.cloud_subtitle')) ?></p>
                        <?php elseif ($isDemo): ?>
                            <h2><?= htmlspecialchars(t('admin.settings.automation.demo_heading')) ?></h2>
                            <p class="pg-card-sub"><?= htmlspecialchars(t('admin.settings.automation.demo_subtitle')) ?></p>
                        <?php else: ?>
                            <h2><?= htmlspecialchars(t('admin.settings.automation.cron_heading')) ?></h2>
                            <p class="pg-card-sub" style="margin-bottom:16px"><?= t('admin.settings.automation.cron_subtitle') ?></p>

                            <?php if (($_GET['msg'] ?? '') === 'cron_secret_regenerated'): ?>
                                <p style="font-size:12px;color:var(--green);margin:0 0 12px"><?= htmlspecialchars(t('admin.settings.automation.secret_regenerated')) ?></p>
                            <?php endif; ?>

                            <?php
                            $cronRows = [
                                [t('admin.settings.automation.row_publish'), '/api/cron/publish', t('admin.settings.automation.freq_every_minute'), '* * * * *'],
                                [t('admin.settings.automation.row_webhooks'), '/api/cron/webhooks', t('admin.settings.automation.freq_every_5_min'), '*/5 * * * *'],
                                [t('admin.settings.automation.row_backup'), '/api/cron/backup', t('admin.settings.automation.freq_daily_3am'), '0 3 * * *'],
                                [t('admin.settings.automation.row_audit'), '/api/cron/audit', t('admin.settings.automation.freq_daily_8am'), '0 8 * * *'],
                            ];
                            ?>
                            <p class="pg-card-sub" style="margin-bottom:12px"><?= t('admin.settings.automation.crontab_hint') ?></p>
                            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
                                <?php foreach ($cronRows as [$cronLabel, $cronPath, $cronFreq, $cronSchedule]): ?>
                                    <?php $cronLine = $cronSchedule . ' curl -fsS "' . $cronBase . $cronPath . '?secret=' . $cronSecret . '" > /dev/null'; ?>
                                    <div>
                                        <label class="pg-label" style="margin-top:0"><?= htmlspecialchars($cronLabel) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.settings.automation.recommended_suffix', ['freq' => $cronFreq])) ?></span></label>
                                        <div style="display:flex;gap:6px">
                                            <input type="text" readonly onclick="this.select()" value="<?= htmlspecialchars($cronLine) ?>" style="width:100%;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-muted)">
                                            <button type="button" class="pg-icon-btn" title="<?= htmlspecialchars(t('admin.settings.automation.copy_title')) ?>" onclick="copyToClipboard('<?= htmlspecialchars($cronLine, ENT_QUOTES) ?>')"><?= svg_icon('copy', '', 14) ?></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('admin.settings.automation.confirm_regenerate'), ENT_QUOTES) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="regenerate_cron_secret">
                                <button type="submit" class="pg-btn-secondary"><?= htmlspecialchars(t('admin.settings.automation.regenerate_button')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2 style="margin-bottom:18px"><?= t('admin.settings.project.heading') ?></h2>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_project">

                            <div class="grid">
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.project.name_label')) ?></label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.project.domain_label')) ?></label>
                                    <?php if ($isLockedInstance): ?>
                                        <input type="text" value="<?= htmlspecialchars($project['domain']) ?>" disabled style="width:100%;opacity:.6;cursor:not-allowed">
                                        <p class="pg-card-sub" style="margin-top:6px"><?= htmlspecialchars(t($isManagedCloud ? 'admin.settings.project.domain_managed_hint' : 'admin.settings.project.domain_demo_hint')) ?></p>
                                    <?php else: ?>
                                        <input type="text" name="domain" value="<?= htmlspecialchars($project['domain']) ?>" required style="width:100%">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.project.primary_lang_label')) ?></label>
                                    <input type="text" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>" required style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.project.active_langs_label')) ?></label>
                                    <input type="text" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>" required style="width:100%">
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.project.brand_color_label')) ?></label>
                                    <div style="display:flex;gap:8px;align-items:center">
                                        <input type="color" id="adm_cp" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>" style="width:34px;height:34px;padding:0;border:1px solid var(--border-strong);border-radius:var(--radius);cursor:pointer;flex-shrink:0" oninput="document.getElementById('adm_ct').value = this.value.toUpperCase();">
                                        <input type="text" id="adm_ct" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>" maxlength="7" style="flex:1;font-family:'JetBrains Mono',monospace;text-transform:uppercase" oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('adm_cp').value = this.value;">
                                    </div>
                                </div>
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.project.logo_url_label')) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.settings.project.optional_hint')) ?></span></label>
                                    <input type="text" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>" placeholder="/paragrafy.svg" style="width:100%">
                                </div>
                            </div>

                            <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-soft)">
                                <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.project.audit_interval_label')) ?></label>
                                <input type="number" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>" min="1" max="36" required style="width:100px">
                                <div class="pg-hint"><?= htmlspecialchars(t('admin.settings.project.audit_interval_hint')) ?></div>
                            </div>

                            <input type="hidden" name="cookie_banner_enabled" value="<?= !empty($project['cookie_banner_enabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="cookie_banner_text" value="<?= htmlspecialchars($project['cookie_banner_text'] ?? '') ?>">
                            <input type="hidden" name="consent_logging_enabled" value="<?= !empty($project['consent_logging_enabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="consent_log_retention_days" value="<?= htmlspecialchars((string)($project['consent_log_retention_days'] ?? 1095)) ?>">
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="">
                            <input type="hidden" name="deepl_api_key" value="">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <div style="margin-top:18px">
                                <button type="submit" class="pg-btn"><?= svg_icon('disk', '', 16) ?> <?= htmlspecialchars(t('admin.settings.project.save_button')) ?></button>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.cookie.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= t('admin.settings.cookie.subtitle') ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="">
                            <input type="hidden" name="deepl_api_key" value="">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">
                            <input type="hidden" name="consent_logging_enabled" value="<?= !empty($project['consent_logging_enabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="consent_log_retention_days" value="<?= htmlspecialchars((string)($project['consent_log_retention_days'] ?? 1095)) ?>">

                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;cursor:pointer">
                                <input type="checkbox" name="cookie_banner_enabled" value="1" <?= !empty($project['cookie_banner_enabled']) ? 'checked' : '' ?>>
                                <?= htmlspecialchars(t('admin.settings.cookie.enable_label')) ?>
                            </label>

                            <label class="pg-label"><?= htmlspecialchars(t('admin.settings.cookie.banner_text_label')) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.settings.cookie.banner_text_hint')) ?></span><?= help_icon(t('admin.settings.cookie.banner_text_help')) ?></label>
                            <textarea name="cookie_banner_text" rows="2" style="width:100%" placeholder="<?= htmlspecialchars(t('public.consent.default_text')) ?>"><?= htmlspecialchars($project['cookie_banner_text'] ?? '') ?></textarea>

                            <div style="margin-top:16px">
                                <button type="submit" class="pg-btn"><?= svg_icon('disk', '', 16) ?> <?= htmlspecialchars(t('admin.settings.project.save_button')) ?></button>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.consent_log.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= t('admin.settings.consent_log.subtitle') ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="cookie_banner_text" value="<?= htmlspecialchars($project['cookie_banner_text'] ?? '') ?>">
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="">
                            <input type="hidden" name="deepl_api_key" value="">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;cursor:pointer">
                                <input type="checkbox" name="consent_logging_enabled" value="1" <?= !empty($project['consent_logging_enabled']) ? 'checked' : '' ?>>
                                <?= htmlspecialchars(t('admin.settings.consent_log.enable_label')) ?>
                                <?= help_icon(t('admin.settings.consent_log.enable_help')) ?>
                            </label>

                            <label class="pg-label"><?= htmlspecialchars(t('admin.settings.consent_log.retention_label')) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.settings.consent_log.retention_hint')) ?></span></label>
                            <input type="number" name="consent_log_retention_days" min="0" value="<?= htmlspecialchars((string)($project['consent_log_retention_days'] ?? 1095)) ?>" style="width:120px">

                            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <button type="submit" class="pg-btn"><?= svg_icon('disk', '', 16) ?> <?= htmlspecialchars(t('admin.settings.consent_log.save_button')) ?></button>
                                <?php if (!empty($project['consent_logging_enabled'])): ?>
                                    <a href="/admin/consent-log?project_id=<?= $project['id'] ?>" class="pg-btn-secondary"><?= svg_icon('shield', '', 14) ?> <?= htmlspecialchars(t('admin.settings.consent_log.view_link')) ?></a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.email.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= htmlspecialchars(t('admin.settings.email.subtitle')) ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="cookie_banner_text" value="<?= htmlspecialchars($project['cookie_banner_text'] ?? '') ?>">
                            <input type="hidden" name="consent_logging_enabled" value="<?= !empty($project['consent_logging_enabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="consent_log_retention_days" value="<?= htmlspecialchars((string)($project['consent_log_retention_days'] ?? 1095)) ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="">
                            <input type="hidden" name="deepl_api_key" value="">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <div class="grid">
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.email.smtp_host_label')) ?></label>
                                    <input type="text" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>" placeholder="z. B. mail.deinefirma.de" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.email.port_encryption_label')) ?></label>
                                    <div style="display:flex;gap:8px">
                                        <input type="number" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>" style="width:90px">
                                        <select name="smtp_secure" style="flex:1">
                                            <option value="tls" <?= ($project['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.settings.email.tls_option')) ?></option>
                                            <option value="ssl" <?= ($project['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.settings.email.ssl_option')) ?></option>
                                            <option value="none" <?= ($project['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.settings.email.none_option')) ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.email.username_label')) ?></label>
                                    <input type="text" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>" placeholder="absender@deinedomain.de" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.email.password_label')) ?></label>
                                    <input type="password" name="smtp_pass" value="" placeholder="<?= htmlspecialchars(!empty($project['smtp_pass']) ? t('admin.common.secret_already_set') : t('admin.settings.email.password_placeholder')) ?>" autocomplete="new-password" style="width:100%">
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.email.sender_label')) ?></label>
                                    <input type="email" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>" placeholder="legal@deinefirma.de" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label"><?= htmlspecialchars(t('admin.settings.email.recipient_label')) ?></label>
                                    <input type="email" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>" placeholder="deine-mail@domain.de" style="width:100%">
                                </div>
                            </div>

                            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <button type="submit" class="pg-btn-secondary"><?= svg_icon('disk', '', 14) ?> <?= htmlspecialchars(t('admin.settings.email.save_button')) ?></button>
                                <?php if (!empty($project['smtp_host'])): ?>
                                    <button type="button" class="btn-test" onclick="triggerTestMail()"><?= svg_icon('mail', '', 14) ?> <?= htmlspecialchars(t('admin.settings.email.send_test_button')) ?></button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.webhooks.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= htmlspecialchars(t('admin.settings.webhooks.subtitle')) ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="cookie_banner_text" value="<?= htmlspecialchars($project['cookie_banner_text'] ?? '') ?>">
                            <input type="hidden" name="consent_logging_enabled" value="<?= !empty($project['consent_logging_enabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="consent_log_retention_days" value="<?= htmlspecialchars((string)($project['consent_log_retention_days'] ?? 1095)) ?>">
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.webhooks.url_label')) ?><?= help_icon(t('admin.settings.webhooks.url_help')) ?></label>
                            <input type="text" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>" placeholder="https://app.deinefirma.de/api/legal-webhook" style="width:100%;margin-bottom:14px">

                            <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.webhooks.secret_label')) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.settings.webhooks.secret_hint')) ?></span><?= help_icon(t('admin.settings.webhooks.secret_help')) ?></label>
                            <input type="text" name="webhook_secret" value="" placeholder="<?= htmlspecialchars(!empty($project['webhook_secret']) ? t('admin.common.secret_already_set') : t('admin.settings.webhooks.secret_placeholder')) ?>" style="width:100%;margin-bottom:14px">

                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
                                <button type="submit" class="pg-btn-secondary"><?= svg_icon('disk', '', 14) ?> <?= htmlspecialchars(t('admin.settings.webhooks.save_button')) ?></button>
                                <?php if (!empty($project['webhook_url'])): ?>
                                    <button type="button" class="btn-test" onclick="triggerTestWebhook()"><?= svg_icon('lightning', '', 14) ?> <?= htmlspecialchars(t('admin.settings.webhooks.test_button')) ?></button>
                                <?php endif; ?>
                            </div>

                            <div style="border-top:1px solid var(--border-soft);padding-top:16px">
                                <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.webhooks.deepl_label')) ?> <span style="color:var(--text-faint);font-weight:400"><?= htmlspecialchars(t('admin.settings.webhooks.deepl_hint')) ?></span></label>
                                <input type="text" name="deepl_api_key" value="" placeholder="<?= !empty($project['deepl_api_key']) ? htmlspecialchars(t('admin.common.secret_already_set')) : 'z. B. xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx' ?>" style="width:100%">
                                <?php if (!empty($envDeepl)): ?>
                                    <div class="pg-hint" style="color:var(--green)"><?= htmlspecialchars(t('admin.settings.webhooks.deepl_env_hint')) ?></div>
                                <?php else: ?>
                                    <div class="pg-hint"><?= t('admin.settings.webhooks.deepl_keys_hint') ?></div>
                                <?php endif; ?>
                            </div>

                            <div style="border-top:1px solid var(--border-soft);padding-top:16px;margin-top:16px">
                                <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.ai_import.heading')) ?> <span class="beta-badge-inline"><?= htmlspecialchars(t('admin.settings.ai_import.beta_label')) ?></span></label>
                                <p class="pg-hint" style="margin-top:0;margin-bottom:10px"><?= t('admin.settings.ai_import.subtitle') ?></p>
                                <select name="ai_provider" style="width:100%;margin-bottom:10px">
                                    <option value="" <?= empty($project['ai_provider']) ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.settings.ai_import.provider_none')) ?></option>
                                    <option value="claude" <?= ($project['ai_provider'] ?? '') === 'claude' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.settings.ai_import.provider_claude')) ?></option>
                                    <option value="openai" <?= ($project['ai_provider'] ?? '') === 'openai' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.settings.ai_import.provider_openai')) ?></option>
                                </select>
                                <input type="password" name="ai_api_key" value="" placeholder="<?= htmlspecialchars(!empty($project['ai_api_key']) ? t('admin.common.secret_already_set') : t('admin.settings.ai_import.key_placeholder')) ?>" style="width:100%" autocomplete="new-password">
                                <?php if (!empty($envAiKey)): ?>
                                    <div class="pg-hint" style="color:var(--green)"><?= htmlspecialchars(t('admin.settings.ai_import.env_hint')) ?></div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.company.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= t('admin.settings.company.subtitle') ?></p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#F0A63C') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="cookie_banner_text" value="<?= htmlspecialchars($project['cookie_banner_text'] ?? '') ?>">
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="consent_logging_enabled" value="<?= !empty($project['consent_logging_enabled']) ? '1' : '0' ?>">
                            <input type="hidden" name="consent_log_retention_days" value="<?= htmlspecialchars((string)($project['consent_log_retention_days'] ?? 1095)) ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="">
                            <input type="hidden" name="deepl_api_key" value="">

                            <div class="grid">
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.company.name_label')) ?></label>
                                    <input type="text" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.company.representative_label')) ?></label>
                                    <input type="text" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>" style="width:100%">
                                </div>
                                <div style="grid-column:1/-1">
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.company.address_label')) ?></label>
                                    <textarea name="address" rows="2" style="width:100%"><?= htmlspecialchars($project['address'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.company.email_label')) ?></label>
                                    <input type="text" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.company.phone_label')) ?></label>
                                    <input type="text" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>" style="width:100%">
                                </div>
                                <div style="grid-column:1/-1">
                                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.settings.company.register_label')) ?></label>
                                    <input type="text" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>" style="width:100%">
                                </div>
                            </div>

                            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px">
                                <button type="submit" class="pg-btn"><?= svg_icon('disk', '', 16) ?> <?= htmlspecialchars(t('admin.settings.company.save_button')) ?></button>

                                <?php if (count($projects) > 1): ?>
                                    <button type="button" class="pg-btn-danger" onclick="document.getElementById('deleteProjectForm').submit()"><?= htmlspecialchars(t('admin.settings.company.delete_project_button')) ?></button>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if (count($projects) > 1): ?>
                            <form id="deleteProjectForm" method="post" onsubmit="return confirm('<?= htmlspecialchars(t('admin.settings.company.confirm_delete_project', ['project' => $project['name']]), ENT_QUOTES) ?>');" style="display:none">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_project">
                                <input type="hidden" name="delete_project_id" value="<?= $project['id'] ?>">
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Sicherung & Export -->
                    <div class="pg-card pg-card-pad">
                        <h2><?= htmlspecialchars(t('admin.settings.backup.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:14px"><?= htmlspecialchars(t('admin.settings.backup.subtitle')) ?></p>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <a href="/admin?action=download_backup" class="pg-btn-secondary"><?= svg_icon('disk', '', 14) ?> <?= htmlspecialchars(t('admin.settings.backup.download_button')) ?></a>
                            <a href="/admin?action=export_markdown" class="pg-btn-secondary"><?= svg_icon('folder', '', 14) ?> <?= htmlspecialchars(t('admin.settings.backup.export_button')) ?></a>
                            <button type="button" class="pg-btn-secondary" onclick="triggerAuditReport()"><?= svg_icon('mail', '', 14) ?> <?= htmlspecialchars(t('admin.settings.backup.audit_report_button')) ?></button>
                        </div>

                        <div style="border-top:1px solid var(--border-soft);margin-top:20px;padding-top:16px">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:4px">
                                <h2 style="margin:0;font-size:14px"><?= htmlspecialchars(t('admin.settings.backup.auto_heading')) ?><?= help_icon(t('admin.settings.backup.auto_help')) ?></h2>
                                <form method="post" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="run_backup_now">
                                    <button type="submit" class="pg-btn-secondary" style="padding:6px 12px;font-size:12px"><?= htmlspecialchars(t('admin.settings.backup.run_now_button')) ?></button>
                                </form>
                            </div>
                            <?php if ($backupMsg === 'backup_created'): ?>
                                <p style="font-size:12px;color:var(--green);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.backup.created_msg')) ?></p>
                            <?php elseif ($backupMsg === 'backup_failed'): ?>
                                <p style="font-size:12px;color:var(--red);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.backup.failed_msg')) ?></p>
                            <?php endif; ?>
                            <?php if ($isManagedCloud): ?>
                                <p class="pg-card-sub" style="margin:0 0 10px"><?= htmlspecialchars(t('admin.settings.backup.cloud_hint')) ?></p>
                            <?php else: ?>
                                <p class="pg-card-sub" style="margin:0 0 10px"><?= htmlspecialchars(t('admin.settings.backup.manual_hint')) ?></p>
                            <?php endif; ?>

                            <?php if (empty($backups)): ?>
                                <div style="color:var(--text-faint);font-size:13px;font-style:italic"><?= htmlspecialchars(t('admin.settings.backup.empty')) ?></div>
                            <?php else: ?>
                                <table class="pg-table" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                                    <thead>
                                        <tr>
                                            <th><?= htmlspecialchars(t('admin.settings.backup.col_created')) ?></th>
                                            <th><?= htmlspecialchars(t('admin.settings.backup.col_size')) ?></th>
                                            <th style="width:110px"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($backups as $b): ?>
                                            <tr>
                                                <td style="color:var(--text-muted)"><?= date('d.m.Y H:i', $b['created_at']) ?></td>
                                                <td style="color:var(--text-muted)"><?= round($b['size'] / 1024 / 1024, 2) ?> MB</td>
                                                <td><a href="/admin?action=download_backup_file&file=<?= urlencode($b['filename']) ?>" class="pg-btn-secondary" style="padding:4px 10px;font-size:12px"><?= htmlspecialchars(t('admin.settings.backup.download_link')) ?></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                        <div style="border-top:1px solid var(--border-soft);margin-top:20px;padding-top:16px">
                            <h2 style="margin:0 0 4px;font-size:14px"><?= htmlspecialchars(t('admin.settings.restore.heading')) ?><?= help_icon(t('admin.settings.restore.help')) ?></h2>
                            <?php if ($backupMsg === 'restore_success'): ?>
                                <p style="font-size:12px;color:var(--green);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.restore.success_msg', ['count' => (int)($_GET['restore_count'] ?? 0)])) ?></p>
                                <?php if (!empty($_GET['restore_over_limit'])): ?>
                                    <div class="pg-alert pg-alert-amber" style="margin-bottom:12px;font-size:12.5px">
                                        <?= htmlspecialchars(t('admin.settings.restore.over_limit_warning', ['count' => (int)($_GET['restore_count'] ?? 0), 'limit' => (int)($_GET['restore_limit'] ?? 0)])) ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($backupMsg === 'restore_failed'): ?>
                                <p style="font-size:12px;color:var(--red);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.restore.error_msg', ['error' => $_GET['restore_error'] ?? ''])) ?></p>
                            <?php endif; ?>
                            <div class="pg-alert pg-alert-red" style="margin-bottom:12px;font-size:12.5px">
                                <?= htmlspecialchars(t('admin.settings.restore.warning')) ?>
                                <?php if ($isManagedCloud): ?> <?= htmlspecialchars(t('admin.settings.restore.warning_cloud_domain')) ?><?php endif; ?>
                            </div>
                            <form method="post" enctype="multipart/form-data" id="restoreForm" onsubmit="return confirm(<?= json_encode(t('admin.settings.restore.confirm_dialog')) ?>);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="restore_backup_upload">
                                <input type="file" name="backup_file" accept=".sqlite" required style="width:100%;margin-bottom:10px">
                                <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;font-weight:500;margin-bottom:12px">
                                    <input type="checkbox" id="restoreConfirmCheckbox" onchange="document.getElementById('restoreSubmitBtn').disabled = !this.checked" style="margin-top:2px">
                                    <?= htmlspecialchars(t('admin.settings.restore.confirm_checkbox')) ?>
                                </label>
                                <button type="submit" id="restoreSubmitBtn" class="pg-btn-secondary" disabled style="border-color:var(--red);color:var(--red)"><?= svg_icon('sync', '', 14) ?> <?= htmlspecialchars(t('admin.settings.restore.submit_button')) ?></button>
                            </form>
                        </div>

                        <div style="border-top:1px solid var(--border-soft);margin-top:20px;padding-top:16px">
                            <h2 style="margin:0 0 4px;font-size:14px"><?= htmlspecialchars(t('admin.settings.project_transfer.heading')) ?><?= help_icon(t('admin.settings.project_transfer.help')) ?></h2>
                            <p class="pg-card-sub" style="margin:0 0 12px"><?= htmlspecialchars(t('admin.settings.project_transfer.subtitle')) ?></p>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
                                <a href="/admin?action=export_project&project_id=<?= (int)$project['id'] ?>" class="pg-btn-secondary"><?= svg_icon('folder', '', 14) ?> <?= htmlspecialchars(t('admin.settings.project_transfer.export_button')) ?></a>
                            </div>

                            <?php if ($backupMsg === 'project_import_success'): ?>
                                <p style="font-size:12px;color:var(--green);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.project_transfer.import_merged_msg', ['count' => (int)($_GET['import_docs'] ?? 0)])) ?></p>
                            <?php elseif ($backupMsg === 'project_import_failed'): ?>
                                <p style="font-size:12px;color:var(--red);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.project_transfer.import_error_msg', ['error' => $_GET['import_error'] ?? ''])) ?></p>
                            <?php endif; ?>

                            <div class="pg-alert pg-alert-amber" style="margin-bottom:12px;font-size:12.5px">
                                <?= htmlspecialchars(t('admin.settings.project_transfer.import_warning')) ?>
                            </div>
                            <form method="post" enctype="multipart/form-data" id="projectImportForm" onsubmit="return confirm(<?= json_encode(t('admin.settings.project_transfer.confirm_dialog')) ?>);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="import_project_upload">
                                <label class="pg-label" style="margin-top:0;font-size:12.5px"><?= htmlspecialchars(t('admin.settings.project_transfer.target_label')) ?></label>
                                <select name="target_project_id" required style="width:100%;margin-bottom:10px">
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === (int)$project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['domain']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="file" name="project_file" accept=".sqlite" required style="width:100%;margin-bottom:10px">
                                <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;font-weight:500;margin-bottom:12px">
                                    <input type="checkbox" id="projectImportConfirmCheckbox" onchange="document.getElementById('projectImportSubmitBtn').disabled = !this.checked" style="margin-top:2px">
                                    <?= htmlspecialchars(t('admin.settings.project_transfer.confirm_checkbox')) ?>
                                </label>
                                <button type="submit" id="projectImportSubmitBtn" class="pg-btn-secondary" disabled><?= svg_icon('sync', '', 14) ?> <?= htmlspecialchars(t('admin.settings.project_transfer.submit_button')) ?></button>
                            </form>
                        </div>
                    </div>

                    <!-- Webhook-Warteschlange -->
                    <div class="pg-card pg-card-pad">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:4px">
                            <h2 style="margin:0"><?= htmlspecialchars(t('admin.settings.queue.heading')) ?><?= help_icon(t('admin.settings.queue.help')) ?></h2>
                            <form method="post" style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="run_webhook_queue_now">
                                <button type="submit" class="pg-btn-secondary" style="padding:6px 12px;font-size:12px"><?= htmlspecialchars(t('admin.settings.queue.run_now_button')) ?></button>
                            </form>
                        </div>
                        <?php if (($_GET['msg'] ?? '') === 'queue_processed'): ?>
                            <p style="font-size:12px;color:var(--green);margin:4px 0 10px"><?= htmlspecialchars(t('admin.settings.queue.processed_msg')) ?></p>
                        <?php endif; ?>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <span class="pg-pill pg-pill-amber"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.settings.queue.pending', ['count' => $queueSummary['pending']])) ?></span>
                            <span class="pg-pill pg-pill-green"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.settings.queue.sent', ['count' => $queueSummary['sent']])) ?></span>
                            <?php if ($queueSummary['failed'] > 0): ?>
                                <span class="pg-pill pg-pill-red"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.settings.queue.failed', ['count' => $queueSummary['failed']])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Webhook Protokoll & Delivery Logs -->
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                            <h2 style="margin:0"><?= htmlspecialchars(t('admin.settings.log.heading')) ?></h2>
                            <?php if (!empty($logs)): ?>
                                <form method="post" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="clear_webhook_logs">
                                    <button type="submit" class="pg-btn-secondary" style="padding:6px 12px;font-size:12px"><?= htmlspecialchars(t('admin.settings.log.clear_button')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <p class="pg-card-sub" style="margin-bottom:14px"><?= htmlspecialchars(t('admin.settings.log.subtitle')) ?></p>

                        <?php if (empty($logs)): ?>
                            <div style="color:var(--text-faint);font-size:13px;font-style:italic;padding:1rem 0;"><?= htmlspecialchars(t('admin.settings.log.empty')) ?></div>
                        <?php else: ?>
                            <table class="log-table">
                                <thead>
                                    <tr>
                                        <th><?= htmlspecialchars(t('admin.settings.log.col_time')) ?></th>
                                        <th><?= htmlspecialchars(t('admin.settings.log.col_event')) ?></th>
                                        <th><?= htmlspecialchars(t('admin.settings.log.col_status')) ?></th>
                                        <th><?= htmlspecialchars(t('admin.settings.log.col_latency')) ?></th>
                                        <th><?= htmlspecialchars(t('admin.settings.log.col_response')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $l): ?>
                                        <?php $isSuccess = ($l['status_code'] >= 200 && $l['status_code'] < 300); ?>
                                        <tr>
                                            <td style="color:var(--text-muted)"><?= date('d.m. H:i:s', strtotime($l['created_at'])) ?></td>
                                            <td style="font-weight:500"><?= htmlspecialchars($l['event_name']) ?></td>
                                            <td>
                                                <span class="status-badge <?= $isSuccess ? 'status-200' : 'status-err' ?>">
                                                    <?= $l['status_code'] ?: 'ERR' ?>
                                                </span>
                                            </td>
                                            <td style="color:var(--text-muted)"><?= $l['duration_ms'] ?> ms</td>
                                            <td>
                                                <?php if (!empty($l['error_message'])): ?>
                                                    <span style="color:var(--red);font-weight:600;"><?= htmlspecialchars($l['error_message']) ?></span>
                                                <?php elseif (!empty($l['response_body'])): ?>
                                                    <div class="payload-preview" title="<?= htmlspecialchars($l['response_body']) ?>">
                                                        <?= htmlspecialchars($l['response_body']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:var(--text-faint);font-style:italic;"><?= htmlspecialchars(t('admin.settings.log.empty_response')) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
            </div>
        </div>

        <div id="toastNotification" class="pg-toast">
            <?= svg_icon('check', '', 16) ?> <span id="toastMsg"><?= htmlspecialchars(t('admin.settings.js.copy_success')) ?></span>
        </div>

        <script>
            const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
            const i18n = {
                copySuccess: <?= json_encode(t('admin.settings.js.copy_success')) ?>,
                copyFailed: <?= json_encode(t('admin.matrix.js.copy_failed')) ?>,
                auditReportSent: <?= json_encode(t('admin.settings.js.audit_report_sent')) ?>,
                errorPrefix: <?= json_encode(t('admin.settings.js.error_prefix')) ?>,
                auditReportFailed: <?= json_encode(t('admin.settings.js.audit_report_failed')) ?>,
                webhookSuccessTemplate: <?= json_encode(t('admin.settings.js.webhook_success')) ?>,
                webhookFailureTemplate: <?= json_encode(t('admin.settings.js.webhook_failure')) ?>,
                emptyParen: <?= json_encode(t('admin.settings.js.empty_paren')) ?>,
                unknownError: <?= json_encode(t('admin.settings.js.unknown_error')) ?>,
                networkErrorPrefix: <?= json_encode(t('admin.settings.js.network_error_prefix')) ?>,
                smtpTestSuccess: <?= json_encode(t('admin.settings.js.smtp_test_success')) ?>,
                smtpTestFailed: <?= json_encode(t('admin.settings.js.smtp_test_failed')) ?>
            };

            function showToast(msg) {
                const toast = document.getElementById('toastNotification');
                document.getElementById('toastMsg').innerText = msg;
                toast.classList.add('show');
                setTimeout(() => { toast.classList.remove('show'); }, 2500);
            }

            function copyToClipboard(text, successMsg = i18n.copySuccess) {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(() => {
                        showToast(successMsg);
                    }).catch(() => {
                        fallbackCopy(text, successMsg);
                    });
                } else {
                    fallbackCopy(text, successMsg);
                }
            }

            function fallbackCopy(text, successMsg) {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast(successMsg);
                } catch (err) {
                    alert(i18n.copyFailed);
                }
                document.body.removeChild(textArea);
            }

            (function() {
                const THEME_KEY = 'paragrafy_theme';
                const toggle = document.getElementById('themeToggle');
                const current = localStorage.getItem(THEME_KEY) || 'auto';

                function applyActive(choice) {
                    toggle.querySelectorAll('button').forEach(function(btn) {
                        btn.classList.toggle('active', btn.dataset.themeChoice === choice);
                    });
                }
                applyActive(current);

                toggle.querySelectorAll('button').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const choice = btn.dataset.themeChoice;
                        try { localStorage.setItem(THEME_KEY, choice); } catch (e) {}
                        if (choice === 'auto') {
                            document.documentElement.removeAttribute('data-theme');
                        } else {
                            document.documentElement.setAttribute('data-theme', choice);
                        }
                        applyActive(choice);
                    });
                });
            })();

            async function triggerAuditReport() {
                try {
                    const res = await fetch('/api/cron/audit');
                    const data = await res.json();
                    if (data.success) {
                        alert(data.message || i18n.auditReportSent);
                    } else {
                        alert(i18n.errorPrefix + (data.error || i18n.auditReportFailed));
                    }
                } catch (e) {
                    alert(i18n.errorPrefix + e.message);
                }
            }

            async function triggerTestWebhook() {
                const formData = new FormData();
                formData.append('action', 'test_webhook');
                formData.append('csrf_token', CSRF_TOKEN);
                try {
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        alert(i18n.webhookSuccessTemplate.replace(':status', data.status_code).replace(':duration', data.duration_ms).replace(':response', data.response || i18n.emptyParen));
                        location.reload();
                    } else {
                        alert(i18n.webhookFailureTemplate.replace(':status', data.status_code).replace(':error', data.error || i18n.unknownError).replace(':response', data.response || i18n.emptyParen));
                        location.reload();
                    }
                } catch (e) {
                    alert(i18n.networkErrorPrefix + e.message);
                }
            }

            async function triggerTestMail() {
                const formData = new FormData();
                formData.append('action', 'test_smtp');
                formData.append('csrf_token', CSRF_TOKEN);
                try {
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        alert(i18n.smtpTestSuccess);
                    } else {
                        alert(i18n.errorPrefix + (data.error || i18n.smtpTestFailed));
                    }
                } catch (e) {
                    alert(i18n.networkErrorPrefix + e.message);
                }
            }
        </script>
    </body>
    </html>
    <?php
}

function render_security_view(PDO $db, array $project, array $projects): void {
    $identity = totp_current_identity($db);
    $enabled = $identity !== null && totp_identity_enabled($identity);
    $setupState = $_SESSION['totp_setup'] ?? null;
    $pendingSecret = is_array($setupState) && isset($setupState['secret']) ? $setupState['secret'] : null;
    $recoveryCodesToShow = is_array($setupState) && isset($setupState['recovery_codes']) ? $setupState['recovery_codes'] : null;
    $msg = $_GET['msg'] ?? '';
    $msgMap = [
        'totp_setup_invalid_code' => ['err', t('admin.security.msg.invalid_code')],
        'totp_enabled' => ['ok', t('admin.security.msg.enabled')],
        'totp_disabled' => ['ok', t('admin.security.msg.disabled')],
        'totp_disable_wrong_password' => ['err', t('admin.security.msg.wrong_password')],
        'totp_setup_persist_failed' => ['err', t('admin.security.msg.persist_failed')],
    ];

    $qrDataUri = null;
    $provisioningUri = null;
    if ($pendingSecret !== null && $identity !== null) {
        $provisioningUri = totp_provisioning_uri($pendingSecret, totp_identity_label($identity, $project), totp_identity_issuer($identity, $project));
        $qrDataUri = totp_qr_data_uri($provisioningUri);
    }
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.security.page_title', ['project' => $project['name']])) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin($project['brand_color'] ?: '#F0A63C') ?>
        <style>
            .recovery-codes { font-family:'JetBrains Mono',monospace; font-size:14px; line-height:2; background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); padding:14px 18px; display:grid; grid-template-columns:1fr 1fr; gap:2px 20px; }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('security', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('admin.security.crumb')) ?></strong></div>
                </div>

                <div class="pg-content" style="max-width:640px">
                    <?php if ($msg && isset($msgMap[$msg])): ?>
                        <div class="pg-alert <?= $msgMap[$msg][0] === 'ok' ? 'pg-alert-amber' : 'pg-alert-red' ?>" style="<?= $msgMap[$msg][0] === 'ok' ? 'background:var(--green-bg);border-color:#bfe6cf;color:#125c3a' : '' ?>">
                            <div><?= htmlspecialchars($msgMap[$msg][1]) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="pg-card pg-card-pad">
                        <h2 style="margin:0 0 4px"><?= htmlspecialchars(t('admin.security.heading')) ?></h2>
                        <p class="pg-card-sub" style="margin-bottom:20px"><?= htmlspecialchars(t('admin.security.subtitle')) ?></p>

                        <?php if ($identity === null): ?>
                            <p style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars(t('admin.security.unavailable_managed_cloud')) ?></p>
                        <?php elseif (!totp_vendor_available()): ?>
                            <p style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars(t('admin.security.vendor_missing')) ?></p>
                        <?php elseif ($recoveryCodesToShow !== null): ?>
                            <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px"><?= htmlspecialchars(t('admin.security.recovery.intro')) ?></p>
                            <div class="recovery-codes">
                                <?php foreach ($recoveryCodesToShow as $code): ?>
                                    <div><?= htmlspecialchars($code) ?></div>
                                <?php endforeach; ?>
                            </div>
                            <form method="post" style="margin-top:18px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="totp_setup_ack">
                                <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--text-muted);margin-bottom:14px">
                                    <input type="checkbox" required style="margin-top:3px">
                                    <?= htmlspecialchars(t('admin.security.recovery.saved_checkbox')) ?>
                                </label>
                                <button type="submit" class="pg-btn"><?= htmlspecialchars(t('admin.security.recovery.continue_button')) ?></button>
                            </form>
                        <?php elseif ($pendingSecret !== null): ?>
                            <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px"><?= htmlspecialchars(t('admin.security.setup.scan_hint')) ?></p>
                            <img src="<?= htmlspecialchars($qrDataUri) ?>" alt="QR" width="200" height="200" style="border:1px solid var(--border);border-radius:var(--radius);padding:8px;background:#fff">
                            <p style="font-size:12px;color:var(--text-faint);margin:12px 0 4px"><?= htmlspecialchars(t('admin.security.setup.manual_entry_hint')) ?></p>
                            <code style="font-family:'JetBrains Mono',monospace;font-size:13px;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:6px 10px;display:inline-block"><?= htmlspecialchars($pendingSecret) ?></code>

                            <form method="post" style="margin-top:20px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="totp_setup_confirm">
                                <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.security.setup.code_label')) ?></label>
                                <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus required style="width:100%;margin-bottom:12px;letter-spacing:2px;font-family:'JetBrains Mono',monospace">
                                <div style="display:flex;gap:10px">
                                    <button type="submit" class="pg-btn"><?= htmlspecialchars(t('admin.security.setup.confirm_button')) ?></button>
                                    <button type="submit" formnovalidate name="action" value="totp_setup_cancel" class="pg-btn-secondary"><?= htmlspecialchars(t('admin.security.setup.cancel_button')) ?></button>
                                </div>
                            </form>
                        <?php elseif ($enabled): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                                <span class="pg-pill pg-pill-green"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.security.status_active')) ?></span>
                            </div>

                            <form method="post" style="margin-bottom:22px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="totp_regenerate_codes">
                                <p style="font-size:12.5px;color:var(--text-faint);margin:0 0 10px"><?= htmlspecialchars(t('admin.security.regenerate.hint')) ?></p>
                                <button type="submit" class="pg-btn-secondary" onclick="return confirm(<?= json_encode(t('admin.security.regenerate.confirm')) ?>)"><?= htmlspecialchars(t('admin.security.regenerate.button')) ?></button>
                            </form>

                            <hr class="pg-sep">

                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="totp_disable">
                                <p style="font-size:12.5px;color:var(--text-faint);margin:0 0 10px"><?= htmlspecialchars(t('admin.security.disable.hint')) ?></p>
                                <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.security.disable.password_label')) ?></label>
                                <input type="password" name="confirm_password" required style="width:100%;margin-bottom:12px">
                                <button type="submit" class="pg-btn-secondary danger" onclick="return confirm(<?= json_encode(t('admin.security.disable.confirm')) ?>)"><?= htmlspecialchars(t('admin.security.disable.button')) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="totp_setup_start">
                                <button type="submit" class="pg-btn"><?= htmlspecialchars(t('admin.security.setup.start_button')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function render_users_view(PDO $db, array $project, array $projects): void {
    $users = $db->query("SELECT * FROM users ORDER BY (status = 'active') DESC, name ASC")->fetchAll();
    $accessMap = [];
    foreach ($db->query("SELECT user_id, project_id FROM user_projects") as $row) {
        $accessMap[(int)$row['user_id']][] = (int)$row['project_id'];
    }
    $msg = $_GET['msg'] ?? '';
    $msgMap = [
        'invited' => ['ok', t('admin.users.msg.invited')],
        'invite_mail_failed' => ['err', t('admin.users.msg.invite_mail_failed')],
        'email_exists' => ['err', t('admin.users.msg.email_exists')],
        'invalid' => ['err', t('admin.users.msg.invalid')],
        'user_deleted' => ['ok', t('admin.users.msg.user_deleted')],
        'managed_cloud_blocked' => ['err', t('admin.users.msg.managed_cloud_blocked')],
        'access_updated' => ['ok', t('admin.users.msg.access_updated')],
        'totp_reset' => ['ok', t('admin.users.msg.totp_reset')],
    ];
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.users.page_title', ['project' => $project['name']])) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin($project['brand_color'] ?: '#F0A63C') ?>
        <style>
            .users-table th { text-align:left; }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('users', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('admin.users.crumb')) ?></strong></div>
                </div>

                <div class="pg-content" style="max-width:840px">
                    <?php if ($msg && isset($msgMap[$msg])): ?>
                        <div class="pg-alert <?= $msgMap[$msg][0] === 'ok' ? 'pg-alert-amber' : 'pg-alert-red' ?>" style="<?= $msgMap[$msg][0] === 'ok' ? 'background:var(--green-bg);border-color:#bfe6cf;color:#125c3a' : '' ?>">
                            <div><?= htmlspecialchars($msgMap[$msg][1]) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="pg-card pg-card-pad">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                            <h2 style="margin:0"><?= htmlspecialchars(t('admin.users.heading')) ?></h2>
                            <button type="button" class="pg-btn" style="padding:8px 14px;font-size:12.5px" onclick="openInviteModal()"><?= htmlspecialchars(t('admin.users.invite_button')) ?></button>
                        </div>
                        <p class="pg-card-sub" style="margin-bottom:16px"><?= htmlspecialchars(t('admin.users.subtitle')) ?></p>

                        <p class="pg-card-sub" style="margin:-4px 0 14px;font-size:12px"><?= htmlspecialchars(t('admin.users.access.hint')) ?></p>

                        <div style="overflow-x:auto">
                        <table class="pg-table users-table" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                            <thead>
                                <tr>
                                    <th><?= htmlspecialchars(t('admin.users.col_name')) ?></th>
                                    <th><?= htmlspecialchars(t('admin.users.col_email')) ?></th>
                                    <th><?= htmlspecialchars(t('admin.users.col_notes')) ?></th>
                                    <th><?= htmlspecialchars(t('admin.users.col_status')) ?></th>
                                    <th style="text-align:center"><?= htmlspecialchars(t('admin.users.col_totp')) ?></th>
                                    <?php foreach ($projects as $p): ?>
                                        <th style="text-align:center;white-space:nowrap"><?= htmlspecialchars($p['name']) ?></th>
                                    <?php endforeach; ?>
                                    <th style="width:120px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="<?= 6 + count($projects) ?>" style="color:var(--text-faint);font-style:italic"><?= htmlspecialchars(t('admin.users.empty')) ?></td></tr>
                                <?php endif; ?>
                                <?php foreach ($users as $u): ?>
                                    <?php
                                        $isSelf = (int)$u['id'] === (int)($_SESSION['paragrafy_user_id'] ?? 0);
                                        $userProjectIds = $accessMap[(int)$u['id']] ?? [];
                                        $formId = 'uf-' . (int)$u['id'];
                                    ?>
                                    <tr>
                                        <td style="font-weight:600"><?= htmlspecialchars($u['name']) ?><?= $isSelf ? ' <span style="color:var(--text-faint);font-weight:500">' . htmlspecialchars(t('admin.users.you_suffix')) . '</span>' : '' ?></td>
                                        <td style="color:var(--text-muted)"><?= htmlspecialchars($u['email']) ?></td>
                                        <td style="color:var(--text-muted)"><?= htmlspecialchars($u['notes'] ?? '') ?></td>
                                        <td>
                                            <?php if ($u['status'] === 'active'): ?>
                                                <span class="pg-pill pg-pill-green"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.users.status_active')) ?></span>
                                            <?php else: ?>
                                                <span class="pg-pill pg-pill-muted"><?= htmlspecialchars(t('admin.users.status_invited')) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center">
                                            <?php if (!empty($u['totp_enabled_at'])): ?>
                                                <form method="post" style="margin:0" onsubmit="return confirm('<?= htmlspecialchars(t('admin.users.confirm_totp_reset', ['name' => $u['name']]), ENT_QUOTES) ?>');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="reset_user_totp">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="pg-icon-btn" title="<?= htmlspecialchars(t('admin.users.totp_reset_title')) ?>">
                                                        <span class="pg-pill pg-pill-green" style="cursor:pointer"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.security.status_active')) ?></span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:var(--text-faintest)">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach ($projects as $p): ?>
                                            <td style="text-align:center">
                                                <input type="checkbox" form="<?= $formId ?>" name="project_ids[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $userProjectIds, true) ? 'checked' : '' ?>>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <div style="display:flex;justify-content:flex-end;gap:4px">
                                                <?php if (!empty($projects)): ?>
                                                    <button type="submit" form="<?= $formId ?>" class="pg-icon-btn" title="<?= htmlspecialchars(t('admin.users.access.save_title')) ?>" onclick="return confirmAccessSave('<?= $formId ?>')">
                                                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h8l2 2v8H3z"/><path d="M5 3v4h6V3"/><path d="M5 13v-4h6v4"/></svg>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($u['status'] === 'invited'): ?>
                                                    <form method="post" style="margin:0">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="resend_invite">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="pg-icon-btn" title="<?= htmlspecialchars(t('admin.users.resend_invite_title')) ?>">
                                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8a6 6 0 1 1 1.8 4.3"/><path d="M2 12v-3h3"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($isSelf): ?>
                                                    <span style="color:var(--text-faintest);padding:6px 8px">&mdash;</span>
                                                <?php else: ?>
                                                    <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('admin.users.confirm_delete', ['name' => $u['name']]), ENT_QUOTES) ?>');" style="margin:0">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="pg-icon-btn danger" title="<?= htmlspecialchars(t('admin.users.delete_title')) ?>">
                                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 4.5h9"/><path d="M6.2 4.5V3.2c0-.4.3-.7.7-.7h2.2c.4 0 .7.3.7.7v1.3"/><path d="M4.8 4.5 5.2 13c0 .4.3.7.7.7h4c.4 0 .7-.3.7-.7l.4-8.5"/><path d="M6.7 7.3v3.6"/><path d="M9.3 7.3v3.6"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <?php foreach ($users as $u): ?>
                            <form id="uf-<?= (int)$u['id'] ?>" method="post" hidden>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_user_projects">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
            </div>
        </div>

        <!-- Modal: Person einladen -->
        <div id="inviteModal" class="pg-modal-backdrop" onclick="if(event.target === this) closeInviteModal()">
            <div class="pg-modal">
                <h2 style="font-size:19px;font-weight:800;margin:0 0 6px"><?= htmlspecialchars(t('admin.users.modal.heading')) ?></h2>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px"><?= htmlspecialchars(t('admin.users.modal.desc')) ?></p>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="invite_user">

                    <label class="pg-label" style="margin-top:0"><?= htmlspecialchars(t('admin.users.modal.name_label')) ?></label>
                    <input type="text" name="invite_name" placeholder="<?= htmlspecialchars(t('admin.users.modal.name_placeholder')) ?>" required style="width:100%">

                    <label class="pg-label"><?= htmlspecialchars(t('admin.users.modal.email_label')) ?></label>
                    <input type="email" name="invite_email" placeholder="<?= htmlspecialchars(t('admin.users.modal.email_placeholder')) ?>" required style="width:100%">

                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:26px">
                        <button type="button" class="pg-btn-secondary" onclick="closeInviteModal()"><?= htmlspecialchars(t('admin.users.modal.cancel_button')) ?></button>
                        <button type="submit" class="pg-btn"><?= t('admin.users.modal.submit_button') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openInviteModal() {
                document.getElementById('inviteModal').style.display = 'flex';
            }
            function closeInviteModal() {
                document.getElementById('inviteModal').style.display = 'none';
            }
            function confirmAccessSave(formId) {
                var checked = document.querySelectorAll('input[type=checkbox][form="' + formId + '"]:checked');
                if (checked.length === 0) {
                    return confirm(<?= json_encode(t('admin.users.access.confirm_unrestricted')) ?>);
                }
                return true;
            }
        </script>
    </body>
    </html>
    <?php
}

function render_consent_log_view(PDO $db, array $project, array $projects): void {
    $loggingEnabled = !empty($project['consent_logging_enabled']);
    $entries = [];
    if ($loggingEnabled) {
        $stmt = $db->prepare("SELECT * FROM consent_logs WHERE project_id = ? ORDER BY created_at DESC LIMIT 500");
        $stmt->execute([$project['id']]);
        $entries = $stmt->fetchAll();
    }
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.consent_log.page_title', ['project' => $project['name']])) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin($project['brand_color'] ?: '#F0A63C') ?>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('consent_log', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('admin.consent_log.crumb')) ?></strong></div>
                </div>

                <div class="pg-content" style="max-width:1040px">
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                            <h2 style="margin:0"><?= htmlspecialchars(t('admin.consent_log.heading')) ?></h2>
                            <?php if (!empty($entries)): ?>
                                <a href="/admin?project_id=<?= $project['id'] ?>&action=export_consent_log_csv" class="pg-btn-secondary" style="padding:6px 12px;font-size:12px"><?= svg_icon('folder', '', 13) ?> <?= htmlspecialchars(t('admin.consent_log.export_csv')) ?></a>
                            <?php endif; ?>
                        </div>
                        <p class="pg-card-sub" style="margin:5px 0 16px"><?= htmlspecialchars(t('admin.consent_log.subtitle')) ?></p>

                        <?php if (!$loggingEnabled): ?>
                            <div style="color:var(--text-faint);font-size:13px;padding:1rem 0;">
                                <?= htmlspecialchars(t('admin.consent_log.disabled_hint')) ?>
                                <a href="/admin/settings?project_id=<?= $project['id'] ?>"><?= htmlspecialchars(t('admin.consent_log.disabled_hint_link')) ?></a>
                            </div>
                        <?php elseif (empty($entries)): ?>
                            <div style="color:var(--text-faint);font-size:13px;font-style:italic;padding:1rem 0;"><?= htmlspecialchars(t('admin.consent_log.empty')) ?></div>
                        <?php else: ?>
                            <div style="overflow-x:auto">
                                <table class="pg-table" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                                    <thead>
                                        <tr>
                                            <th><?= htmlspecialchars(t('admin.consent_log.col_time')) ?></th>
                                            <th><?= htmlspecialchars(t('admin.consent_log.col_action')) ?></th>
                                            <th><?= htmlspecialchars(t('admin.consent_log.col_ip')) ?></th>
                                            <th><?= htmlspecialchars(t('admin.consent_log.col_consent_id')) ?></th>
                                            <th><?= htmlspecialchars(t('admin.consent_log.col_lang')) ?></th>
                                            <th><?= htmlspecialchars(t('admin.consent_log.col_useragent')) ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($entries as $e): ?>
                                            <tr>
                                                <td style="color:var(--text-muted);white-space:nowrap"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></td>
                                                <td>
                                                    <?php if ($e['action'] === 'accepted'): ?>
                                                        <span class="pg-pill pg-pill-green"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.consent_log.action_accepted')) ?></span>
                                                    <?php else: ?>
                                                        <span class="pg-pill pg-pill-amber"><span class="pg-pill-dot"></span><?= htmlspecialchars(t('admin.consent_log.action_declined')) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= htmlspecialchars($e['ip_anonymized']) ?></td>
                                                <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-faint);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($e['consent_id']) ?>"><?= htmlspecialchars($e['consent_id']) ?></td>
                                                <td><?= htmlspecialchars($e['lang']) ?></td>
                                                <td style="color:var(--text-faint);font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($e['user_agent']) ?>"><?= htmlspecialchars($e['user_agent']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function render_audit_view(PDO $db, array $project, array $projects): void {
    if (current_user_is_primary_admin()) {
        $stmt = $db->prepare("SELECT * FROM audit_log WHERE project_id = ? OR project_id IS NULL ORDER BY created_at DESC LIMIT 200");
    } else {
        $stmt = $db->prepare("SELECT * FROM audit_log WHERE project_id = ? ORDER BY created_at DESC LIMIT 200");
    }
    $stmt->execute([$project['id']]);
    $entries = $stmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars(current_locale()) ?>">
    <head>
        <meta charset="utf-8"><title><?= htmlspecialchars(t('admin.audit.page_title', ['project' => $project['name']])) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags_admin() ?>
        <?= theme_base_css_admin($project['brand_color'] ?: '#F0A63C') ?>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('audit', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('admin.audit.crumb')) ?></strong></div>
                </div>

                <div class="pg-content" style="max-width:840px">
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                            <h2 style="margin:0"><?= htmlspecialchars(t('admin.audit.heading')) ?></h2>
                            <?php if (!empty($entries)): ?>
                                <a href="/admin?project_id=<?= $project['id'] ?>&action=export_audit_csv" class="pg-btn-secondary" style="padding:6px 12px;font-size:12px"><?= svg_icon('folder', '', 13) ?> <?= htmlspecialchars(t('admin.audit.export_csv')) ?></a>
                            <?php endif; ?>
                        </div>
                        <p class="pg-card-sub" style="margin:5px 0 16px"><?= htmlspecialchars(t('admin.audit.subtitle')) ?></p>

                        <?php if (empty($entries)): ?>
                            <div style="color:var(--text-faint);font-size:13px;font-style:italic;padding:1rem 0;"><?= htmlspecialchars(t('admin.audit.empty')) ?></div>
                        <?php else: ?>
                            <table class="pg-table" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                                <thead>
                                    <tr>
                                        <th><?= htmlspecialchars(t('admin.audit.col_time')) ?></th>
                                        <th><?= htmlspecialchars(t('admin.audit.col_user')) ?></th>
                                        <th><?= htmlspecialchars(t('admin.audit.col_action')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entries as $e): ?>
                                        <tr>
                                            <td style="color:var(--text-muted);white-space:nowrap"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></td>
                                            <td style="font-weight:600"><?= htmlspecialchars($e['user_name']) ?></td>
                                            <td><?= htmlspecialchars($e['action']) ?><?php if (!empty($e['project_name'])): ?> <span style="color:var(--text-faint)">&middot; <?= htmlspecialchars($e['project_name']) ?></span><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
