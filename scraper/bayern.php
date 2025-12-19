<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = json_decode($_ENV['KEYWORDS'], true) ?? [];
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
    
    /* // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    $limitDate = date("Y-m-d", strtotime("-60 days"));
    if (strtotime($date) < strtotime($limitDate)) {
        echo "[INFO] Artikel zu alt (Datum: $date, Limit: $limitDate) – ignoriert.\n";
        continue;
    } */
    
        $summary = buildSummary($paragraphs);
    
        saveToSupabase(
            $article["title"],
            $summary,
            $date,
            $location,
            $lat,
            $lon,
            $article["url"],
            $federal,
            $type
        );

        $relevantCount++;
}

echo "[INFO] Gesamt verarbeitete Artikel: $relevantCount\n";

/* ================= Funktionen ================= */

function extractLocation($text) {
    preg_match_all('/\b(?:in|bei|nahe)\s+([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u', $text, $m);
    return $m[1][0] ?? null;
}

function geocodeLocation($location) {
    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($location . ", Deutschland") . "&format=json&limit=1";
    sleep(1);
    $ctx = stream_context_create(["http" => ["header" => "User-Agent: razzia-map/1.0"]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [null, null];
    $data = json_decode($json, true);
    return $data ? [$data[0]['lat'], $data[0]['lon']] : [null, null];
}

function saveToSupabase($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type) {
    global $SUPABASE_URL, $SUPABASE_KEY;

    $data = json_encode([
        "title" => $title,
        "summary" => $summary,
        "date" => $date,
        "location" => $location,
        "lat" => $lat,
        "lon" => $lon,
        "url" => $url,
        "federal" => $federal,
        "type" => $type,
        "scraper" => true
    ]);

    $ch = curl_init($SUPABASE_URL . "/rest/v1/raids");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_KEY",
            "Authorization: Bearer $SUPABASE_KEY",
            "Content-Type: application/json"
        ]
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function buildSummary($paragraphs) {
    foreach ($paragraphs as $p) {
        $t = trim($p->textContent);
        if (mb_strlen($t) > 80) return mb_substr($t, 0, 300);
    }
    return "";
}

function getFederalState($lat, $lon) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=$lat&lon=$lon";
    sleep(1);
    $ctx = stream_context_create(["http" => ["header" => "User-Agent: razzia-map/1.0"]]);
    $json = @file_get_contents($url, false, $ctx);
    $data = json_decode($json, true);
    return $data['address']['state'] ?? null;
}

function extractMetadataWithGPT($text) {
    $apiKey = $_ENV['OPENAI_API_KEY'];

    $payload = [
        "model" => "gpt-4",
        "messages" => [[
            "role" => "user",
            "content" => mb_substr($text, 0, 2000)
        ]],
        "temperature" => 0.1
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    return json_decode($json['choices'][0]['message']['content'] ?? '', true);
}

function urlExistsInDatabase($url) {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $q = $SUPABASE_URL . "/rest/v1/raids?url=eq." . urlencode($url) . "&select=url&limit=1";

    $ch = curl_init($q);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_KEY",
            "Authorization: Bearer $SUPABASE_KEY"
        ]
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    return !empty(json_decode($res, true));
}
