<?php
/**
 * Paragrafy - Public Router, Document Viewer, Embed Drawer, JSON API & Cron Audit
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

if (!is_installed()) {
    require_once __DIR__ . '/install.php';
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

if ($uri === '/paragrafy.svg') {
    header('Content-Type: image/svg+xml');
    if (file_exists(__DIR__ . '/paragrafy.svg')) {
        readfile(__DIR__ . '/paragrafy.svg');
    }
    exit;
}

// Rollierendes Backup (7 Tage) -- per externem Cron täglich aufzurufen, z. B. via crontab oder Uptime-Kuma-Healthcheck
if ($uri === '/api/cron/backup') {
    require_cron_secret();
    header('Content-Type: application/json');
    echo json_encode(run_scheduled_backup());
    exit;
}

// Webhook-Warteschlange abarbeiten -- per externem Cron alle paar Minuten aufzurufen
if ($uri === '/api/cron/webhooks') {
    require_cron_secret();
    header('Content-Type: application/json');
    echo json_encode(process_webhook_queue());
    exit;
}

// Geplante Veröffentlichungen live schalten -- projektübergreifend, für explizite Cron-Aufrufe
if ($uri === '/api/cron/publish') {
    require_cron_secret();
    header('Content-Type: application/json');
    echo json_encode(check_and_publish_scheduled(get_db()));
    exit;
}

if (str_starts_with($uri, '/admin')) {
    require_once __DIR__ . '/admin.php';
    exit;
}

$host = get_current_host();
$db = get_db();

$stmt = $db->prepare("SELECT * FROM projects WHERE domain = ? OR domain = 'localhost' ORDER BY (domain = ?) DESC LIMIT 1");
$stmt->execute([$host, $host]);
$project = $stmt->fetch();

if ($uri === '/embed.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    render_embed_js();
    exit;
}

if ($uri === '/consent.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    render_consent_js($project ?: null);
    exit;
}

if ($uri === '/api/consent-log') {
    handle_consent_log($project ?: null, $db);
    exit;
}

if (!$project) {
    http_response_code(404);
    echo "<h1>" . htmlspecialchars(t('public.error.no_project_title')) . "</h1><p>" . t('public.error.no_project_desc', ['domain' => htmlspecialchars($host)]) . "</p>";
    exit;
}

// Zero-Config-Fallback: bei jedem Seitenaufruf prüfen, ob für dieses Projekt etwas fällig ist.
// Ersetzt keinen Cron (der greift auch ohne Traffic), fängt aber Instanzen ohne eingerichteten Cron auf.
check_and_publish_scheduled($db, $project);

$parts = array_values(array_filter(explode('/', $uri)));
$primaryLang = $project['primary_lang'] ?: 'de';
$activeLangs = array_filter(array_map('trim', explode(',', $project['active_languages'] ?? 'de,en')));

// Cron Audit Endpoint (/api/cron/audit)
if (!empty($parts) && $parts[0] === 'api' && ($parts[1] ?? '') === 'cron' && ($parts[2] ?? '') === 'audit') {
    require_cron_secret();
    handle_cron_audit($project, $db);
    exit;
}

// Vorschau-Kennzeichnung (.../preview) abtrennen, gilt für Seiten- und API-Routen gleichermaßen
$isPreview = false;
if (!empty($parts) && end($parts) === 'preview') {
    array_pop($parts);
    $isPreview = true;
}

// JSON API Endpoint (/api/de/impressum oder /api/impressum)
if (!empty($parts) && $parts[0] === 'api') {
    if (rate_limit_wait($db, 'api:' . get_client_ip(), 120, 5) > 0) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'rate_limited']);
        exit;
    }
    record_login_failure($db, 'api:' . get_client_ip());
    handle_json_api($parts, $project, $db, $primaryLang, $isPreview);
    exit;
}

if (empty($parts)) {
    render_public_overview($project, $db, $primaryLang);
    exit;
}

if (count($parts) === 1) {
    $first = strtolower($parts[0]);
    if (in_array($first, $activeLangs) || (strlen($first) === 2 && !is_numeric($first))) {
        render_public_overview($project, $db, $first);
        exit;
    }
    $lang = $primaryLang;
    $slug = $first;
} else {
    $lang = strtolower($parts[0]);
    $slug = strtolower($parts[1]);
}

$stmt = $db->prepare("
    SELECT t.*, d.doc_type_id, dt.title AS default_title, dt.slug AS default_slug
    FROM translations t
    JOIN documents d ON t.document_id = d.id
    JOIN doc_types dt ON d.doc_type_id = dt.id
    WHERE d.project_id = ? AND t.lang = ? AND (t.slug = ? OR dt.slug = ?) AND t.status = 'published'
    LIMIT 1
");
$stmt->execute([$project['id'], $lang, $slug, $slug]);
$trans = $stmt->fetch();

if (!$trans) {
    http_response_code(404);
    render_public_404($project, $lang);
    exit;
}

$liveSlug = $trans['slug'];

if ($isPreview) {
    if (empty($trans['scheduled_at'])) {
        http_response_code(404);
        render_public_404($project, $lang);
        exit;
    }
    $trans['title'] = $trans['scheduled_title'] !== '' ? $trans['scheduled_title'] : $trans['title'];
    $trans['slug'] = $trans['scheduled_slug'] !== '' ? $trans['scheduled_slug'] : $trans['slug'];
    $previewContent = $trans['scheduled_content'] !== '' ? $trans['scheduled_content'] : $trans['content'];
} else {
    $previewContent = $trans['content'];
}

$stmt = $db->prepare("
    SELECT t.lang, t.slug, dt.slug as default_slug
    FROM translations t
    JOIN documents d ON t.document_id = d.id
    JOIN doc_types dt ON d.doc_type_id = dt.id
    WHERE t.document_id = ? AND t.status = 'published'
");
$stmt->execute([$trans['document_id']]);
$languages = $stmt->fetchAll();

$content = replace_placeholders(sanitize_legal_html($previewContent), $project);
render_public_document($project, $trans, $content, $languages, $lang, $isPreview, $liveSlug);

function get_i18n_strings(string $lang): array {
    $dict = [
        'de' => [
            'toc' => 'Inhaltsverzeichnis',
            'search_ph' => 'Dokument durchsuchen (z. B. Cookies, Haftung, Widerruf)...',
            'read_time' => '~%d Min. Lesezeit',
            'last_updated' => 'Stand: %s',
            'print' => 'Drucken',
            'overview_title' => 'Rechtliche Dokumente',
            'overview_sub' => 'Rechtliche Hinweise und Bestimmungen.',
            'not_found_title' => 'Dokument nicht gefunden',
            'not_found_desc' => 'Der angeforderte Rechtstext existiert nicht oder wurde noch nicht freigegeben.',
            'back_to_overview' => 'Zurück zur Übersicht',
            'copy_anchor' => 'Direktlink kopieren',
            'preview_suffix' => ' (Vorschau)',
            'print_title' => 'Drucken oder als PDF speichern',
            'preview_banner_text' => 'Vorschau: Dies ist die geplante Neufassung. Sie geht am %s Uhr live. Bis dahin gilt weiterhin die aktuelle Fassung.',
            'preview_banner_link' => 'Aktuelle Fassung ansehen &rarr;',
            'upcoming_banner_text' => 'Geplante Neufassung verfügbar: Diese Bestimmungen werden zum %s aktualisiert.',
            'upcoming_banner_link' => 'Vorab ansehen &rarr;',
            'powered_by_toc' => 'Bereitgestellt mit Paragrafy',
            'powered_by_footer' => 'Bereitgestellt über Paragrafy'
        ],
        'en' => [
            'toc' => 'Table of Contents',
            'search_ph' => 'Search document (e.g. cookies, liability, revocation)...',
            'read_time' => '~%d min read',
            'last_updated' => 'Last updated: %s',
            'print' => 'Print',
            'overview_title' => 'Legal Documents',
            'overview_sub' => 'Legal notices, policies and terms.',
            'not_found_title' => 'Document not found',
            'not_found_desc' => 'The requested legal document does not exist or has not been published yet.',
            'back_to_overview' => 'Back to Overview',
            'copy_anchor' => 'Copy section link',
            'preview_suffix' => ' (Preview)',
            'print_title' => 'Print or save as PDF',
            'preview_banner_text' => 'Preview: this is the scheduled new version. It goes live at %s. Until then, the current version remains in effect.',
            'preview_banner_link' => 'View current version &rarr;',
            'upcoming_banner_text' => 'Upcoming revision available: these terms will be updated on %s.',
            'upcoming_banner_link' => 'Preview now &rarr;',
            'powered_by_toc' => 'Powered by Paragrafy',
            'powered_by_footer' => 'Powered by Paragrafy'
        ],
        'es' => [
            'toc' => 'Índice de contenidos',
            'search_ph' => 'Buscar en el documento (ej. cookies, responsabilidad)...',
            'read_time' => '~%d min de lectura',
            'last_updated' => 'Última actualización: %s',
            'print' => 'Imprimir',
            'overview_title' => 'Documentos legales',
            'overview_sub' => 'Avisos legales, términos y políticas.',
            'not_found_title' => 'Documento no encontrado',
            'not_found_desc' => 'El documento legal solicitado no existe o aún no ha sido publicado.',
            'back_to_overview' => 'Volver a la vista general',
            'copy_anchor' => 'Copiar enlace',
            'preview_suffix' => ' (Vista previa)',
            'print_title' => 'Imprimir o guardar como PDF',
            'preview_banner_text' => 'Vista previa: esta es la nueva versión programada. Estará activa a partir del %s. Hasta entonces, sigue vigente la versión actual.',
            'preview_banner_link' => 'Ver versión actual &rarr;',
            'upcoming_banner_text' => 'Nueva versión programada disponible: estas condiciones se actualizarán el %s.',
            'upcoming_banner_link' => 'Ver vista previa &rarr;',
            'powered_by_toc' => 'Desarrollado con Paragrafy',
            'powered_by_footer' => 'Desarrollado con Paragrafy'
        ],
        'fr' => [
            'toc' => 'Table des matières',
            'search_ph' => 'Rechercher dans le document (ex. cookies, responsabilité)...',
            'read_time' => '~%d min de lecture',
            'last_updated' => 'Dernière mise à jour: %s',
            'print' => 'Imprimer',
            'overview_title' => 'Documents juridiques',
            'overview_sub' => 'Mentions légales, conditions et politiques.',
            'not_found_title' => 'Document non trouvé',
            'not_found_desc' => 'Le document juridique demandé n\'existe pas ou n\'a pas encore été publié.',
            'back_to_overview' => 'Retour à la vue d\'ensemble',
            'copy_anchor' => 'Copier le lien',
            'preview_suffix' => ' (Aperçu)',
            'print_title' => 'Imprimer ou enregistrer en PDF',
            'preview_banner_text' => 'Aperçu : voici la nouvelle version prévue. Elle entrera en vigueur le %s. D\'ici là, la version actuelle reste applicable.',
            'preview_banner_link' => 'Voir la version actuelle &rarr;',
            'upcoming_banner_text' => 'Nouvelle version prévue : ces dispositions seront mises à jour le %s.',
            'upcoming_banner_link' => 'Aperçu anticipé &rarr;',
            'powered_by_toc' => 'Propulsé par Paragrafy',
            'powered_by_footer' => 'Propulsé par Paragrafy'
        ],
        'it' => [
            'toc' => 'Indice dei contenuti',
            'search_ph' => 'Cerca nel documento (es. cookie, responsabilità)...',
            'read_time' => '~%d min di lettura',
            'last_updated' => 'Ultimo aggiornamento: %s',
            'print' => 'Stampa',
            'overview_title' => 'Documenti legali',
            'overview_sub' => 'Note legali, termini e informative.',
            'not_found_title' => 'Documento non trovato',
            'not_found_desc' => 'Il documento legale richiesto non esiste o non è ancora stato pubblicato.',
            'back_to_overview' => 'Torna alla panoramica',
            'copy_anchor' => 'Copia link',
            'preview_suffix' => ' (Anteprima)',
            'print_title' => 'Stampa o salva come PDF',
            'preview_banner_text' => 'Anteprima: questa è la nuova versione pianificata. Sarà attiva dal %s. Fino ad allora resta valida la versione attuale.',
            'preview_banner_link' => 'Vedi versione attuale &rarr;',
            'upcoming_banner_text' => 'Nuova versione pianificata disponibile: queste disposizioni saranno aggiornate il %s.',
            'upcoming_banner_link' => 'Anteprima anticipata &rarr;',
            'powered_by_toc' => 'Offerto da Paragrafy',
            'powered_by_footer' => 'Offerto da Paragrafy'
        ],
        'nl' => [
            'toc' => 'Inhoudsopgave',
            'search_ph' => 'Document doorzoeken (bijv. cookies, aansprakelijkheid)...',
            'read_time' => '~%d min leestijd',
            'last_updated' => 'Laatst bijgewerkt: %s',
            'print' => 'Afdrukken',
            'overview_title' => 'Juridische documenten',
            'overview_sub' => 'Juridische kennisgevingen en voorwaarden.',
            'not_found_title' => 'Document niet gevonden',
            'not_found_desc' => 'Het opgevraagde juridische document bestaat niet of is nog niet gepubliceerd.',
            'back_to_overview' => 'Terug naar het overzicht',
            'copy_anchor' => 'Kopieer link',
            'preview_suffix' => ' (Voorbeeld)',
            'print_title' => 'Afdrukken of opslaan als PDF',
            'preview_banner_text' => 'Voorbeeld: dit is de geplande nieuwe versie. Deze gaat live op %s. Tot die tijd blijft de huidige versie geldig.',
            'preview_banner_link' => 'Huidige versie bekijken &rarr;',
            'upcoming_banner_text' => 'Geplande wijziging beschikbaar: deze bepalingen worden bijgewerkt op %s.',
            'upcoming_banner_link' => 'Vooraf bekijken &rarr;',
            'powered_by_toc' => 'Mogelijk gemaakt door Paragrafy',
            'powered_by_footer' => 'Mogelijk gemaakt door Paragrafy'
        ]
    ];
    return $dict[$lang] ?? $dict['en'];
}

/**
 * DSGVO-Nachweispflicht: receives the fire-and-forget POST that /consent.js
 * sends whenever a visitor accepts/declines the cookie banner, and stores a
 * privacy-preserving proof record via log_consent(). Called from an
 * arbitrary third-party website, so it needs its own CORS handling (the
 * JSON Content-Type makes this a non-simple request, requiring an OPTIONS
 * preflight response) independent of the read-only JSON API above.
 */
function handle_consent_log(?array $project, PDO $db): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$project || empty($project['consent_logging_enabled'])) {
        echo json_encode(['success' => false]);
        return;
    }

    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }

    $action = (string)($body['action'] ?? '');
    if ($action !== 'accepted' && $action !== 'declined') {
        http_response_code(400);
        echo json_encode(['success' => false]);
        return;
    }

    $consentId = substr(trim((string)($body['consentId'] ?? '')), 0, 100);
    $lang = substr(trim((string)($body['lang'] ?? '')), 0, 20);
    $textHash = substr(trim((string)($body['textHash'] ?? '')), 0, 64);

    if (!preg_match('/^[A-Za-z0-9\-]{1,100}$/', $consentId) || !preg_match('/^[a-f0-9]{64}$/', $textHash)) {
        http_response_code(400);
        echo json_encode(['success' => false]);
        return;
    }

    $rateIdentifier = 'consent:' . $project['id'] . ':' . get_client_ip();
    if (rate_limit_wait($db, $rateIdentifier, 30, 15) > 0) {
        http_response_code(429);
        echo json_encode(['success' => false]);
        return;
    }
    record_login_failure($db, $rateIdentifier);

    log_consent(
        $db,
        (int)$project['id'],
        $consentId,
        $action,
        $lang,
        $textHash,
        get_client_ip(),
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    echo json_encode(['success' => true]);
}

function handle_cron_audit(array $project, PDO $db): void {
    header('Content-Type: application/json');
    echo json_encode(run_audit_check($project, $db));
}

function handle_json_api(array $parts, array $project, PDO $db, string $primaryLang, bool $isPreview = false): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $lang = $primaryLang;
    $slug = '';

    if (count($parts) === 2) {
        $slug = strtolower($parts[1]);
    } elseif (count($parts) >= 3) {
        $lang = strtolower($parts[1]);
        $slug = strtolower($parts[2]);
    }

    if (empty($slug)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing document slug']);
        return;
    }

    $stmt = $db->prepare("
        SELECT t.title, t.slug, t.lang, t.content, t.updated_at, t.change_note, t.scheduled_at, t.scheduled_title, t.scheduled_slug, t.scheduled_content
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE d.project_id = ? AND t.lang = ? AND (t.slug = ? OR dt.slug = ?) AND t.status = 'published'
        LIMIT 1
    ");
    $stmt->execute([$project['id'], $lang, $slug, $slug]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found or not published']);
        return;
    }

    if ($isPreview) {
        if (empty($doc['scheduled_at'])) {
            http_response_code(404);
            echo json_encode(['error' => 'No scheduled preview available for this document']);
            return;
        }
        $doc['title'] = $doc['scheduled_title'] !== '' ? $doc['scheduled_title'] : $doc['title'];
        $doc['slug'] = $doc['scheduled_slug'] !== '' ? $doc['scheduled_slug'] : $doc['slug'];
        $doc['content'] = $doc['scheduled_content'] !== '' ? $doc['scheduled_content'] : $doc['content'];
    }

    $doc['rendered_html'] = replace_placeholders(sanitize_legal_html($doc['content']), $project);
    $response = [
        'project' => $project['name'],
        'title' => $doc['title'],
        'slug' => $doc['slug'],
        'lang' => $doc['lang'],
        'updated_at' => $doc['updated_at'],
        'change_note' => $doc['change_note'],
        'html' => $doc['rendered_html'],
        'preview' => $isPreview
    ];
    if ($isPreview) {
        $response['effective_date'] = date('c', strtotime($doc['scheduled_at']));
    }
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function render_public_document(array $project, array $trans, string $content, array $languages, string $currentLang, bool $isPreview = false, ?string $liveSlug = null): void {
    $liveSlug = $liveSlug ?? $trans['slug'];
    $brand = htmlspecialchars($project['brand_color'] ?: '#F0A63C');
    $logoUrl = $project['logo_url'] ?: '/paragrafy.svg';
    $i18n = get_i18n_strings($currentLang);

    $wordCount = str_word_count(strip_tags($content));
    $readMinutes = max(1, (int)ceil($wordCount / 200));
    $readTimeStr = sprintf($i18n['read_time'], $readMinutes);
    $lastUpdatedStr = sprintf($i18n['last_updated'], date('d.m.Y', strtotime($trans['updated_at'])));
    $hasUpcoming = !$isPreview && !empty($trans['scheduled_at']);
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($currentLang) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php if ($isPreview): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
        <title><?= htmlspecialchars($trans['title']) ?><?= $isPreview ? htmlspecialchars($i18n['preview_suffix']) : '' ?> - <?= htmlspecialchars($project['name']) ?></title>
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($logoUrl) ?>">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#F0A63C', false) ?>
        <style>
            body { line-height: 1.7; }
            .preview-banner { background: oklch(24% 0.05 250); border-radius: 10px; padding: 14px 18px; margin-bottom: 22px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
            .preview-banner span { font-size: 13px; color: oklch(85% 0.04 250); }
            .preview-banner a.btn-preview { border: none; background: oklch(58% 0.16 250); color: #fff; border-radius: 7px; padding: 7px 14px; font-size: 12px; font-weight: 700; text-decoration: none; margin-left: auto; }
            .hero { background: #17141b; padding: 24px 40px 40px; }
            .hero-inner { max-width: 1160px; margin: 0 auto; }
            .hero-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 1rem; }
            .brand-wrap { display: flex; align-items: center; gap: 10px; text-decoration: none; }
            .brand-wrap img { width: 30px; height: 30px; border-radius: 7px; }
            .brand-name { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 17px; color: #fff; }

            .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
            .lang-switch a { text-decoration: none; padding: 6px 14px; border-radius: 7px; font-size: 12px; border: 1px solid rgba(255,255,255,0.16); color: rgba(255,255,255,0.6); font-weight: 700; }
            .lang-switch a.active { background: var(--accent); color: #fff; border-color: var(--accent); }
            .btn-action { background: transparent; border: 1px solid rgba(255,255,255,0.16); color: rgba(255,255,255,0.75); padding: 6px 14px; border-radius: 7px; font-size: 12px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; font-family: 'IBM Plex Sans', sans-serif; }
            .btn-action:hover { border-color: rgba(255,255,255,0.4); color: #fff; }

            h1 { font-size: 32px; margin: 0 0 14px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.2; color: #fff; }
            .meta-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; font-size: 12px; }
            .meta-pill { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); padding: 6px 12px; border-radius: 20px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }

            .search-box { position: relative; }
            .search-box input { width: 100%; box-sizing: border-box; padding: 12px 16px 12px 40px; border: 1px solid rgba(255,255,255,0.14); border-radius: 8px; background: rgba(255,255,255,0.06); color: #fff; font-size: 13px; outline: none; font-family: 'IBM Plex Sans', sans-serif; }
            .search-box input::placeholder { color: rgba(255,255,255,0.4); }
            .search-box input:focus { border-color: var(--accent); }
            .search-box svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.4); pointer-events: none; }

            .wrapper { max-width: 1160px; margin: 0 auto; padding: 36px 40px 90px; display: grid; grid-template-columns: 1fr 280px; gap: 48px; align-items: start; }
            @media (max-width: 980px) { .wrapper { grid-template-columns: 1fr; } .toc-sidebar { display: none; } .hero { padding: 20px 20px 28px; } .wrapper { padding: 28px 20px 60px; } }

            .content { font-size: 14.5px; color: var(--text); }
            .content h1, .content h2, .content h3 { scroll-margin-top: 2rem; position: relative; }
            .content h1 { font-size: 26px; font-weight: 800; margin-top: 2.25rem; margin-bottom: 0.75rem; color: var(--text); }
            .content h2 { font-size: 22px; font-weight: 700; margin-top: 2.25rem; margin-bottom: 0.75rem; }
            .content h3 { font-size: 16px; font-weight: 700; margin-top: 1.75rem; margin-bottom: 0.5rem; }
            .content a { text-decoration: underline; }
            .content p { margin: 1rem 0; }

            .anchor-link { opacity: 0; margin-left: 0.5rem; text-decoration: none !important; color: var(--text-faint) !important; font-weight: normal; transition: opacity 0.2s; font-size: 0.9em; }
            .content h1:hover .anchor-link, .content h2:hover .anchor-link, .content h3:hover .anchor-link { opacity: 1; }

            .toc-sidebar { position: sticky; top: 24px; border-left: 1px solid var(--border); padding-left: 24px; }
            .toc-title { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-faint); letter-spacing: 0.06em; margin-bottom: 14px; }
            .toc-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 11px; max-height: calc(100vh - 12rem); overflow-y: auto; }
            .toc-link { display: block; font-size: 13px; color: var(--text-muted); text-decoration: none; border-left: 2px solid transparent; padding-left: 10px; margin-left: -11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .toc-link:hover { color: var(--text); }
            .toc-link.active { color: var(--accent); font-weight: 600; border-left-color: var(--accent); }
            .toc-brand { display: flex; align-items: center; gap: 6px; margin-top: 28px; font-size: 12px; color: var(--text-faint); font-weight: 500; }
            .toc-brand img { width: 15px; height: 15px; border-radius: 4px; display: block; }

            footer { text-align: center; padding-bottom: 2rem; font-size: 12px; color: var(--text-faint); }
            @media print { .header-actions, .toc-sidebar, .search-box, .anchor-link, .hero a, .preview-banner, footer { display: none; } .hero { background: #fff; padding: 0; } h1, .brand-name { color: #000; } .wrapper { display: block; padding: 0; } body { background: #fff; color: #000; } }
        </style>
    </head>
    <body>
        <div class="hero">
            <div class="hero-inner">
                <div class="hero-top">
                    <a href="/<?= htmlspecialchars($currentLang) ?>" class="brand-wrap">
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                        <span class="brand-name"><?= htmlspecialchars($project['name']) ?></span>
                    </a>
                    <div class="header-actions">
                        <div class="lang-switch">
                            <?php foreach ($languages as $l): ?>
                                <a href="/<?= htmlspecialchars($l['lang']) ?>/<?= htmlspecialchars($l['slug'] ?: $l['default_slug']) ?><?= $isPreview ? '/preview' : '' ?>" class="<?= $l['lang'] === $currentLang ? 'active' : '' ?>">
                                    <?= strtoupper(htmlspecialchars($l['lang'])) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <button onclick="window.print()" class="btn-action" title="<?= htmlspecialchars($i18n['print_title']) ?>">
                            <?= svg_icon('print', '', 14) ?> <?= htmlspecialchars($i18n['print']) ?>
                        </button>
                    </div>
                </div>

                <?php if ($isPreview): ?>
                    <div class="preview-banner">
                        <span><?= sprintf($i18n['preview_banner_text'], '<strong style="color:#fff">' . date('d.m.Y \u\m H:i', strtotime($trans['scheduled_at'])) . '</strong>') ?></span>
                        <a href="/<?= htmlspecialchars($currentLang) ?>/<?= htmlspecialchars($liveSlug) ?>" class="btn-preview"><?= $i18n['preview_banner_link'] ?></a>
                    </div>
                <?php elseif ($hasUpcoming): ?>
                    <div class="preview-banner">
                        <span><?= sprintf($i18n['upcoming_banner_text'], '<strong style="color:#fff">' . date('d.m.Y', strtotime($trans['scheduled_at'])) . '</strong>') ?></span>
                        <a href="/<?= htmlspecialchars($currentLang) ?>/<?= htmlspecialchars($trans['slug']) ?>/preview" class="btn-preview"><?= $i18n['upcoming_banner_link'] ?></a>
                    </div>
                <?php endif; ?>

                <h1><?= htmlspecialchars($trans['title']) ?></h1>
                <div class="meta-bar">
                    <span class="meta-pill"><?= svg_icon('clock', '', 13) ?> <?= htmlspecialchars($lastUpdatedStr) ?></span>
                    <span class="meta-pill"><?= svg_icon('eye', '', 13) ?> <?= htmlspecialchars($readTimeStr) ?></span>
                    <?php if (!empty($trans['change_note'])): ?>
                        <span class="meta-pill"><?= svg_icon('edit', '', 13) ?> <?= htmlspecialchars($trans['change_note']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="search-box">
                    <?= svg_icon('search', '', 16) ?>
                    <input type="text" id="docSearchInput" placeholder="<?= htmlspecialchars($i18n['search_ph']) ?>" oninput="filterDocumentText(this.value)">
                </div>
            </div>
        </div>

        <div class="wrapper">
            <main>
                <div class="content" id="documentContent">
                    <?= $content ?>
                </div>
            </main>

            <aside class="toc-sidebar">
                <div class="toc-title"><?= htmlspecialchars($i18n['toc']) ?></div>
                <ul class="toc-list" id="tocList" style="list-style:none;padding:0;margin:0"></ul>
                <div class="toc-brand"><img src="<?= htmlspecialchars($logoUrl) ?>" alt=""> <?= htmlspecialchars($i18n['powered_by_toc']) ?></div>
            </aside>
        </div>

        <footer>
            &copy; <?= date('Y') ?> <?= htmlspecialchars($project['company_name'] ?: $project['name']) ?> &bull; <?= htmlspecialchars($i18n['powered_by_footer']) ?>
            <?= render_locale_switcher() ?>
        </footer>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const content = document.getElementById('documentContent');
                const headings = content.querySelectorAll('h1, h2, h3');
                const tocList = document.getElementById('tocList');

                if (headings.length === 0) {
                    document.querySelector('.toc-sidebar').style.display = 'none';
                    document.querySelector('.wrapper').style.gridTemplateColumns = '1fr';
                    return;
                }

                headings.forEach((h, idx) => {
                    const id = 'section-' + idx;
                    h.id = id;

                    const anchor = document.createElement('a');
                    anchor.className = 'anchor-link';
                    anchor.href = '#' + id;
                    anchor.innerHTML = '#';
                    anchor.title = '<?= htmlspecialchars($i18n['copy_anchor']) ?>';
                    anchor.onclick = (e) => {
                        e.preventDefault();
                        navigator.clipboard.writeText(window.location.origin + window.location.pathname + '#' + id);
                        window.location.hash = id;
                    };
                    h.appendChild(anchor);

                    const li = document.createElement('li');
                    li.className = 'toc-item';
                    const link = document.createElement('a');
                    link.className = 'toc-link' + (h.tagName === 'H3' ? ' toc-sub' : '');
                    link.href = '#' + id;
                    link.innerText = h.childNodes[0].nodeValue.trim();
                    link.dataset.target = id;
                    li.appendChild(link);
                    tocList.appendChild(li);
                });

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            document.querySelectorAll('.toc-link').forEach(l => l.classList.remove('active'));
                            const activeLink = document.querySelector(`.toc-link[data-target="${entry.target.id}"]`);
                            if (activeLink) activeLink.classList.add('active');
                        }
                    });
                }, { rootMargin: '0px 0px -70% 0px' });

                headings.forEach(h => observer.observe(h));
            });

            function filterDocumentText(term) {
                const paragraphs = document.querySelectorAll('#documentContent p, #documentContent li, #documentContent h1, #documentContent h2, #documentContent h3');
                const query = term.toLowerCase().trim();
                paragraphs.forEach(p => {
                    if (query === '' || p.innerText.toLowerCase().includes(query)) {
                        p.style.opacity = '1';
                        p.style.display = '';
                    } else {
                        p.style.opacity = '0.15';
                    }
                });
            }
        </script>
    </body>
    </html>
    <?php
}

function render_public_overview(array $project, PDO $db, string $lang): void {
    $brand = htmlspecialchars($project['brand_color'] ?: '#F0A63C');
    $logoUrl = $project['logo_url'] ?: '/paragrafy.svg';
    $i18n = get_i18n_strings($lang);

    $stmt = $db->prepare("
        SELECT t.title, t.slug, dt.slug as default_slug, dt.title as fallback_title
        FROM documents d
        JOIN doc_types dt ON d.doc_type_id = dt.id
        LEFT JOIN translations t ON d.id = t.document_id AND t.lang = ? AND t.status = 'published'
        WHERE d.project_id = ?
    ");
    $stmt->execute([$lang, $project['id']]);
    $docs = $stmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($lang) ?>">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($i18n['overview_title']) ?> - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($logoUrl) ?>">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#F0A63C', false) ?>
        <style>
            body { display: flex; align-items: center; justify-content: center; padding: 70px 20px; }
            .box { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 36px; max-width: 460px; width: 100%; box-shadow: 0 2px 12px rgba(0,0,0,.04); }
            .head-logo { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
            .head-logo img { width: 32px; height: 32px; border-radius: 8px; }
            .head-logo span { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 19px; letter-spacing: -0.01em; }
            ul { list-style: none; padding: 0; margin: 20px 0 0; display: flex; flex-direction: column; gap: 10px; }
            li a { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border: 1px solid var(--border); border-radius: 10px; color: var(--text); text-decoration: none; font-weight: 600; font-size: 14px; }
            li a:hover { border-color: var(--accent); }
            li a span:last-child { color: var(--text-faint); }
            .toc-brand { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 14px; font-size: 12px; color: var(--text-faint); font-weight: 500; }
            .toc-brand img { width: 15px; height: 15px; border-radius: 4px; display: block; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="head-logo">
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                <span><?= htmlspecialchars($project['name']) ?></span>
            </div>
            <p style="font-size:14px;color:var(--text-muted);margin:0 0 20px;"><?= htmlspecialchars($i18n['overview_sub']) ?></p>
            <ul>
                <?php foreach ($docs as $doc): ?>
                    <?php if ($doc['title']): ?>
                        <li>
                            <a href="/<?= htmlspecialchars($lang) ?>/<?= htmlspecialchars($doc['slug'] ?: $doc['default_slug']) ?>">
                                <span><?= htmlspecialchars($doc['title']) ?></span>
                                <span>&rarr;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <p style="font-size:11px;color:var(--text-faintest);line-height:1.5;text-align:center;margin:22px 0 0"><?= htmlspecialchars($i18n['overview_title']) ?></p>
            <div class="toc-brand"><img src="<?= htmlspecialchars($logoUrl) ?>" alt=""> <?= htmlspecialchars($i18n['powered_by_toc']) ?></div>
            <?= render_locale_switcher() ?>
        </div>
    </body>
    </html>
    <?php
}

/**
 * Small language switcher for admin-facing UI chrome on public pages
 * (distinct from the document content-language switcher above, which is
 * driven by lang_meta()/active_languages). Links to the current URL with
 * ?locale=xx appended, using the labels/flags from ui_locales().
 */
function render_locale_switcher(): string {
    $locales = ui_locales();
    $current = current_locale();
    $qs = $_GET ?? [];
    $links = [];
    foreach ($locales as $code => $meta) {
        $qs['locale'] = $code;
        $href = htmlspecialchars('?' . http_build_query($qs));
        $label = htmlspecialchars(($meta['flag'] ?? '') . ' ' . ($meta['label'] ?? strtoupper($code)));
        $active = $code === $current ? ' style="font-weight:700;text-decoration:underline"' : '';
        $links[] = '<a href="' . $href . '"' . $active . '>' . $label . '</a>';
    }
    if (empty($links)) return '';
    return '<div style="margin-top:8px;font-size:11px;display:flex;gap:8px;justify-content:center;opacity:0.8"><span>' . htmlspecialchars(t('public.locale_switch_label')) . ':</span> ' . implode(' ', $links) . '</div>';
}

function render_public_404(array $project, string $lang): void {
    $i18n = get_i18n_strings($lang);
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($lang) ?>">
    <head>
        <meta charset="utf-8"><title>404 - <?= htmlspecialchars($i18n['not_found_title']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#F0A63C', false) ?>
    </head>
    <body style="text-align:center; padding: 5rem 1rem;">
        <h2 style="font-size:20px;font-weight:800;"><?= htmlspecialchars($i18n['not_found_title']) ?></h2>
        <p style="color:var(--text-muted)"><?= htmlspecialchars($i18n['not_found_desc']) ?></p>
        <p><a href="/<?= htmlspecialchars($lang) ?>" style="font-weight:700;">&larr; <?= htmlspecialchars($i18n['back_to_overview']) ?></a></p>
        <?= render_locale_switcher() ?>
    </body></html>
    <?php
}

/**
 * In-App Embed-Drawer: bind buttons with data-paragrafy-slug / data-paragrafy-lang
 * and open a modal sheet loading the document via the public JSON API.
 */
function render_embed_js(): void {
    $i18nJs = json_encode([
        'loading' => t('public.embed.loading_title'),
        'close' => t('public.embed.close_label'),
        'loadingBody' => t('public.embed.loading_body'),
        'error' => t('public.embed.error_title'),
        'errorBody' => t('public.embed.error_body'),
    ], JSON_UNESCAPED_UNICODE);
    ?>
(function () {
    var i18n = <?= $i18nJs ?>;
    var scriptEl = document.currentScript;
    if (!scriptEl) {
        var scripts = document.getElementsByTagName('script');
        scriptEl = scripts[scripts.length - 1];
    }
    var origin;
    try { origin = new URL(scriptEl.src, window.location.href).origin; } catch (e) { origin = window.location.origin; }

    function injectStyles() {
        if (document.getElementById('paragrafy-embed-style')) return;
        var style = document.createElement('style');
        style.id = 'paragrafy-embed-style';
        style.textContent =
            '.paragrafy-embed-backdrop{position:fixed;inset:0;background:rgba(20,16,22,.5);z-index:999999;display:flex;align-items:flex-end;justify-content:center;padding:0}' +
            '@media (min-width:720px){.paragrafy-embed-backdrop{align-items:center;padding:20px}}' +
            '.paragrafy-embed-sheet{background:#fff;width:100%;max-width:640px;max-height:85vh;border-radius:16px 16px 0 0;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 -10px 40px rgba(0,0,0,.2);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' +
            '@media (min-width:720px){.paragrafy-embed-sheet{border-radius:16px}}' +
            '.paragrafy-embed-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #E8E4DC;flex-shrink:0}' +
            '.paragrafy-embed-header h2{margin:0;font-size:16px;font-weight:700;color:#201C24}' +
            '.paragrafy-embed-close{border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:#746E78;padding:4px;flex-shrink:0}' +
            '.paragrafy-embed-body{padding:20px;overflow-y:auto;font-size:14px;line-height:1.7;color:#201C24}' +
            '.paragrafy-embed-body h2{font-size:18px;margin-top:20px}' +
            '.paragrafy-embed-body h3{font-size:15px;margin-top:16px}' +
            '.paragrafy-embed-body p{margin:0.75em 0}' +
            '.paragrafy-embed-loading,.paragrafy-embed-error{padding:40px 20px;text-align:center;color:#746E78;font-size:14px}';
        document.head.appendChild(style);
    }

    function openDrawer(lang, slug) {
        injectStyles();
        var backdrop = document.createElement('div');
        backdrop.className = 'paragrafy-embed-backdrop';
        backdrop.innerHTML =
            '<div class="paragrafy-embed-sheet" role="dialog" aria-modal="true">' +
                '<div class="paragrafy-embed-header"><h2>' + i18n.loading + '</h2>' +
                '<button type="button" class="paragrafy-embed-close" aria-label="' + i18n.close + '">&times;</button></div>' +
                '<div class="paragrafy-embed-body"><div class="paragrafy-embed-loading">' + i18n.loadingBody + '</div></div>' +
            '</div>';
        document.body.appendChild(backdrop);

        function close() {
            backdrop.remove();
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', onKey);
        backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
        backdrop.querySelector('.paragrafy-embed-close').addEventListener('click', close);

        fetch(origin + '/api/' + encodeURIComponent(lang) + '/' + encodeURIComponent(slug))
            .then(function (res) {
                if (!res.ok) throw new Error('not_found');
                return res.json();
            })
            .then(function (data) {
                backdrop.querySelector('.paragrafy-embed-header h2').textContent = data.title || '';
                backdrop.querySelector('.paragrafy-embed-body').innerHTML = data.html || '';
            })
            .catch(function () {
                backdrop.querySelector('.paragrafy-embed-header h2').textContent = i18n.error;
                backdrop.querySelector('.paragrafy-embed-body').innerHTML =
                    '<div class="paragrafy-embed-error">' + i18n.errorBody + '</div>';
            });
    }

    function bind(el) {
        if (el.__paragrafyBound) return;
        el.__paragrafyBound = true;
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var slug = el.getAttribute('data-paragrafy-slug');
            var lang = el.getAttribute('data-paragrafy-lang') || 'de';
            if (slug) openDrawer(lang, slug);
        });
    }

    function scan() {
        var els = document.querySelectorAll('[data-paragrafy-slug]');
        for (var i = 0; i < els.length; i++) bind(els[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    if (window.MutationObserver) {
        new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
    <?php
}

/**
 * DSGVO Cookie-Consent-Banner. $project may be null (unknown domain) — falls
 * back to generic text/colors in that case.
 */
function render_consent_js(?array $project): void {
    $brand = $project['brand_color'] ?? '#F0A63C';
    $primaryLang = $project['primary_lang'] ?? 'de';
    $bannerText = trim($project['cookie_banner_text'] ?? '') ?: t('public.consent.default_text');

    // consent.js runs embedded on the CLIENT's website, so the privacy link
    // must be an absolute URL back to this Paragrafy instance -- a relative
    // path like "/de/datenschutz" would resolve against the embedding site
    // instead and 404 (or hit an unrelated page) there.
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    $selfHost = get_current_host();
    $privacySlug = 'datenschutz';
    if ($project) {
        $db = get_db();
        $stmt = $db->prepare("
            SELECT t.slug, dt.slug AS default_slug
            FROM translations t
            JOIN documents d ON t.document_id = d.id
            JOIN doc_types dt ON d.doc_type_id = dt.id
            WHERE d.project_id = ? AND t.lang = ? AND dt.slug = 'datenschutz' AND t.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([$project['id'], $primaryLang]);
        $privacyDoc = $stmt->fetch();
        if ($privacyDoc) {
            $privacySlug = $privacyDoc['slug'] ?: $privacyDoc['default_slug'];
        }
    }
    $privacyUrl = $scheme . '://' . $selfHost . '/' . $primaryLang . '/' . $privacySlug;

    $brandJs = json_encode($brand, JSON_UNESCAPED_UNICODE);
    $textJs = json_encode($bannerText, JSON_UNESCAPED_UNICODE);
    $privacyUrlJs = json_encode($privacyUrl, JSON_UNESCAPED_UNICODE);
    $loggingEnabledJs = json_encode(!empty($project['consent_logging_enabled']));
    $bannerTextHashJs = json_encode(hash('sha256', $bannerText));
    $i18nJs = json_encode([
        'learnMore' => t('public.consent.learn_more'),
        'decline' => t('public.consent.decline'),
        'accept' => t('public.consent.accept'),
        'ariaLabel' => t('public.consent.aria_label'),
    ], JSON_UNESCAPED_UNICODE);
    ?>
(function () {
    var CONSENT_KEY = 'paragrafy_consent';
    var CONSENT_ID_KEY = 'paragrafy_consent_id';
    try { if (localStorage.getItem(CONSENT_KEY)) return; } catch (e) {}

    var brand = <?= $brandJs ?>;
    var text = <?= $textJs ?>;
    var privacyUrl = <?= $privacyUrlJs ?>;
    var i18n = <?= $i18nJs ?>;
    var loggingEnabled = <?= $loggingEnabledJs ?>;
    var bannerTextHash = <?= $bannerTextHashJs ?>;
    var origin;
    try { origin = new URL((document.currentScript || {}).src || '', window.location.href).origin; } catch (e) { origin = window.location.origin; }

    var style = document.createElement('style');
    style.textContent =
        '.paragrafy-consent-bar{position:fixed;left:16px;right:16px;bottom:16px;max-width:560px;margin:0 auto;background:#201C24;color:#fff;border-radius:12px;padding:16px 18px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;line-height:1.5;z-index:999998;box-shadow:0 10px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;gap:12px}' +
        '.paragrafy-consent-bar a{color:#fff;text-decoration:underline}' +
        '.paragrafy-consent-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}' +
        '.paragrafy-consent-actions button{border:none;border-radius:8px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit}' +
        '.paragrafy-consent-decline{background:transparent !important;color:#fff;border:1px solid rgba(255,255,255,.3) !important}' +
        '.paragrafy-consent-accept{background:' + brand + ';color:#fff}';
    document.head.appendChild(style);

    var bar = document.createElement('div');
    bar.className = 'paragrafy-consent-bar';
    bar.setAttribute('role', 'dialog');
    bar.setAttribute('aria-label', i18n.ariaLabel);

    var textEl = document.createElement('div');
    textEl.textContent = text + ' ';
    var link = document.createElement('a');
    link.href = privacyUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = i18n.learnMore;
    textEl.appendChild(link);

    var actions = document.createElement('div');
    actions.className = 'paragrafy-consent-actions';
    var declineBtn = document.createElement('button');
    declineBtn.type = 'button';
    declineBtn.className = 'paragrafy-consent-decline';
    declineBtn.textContent = i18n.decline;
    var acceptBtn = document.createElement('button');
    acceptBtn.type = 'button';
    acceptBtn.className = 'paragrafy-consent-accept';
    acceptBtn.textContent = i18n.accept;

    actions.appendChild(declineBtn);
    actions.appendChild(acceptBtn);
    bar.appendChild(textEl);
    bar.appendChild(actions);
    document.body.appendChild(bar);

    function setConsent(value) {
        try { localStorage.setItem(CONSENT_KEY, value); } catch (e) {}
        bar.remove();
        document.dispatchEvent(new CustomEvent('paragrafy:consent', { detail: { consent: value } }));

        if (loggingEnabled) {
            var consentId = '';
            try { consentId = localStorage.getItem(CONSENT_ID_KEY) || ''; } catch (e) {}
            if (!consentId) {
                consentId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() :
                    ('cid-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2));
                try { localStorage.setItem(CONSENT_ID_KEY, consentId); } catch (e) {}
            }
            try {
                fetch(origin + '/api/consent-log', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: value,
                        lang: navigator.language || '',
                        textHash: bannerTextHash,
                        consentId: consentId
                    }),
                    keepalive: true
                }).catch(function () {});
            } catch (e) {}
        }
    }

    acceptBtn.addEventListener('click', function () { setConsent('accepted'); });
    declineBtn.addEventListener('click', function () { setConsent('declined'); });
})();
    <?php
}
