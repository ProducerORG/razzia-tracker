<?php

// scraper/schleswig-holstein.php
// Funktioniert analog zu presseportal.php, wertet aber https://www.shz.de/lokales/blaulicht-sh aus
// und lädt zusätzlich 2–3x „Weitere Inhalte laden“.

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
//KEYWORDS=glücksspiel,spielhalle,spielautomat,casino,lotto,lotterie,online-casino,automatenspiele
//$KEYWORDS = ['Drogen']; //Testzwecke
$NEWS_URL = 'https://www.shz.de/lokales/blaulicht-sh';
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

echo "[INFO] News-URL: $NEWS_URL\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

$articles = [];
$seenUrls = [];
$relevantCount = 0;

// User-Agent für Fetches (einige Endpunkte verlangen einen UA)
$httpCtx = stream_context_create([
    'http' => [
        'header' => "User-Agent: razzia-map/1.0\r\nAccept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        'timeout' => 20
    ]
]);

// 1) Basisseite laden und Teaser sammeln
echo "[INFO] Lade Basisseite\n";
$baseHtml = @file_get_contents($NEWS_URL, false, $httpCtx);
if ($baseHtml) {
    list($newTeasers, $nextAjaxUrl) = parseShzListingPage($baseHtml, $NEWS_URL);
    foreach ($newTeasers as $t) {
        if (isset($seenUrls[$t['url']])) continue;
        $seenUrls[$t['url']] = true;
        $articles[] = $t;
    }
} else {
    echo "[WARN] Basisseite konnte nicht geladen werden\n";
    $nextAjaxUrl = null;
}

// 2) „Weitere Inhalte laden“ 2–3x simulieren (AJAX-Endpunkt)
$maxLoads = 3;
$loadNum = 0;

while ($nextAjaxUrl && $loadNum < $maxLoads) {
    $loadNum++;
    echo "[INFO] Lade weitere Inhalte ($loadNum): $nextAjaxUrl\n";
    $ajaxHtml = @file_get_contents($nextAjaxUrl, false, $httpCtx);
    if (!$ajaxHtml) {
        echo "[WARN] AJAX-Chunk konnte nicht geladen werden\n";
        break;
    }

    list($newTeasers, $updatedAjaxUrl) = parseShzListingPage($ajaxHtml, $NEWS_URL);
    $added = 0;
    foreach ($newTeasers as $t) {
        if (isset($seenUrls[$t['url']])) continue;
        $seenUrls[$t['url']] = true;
        $articles[] = $t;
        $added++;
    }
    echo "[INFO] Zusätzliche Teaser gefunden: $added\n";

    // Nächste AJAX-URL aktualisieren
    $nextAjaxUrl = $updatedAjaxUrl ?: null;

    usleep(400000); // 0.4s Pause
}

// 3) Artikel verarbeiten
foreach ($articles as $article) {

    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Artikel bereits in Datenbank, übersprungen: {$article['url']}\n";
        continue;
    }

    $contentHtml = @file_get_contents($article["url"], false, $httpCtx);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);

    // Versuche, Artikeltext robust zu extrahieren (SHZ hat mehrere Layoutvarianten, teils Paywall)
    $paragraphs = $xpath2->query("//div[contains(@class,'article__detail__content')]//p");
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//div[contains(@class,'article-detail')]//p");
    }
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//article//p");
    }
    // Fallback auf Meta-Description, falls Paywall oder keine Absätze gefunden
    $contentText = "";
    if ($paragraphs->length > 0) {
        foreach ($paragraphs as $p) {
            $line = trim($p->textContent);
            if ($line === '') continue;
            $contentText .= $line . "\n";
        }
    } else {
        $metaDesc = extractMetaDescription($xpath2);
        if ($metaDesc) {
            $contentText = $metaDesc;
            echo "[INFO] Fallback: Meta-Description als Inhalt verwendet (vermutlich Paywall)\n";
        } else {
            echo "[WARN] Kein Artikeltext gefunden für: {$article['title']}\n";
            continue;
        }
    }

    // Keyword-Prüfung
    $found = false; $kw = null;
    foreach ($KEYWORDS as $k) {
        if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $contentText)) {
            $found = true;
            $kw = $k;
            echo "[DEBUG] Schlüsselwort gefunden: $k\n";
            break;
        }
    }
    // Letzter Fallback: in Titel prüfen, wenn Text knapp ist
    if (!$found && mb_strlen($contentText) < 200) {
        foreach ($KEYWORDS as $k) {
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $article['title'])) {
                $found = true;
                $kw = $k;
                echo "[DEBUG] Schlüsselwort im Titel gefunden: $k\n";
                break;
            }
        }
    }
    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort gefunden, Artikel übersprungen.\n";
        continue;
    }

    // GPT-Metadaten extrahieren
    $gptResult = extractMetadataWithGPT(mb_substr($contentText, 0, 4000));
    if (!$gptResult || !is_array($gptResult) || ($gptResult['illegal'] ?? false) !== true) {
        echo "[INFO] Kein Fall von illegalem Glücksspiel – Artikel verworfen\n";
        continue;
    }

    $type = "Sonstige";
    $gptOrt = $gptResult['ort'] ?? null;
    $gptLat = $gptResult['koord']['lat'] ?? null;
    $gptLon = $gptResult['koord']['lon'] ?? null;
    $federal = $gptResult['bundesland'] ?? null;

    if ($gptOrt) echo "[GPT] Ort erkannt: $gptOrt\n";
    echo "[GPT] Typ erkannt: " . ($gptResult['typ'] ?? $type) . "\n";
    if ($gptLat && $gptLon) echo "[GPT] Koordinaten erkannt: $gptLat, $gptLon\n";

    $type = $gptResult['typ'] ?? "Sonstige";

    // Fallback-Logik bei fehlendem Ort
    $location = $gptOrt ?: extractLocation($contentText);
    if (!$location) {
        echo "[WARN] Kein Ort durch GPT oder Fallback-Logik gefunden\n";
    }

    $lat = $gptLat;
    $lon = $gptLon;

    // Wenn GPT keine Koordinaten liefert → Fallback auf Geocoding
    if ((!$lat || !$lon) && $location) {
        list($lat, $lon) = geocodeLocation($location);
        if ($lat && $lon) {
            echo "[INFO] Fallback-Koordinaten ermittelt: $lat, $lon\n";
        } else {
            echo "[WARN] Fallback-Geocoding fehlgeschlagen\n";
        }
    }

    // Bundesland ermitteln (sofern Koordinaten vorhanden)
    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) {
            echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
        }
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

    // Veröffentlichungsdatum robust extrahieren
    $date = extractIsoDate($xpath2);
    if ($date) {
        echo "[DEBUG] Veröffentlichungsdatum extrahiert: $date\n";
    } else {
        echo "[WARN] Veröffentlichungsdatum nicht gefunden, Fallback auf heute\n";
        $date = gmdate("Y-m-d");
    }

    // Kurze Summary bauen (aus den ersten sinnvollen Absätzen oder Meta)
    $summary = ($paragraphs->length > 0) ? buildSummary($paragraphs) : mb_substr($contentText, 0, 300);

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
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";


/**
 * Hilfsfunktionen
 */

function parseShzListingPage($html, $baseUrl) {
    // Liefert: [ [ ['title'=>..., 'url'=>...], ... ], $nextAjaxUrl ]
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $items = [];

    // Alle Teaser (News/Video) aufnehmen
    $nodes = $xpath->query("//div[contains(@class,'article__teaser')]//a[contains(@href,'/lokales/')][@target='_self' or not(@target)]");
    $picked = 0;
    foreach ($nodes as $a) {
        $href = trim($a->getAttribute("href"));
        if ($href === '' || strpos($href, '/video/') !== false) continue; // Video-Teaser überspringen
        // Volle URL bauen
        $full = (strpos($href, 'http') === 0) ? $href : rtrim('https://www.shz.de', '/') . '/' . ltrim($href, '/');

        // Titel
        $title = trim($a->textContent);
        if ($title === '') {
            // Alternative: H3 innerhalb Teasers
            $h = $a->parentNode ? $a->parentNode->getElementsByTagName('h3')->item(0) : null;
            if ($h) $title = trim($h->textContent);
        }
        if ($title === '') {
            // Letzter Fallback: data-article-id referenziert? Dann ignorieren wir ohne Titel nicht.
            $title = "Ohne Titel";
        }

        $items[] = ['title' => $title, 'url' => $full];
        $picked++;
    }
    echo "[INFO] Teaser auf Seite/Chunk: $picked\n";

    // Button „Weitere Inhalte laden“ finden -> nächste AJAX-URL
    $btn = $xpath->query("//button[contains(@class,'button-reload') and @data-url]");
    $nextAjaxUrl = null;
    if ($btn->length > 0) {
        $dataUrl = $btn->item(0)->getAttribute('data-url');
        if ($dataUrl) {
            $nextAjaxUrl = (strpos($dataUrl, 'http') === 0) ? $dataUrl : rtrim('https://www.shz.de', '/') . '/' . ltrim($dataUrl, '/');
        }
    }

    return [$items, $nextAjaxUrl];
}

function extractMetaDescription(DOMXPath $xpath) {
    $nodes = $xpath->query("//meta[@name='description']/@content");
    if ($nodes->length > 0) return trim($nodes->item(0)->nodeValue);
    $og = $xpath->query("//meta[@property='og:description']/@content");
    if ($og->length > 0) return trim($og->item(0)->nodeValue);
    return null;
}

function extractIsoDate(DOMXPath $xpath) {
    // 1) <time datetime="YYYY-MM-DD ...">
    $time = $xpath->query("//time[@datetime]");
    if ($time->length > 0) {
        $dt = $time->item(0)->getAttribute('datetime');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
    }
    // 2) data-attributes oder JS-Varianten sind aufwendig; Fallback: heute
    return null;
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
