# Changelog

Alle nennenswerten Änderungen an Paragrafy werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/).
Die Versionsnummer folgt **CalVer** (`JAHR.MONAT.BUILD`) statt SemVer:
`BUILD` zählt die Releases innerhalb eines Kalendermonats hoch und startet
jeden Monat wieder bei `1`. Änderungen vor `2026.9.1` sind nicht rückwirkend
erfasst — siehe dafür die Git-Historie.

## [2026.9.11] - 2026-09-08

### Security
Vollständiges Security-Audit von Core (siehe `SECURITY.md`-artige Aufstellung im Audit-Report)
mit direkter Umsetzung aller Kritisch/Hoch/Mittel/Niedrig-Funde:

- **Breaking Change (Docker, self-hosted):** `PARAGRAFY_DATA_DIR` liegt im Container jetzt unter
  `/var/www/data`, außerhalb des Apache-Docroots `/var/www/html` (vorher
  `/var/www/html/data` — web-erreichbar, falls eine vorgeschaltete vhost-Härtung fehlt oder
  fehlkonfiguriert ist; ein Forensik-Fund vom 2026-09-07 auf einem fremden, unabhängigen
  System auf demselben Server hat das konkret aufgezeigt). Der Host-seitige `./data`-Ordner
  bleibt unverändert — ein normales `git pull` + `docker compose up -d --build` genügt, keine
  manuelle Datenmigration nötig. Zusätzlich: `Options Indexes` (Directory-Listing) im
  Docker-Image entfernt, neue `.htaccess`-Deny-Regeln für `*.sqlite*`/`config.php`/`.env*` als
  zusätzliches Sicherheitsnetz für bare-metal-Installationen.
- **SSRF-Schutz für Webhook-Ziel-URLs**: Webhook-URLs (Test-Button, Warteschlange) werden jetzt
  gegen private/interne IP-Ranges geprüft (`is_public_http_url()`, bisher nur beim
  KI-Einlesemodus genutzt) — verhindert, dass ein Projekt-Webhook auf `localhost`,
  `169.254.169.254` (Cloud-Metadata) oder interne Hosts zeigen kann.
- **HTML-Sanitizing für Rechtstext-Inhalte**: Der WYSIWYG-Editor-Content, KI-Import- und
  DeepL-Übersetzungsergebnisse durchlaufen jetzt eine Allowlist-HTML-Bereinigung
  (`sanitize_legal_html()`) statt ungefiltert gespeichert/ausgegeben zu werden — schließt eine
  Stored-XSS-Lücke, die auf eingebetteten Kundenseiten ausgenutzt werden konnte.
- **Backup-Download/-Restore und weitere instanzweite Aktionen** (Voll-Instanz-Backup,
  Cron-Secret-Rotation, Webhook-Queue, Dokumenttyp-Verwaltung) sind jetzt ausschließlich dem
  primären Admin-Login vorbehalten, nicht mehr jedem eingeladenen Multi-User.
- **CSRF-Schutz** für alle zustandsändernden Formulare/AJAX-Aufrufe in `admin.php`/`editor.php`.
- **Session-Härtung**: `Secure`/`HttpOnly`/`SameSite`-Cookie-Flags, `session_regenerate_id()`
  nach jedem Login-Weg (Passwort, Einladung, Passwort-Reset, SSO).
- **Login-Rate-Limiting** jetzt zusätzlich pro Account (nicht mehr nur pro IP); neues generisches
  Rate-Limiting für den Consent-Log-Endpunkt und die öffentliche JSON-API.
- **Consent-Log-Endpunkt**: Format-Validierung von `consentId`/`textHash`.
- **SMTP-Header-Injection**: CR/LF-Bereinigung von Empfänger/Absender/Projektname vor dem
  Einbetten in Mail-Header/SMTP-Kommandos.
- **CSV-Injection**: Formel-Escaping (`=+-@`) in den Audit-Log- und Consent-Nachweis-CSV-Exporten.
- **Projekt-Backup-Export** enthält keine Klartext-Secrets (SMTP-Passwort, Webhook-Secret,
  KI-/DeepL-API-Keys) mehr.
- Instanzweite Audit-Log-Einträge sind für eingeschränkte Multi-User nicht mehr sichtbar.
- Einladungs- und Passwort-Reset-Links laufen jetzt ab (7 Tage bzw. 1 Stunde).
- Race-Condition-Schutz (Lock) bei der Erstinstallation; generische Fehlermeldungen statt
  Klartext-Exceptions bei Installationsfehlern.
- `display_errors` wird jetzt in allen Einstiegspunkten explizit deaktiviert.
- Kleinere Härtungen: IPv4-mapped-IPv6 bei der Consent-IP-Anonymisierung, striktere
  Passwort-Mindestlänge (10 Zeichen) und Domain-Validierung im Setup-Wizard, konsistente
  Prepared Statements.

## [2026.9.10] - 2026-09-05

### Added
- **Projekt-Berechtigungsmatrix für Benutzer**: Bisher hatte jede über „Person einladen"
  angelegte Person automatisch vollen Zugriff auf alle Projekte der Instanz. Da die
  SaaS-Plattform (Paragrafy-cloud) ab sofort Benutzer für ihre Kunden direkt anlegt, können
  Admins jetzt in der Benutzerverwaltung per Checkbox-Matrix festlegen, auf welche Projekte
  eine Person Zugriff hat — ein direkter Aufruf einer fremden `project_id` (über die URL,
  Sidebar-Auswahl oder den Editor) wird mit Zugriff verweigert abgelehnt. Ohne gesetztes
  Häkchen bleibt eine Person weiterhin uneingeschränkt (Abwärtskompatibilität für alle vor
  diesem Update angelegten Zugänge, kein Zugang wird beim Update ausgesperrt). Neu ist zudem
  ein informatives Notiz-Feld pro Person, das von der Plattform befüllt werden kann.
- **Nutzerverwaltung exklusiv für den primären Admin-Login**: Ein über die Benutzertabelle
  eingeloggter (sekundärer) Nutzer kann jetzt grundsätzlich nie mehr selbst weitere Personen
  einladen, deren Zugriff ändern oder entfernen — dazu ist ausschließlich der primäre
  Admin-Login berechtigt. Auf Managed-Cloud-Instanzen ist zusätzlich das lokale „Person
  einladen" komplett gesperrt, da neue Zugänge dort über das Kundenportal angelegt werden.

## [2026.9.9] - 2026-09-04

### Changed
- **Projekt-Import: explizite Zielprojekt-Auswahl statt automatischer Domain-Erkennung**: Der
  Projekt-Import erkannte bislang selbst per Domain-Abgleich, ob ein bestehendes Projekt
  aktualisiert oder ein neues angelegt werden soll. Das führte auf Managed Cloud zu
  Duplikaten, wenn sich die gespeicherte Domain zwischen Export und Import geändert hatte (z. B.
  weil zwischenzeitlich eine eigene Domain verbunden wurde) — der Abgleich schlug fehl und legte
  statt eines Updates ein neues, doppeltes Projekt an. Der Import verlangt jetzt zwingend die
  explizite Auswahl eines **bereits bestehenden** Zielprojekts aus einem Dropdown; es wird nie
  mehr automatisch ein neues Projekt angelegt. Ein Zielprojekt muss vorher regulär angelegt sein.

## [2026.9.8] - 2026-09-04

### Fixed
- **„Projekt exportieren"-Link führte zu „Projekt wurde nicht gefunden"**: Der Button in den
  Einstellungen verwendete versehentlich eine Variable außerhalb ihres Gültigkeitsbereichs
  (`render_settings_view()` ist eine eigene Funktion, in der die globale `$projectId` nicht
  existiert) und erzeugte dadurch eine URL ohne Projekt-ID. Betraf ausschließlich den in
  `2026.9.7` neu eingeführten Projekt-Export — der Voll-Instanz-Restore war nicht betroffen.

## [2026.9.7] - 2026-09-04

### Added
- **Projekt-Export & Merge-Import**: Neben dem Voll-Instanz-Backup gibt es jetzt in den
  Projekteinstellungen einen gezielten Export/Import für ein **einzelnes Projekt** — nützlich, um
  z. B. nur ein Projekt zwischen Self-Hosting und Managed Cloud zu übertragen, ohne dabei andere
  Projekte der Zielinstanz zu überschreiben (im Gegensatz zum bestehenden Voll-Restore, der immer
  die komplette Datenbank ersetzt). „Dieses Projekt exportieren" lädt eine eigenständige Datei mit
  nur den Rechtsinhalten dieses Projekts herunter (Stammdaten, Dokumente, Übersetzungen,
  Versionshistorie — keine Betriebsdaten wie Webhook-Logs oder Nutzerkonten). Beim Import wird die
  Domain des importierten Projekts gegen die Zielinstanz abgeglichen: Existiert sie bereits,
  werden nur dessen Dokumente/Übersetzungen aktualisiert (Firmendaten des bestehenden Projekts
  bleiben unverändert, andere Projekte der Instanz bleiben komplett unberührt); existiert sie
  noch nicht, wird ein neues Projekt angelegt. Rechtstext-Typen (`doc_types`) werden dabei per
  Slug gegen bestehende Einträge abgeglichen, keine Duplikate. Vor jedem Import wird automatisch
  eine Sicherheitskopie der Zielinstanz angelegt.

### Fixed
- **Backup-Download für rollierende Backups repariert**: Der Download-Link für einzelne
  rollierende Backups (7-Tage-Verlauf) warf immer „Backup nicht gefunden", weil die
  Dateinamens-Prüfung keine Bindestriche zuließ, obwohl die Backup-Dateinamen (`Y-m-d_His`) welche
  enthalten. Betraf alle Backup-Dateien unabhängig vom Erstellungsdatum, war kein neues Problem
  durch diese Version.

## [2026.9.6] - 2026-09-04

### Changed
- **Backup-Wiederherstellung: klarere Warnungen**: Der Warnhinweis vor dem Restore macht jetzt
  explizit deutlich, dass es sich um einen vollständigen Ersatz (kein Zusammenführen) handelt.
  Auf Managed Cloud erscheint zusätzlich der Hinweis, dass die in der hochgeladenen Datei
  enthaltene Domain die bisherige überschreibt und danach im Cloud-Dashboard geprüft werden
  sollte. Enthält die wiederhergestellte Datenbank mehr Projekte, als der aktuelle Plan
  (`project_limit`) erlaubt, erscheint nach dem Restore ein zusätzlicher Warnhinweis — der
  Restore selbst wird dadurch nicht blockiert, da es sich um die eigenen Daten des Kunden
  handelt.

## [2026.9.5] - 2026-09-04

### Added
- **Backup-Upload & Wiederherstellung**: In den Projekteinstellungen kann jetzt neben dem
  Erstellen/Herunterladen von Backups auch eine `.sqlite`-Sicherungskopie hochgeladen werden, um
  die komplette Datenbank der Instanz zu ersetzen — erleichtert den Wechsel zwischen Self-Hosting
  und Managed Cloud in beide Richtungen. Die hochgeladene Datei wird vorab geprüft (gültiges
  SQLite-Format, vorhandene Paragrafy-Kerntabellen); vor dem Ersetzen wird automatisch eine
  Sicherheitskopie der aktuellen Datenbank angelegt (erscheint in der bestehenden Backup-Liste).
  Backups aus älteren Paragrafy-Versionen mit fehlenden Spalten/Tabellen werden dabei automatisch
  auf den aktuellen Schema-Stand gebracht. Zum Schutz vor Fehlklicks ist eine Bestätigungs-Checkbox
  plus Bestätigungsdialog erforderlich.

## [2026.9.4] - 2026-09-04

### Added
- **Einlesemodus für Rechtstexte (BETA)**: Im Editor der 6 Standard-Rechtstexte (Impressum,
  Datenschutzerklärung, AGB B2C/B2B, Cookie-Richtlinie, Widerrufsbelehrung) steht jetzt ein
  neuer Button „Alten Text einlesen (BETA)" zur Verfügung. Er liest eine bestehende
  Rechtstext-Seite per URL oder Datei-Upload (`.html`, `.htm`, `.txt`, `.pdf`) ein, lässt sie
  von einem KI-Provider (Anthropic Claude oder OpenAI, konfigurierbar in den
  Projekteinstellungen) in die passende Zielstruktur überführen und schlägt dabei sowohl den
  generierten Inhalt (mit korrekt gesetzten `{{platzhaltern}}`) als auch die im Text erkannten
  Firmendaten (Name, Adresse, E-Mail, Telefon, Vertretung, Registereintrag) zur Bestätigung vor.
  Nichts wird automatisch übernommen — weder der Text noch die Firmendaten landen ohne
  ausdrückliche Bestätigung im System. URL-Abrufe sind gegen SSRF abgesichert (keine
  privaten/lokalen Adressen), Uploads sind auf zulässige Dateitypen und eine Maximalgröße
  begrenzt. Neue Projekteinstellung „KI-Einlesemodus" für Provider-Auswahl und API-Key,
  analog zum bestehenden DeepL-Key-Pattern inkl. `.env.local`-Fallback.

## [2026.9.3] - 2026-09-04

### Changed
- **Admin-Dashboard & Editor auf das "§ — Ink & Paper, quiet"-Design umgestellt**: neue Farbpalette
  (warmes Ink/Paper statt Indigo, sowohl Dark- als auch Light-Variante), self-hosted Fraunces/
  Inter/JetBrains-Mono-Fonts statt Google-Fonts-CDN, durchgängig eckige 3px/2px-Radien statt der
  bisherigen 7–20px-Rundungen, eckige Badges (Rand statt Füllfarbe, kein Farbpunkt) statt voller
  Pillen, wachsender Unterstrich-Hover in der Sidebar-Navigation, sichtbarer Fokusring. Die vom
  Kunden gewählte Akzentfarbe (`brand_color`) bleibt unverändert der Primärakzent; Button-Text
  wird jetzt serverseitig per Kontrastberechnung (WCAG-Luminanz) automatisch hell oder dunkel
  gewählt, damit auch sehr helle oder sehr dunkle Kundenfarben lesbar bleiben. Der bestehende
  Hell/Dunkel/Automatisch-Umschalter bleibt unverändert erhalten. Das öffentlich eingebettete
  Consent-Banner (`index.php`) ist von dieser Umstellung bewusst nicht betroffen.
- Neues Logo (`paragrafy.svg`).

## [2026.9.2] - 2026-09-04

### Added
- **Consent-Nachweise (DSGVO-Nachweispflicht) für `/consent.js`**: Optionales, projektweit
  aktivierbares serverseitiges Protokoll jeder Consent-Entscheidung (akzeptiert/abgelehnt),
  gedacht als Nachweis bei Prüfungen. Gespeichert werden Zeitpunkt, Aktion, eine anonymisierte
  IP-Adresse (letztes Oktett bzw. bei IPv6 die letzten 80 Bit genullt — nie die vollständige
  IP), der Browser (User-Agent), eine Consent-ID sowie ein Hash des zum Zeitpunkt der
  Einwilligung angezeigten Banner-Texts. Neue Admin-Seite „Consent-Nachweise" mit CSV-Export,
  neuer Endpunkt `/api/consent-log`, konfigurierbare Aufbewahrungsdauer (Einstellungen →
  Consent-Nachweise) — abgelaufene Einträge werden automatisch zusammen mit dem täglichen
  rollierenden Backup bereinigt.

## [2026.9.1] - 2026-09-02

### Changed
- Versionsschema von SemVer (`1.6.2`) auf CalVer (`JAHR.MONAT.BUILD`)
  umgestellt. Einzige Quelle bleibt `PARAGRAFY_VERSION` in `db.php`.
- Veraltete, hartcodierte Versionsnummern in Datei-Kopfkommentaren
  (`admin.php`, `editor.php`, `index.php`, `install.php`) entfernt, die
  teils erheblich von der tatsächlich ausgelieferten Version abwichen.

### Added
- Dieses Changelog.
- Warnung vor Datenverlust im Editor: Beim Verlassen der Seite mit
  ungespeicherten Änderungen erscheint jetzt eine Bestätigungsabfrage,
  zusätzlich zeigt ein Hinweis neben dem Speichern-Button den
  ungespeicherten Zustand an (`editor.php`).
