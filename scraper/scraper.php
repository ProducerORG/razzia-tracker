<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
//$KEYWORDS = ['Drogen']; //Testzwecke
$NEWS_URL = $_ENV['NEWS_URL'] ?? 'https://www.presseportal.de/blaulicht';
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

echo "[INFO] News-URL: $NEWS_URL\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

$articles = [];
$seenUrls = [];
$relevantCount = 0;
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

    foreach ($nodes as $node) {
        $titleRaw = trim($node->textContent);
        $title = preg_replace('/^POL-[A-ZÄÖÜ]{1,5}:\s*/u', '', $titleRaw);
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
    //echo "[INFO] Verarbeite Artikel: {$article['title']}\n";

    $contentHtml = file_get_contents($article["url"]);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);
    $paragraphs = $xpath2->query("(//div[contains(@class,'article-text')])[1]//p");

    // Fallback bei fehlendem Text
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//div[contains(@class,'text')]//p");
        if ($paragraphs->length === 0) {
            $paragraphs = $xpath2->query("//p");
        }
    }

    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden für: {$article['title']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
    
        // Stoppe bei erkennbaren Footer-Bereichen
        if (preg_match('/^(weitere meldungen|original-content|druckversion|pdf-version|orte in dieser meldung|themen in dieser meldung|rückfragen bitte an)/i', $line)) {
            break;
        }
    
        $contentText .= $line . "\n";
    }

    $lowerText = mb_strtolower($contentText);
    $found = false;
    foreach ($KEYWORDS as $kw) {
        if (mb_strpos($lowerText, mb_strtolower($kw)) !== false) {
            $found = true;
            echo "[DEBUG] Schlüsselwort gefunden: $kw\n";
            echo "[DEBUG] Textauszug: " . substr($contentText, mb_strpos($lowerText, mb_strtolower($kw)) - 30, 60) . "\n";
            break;
        }
    }

    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort gefunden, Artikel übersprungen.\n";
        continue;
    }

    $location = extractLocation($contentText);
    if ($location) {
        echo "[DEBUG] Erkannter Ort: $location\n";
        list($lat, $lon) = geocodeLocation($location);
        if (!$lat || !$lon) {
            echo "[WARN] Geokodierung fehlgeschlagen für: $location\n";
            $lat = null;
            $lon = null;
        } else {
            echo "[DEBUG] Geokoordinaten: $lat, $lon\n";
        }
    } else {
        echo "[INFO] Kein Ort erkannt, Felder bleiben leer.\n";
        $location = null;
        $lat = null;
        $lon = null;
    }

    $date = gmdate("Y-m-d");
    $summary = buildSummary($paragraphs);

    echo "[DEBUG] Speichere Artikel mit Keyword: $kw\n";
    echo "[DEBUG] Titel: {$article['title']}\n";
    echo "[DEBUG] URL: {$article['url']}\n";
    echo "[DEBUG] Ausschnitt: " . mb_substr($contentText, mb_strpos($lowerText, mb_strtolower($kw)) - 20, 60) . "\n";
    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"]);


    $relevantCount++;
}

function extractLocation($text) {
    $matches = [];
    $locations = [];

    // Alle potenziellen Ortsangaben sammeln
    preg_match_all('/\b(in|bei|nahe)\s+(?!Richtung\b)([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u', $text, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $location = $match[2];

        // Dubletten ignorieren
        if (in_array($location, $locations)) continue;

        // Orte wie "Polizei", "Polizeipräsidium" etc. ignorieren
        if (preg_match('/^Polizei|Kriminalpolizei|Staatsanwaltschaft|Feuerwehr/i', $location)) continue;

        $locations[] = $location;
    }

    // Gib den ersten relevanten Ort zurück (z. B. als Hauptort)
    return $locations[0] ?? null;
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
        "Content-Type: application/json",
        "Prefer: return=representation"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log('[ERROR] Supabase CURL error: ' . curl_error($ch));
    } elseif ($httpCode >= 400) {
        echo "[ERROR] Supabase returned HTTP $httpCode\n";
        echo "[ERROR] Response: $response\n";
        echo "[ERROR] Request payload: $data\n";
    } else {
        echo "[DEBUG] Supabase Response (HTTP $httpCode): $response\n";
        echo "[SUCCESS] Gespeichert: $title ($location)\n";
    }
    curl_close($ch);
}

function buildSummary($paragraphs) {
    $content = "";

    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);

        // Unnütze Textfragmente überspringen
        if (preg_match('/^(mehr themen|[\d]{2}\.\d{2}\.\d{4}|rückfragen bitte an|^kreispolizeibehörde|^original-content|^pdf-version|^druckversion)/i', $line)) {
            continue;
        }

        // Erst sinnvoller Textblock wird verwendet
        if (mb_strlen($line) > 80) {
            $content = $line;
            break;
        }
    }

    return mb_substr($content, 0, 300) . (mb_strlen($content) > 300 ? "..." : "");
}


echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";