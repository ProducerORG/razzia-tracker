<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/* ----------------- Hilfsfunktionen ZUERST definieren (mit Guards) ----------------- */

if (!function_exists('extractLocation')) {
function extractLocation($text) {
    preg_match_all('/\b(?:in|bei|nahe)\s+(?!Richtung\b)(?!Höhe\b)([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u', $text, $matches);
    $found = [];
    foreach ($matches[1] as $loc) {
        if (preg_match('/Polizei|Kriminalpolizei|Staatsanwaltschaft|Feuerwehr/i', $loc)) continue;
        if (!in_array($loc, $found)) $found[] = $loc;
    }
    return $found[0] ?? null;
}
}

if (!function_exists('geocodeLocation')) {
function geocodeLocation($location) {
    $encodedLocation = urlencode($location . ", Deutschland");
    $url = "https://nominatim.openstreetmap.org/search?q=$encodedLocation&format=json&limit=1";
    sleep(1);
    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $ctx = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [null, null];
    $data = json_decode($json, true);
    if (count($data) > 0) return [$data[0]['lat'], $data[0]['lon']];
    return [null, null];
}
}

if (!function_exists('saveToSupabase')) {
function saveToSupabase($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type) {
    $SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
    $SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

    $apiUrl = $SUPABASE_URL . "/rest/v1/raids";
    $data = json_encode([
        "title" => $title,
        "summary" => $summary,
        "date" => $date,
        "location" => $location,
        "lat" => $lat,
        "lon" => $lon,
        "url" => $url,
        "federal" => $federal,
        "type" => $type 
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
        echo "[ERROR] Payload: $data\n";
    } else {
        echo "[SUCCESS] Gespeichert: $title ($location, $federal)\n";
    }
    curl_close($ch);
}
}

if (!function_exists('buildSummary')) {
function buildSummary($paragraphs) {
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if (preg_match('/^(mehr themen|[\d]{2}\.\d{2}\.\d{4}|rückfragen bitte an|^kreispolizeibehörde|^original-content|^pdf-version|^druckversion|^hinweise erbeten)/i', $line)) continue;
        if (mb_strlen($line) > 80) {
            return mb_substr($line, 0, 300) . (mb_strlen($line) > 300 ? "..." : "");
        }
    }
    return "";
}
}

if (!function_exists('getFederalState')) {
function getFederalState($lat, $lon) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=$lat&lon=$lon";
    sleep(1);
    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $ctx = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    return $data['address']['state'] ?? null;
}
}

if (!function_exists('extractMetadataWithGPT')) {
function extractMetadataWithGPT($text) {
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
    if (!$apiKey) return null;

    $url = 'https://api.openai.com/v1/chat/completions';
    $prompt = "Analysiere den folgenden Nachrichtentext. Gib das Ergebnis als JSON zurück mit den Feldern:
- 'ort': Der Ort, wo der Vorfall stattgefunden hat. Gib ausschließlich den Ortsnamen zurück, ohne Zusätze wie 'in', 'bei' oder 'nahe'.
- 'typ': Einer der Werte: 'Automatenspiel', 'Wetten', 'Online-Spiele'. Je nach dem, was im Text am ehesten zutrifft. Trifft defintiv nichts davon zu, gib den Wert 'Sonstige' zurück.
- 'koord': Falls ermittelbar, ein Objekt mit 'lat' und 'lon' (sonst null). Die Koordinaten müssen unbedingt dem deutschen Ort entsprechen, wo der Vorfall stattgefunden hat.
- 'bundesland': Das deutsche Bundesland des Orts (z. B. 'Nordrhein-Westfalen', 'Bayern'). Falls nicht bestimmbar, null.
- 'illegal': true oder false. Gib 'true' zurück, **wenn es sich eindeutig um illegales Glücksspiel handelt**. Gib 'false' zurück, **wenn es um andere Vorfälle wie Diebstahl, Einbruch, Überfall, Geldwäsche, oder Straftaten im Umfeld legaler Glücksspiele geht.**

Text:\n" . mb_substr($text, 0, 2000);

    $data = [
        "model" => "gpt-4",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ],
        "temperature": 0.1
    ];
    $headers = [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return null;
    $json = json_decode($response, true);
    $content = $json['choices'][0]['message']['content'] ?? null;
    if (!$content) return null;
    $result = json_decode($content, true);
    return $result;
}
}

if (!function_exists('urlExistsInDatabase')) {
function urlExistsInDatabase($url) {
    $SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
    $SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

    $queryUrl = $SUPABASE_URL . "/rest/v1/raids?url=eq." . urlencode($url) . "&select=url&limit=1";
    $headers = [
        "apikey: $SUPABASE_KEY",
        "Authorization: Bearer $SUPABASE_KEY",
        "Accept: application/json"
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $queryUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) {
        echo "[WARN] Fehler bei DB-URL-Prüfung für $url\n";
        return false; // Im Zweifel nicht überspringen
    }
    $data = json_decode($response, true);
    return !empty($data);
}
}

/* ----------------- Hauptlogik ----------------- */

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));

$RSS_BASE = 'https://polizei.nrw/presse/pressemitteilungen/rss/all/all/all/all';
echo "[INFO] RSS-URL: $RSS_BASE\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

// Wie viele RSS-Seiten holen? page=0 ist die erste. Pro Seite ~25 Items.
$MAX_RSS_PAGES = 4; // ergibt ca. bis zu ~100 Items
$articles = [];
$seenUrls = [];
$totalFetched = 0;

libxml_use_internal_errors(true);

for ($page = 0; $page < $MAX_RSS_PAGES; $page++) {
    $rssUrl = $RSS_BASE . ($page > 0 ? '?page=' . $page : '');
    echo "[INFO] Lade RSS-Seite $page: $rssUrl\n";

    $rssXml = @file_get_contents($rssUrl);
    if (!$rssXml) {
        echo "[WARN] RSS konnte nicht geladen werden: $rssUrl\n";
        continue;
    }
    $xml = @simplexml_load_string($rssXml);
    if (!$xml || !isset($xml->channel->item)) {
        echo "[WARN] Keine Items auf RSS-Seite $page\n";
        // Früh abbrechen, wenn ab Seite>0 nichts mehr kommt
        if ($page > 0) break;
        continue;
    }

    $newOnThisPage = 0;

    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link  = trim((string)$item->link);
        $pub   = trim((string)$item->pubDate);

        // absolute URL erzwingen
        if ($link !== '' && strpos($link, 'http') !== 0) {
            $link = 'https://polizei.nrw' . (substr($link, 0, 1) === '/' ? '' : '/') . $link;
        }

        if ($link === '' || isset($seenUrls[$link])) continue;
        $seenUrls[$link] = true;

        $dateRss = null;
        if ($pub) {
            $ts = strtotime($pub);
            if ($ts !== false) $dateRss = gmdate('Y-m-d', $ts);
        }

        $articles[] = ["title" => $title, "url" => $link, "rssDate" => $dateRss];
        $newOnThisPage++;
        $totalFetched++;
    }

    echo "[INFO] RSS-Seite $page: neu gesammelt: $newOnThisPage, gesamt: $totalFetched\n";

    // Wenn keine neuen Items mehr geliefert werden, Abbruch.
    if ($newOnThisPage === 0) break;

    usleep(300000); // 0,3s Pause zwischen RSS-Requests
}

$relevantCount = 0;

// Artikel verarbeiten
foreach ($articles as $article) {
    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Artikel bereits in Datenbank, übersprungen: {$article['url']}\n";
        continue;
    }

    $contentHtml = @file_get_contents($article["url"]);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);

    // Absätze im Artikeltext (robust für Drupal 10)
    $paragraphs = $xpath2->query(
        "(//main//div[contains(@class,'node__content')]//p)
         | (//main//div[contains(@class,'field')][contains(@class,'body') or contains(@class,'text')]//p)
         | (//div[contains(@class,'article')]//p)"
    );
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//p");
    }
    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden für: {$article['title']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if (preg_match('/^(hinweise erbeten|mehr zum thema|kontakt|impressum|datenschutzerkl|teilen:)/i', $line)) {
            break;
        }
        $contentText .= $line . "\n";
    }

    $found = false;
    $kw = null;
    foreach ($KEYWORDS as $kwCandidate) {
        if (preg_match('/\b' . preg_quote($kwCandidate, '/') . '\b/i', $contentText)) {
            $found = true;
            $kw = $kwCandidate;
            echo "[DEBUG] Schlüsselwort gefunden: $kw\n";
            break;
        }
    }
    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort gefunden, übersprungen: {$article['title']} | {$article['url']}\n";
        continue;
    }

    // GPT-Metadaten extrahieren
    $gptResult = extractMetadataWithGPT($contentText);
    if (!$gptResult || !is_array($gptResult) || ($gptResult['illegal'] ?? false) !== true) {
        echo "[INFO] Kein Fall von illegalem Glücksspiel – Artikel verworfen: {$article['title']} | {$article['url']}\n";
        continue;
    }

    $type   = $gptResult['typ'] ?? "Sonstige";
    $gptOrt = $gptResult['ort'] ?? null;
    $gptLat = $gptResult['koord']['lat'] ?? null;
    $gptLon = $gptResult['koord']['lon'] ?? null;
    $federal = $gptResult['bundesland'] ?? null;

    if ($gptOrt) echo "[GPT] Ort erkannt: $gptOrt\n";
    echo "[GPT] Typ erkannt: $type\n";
    if ($gptLat && $gptLon) echo "[GPT] Koordinaten erkannt: $gptLat, $gptLon\n";

    // Fallback-Ort
    $location = $gptOrt ?: extractLocation($contentText);
    if (!$location) echo "[WARN] Kein Ort durch GPT oder Fallback-Logik gefunden\n";

    $lat = $gptLat;
    $lon = $gptLon;

    // Fallback Geocoding
    if (!$lat || !$lon) {
        if ($location) {
            list($lat, $lon) = geocodeLocation($location);
            if ($lat && $lon) {
                echo "[INFO] Fallback-Koordinaten ermittelt: $lat, $lon\n";
            } else {
                echo "[WARN] Fallback-Geocoding fehlgeschlagen\n";
            }
        }
    }

    // Bundesland ggf. aus Koordinaten ableiten
    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

    // Veröffentlichungsdatum: <time datetime> bevorzugt, sonst RSS
    $date = null;
    $dateNode = $xpath2->query("//time[@datetime]")->item(0);
    if ($dateNode) {
        $datetimeAttr = $dateNode->getAttribute("datetime");
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/',$datetimeAttr,$m)) {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            echo "[DEBUG] Veröffentlichungsdatum (Artikel): $date\n";
        }
    }
    if (!$date && !empty($article['rssDate'])) {
        $date = $article['rssDate'];
        echo "[DEBUG] Veröffentlichungsdatum (RSS/Fallback): $date\n";
    }
    if (!$date) {
        echo "[WARN] Veröffentlichungsdatum nicht gefunden, Fallback auf heute\n";
        $date = gmdate("Y-m-d");
    }

    $summary = buildSummary($paragraphs);

    echo "[DEBUG] Speichere Artikel mit Keyword: $kw\n";
    echo "[DEBUG] Titel: {$article['title']}\n";
    echo "[DEBUG] URL: {$article['url']}\n";
    $lowerText = mb_strtolower($contentText);
    $pos = $kw ? mb_strpos($lowerText, mb_strtolower($kw)) : false;
    if ($pos !== false) {
        $start = max(0, $pos - 20);
        $snippet = mb_substr($contentText, $start, 60);
        echo "[DEBUG] Kontext des Schlüsselwortes: $snippet\n";
    } else {
        echo "[DEBUG] Kein Kontext des Schlüsselwortes\n";
    }

    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"], $federal, $type);
    $relevantCount++;

    usleep(300000); // 0,3s Throttle pro Artikel
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";
