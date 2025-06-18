# Razzia-Tracker

## Struktur

```
├── README.md
├── api
│   └── index.php
├── scraper
│   └── scraper.php
├── docs
│   ├── index.html
│   └── map.js
```

## Setup auf Webhoster (PHP 8.3.2)

### Voraussetzungen

- PHP 8.3.2 (mindestens 8.0)
- Zugriff auf Cronjobs für den Scraper (empfohlen)
- Umgebungsvariablen müssen gesetzt werden können, s.u. (z.B. .env-Datei, Hoster-Konfiguration o.ä.)

### Konfiguration

Umgebungsvariablen anlegen (z.B. in `.htaccess`, `.env` oder im Hoster-Interface):

```
SUPABASE_URL="https://rbxjghygifiaxgfpybgz.supabase.co"
SUPABASE_KEY=(...ZU ÜBERMITTELN)
```

### API starten

Den Ordner `api/` via Webserver erreichbar machen. Beispiel:

```
https://server.de/api/index.php/api/raids
```

Am Besten mit einer `.htaccess` Rewrite-Rule arbeiten:

**Beispiel `.htaccess` in `api/`:**

```
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

Dann genügt Aufruf z.B. unter:

```
https://server.de/api/raids
```

### Frontend öffnen

Öffne `docs/index.html` 

### Scraper manuell ausführen

+++TODO

#### Scraper automatisch

+++TODO

### Hinweis zum Geocoding

`User-Agent` in der Funktion `geocodeLocation()` nicht entfernen, um die OSM API-Richtlinien einzuhalten.
