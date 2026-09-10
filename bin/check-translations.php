<?php
declare(strict_types=1);

/**
 * Checks that every lang/*.php file contains the same key set as the
 * canonical reference, lang/de.php.
 *
 * Usage: php bin/check-translations.php
 *
 * Exit code 0: all languages have every key from the reference.
 * Exit code 1: at least one language is missing one or more keys.
 *
 * Orphan keys (present in a language file but not in the reference) are
 * reported as warnings only and do not affect the exit code.
 */

$langDir = __DIR__ . '/../lang';
$referenceFile = $langDir . '/de.php';

if (!is_file($referenceFile)) {
    fwrite(STDERR, "Reference language file not found: $referenceFile\n");
    exit(1);
}

/** @var array<string, string> $reference */
$reference = require $referenceFile;
if (!is_array($reference)) {
    fwrite(STDERR, "Reference language file did not return an array: $referenceFile\n");
    exit(1);
}
$referenceKeys = array_keys($reference);

$files = glob($langDir . '/*.php');
if ($files === false) {
    fwrite(STDERR, "Could not read language directory: $langDir\n");
    exit(1);
}
sort($files);

$hasMissing = false;

foreach ($files as $file) {
    if (realpath($file) === realpath($referenceFile)) {
        continue;
    }

    $locale = basename($file, '.php');
    $translations = require $file;

    if (!is_array($translations)) {
        fwrite(STDERR, "[$locale] does not return an array — skipping.\n");
        $hasMissing = true;
        continue;
    }

    $translationKeys = array_keys($translations);
    $missing = array_values(array_diff($referenceKeys, $translationKeys));
    $orphans = array_values(array_diff($translationKeys, $referenceKeys));

    if (empty($missing) && empty($orphans)) {
        echo "[$locale] OK (" . count($translationKeys) . " keys)\n";
        continue;
    }

    if (!empty($missing)) {
        $hasMissing = true;
        echo "[$locale] MISSING " . count($missing) . " key(s):\n";
        foreach ($missing as $key) {
            echo "  - $key\n";
        }
    }

    if (!empty($orphans)) {
        echo "[$locale] WARNING: " . count($orphans) . " orphan key(s) not in reference (lang/de.php):\n";
        foreach ($orphans as $key) {
            echo "  - $key\n";
        }
    }
}

if ($hasMissing) {
    echo "\nFAILED: one or more languages are missing keys from the reference (lang/de.php).\n";
    exit(1);
}

echo "\nOK: all language files contain every key from the reference (lang/de.php).\n";
exit(0);
