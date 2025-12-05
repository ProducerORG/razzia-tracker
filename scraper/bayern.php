<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

$BASE_URL = "https://www.polizei.bayern.de/aktuelles/pressemitteilungen";
$MAX_PAGES = 20; // Sicherheitshalber begrenzen, falls sehr viele Seiten existieren

echo "[INFO] Starte Bayern-Scraper\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

$articles = [];
$seenUrls = [];
$relevantCount = 0;

for ($page = 1; $page <= $MAX_PAGES; $page++) {
    $url = $BASE_URL . "/index.html";
    if ($page > 1) {
        $url .= "?page=" . $page;
    }

    echo "[INFO] Lade Seite: $url\n";
    $html = @file_get_contents($url);
    if (!$html) {
        echo "[WARN] Fehler beim Laden: $url\n";
        break;
    }

    // JSON aus window.montagedata extrahieren
    if (!preg_match('/window\.montagedata\s*=\s*(\[.+?\]);/s', $html, $m)) {
        echo "[WARN] Keine montagedata auf Seite gefunden – vermutlich letzte Seite erreicht.\n";
        break;
    }

    $json = $m[1];
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) {
        echo "[WARN] montagedata leer – Abbruch.\n";
        break;
    }

    foreach ($data as $item) {
        if (empty($item['href']) || empty($item['title'])) continue;

        $fullUrl = "https://www.polizei.bayern.de" . $item['href'];
        if (isset($seenUrls[$fullUrl])) continue;
        $seenUrls[$fullUrl] = true;

        $articles[] = [
            'title' => trim($item['title']),
            'url' => $fullUrl
        ];
    }

    usleep(500000); // 0,5s warten
}

echo "[INFO] Gesamt gefundene Artikel: " . count($articles) . "\n";

// Artikel verarbeiten
foreach ($articles as $article) {
    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Artikel bereits in DB: {$article['url']}\n";
        continue;
    }

    echo "[INFO] Lade Artikel: {$article['url']}\n";
    $contentHtml = @file_get_contents($article["url"]);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden\n";
        continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($contentHtml);
    $xpath = new DOMXPath($dom);

    // Bayern-Artikeltexte liegen in div.bp-text oder bp-article, oft Absätze <p>
    $paragraphs = $xpath->query("//div[contains(@class,'bp-text')]//p");
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath->query("//p");
    }
    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if (preg_match('/^(weitere meldungen|original-content|druckversion|pdf-version)/i', $line)) {
            break;
        }
        $contentText .= $line . "\n";
    }

    // Keywords prüfen
    $found = false;
    $kw = null;
    foreach ($KEYWORDS as $kw) {
        if (preg_match('/' . $kw . '/i', $contentText)) {
            $found = true;
            $kw = $k;
            echo "[DEBUG] Schlüsselwort gefunden: $k\n";
            break;
        }
    }
    if (!$found) {
        echo "[INFO] Kein relevantes Keyword, übersprungen.\n";
        continue;
    }

    // GPT-Analyse
    $gptResult = extractMetadataWithGPT($contentText);
    if (!$gptResult || !is_array($gptResult) || ($gptResult['illegal'] ?? false) !== true) {
        echo "[INFO] Kein Fall von illegalem Glücksspiel – Artikel verworfen\n";
        continue;
    }

    $type = $gptResult['typ'] ?? "Sonstige";
    $location = $gptResult['ort'] ?? extractLocation($contentText);
    $lat = $gptResult['koord']['lat'] ?? null;
    $lon = $gptResult['koord']['lon'] ?? null;
    $federal = $gptResult['bundesland'] ?? null;

    if (!$lat || !$lon) {
        if ($location) {
            list($lat, $lon) = geocodeLocation($location);
        }
    }
    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
    }

        // Veröffentlichungsdatum (oft <time datetime=""> oder aus Detailseite extrahierbar)
        $dateNode = $xpath->query("//time[@datetime]");
        if ($dateNode->length > 0) {
            $datetimeAttr = $dateNode[0]->getAttribute("datetime");
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $datetimeAttr, $matches)) {
                $date = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            } else {
                $date = gmdate("Y-m-d");
            }
        } else {
            $date = gmdate("Y-m-d");
        }
    
    // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    $limitDate = date("Y-m-d", strtotime("-60 days"));
    if (strtotime($date) < strtotime($limitDate)) {
        echo "[INFO] Artikel zu alt (Datum: $date, Limit: $limitDate) – ignoriert.\n";
        continue;
    }
    
        $summary = buildSummary($paragraphs);
    
        saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"], $federal, $type);
        $relevantCount++;
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";

// === Hilfsfunktionen (wie in presseportal.php, unverändert übernehmen) ===

function extractLocation($text) { /* ... wie in presseportal.php ... */ }
function geocodeLocation($location) { /* ... */ }
function saveToSupabase($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type) { /* ... */ }
function buildSummary($paragraphs) { /* ... */ }
function getFederalState($lat, $lon) { /* ... */ }
function extractMetadataWithGPT($text) { /* ... */ }
function urlExistsInDatabase($url) { /* ... */ }
