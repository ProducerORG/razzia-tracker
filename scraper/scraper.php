<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
$KEYWORDS = ['Drogen'];
$NEWS_URL = $_ENV['NEWS_URL'] ?? 'https://www.presseportal.de/blaulicht';
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

echo "[INFO] News-URL: $NEWS_URL\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

$articles = [];
$pageCount = 25;
$step = 30;

for ($i = 0; $i < $pageCount; $i++) {
    $offset = $i * $step;
    $url = $NEWS_URL;
    if ($offset > 0) {
        $url .= "/$offset";
    }

    echo "[INFO] Lade Seite: $url\n";
    $html = file_get_contents($url);
    if (!$html) {
        echo "[WARN] Fehler beim Laden: $url\n";
        continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//a[contains(@href, '/blaulicht/pm/')]");

    echo "[INFO] Artikel auf Seite ($offset): " . $nodes->length . " (nur Links, keine Filterung)\n";

    $seenUrls = [];
    foreach ($nodes as $node) {
        $title = trim($node->textContent);
        $href = $node->getAttribute("href");
        $fullUrl = (strpos($href, 'http') === 0) ? $href : "https://www.presseportal.de$href";
    
        if (isset($seenUrls[$fullUrl])) continue; // Duplikat
        $seenUrls[$fullUrl] = true;
    
        $articles[] = ["title" => $title, "url" => $fullUrl];
    }

    usleep(500000); // 0.5 Sekunden warten, um Server nicht zu belasten
}

// Artikel verarbeiten
foreach ($articles as $article) {
    echo "[INFO] Verarbeite Artikel: {$article['title']}\n";

    $contentHtml = file_get_contents($article["url"]);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);
    $paragraphs = $xpath2->query("//div[contains(@class,'article-text')]//p");

    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden für: {$article['title']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $contentText .= $p->textContent . "\n";
    }

    $lowerText = mb_strtolower($contentText);
    $found = false;
    foreach ($KEYWORDS as $kw) {
        if (mb_strpos($lowerText, mb_strtolower($kw)) !== false) {
            $found = true;
            echo "[DEBUG] Schlüsselwort gefunden: $kw\n";
            break;
        }
    }

    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort gefunden, Artikel übersprungen.\n";
        continue;
    }

    $location = extractLocation($contentText);
    if (!$location) {
        echo "[INFO] Kein Ort erkannt, Artikel übersprungen.\n";
        continue;
    }

    echo "[DEBUG] Erkannter Ort: $location\n";

    list($lat, $lon) = geocodeLocation($location);
    if (!$lat || !$lon) {
        echo "[WARN] Geokodierung fehlgeschlagen für: $location\n";
        continue;
    }

    echo "[DEBUG] Geokoordinaten: $lat, $lon\n";

    $date = gmdate("Y-m-d");
    $summary = mb_substr($contentText, 0, 300) . "...";

    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"]);

    echo "[SUCCESS] Gespeichert: {$article['title']} ($location)\n";
}

function extractLocation($text) {
    $pattern = "/\b(in|bei|nahe|im|am) ([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u";
    if (preg_match($pattern, $text, $matches)) {
        return $matches[2];
    }
    return null;
}

function geocodeLocation($location) {
    $encodedLocation = urlencode($location . ", Deutschland");
    $url = "https://nominatim.openstreetmap.org/search?q=$encodedLocation&format=json&limit=1";

    sleep(1); // API schonen

    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $context = stream_context_create($opts);
    $json = file_get_contents($url, false, $context);
    if (!$json) return [null, null];

    $data = json_decode($json, true);
    if (count($data) > 0) {
        return [$data[0]["lat"], $data[0]["lon"]];
    }
    return [null, null];
}

function saveToSupabase($title, $summary, $date, $location, $lat, $lon, $url) {
    global $SUPABASE_URL, $SUPABASE_KEY;

    $apiUrl = $SUPABASE_URL . "/rest/v1/raids";
    $data = json_encode([
        "title" => $title,
        "summary" => $summary,
        "date" => $date,
        "location" => $location,
        "lat" => $lat,
        "lon" => $lon,
        "url" => $url
    ]);

    $headers = [
        "apikey: $SUPABASE_KEY",
        "Authorization: Bearer $SUPABASE_KEY",
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Supabase insert error: ' . curl_error($ch));
    } else {
        echo "[DEBUG] Supabase Response: $response\n";
    }
    curl_close($ch);
}


echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";