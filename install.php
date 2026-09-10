<?php
/**
 * Paragrafy - Interactive Setup & Installation Wizard
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

if (is_installed()) {
    header('Location: /admin');
    exit;
}

$error = null;
$host = get_current_host();

$standardTemplates = get_standard_legal_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminPass = $_POST['admin_password'] ?? '';
    $projectName = trim($_POST['project_name'] ?? '');
    $projectDomain = trim($_POST['project_domain'] ?? '');
    $primaryLang = $_POST['primary_lang'] ?? 'de';
    $selectedLangs = $_POST['active_languages'] ?? ['de'];
    $brandColor = trim($_POST['brand_color'] ?? '#F0A63C');
    $deeplApiKey = trim($_POST['deepl_api_key'] ?? '');
    if (!str_starts_with($brandColor, '#')) {
        $brandColor = '#' . $brandColor;
    }

    $companyName = trim($_POST['company_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $representative = trim($_POST['representative'] ?? '');
    $registerInfo = trim($_POST['register_info'] ?? '');

    $selectedPages = $_POST['pages'] ?? [];
    $customTitles = $_POST['custom_title'] ?? [];
    $customSlugs = $_POST['custom_slug'] ?? [];
    $customRequired = $_POST['custom_required'] ?? [];

    if (strlen($adminPass) < 10) {
        $error = t('install.error.password_too_short');
    } elseif (empty($projectName) || empty($projectDomain)) {
        $error = t('install.error.missing_fields');
    } elseif (!preg_match('/^[a-z0-9.-]+$/i', $projectDomain)) {
        $error = t('install.error.invalid_domain');
    } else {
        $lockFile = PARAGRAFY_DATA_DIR . '/.install.lock';
        $lockHandle = fopen($lockFile, 'c');
        if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $error = t('install.error.install_in_progress');
        } elseif (is_installed()) {
            // Eine parallele Anfrage hat die Installation zwischenzeitlich bereits
            // abgeschlossen -- sauber abbrechen statt ein zweites Projekt/config.php anzulegen.
            flock($lockHandle, LOCK_UN);
            header('Location: /admin');
            exit;
        } else {
        try {
            $pdo = get_db();
            init_database_schema($pdo);

            write_config([
                'admin_password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                'installed_at' => date('c'),
                'cron_secret' => bin2hex(random_bytes(32)),
                'ui_locale' => current_locale(),
            ]);

            $docTypeIds = [];
            foreach ($standardTemplates as $slugKey => $tpl) {
                $isRequired = in_array($slugKey, ['impressum', 'privacy']) ? 1 : (isset($selectedPages[$slugKey]) ? 0 : 0);
                $stmt = $pdo->prepare("INSERT INTO doc_types (slug, title, is_required) VALUES (?, ?, ?)");
                $stmt->execute([$tpl['slug'], $tpl['title'], $isRequired]);
                $docTypeIds[$slugKey] = (int)$pdo->lastInsertId();
            }

            $customCreatedIds = [];
            if (!empty($customTitles) && is_array($customTitles)) {
                foreach ($customTitles as $idx => $cTitle) {
                    $cTitle = trim((string)$cTitle);
                    if ($cTitle === '') continue;
                    $cSlug = trim((string)($customSlugs[$idx] ?? ''));
                    if ($cSlug === '') {
                        $cSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $cTitle));
                    }
                    $cIsReq = !empty($customRequired[$idx]) ? 1 : 0;

                    $stmt = $pdo->prepare("INSERT INTO doc_types (slug, title, is_required) VALUES (?, ?, ?)");
                    $stmt->execute([$cSlug, $cTitle, $cIsReq]);
                    $customCreatedIds[] = [
                        'id' => (int)$pdo->lastInsertId(),
                        'title' => $cTitle,
                        'slug' => $cSlug,
                        'is_required' => $cIsReq
                    ];
                }
            }

            $activeLanguagesStr = implode(',', array_unique(array_merge([$primaryLang], $selectedLangs)));
            $stmt = $pdo->prepare("
                INSERT INTO projects (domain, name, primary_lang, active_languages, brand_color, deepl_api_key, company_name, address, email, phone, representative, register_info)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $projectDomain, $projectName, $primaryLang, $activeLanguagesStr, $brandColor, $deeplApiKey,
                $companyName, $address, $email, $phone, $representative, $registerInfo
            ]);
            $projectId = (int)$pdo->lastInsertId();

            foreach ($selectedPages as $pageKey) {
                if (isset($standardTemplates[$pageKey]) && isset($docTypeIds[$pageKey])) {
                    $docTypeId = $docTypeIds[$pageKey];
                    $tpl = $standardTemplates[$pageKey];

                    $docStmt = $pdo->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                    $docStmt->execute([$projectId, $docTypeId]);
                    $documentId = (int)$pdo->lastInsertId();

                    $transStmt = $pdo->prepare("
                        INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, source_hash)
                        VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
                    ");
                    $transStmt->execute([
                        $documentId,
                        $primaryLang,
                        $tpl['title'],
                        $tpl['slug'],
                        $tpl['content'],
                        $tpl['content'],
                        md5($tpl['content'])
                    ]);
                }
            }

            foreach ($customCreatedIds as $cDoc) {
                $docStmt = $pdo->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $docStmt->execute([$projectId, $cDoc['id']]);
                $documentId = (int)$pdo->lastInsertId();

                $defaultContent = "<h2>" . htmlspecialchars($cDoc['title']) . "</h2>\n<p>Hier den Inhalt für {{company_name}} einfügen.</p>";
                $transStmt = $pdo->prepare("
                    INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, source_hash)
                    VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
                ");
                $transStmt->execute([
                    $documentId,
                    $primaryLang,
                    $cDoc['title'],
                    $cDoc['slug'],
                    $defaultContent,
                    $defaultContent,
                    md5($defaultContent)
                ]);
            }

            if (session_status() === PHP_SESSION_NONE) {
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
                session_start();
            }
            session_regenerate_id(true);
            $_SESSION['paragrafy_admin'] = true;
            flock($lockHandle, LOCK_UN);
            header('Location: /admin');
            exit;
        } catch (Throwable $e) {
            error_log('Paragrafy install failed: ' . $e->getMessage());
            $error = t('install.error.install_failed', ['message' => t('install.error.generic_detail')]);
        }
        flock($lockHandle, LOCK_UN);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars(t('install.page_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
    <style>
        :root {
            --primary: #F0A63C;
            --primary-dark: #C9862A;
            --accent-text: #F5C583;
            --accent-gradient: linear-gradient(135deg,#F0A63C,#F5C583);
            --bg: #10131F;
            --card: #1C1F2E;
            --text: #F5F5F7;
            --muted: #B4B7C7;
            --muted2: #8E92A6;
            --border: rgba(255,255,255,0.14);
            --input-bg: #0A0C14;
        }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 2.5rem 1rem; line-height: 1.5; }
        .wizard-container { max-width: 820px; margin: 0 auto; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 2.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
        .logo-header { display: flex; align-items: center; gap: 0.85rem; margin-bottom: 0.5rem; }
        .logo-header img { width: 38px; height: 38px; border-radius: 10px; box-shadow: 0 4px 12px rgba(240,166,60,0.3); }
        h1 { font-size: 1.85rem; margin: 0; color: #fff; font-weight: 800; letter-spacing: -0.02em; }
        .subtitle { color: var(--muted); margin-bottom: 2rem; font-size: 0.95rem; }
        .section { background: rgba(0,0,0,0.25); border: 1px solid var(--border); border-radius: 14px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .section-title { font-size: 1.05rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem; color: var(--accent-text); display: flex; align-items: center; gap: 0.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--muted); margin-bottom: 0.35rem; }
        input[type=text], input[type=password], input[type=email], textarea, select { width: 100%; box-sizing: border-box; background: var(--input-bg); border: 1px solid var(--border); border-radius: 9px; padding: 0.7rem 0.9rem; color: #fff; font-size: 0.9rem; transition: all 0.2s; }
        input:focus, textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(240,166,60,0.2); }
        .checkbox-group { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 0.5rem; }
        .checkbox-card { display: flex; align-items: center; gap: 0.6rem; background: var(--input-bg); padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 9px; cursor: pointer; transition: all 0.2s; }
        .checkbox-card:hover { border-color: rgba(255,255,255,0.32); }
        .checkbox-card input { cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary); }
        .color-picker-wrap { display: flex; align-items: center; gap: 0.5rem; }
        .color-picker-wrap input[type=color] { width: 44px; height: 40px; padding: 0; border: 1px solid var(--border); border-radius: 9px; background: transparent; cursor: pointer; }
        .color-picker-wrap input[type=text] { width: 130px; text-transform: uppercase; font-family: monospace; }
        .btn { width: 100%; background: var(--accent-gradient); box-shadow: 0 6px 18px rgba(240,166,60,0.3); color: #fff; border: none; padding: 0.9rem 1.5rem; border-radius: 9px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s; margin-top: 1rem; }
        .btn:hover { filter: brightness(1.08); }
        .btn-add { background: rgba(255,255,255,0.06); color: var(--text); border: 1px solid var(--border); padding: 0.5rem 1rem; border-radius: 9px; font-size: 0.8125rem; font-weight: 600; cursor: pointer; margin-top: 0.75rem; transition: all 0.2s; }
        .btn-add:hover { background: rgba(255,255,255,0.1); }
        .custom-row { display: grid; grid-template-columns: 1.5fr 1fr auto auto; gap: 0.5rem; align-items: center; background: var(--input-bg); padding: 0.6rem; border: 1px solid var(--border); border-radius: 9px; margin-top: 0.5rem; }
        .custom-row input { margin: 0; }
        .btn-del { background: rgba(248,113,113,0.15); color: #FCA5A5; border: 1px solid rgba(248,113,113,0.35); padding: 0.4rem 0.65rem; border-radius: 7px; cursor: pointer; font-weight: bold; }
        .error-box { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.35); color: #FCA5A5; padding: 1rem; border-radius: 9px; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="logo-header">
            <img src="/paragrafy.svg" alt="Paragrafy Logo">
            <h1><?= htmlspecialchars(t('install.heading')) ?></h1>
        </div>
        <div class="subtitle"><?= htmlspecialchars(t('install.subtitle')) ?></div>

        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="section">
                <div class="section-title"><?= htmlspecialchars(t('install.section1.title')) ?></div>
                <label><?= htmlspecialchars(t('install.section1.password_label')) ?></label>
                <input type="password" name="admin_password" placeholder="<?= htmlspecialchars(t('install.section1.password_placeholder')) ?>" required autofocus>
            </div>

            <div class="section">
                <div class="section-title"><?= htmlspecialchars(t('install.section2.title')) ?></div>
                <div class="grid">
                    <div>
                        <label><?= htmlspecialchars(t('install.section2.project_name_label')) ?></label>
                        <input type="text" name="project_name" placeholder="<?= htmlspecialchars(t('install.section2.project_name_placeholder')) ?>" required>
                    </div>
                    <div>
                        <label><?= htmlspecialchars(t('install.section2.domain_label')) ?></label>
                        <input type="text" name="project_domain" value="<?= htmlspecialchars($host) ?>" required>
                    </div>
                </div>
                <div class="grid" style="margin-top: 1rem;">
                    <div>
                        <label><?= htmlspecialchars(t('install.section2.primary_lang_label')) ?></label>
                        <select name="primary_lang">
                            <option value="de" selected><?= htmlspecialchars(t('install.section2.lang_de')) ?></option>
                            <option value="en"><?= htmlspecialchars(t('install.section2.lang_en')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label><?= htmlspecialchars(t('install.section2.brand_color_label')) ?></label>
                        <div class="color-picker-wrap">
                            <input type="color" id="color_picker" value="#F0A63C" oninput="syncColor(this.value, 'text')">
                            <input type="text" id="color_text" name="brand_color" value="#F0A63C" placeholder="#F0A63C" maxlength="7" oninput="syncColor(this.value, 'picker')">
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <label><?= htmlspecialchars(t('install.section2.deepl_label')) ?></label>
                    <input type="text" name="deepl_api_key" placeholder="<?= htmlspecialchars(t('install.section2.deepl_placeholder')) ?>">
                </div>

                <div style="margin-top: 1rem;">
                    <label><?= htmlspecialchars(t('install.section2.active_langs_label')) ?></label>
                    <div class="checkbox-group">
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="de" checked disabled> <?= htmlspecialchars(t('install.section2.lang_de_base')) ?></label>
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="en" checked> <?= htmlspecialchars(t('install.section2.lang_en')) ?></label>
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="es"> <?= htmlspecialchars(t('install.section2.lang_es')) ?></label>
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="fr"> <?= htmlspecialchars(t('install.section2.lang_fr')) ?></label>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title"><?= htmlspecialchars(t('install.section3.title')) ?></div>
                <p style="color: var(--muted); font-size: 0.8125rem; margin-top: 0;"><?= htmlspecialchars(t('install.section3.help')) ?></p>

                <div class="checkbox-group">
                    <?php foreach ($standardTemplates as $k => $t): ?>
                        <label class="checkbox-card">
                            <input type="checkbox" name="pages[]" value="<?= $k ?>" <?= $t['checked'] ? 'checked' : '' ?>>
                            <?= htmlspecialchars($t['title']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 1.5rem;">
                    <label><?= htmlspecialchars(t('install.section3.custom_label')) ?></label>
                    <div id="custom_docs_container"></div>
                    <button type="button" class="btn-add" onclick="addCustomDoc()"><?= htmlspecialchars(t('install.section3.add_btn')) ?></button>
                </div>
            </div>

            <div class="section">
                <div class="section-title"><?= htmlspecialchars(t('install.section4.title')) ?></div>
                <div class="grid">
                    <div>
                        <label><?= htmlspecialchars(t('install.section4.company_label')) ?></label>
                        <input type="text" name="company_name" placeholder="<?= htmlspecialchars(t('install.section4.company_placeholder')) ?>">
                    </div>
                    <div>
                        <label><?= htmlspecialchars(t('install.section4.representative_label')) ?></label>
                        <input type="text" name="representative" placeholder="<?= htmlspecialchars(t('install.section4.representative_placeholder')) ?>">
                    </div>
                </div>
                <div style="margin-top: 1rem;">
                    <label><?= htmlspecialchars(t('install.section4.address_label')) ?></label>
                    <textarea name="address" rows="2" placeholder="Musterweg 1&#10;12345 Musterstadt, Deutschland"></textarea>
                </div>
                <div class="grid" style="margin-top: 1rem;">
                    <div>
                        <label><?= htmlspecialchars(t('install.section4.email_label')) ?></label>
                        <input type="email" name="email" placeholder="<?= htmlspecialchars(t('install.section4.email_placeholder')) ?>">
                    </div>
                    <div>
                        <label><?= htmlspecialchars(t('install.section4.phone_label')) ?></label>
                        <input type="text" name="phone" placeholder="<?= htmlspecialchars(t('install.section4.phone_placeholder')) ?>">
                    </div>
                </div>
                <div style="margin-top: 1rem;">
                    <label><?= htmlspecialchars(t('install.section4.register_label')) ?></label>
                    <input type="text" name="register_info" placeholder="<?= htmlspecialchars(t('install.section4.register_placeholder')) ?>">
                </div>
            </div>

            <button type="submit" class="btn"><?= t('install.submit_btn') ?></button>
        </form>
    </div>

    <script>
        function syncColor(val, target) {
            if (target === 'text') {
                document.getElementById('color_text').value = val.toUpperCase();
            } else if (target === 'picker') {
                let hex = val.trim();
                if (!hex.startsWith('#')) hex = '#' + hex;
                if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
                    document.getElementById('color_picker').value = hex;
                }
            }
        }

        const i18n = {
            customTitlePlaceholder: <?= json_encode(t('install.custom_title_placeholder')) ?>,
            customSlugPlaceholder: <?= json_encode(t('install.custom_slug_placeholder')) ?>,
            customRequiredLabel: <?= json_encode(t('install.custom_required_label')) ?>
        };

        let customIdx = 0;
        function addCustomDoc(title = '', slug = '', isReq = false) {
            const container = document.getElementById('custom_docs_container');
            const div = document.createElement('div');
            div.className = 'custom-row';
            div.id = 'crow_' + customIdx;
            div.innerHTML = `
                <input type="text" name="custom_title[]" value="${title}" placeholder="${i18n.customTitlePlaceholder}" required oninput="autoSlug(this, ${customIdx})">
                <input type="text" name="custom_slug[]" id="cslug_${customIdx}" value="${slug}" placeholder="${i18n.customSlugPlaceholder}" required>
                <label style="display:flex; align-items:center; gap:0.3rem; margin:0; font-size:0.75rem; white-space:nowrap; cursor:pointer;">
                    <input type="checkbox" name="custom_required[${customIdx}]" value="1" ${isReq ? 'checked' : ''} style="width:14px; height:14px;"> ${i18n.customRequiredLabel}
                </label>
                <button type="button" class="btn-del" onclick="document.getElementById('crow_${customIdx}').remove()">&times;</button>
            `;
            container.appendChild(div);
            customIdx++;
        }

        function autoSlug(input, idx) {
            const slugField = document.getElementById('cslug_' + idx);
            if (!slugField.dataset.customized) {
                slugField.value = input.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            }
        }
    </script>
</body>
</html>
