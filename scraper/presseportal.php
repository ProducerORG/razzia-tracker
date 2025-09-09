<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
//KEYWORDS=glücksspiel,spielhalle,spielautomat,casino,lotto,lotterie,online-casino,automatenspiele
//$KEYWORDS = ['Drogen']; //Testzwecke
$NEWS_URL = $_ENV['NEWS_URL'] ?? 'https://www.presseportal.de/blaulicht';
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

echo "[INFO] News-URL: $NEWS_URL\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

$articles = [];
$seenUrls = [];
$relevantCount = 0;
$pageCount = 60; //+++
$step = 30;

$SCRAPE_URLS = [
    ['base' => $NEWS_URL, 'type' => 'offset'], // z.B. https://www.presseportal.de/blaulicht
    ['base' => 'https://www.presseportal.de/suche/gl%C3%BCcksspiel/blaulicht', 'type' => 'page'] // Such-URL mit ?page=
];

foreach ($SCRAPE_URLS as $source) {
    $baseUrl = $source['base'];
    $mode = $source['type'];

    for ($i = 0; $i < $pageCount; $i++) {
        if ($mode === 'offset') {
            $offset = $i * $step;
            $url = $baseUrl . ($offset > 0 ? "/$offset" : '');
        } elseif ($mode === 'page') {
            $page = $i + 1;
            $url = $baseUrl . '?page=' . $page;
        } else {
            continue;
        }

        echo "[INFO] Lade Seite: $url\n";
        $html = @file_get_contents($url);
        if (!$html) {
            echo "[WARN] Fehler beim Laden: $url\n";
            continue;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//a[contains(@href, '/blaulicht/pm/')]");

        echo "[INFO] Artikel auf Seite ($url): " . $nodes->length . " (nur Links, keine Filterung)\n";

        foreach ($nodes as $node) {
            $titleRaw = trim($node->textContent);
            $title = preg_replace('/^POL-[A-ZÄÖÜ]{1,5}:\s*/u', '', $titleRaw);
            $href = $node->getAttribute("href");
            $fullUrl = (strpos($href, 'http') === 0) ? $href : "https://www.presseportal.de$href";

            if (isset($seenUrls[$fullUrl])) continue; // Duplikat
            $seenUrls[$fullUrl] = true;

            $articles[] = ["title" => $title, "url" => $fullUrl];
        }

        usleep(500000); // 0.5 Sekunden
    }
}

// Artikel verarbeiten
foreach ($articles as $article) {
    //echo "[INFO] Verarbeite Artikel: {$article['title']}\n";

    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Artikel bereits in Datenbank, übersprungen: {$article['url']}\n";
        continue;
    }

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

    $found = false;
    foreach ($KEYWORDS as $kw) {
        if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $contentText)) {
            $found = true;
            echo "[DEBUG] Schlüsselwort gefunden: $kw\n";
            break;
        }
    }

    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort gefunden, Artikel übersprungen.\n";
        continue;
    }


    // GPT-Metadaten extrahieren
    $gptResult = extractMetadataWithGPT($contentText);

    if (!$gptResult || !is_array($gptResult) || ($gptResult['illegal'] ?? false) !== true) {
        echo "[INFO] Kein Fall von illegalem Glücksspiel – Artikel verworfen\n";
        continue;
    }

    $type = "Sonstige"; // Default
    $gptOrt = null;
    $gptLat = null;
    $gptLon = null;

    // GPT-Antwort auswerten
    if ($gptResult && is_array($gptResult)) {
        $gptOrt = $gptResult['ort'] ?? null;
        $type = $gptResult['typ'] ?? "Sonstige";
        $gptLat = $gptResult['koord']['lat'] ?? null;
        $gptLon = $gptResult['koord']['lon'] ?? null;
        $federal = $gptResult['bundesland'] ?? null;

        if ($gptOrt) {
            echo "[GPT] Ort erkannt: $gptOrt\n";
        }
        echo "[GPT] Typ erkannt: $type\n";
        if ($gptLat && $gptLon) {
            echo "[GPT] Koordinaten erkannt: $gptLat, $gptLon\n";
        }
    }

    // Fallback-Logik bei fehlendem Ort
    $location = $gptOrt ?: extractLocation($contentText);
    if (!$location) {
        echo "[WARN] Kein Ort durch GPT oder Fallback-Logik gefunden\n";
    }

    $lat = $gptLat;
    $lon = $gptLon;

    // Wenn GPT keine Koordinaten liefert → Fallback auf Geocoding
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

    // Bundesland ermitteln (sofern Koordinaten vorhanden)
    $federal = $gptResult['bundesland'] ?? null;

    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) {
            echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
        }
    }

    if ($federal) {
        echo "[INFO] Bundesland: $federal\n";
    }

    // Robuste Extraktion des Veröffentlichungsdatums aus dem <time datetime="">
    $dateNode = $xpath2->query("//p[contains(@class,'date')]/time");
    if ($dateNode->length > 0) {
        $datetimeAttr = $dateNode[0]->getAttribute("datetime"); // Format: 2025-07-02 10:30:01
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $datetimeAttr, $matches)) {
            $date = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            echo "[DEBUG] Veröffentlichungsdatum extrahiert: $date\n";
        } else {
            echo "[WARN] Datum nicht interpretierbar, Fallback auf heute\n";
            $date = gmdate("Y-m-d");
        }
    } else {
        echo "[WARN] Veröffentlichungsdatum nicht gefunden, Fallback auf heute\n";
        $date = gmdate("Y-m-d");
    }
    
    $summary = buildSummary($paragraphs);

    echo "[DEBUG] Speichere Artikel mit Keyword: $kw\n";
    echo "[DEBUG] Titel: {$article['title']}\n";
    echo "[DEBUG] URL: {$article['url']}\n";
    $lowerText = mb_strtolower($contentText);
    $pos = mb_strpos($lowerText, mb_strtolower($kw));

    if ($pos !== false) {
        $start = max(0, $pos - 20);
        $snippet = mb_substr($contentText, $start, 60);
        echo "[DEBUG] Kontext des Schlüsselwortes: $snippet\n";
    } else {
        echo "[DEBUG] Kein Kontext des Schlüsselwortes\n";
    }
    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"], $federal, $type);

    $relevantCount++;
}

function extractLocation($text) {
    preg_match_all('/\b(?:in|bei|nahe)\s+(?!Richtung\b)(?!Höhe\b)([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u', $text, $matches);
    $found = [];

    foreach ($matches[1] as $loc) {
        if (preg_match('/Polizei|Kriminalpolizei|Staatsanwaltschaft|Feuerwehr/i', $loc)) continue;
        if (!in_array($loc, $found)) $found[] = $loc;
    }

    return $found[0] ?? null;
}

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

function saveToSupabase($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type) {
    global $SUPABASE_URL, $SUPABASE_KEY;

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

function buildSummary($paragraphs) {
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if (preg_match('/^(mehr themen|[\d]{2}\.\d{2}\.\d{4}|rückfragen bitte an|^kreispolizeibehörde|^original-content|^pdf-version|^druckversion)/i', $line)) continue;
        if (mb_strlen($line) > 80) {
            return mb_substr($line, 0, 300) . (mb_strlen($line) > 300 ? "..." : "");
        }
    }
    return "";
}

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

function extractMetadataWithGPT($text) {
    $apiKey = $_ENV['OPENAI_API_KEY'];
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
        "temperature" => 0.1
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


function urlExistsInDatabase($url) {
    global $SUPABASE_URL, $SUPABASE_KEY;

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
    return !empty($data); // true, wenn URL schon existiert
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";