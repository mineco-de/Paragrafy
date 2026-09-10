# § Paragrafy Webhook Referenz & Spezifikation

Dieses Dokument beschreibt die Webhook-Schnittstelle von Paragrafy (v1.6.2) zur automatisierten Synchronisation von Rechtstexten (AGB, Datenschutzerklärung, Impressum etc.) mit angebundenen Web- und Mobile-Anwendungen.

**Zustellung ist asynchron:** Veröffentlichungen legen den Webhook in einer Warteschlange ab statt ihn sofort zu senden, damit ein langsamer oder nicht erreichbarer Empfänger niemals das Speichern eines Rechtstexts blockiert. Ein externer Cron-Job muss `/api/cron/webhooks` regelmäßig aufrufen (empfohlen: alle 5 Minuten), damit die Warteschlange abgearbeitet wird — ohne diesen Cron werden Webhooks nur zugestellt, wenn im Admin-Bereich manuell auf „Jetzt abarbeiten" geklickt wird. Fehlgeschlagene Zustellungen werden bis zu 5-mal mit steigendem Abstand (1 / 5 / 15 / 60 / 180 Minuten) wiederholt, mit einem Timeout von 5 Sekunden pro Versuch.

---

## 1. HTTP-Header & Authentifizierung

Jeder von Paragrafy versendete Webhook wird als `POST`-Request mit folgendem Header-Schema übermittelt:

| Header | Beschreibung | Beispiel |
| :--- | :--- | :--- |
| `Content-Type` | MIME-Type des Payloads | `application/json` |
| `User-Agent` | Client-Identifikator | `Paragrafy-Webhook/2026.9.1` |
| `X-Paragrafy-Event` | Event-Typ | `legal_text.updated` / `legal_text.scheduled` |
| `X-Paragrafy-Signature` | HMAC-SHA256 Signatur des rohen Body-Strings | `a3f8e... (hex)` *(nur wenn Secret gesetzt)* |

---

## 2. Event-Typen im Überblick

| Event | Auslöser | Einsatzzweck in deiner App |
| :--- | :--- | :--- |
| `legal_text.scheduled` | Eine Textänderung wurde für einen zukünftigen Zeitpunkt geplant. | Vorankündigungs-Banner mit **Vorschau-Link** für Nutzer anzeigen (*„AGB ändern sich zum 31.08. [Jetzt Vorab-Fassung lesen]“*). |
| `legal_text.updated` | Ein Rechtstext wurde sofort live veröffentlicht, ein geplanter Stichtag wurde erreicht, oder eine frühere Version wurde wiederhergestellt. | Neue AGB-Zustimmung im User-Account erzwingen, App-Cache invalidieren. |

---

## 3. Payload-Spezifikation

### A. Event: `legal_text.scheduled` (Vorankündigung mit Vorschau-Link)

Wird gefeuert, wenn im Editor eine zeitgesteuerte Live-Schaltung für die Zukunft geplant wird. `url`/`api_url` verweisen weiterhin auf die aktuell live sichtbare Fassung (unverändert bis zum Stichtag). Zusätzlich liefert Paragrafy `preview_url`/`preview_api_url` — unter dieser Adresse ist die **geplante Neufassung** schon vor dem Stichtag öffentlich einsehbar (dieselbe Seite hängt lediglich `/preview` an), z. B. um Nutzer:innen vorab über anstehende AGB-Änderungen zu informieren. Die Vorschau-Seite wird mit `noindex` ausgeliefert und verschwindet automatisch, sobald die Fassung live geht (der Pfad zeigt danach wieder 404, weil keine Planung mehr vorliegt).

#### JSON-Payload:
```json
{
  "event": "legal_text.scheduled",
  "timestamp": "2026-08-30T15:30:00+02:00",
  "project": {
    "id": 1,
    "name": "MeinProjekt",
    "domain": "legal.deinedomain.de"
  },
  "data": {
    "document_id": 3,
    "slug": "agb-b2c",
    "lang": "de",
    "title": "AGB (Endkunden / B2C)",
    "status": "scheduled",
    "change_note": "Aktualisierung der Zahlungsbedingungen zum 31.08.",
    "scheduled_at": "2026-08-31T00:00:00+02:00",
    "effective_date": "2026-08-31T00:00:00+02:00",
    "url": "https://legal.deinedomain.de/de/agb-b2c",
    "api_url": "https://legal.deinedomain.de/api/de/agb-b2c",
    "preview_url": "https://legal.deinedomain.de/de/agb-b2c/preview",
    "preview_api_url": "https://legal.deinedomain.de/api/de/agb-b2c/preview",
    "was_scheduled": false
  }
}
```

---

### B. Event: `legal_text.updated` (Live-Veröffentlichung)

Wird gefeuert, sobald ein Rechtstext aktiv geschaltet wurde (sofort oder nach Ablauf des Stichtags).

#### JSON-Payload:
```json
{
  "event": "legal_text.updated",
  "timestamp": "2026-08-30T15:45:00+02:00",
  "project": {
    "id": 1,
    "name": "MeinProjekt",
    "domain": "legal.deinedomain.de"
  },
  "data": {
    "document_id": 3,
    "slug": "agb-b2c",
    "lang": "de",
    "title": "AGB (Endkunden / B2C)",
    "status": "published",
    "change_note": "Aktualisierung der Zahlungsbedingungen zum Monatsende",
    "was_scheduled": true,
    "effective_date": "2026-08-30T15:45:00+02:00",
    "url": "https://legal.deinedomain.de/de/agb-b2c",
    "api_url": "https://legal.deinedomain.de/api/de/agb-b2c",
    "updated_at": "2026-08-30T15:45:00+02:00"
  }
}
```

---

## 4. Felddefinitionen (Daten-Mapping)

| Feldname | Typ | Bedeutung |
| :--- | :--- | :--- |
| `data.title` | `string` | Der Titel in der jeweiligen Zielsprache. |
| `data.slug` | `string` | Eindeutiger Bezeichner (`agb-b2c`, `datenschutz`, `impressum`). |
| `data.lang` | `string` | 2-stelliger Sprachcode (`de`, `en`, `es`, `fr` etc.). |
| `data.effective_date` | `string` (ISO 8601) | **Inkrafttretungsdatum** (bei Live sofort, bei Scheduled der Stichtag). |
| `data.scheduled_at` | `string` (ISO 8601) | Nur bei `scheduled`: Der geplante Umschaltzeitpunkt. |
| `data.url` | `string` | URL der aktuell gültigen Live-Version. |
| `data.api_url` | `string` | JSON-API URL der aktuell gültigen Live-Version. |
| `data.preview_url` | `string` | Nur bei `scheduled`: Öffentliche Vorschau-URL der geplanten Neufassung (`/preview`-Suffix), noch vor dem Stichtag abrufbar. |
| `data.preview_api_url` | `string` | Nur bei `scheduled`: JSON-API-Variante der Vorschau-URL. |
| `data.was_scheduled` | `boolean` | `true`, falls diese Veröffentlichung aus einer Planung hervorging. |
| `data.change_note` | `string` | Die vom Admin vergebene Revisionsnotiz. |

---

## 5. Implementierungsbeispiel in TypeScript / Node.js

```typescript
import express, { Request, Response } from 'express';
import crypto from 'crypto';

interface ParagrafyWebhookPayload {
  event: 'legal_text.updated' | 'legal_text.scheduled';
  timestamp: string;
  project: {
    id: number;
    name: string;
    domain: string;
  };
  data: {
    document_id: number;
    slug: string;
    lang: string;
    title: string;
    status: string;
    change_note?: string;
    was_scheduled?: boolean;
    scheduled_at?: string;
    effective_date: string;
    url: string;
    api_url: string;
    preview_url?: string;
    preview_api_url?: string;
    updated_at?: string;
  };
}

const app = express();
const WEBHOOK_SECRET = process.env.PARAGRAFY_WEBHOOK_SECRET || 'mein-webhook-secret';

app.post('/api/legal-webhook', express.raw({ type: 'application/json' }), (req: Request, res: Response) => {
  const signature = req.headers['x-paragrafy-signature'] as string;
  const rawBody = req.body.toString('utf8');

  // 1. Signatur validieren
  if (WEBHOOK_SECRET) {
    const expected = crypto.createHmac('sha256', WEBHOOK_SECRET).update(rawBody).digest('hex');
    const valid = signature && crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
    if (!valid) {
      return res.status(401).json({ error: 'Ungültige Signatur' });
    }
  }

  const payload: ParagrafyWebhookPayload = JSON.parse(rawBody);

  // 2. Event Routing
  switch (payload.event) {
    case 'legal_text.scheduled':
      // Vorankündigungs-Banner schalten mit Vorschau-Link!
      console.log(`[Vorankündigung] ${payload.data.title} ändert sich zum ${payload.data.scheduled_at}`);
      console.log(`Vorschau-Link für Nutzer: ${payload.data.preview_url}`);
      break;

    case 'legal_text.updated':
      // Neue Version ist aktiv: User-Consent anfordern
      console.log(`[Live] ${payload.data.title} ist jetzt in Kraft.`);
      break;
  }

  return res.status(200).json({ success: true });
});
```
