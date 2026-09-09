<?php
/**
 * Paragrafy - HTTP Validation Caching (ETag/Last-Modified/304) & Public Render Cache
 */
declare(strict_types=1);

define('PUBLIC_CACHE_DIR', PARAGRAFY_DATA_DIR . '/cache/public');
define('PUBLIC_CACHE_ENABLED', filter_var(getenv('PARAGRAFY_PUBLIC_CACHE') ?: '1', FILTER_VALIDATE_BOOLEAN));
define('PUBLIC_CACHE_MAX_AGE_DAYS', 30);
define('PUBLIC_CACHE_MAX_AGE_SECONDS', 300);

/**
 * Zeitpunkt des letzten Deploys, ueber die juengste Dateimtime aller App-PHP-Dateien
 * (Wurzelverzeichnis + lang/, wo t() seine Uebersetzungsstrings herlaedt) geschaetzt:
 * aendert sich automatisch bei jedem Deploy (git pull/rsync/Docker-Build aktualisieren
 * Datei-mtimes), ohne dass PARAGRAFY_VERSION manuell in ein Datum uebersetzt werden
 * muesste oder eine Datei-Liste von Hand gepflegt werden muss. Dient als untere Schranke
 * fuer Last-Modified, damit ein reiner If-Modified-Since-Abgleich (ohne ETag) nach einem
 * Rendering- oder Text-Deploy nicht faelschlich 304 liefert, obwohl sich die Translation
 * selbst nicht geaendert hat.
 */
function public_cache_deploy_ts(): int {
    static $ts = null;
    if ($ts === null) {
        $ts = 0;
        $files = array_merge(
            glob(PARAGRAFY_DIR . '/*.php') ?: [],
            glob(PARAGRAFY_DIR . '/lang/*.php') ?: []
        );
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false) {
                $ts = max($ts, $mtime);
            }
        }
    }
    return $ts;
}

/**
 * Baut den ETag fuer ein oeffentlich ausgeliefertes Dokument. Fliessen ein:
 * PARAGRAFY_VERSION (ein Deploy mit geaendertem Rendering invalidiert automatisch alle
 * bisherigen ETags) sowie projects.settings_version (Projekt-Stammdaten wie Firmenname/
 * Adresse/Branding werden per replace_placeholders() bzw. direkt ins Template eingebettet).
 * settings_version ist ein bei jedem UPDATE monoton hochgezaehlter Zaehler statt (nur) ein
 * DATETIME, damit zwei Aenderungen innerhalb derselben Sekunde (CURRENT_TIMESTAMP hat nur
 * Sekundenaufloesung in SQLite) trotzdem unterschiedliche Cache-Identitaeten erzeugen.
 */
function build_public_cache_etag(int $projectId, string $lang, string $slug, string $updatedAt, int $projectSettingsVersion): string {
    $hash = sha1($projectId . '|' . $lang . '|' . $slug . '|' . $updatedAt . '|' . $projectSettingsVersion . '|' . PARAGRAFY_VERSION);
    return '"' . $hash . '"';
}

/**
 * SQLite CURRENT_TIMESTAMP liefert UTC ("YYYY-MM-DD HH:MM:SS"). Last-Modified ist das
 * juengste der drei Freshness-Signale (Dokument-Update, Projekt-Stammdaten-Update,
 * Code-Deploy), damit ein reiner If-Modified-Since-Vergleich (ohne ETag) keinen Fall
 * uebersieht, den der ETag bereits abdeckt. Bei zwei Aenderungen innerhalb derselben
 * Sekunde bleibt Last-Modified unveraendert (Sekundenaufloesung) - die eigentliche
 * Cache-Trennung uebernimmt in dem Fall settings_version im ETag (s.o.).
 */
function build_public_last_modified(string $updatedAtSql, string $projectUpdatedAtSql = ''): int {
    $docTs = strtotime($updatedAtSql . ' UTC') ?: 0;
    $projectTs = $projectUpdatedAtSql !== '' ? (strtotime($projectUpdatedAtSql . ' UTC') ?: 0) : 0;
    $ts = max($docTs, $projectTs, public_cache_deploy_ts());
    return $ts > 0 ? $ts : time();
}

/**
 * Wertet If-None-Match / If-Modified-Since gegen den aktuellen ETag/Last-Modified
 * aus. Setzt IMMER zuerst ETag/Last-Modified/Cache-Control (auch bei 304 laut RFC
 * 7232 Sec. 4.1 vorgeschrieben). Bei Match wird der 304-Status gesetzt und true
 * zurueckgegeben - der Aufrufer muss dann selbst exit/return ohne Body ausloesen.
 */
function check_conditional_request(string $etag, int $lastModifiedTs, int $maxAge = PUBLIC_CACHE_MAX_AGE_SECONDS): bool {
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT');
    header('Cache-Control: public, max-age=' . $maxAge . ', must-revalidate');

    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch !== '') {
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                http_response_code(304);
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                http_response_code(304);
                return true;
            }
        }
        return false;
    }

    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifModifiedSince !== '') {
        $since = strtotime($ifModifiedSince);
        if ($since !== false && $lastModifiedTs <= $since) {
            http_response_code(304);
            return true;
        }
    }

    return false;
}

/** Saeubert lang/slug fuer die Verwendung im Dateinamen (defensiv, obwohl DB-validiert). */
function public_cache_safe_part(string $value): string {
    $safe = preg_replace('/[^a-z0-9_-]/i', '_', $value) ?? '';
    return $safe !== '' ? $safe : '_';
}

function public_cache_project_dir(int $projectId, string $type): string {
    return PUBLIC_CACHE_DIR . '/' . $projectId . '/' . $type;
}

function public_cache_path(int $projectId, string $type, string $lang, string $slug, string $etagHash): string {
    $ext = $type === 'json' ? 'json' : 'html';
    $filename = public_cache_safe_part($lang) . '_' . public_cache_safe_part($slug) . '_' . $etagHash . '.' . $ext;
    return public_cache_project_dir($projectId, $type) . '/' . $filename;
}

/**
 * Liest einen gecachten Render-Output. Der ETag-Hash ist bereits Teil des
 * Dateinamens und damit selbst die Freshness-Pruefung - existiert die Datei,
 * ist sie per Definition aktuell (sonst wuerde ein anderer Hash im Namen stehen).
 */
function public_cache_get(int $projectId, string $type, string $lang, string $slug, string $etagHash): ?string {
    if (!PUBLIC_CACHE_ENABLED) {
        return null;
    }
    $path = public_cache_path($projectId, $type, $lang, $slug, $etagHash);
    if (!is_file($path)) {
        return null;
    }
    $content = @file_get_contents($path);
    return $content !== false ? $content : null;
}

/**
 * Schreibt einen Render-Output in den Datei-Cache. Rein optimierend: Fehler
 * werden geloggt, duerfen die Auslieferung aber nie blockieren. Schreibt atomar
 * (temp-Datei + rename im selben Verzeichnis) um kaputte Reads bei parallelen
 * Requests zu vermeiden.
 */
function public_cache_put(int $projectId, string $type, string $lang, string $slug, string $etagHash, string $content): void {
    if (!PUBLIC_CACHE_ENABLED) {
        return;
    }
    try {
        $dir = public_cache_project_dir($projectId, $type);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log("public_cache_put: Verzeichnis konnte nicht angelegt werden: $dir");
            return;
        }
        $path = public_cache_path($projectId, $type, $lang, $slug, $etagHash);
        $tmp = @tempnam($dir, 'tmp_');
        if ($tmp === false) {
            error_log("public_cache_put: tempnam fehlgeschlagen in $dir");
            return;
        }
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            error_log("public_cache_put: Schreiben/Rename fehlgeschlagen fuer $path");
            @unlink($tmp);
            return;
        }

        // Opportunistisches Cleanup ohne zusaetzliche Cron-Abhaengigkeit.
        if (mt_rand(1, 100) === 1) {
            public_cache_cleanup($projectId);
        }
    } catch (Throwable $e) {
        error_log('public_cache_put: ' . $e->getMessage());
    }
}

/** Loescht Cache-Dateien eines Projekts, die aelter als $maxAgeDays sind. Gibt die Anzahl geloeschter Dateien zurueck. */
function public_cache_cleanup(int $projectId, int $maxAgeDays = PUBLIC_CACHE_MAX_AGE_DAYS): int {
    $deleted = 0;
    $cutoff = time() - $maxAgeDays * 86400;
    foreach (['html', 'json'] as $type) {
        $dir = public_cache_project_dir($projectId, $type);
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && (filemtime($file) ?: 0) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
    }
    return $deleted;
}

/** Raeumt den Cache aller Projekte auf (auch verwaiste Projektverzeichnisse) - fuer den optionalen Cron-Endpoint. */
function public_cache_cleanup_all(int $maxAgeDays = PUBLIC_CACHE_MAX_AGE_DAYS): int {
    $deleted = 0;
    foreach (glob(PUBLIC_CACHE_DIR . '/*', GLOB_ONLYDIR) ?: [] as $projectDir) {
        $projectId = (int)basename($projectDir);
        if ($projectId > 0) {
            $deleted += public_cache_cleanup($projectId, $maxAgeDays);
        }
    }
    return $deleted;
}
