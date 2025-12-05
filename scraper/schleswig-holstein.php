<?php

// scraper/schleswig-holstein.php
// SHZ-Blaulicht (https://www.shz.de/lokales/blaulicht-sh) mit robustem Nachladen + RSS-Fallback

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

// ---------- HTTP ----------
function http_fetch($url, $isAjax = false, $referer = null) {
    $ch = curl_init();
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: de-DE,de;q=0.9,en;q=0.7',
        'User-Agent: razzia-map/1.0 (+https://example.invalid)',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    if ($isAjax) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_ENCODING => '', // gzip/deflate
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($referer) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        echo "[WARN] CURL ".curl_errno($ch).": ".curl_error($ch)." @ $url\n";
    } elseif ($httpCode >= 400) {
        echo "[WARN] HTTP $httpCode @ $url\n";
    }
    curl_close($ch);
    return $html ?: '';
}

// ---------- Parsen Auflistung ----------
function parseShzListingPage($html) {
    // Liefert: [ items[], nextAjaxUrl, rssUrl ]
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $items = [];

    // 1) Robuste Teaser-Suche:
    //    - Container mit data-article-id ODER class article__teaser
    //    - Links zu /artikel/
    $containers = $xpath->query("//*[(@data-article-id) or contains(@class,'article__teaser')]");
    $picked = 0;
    foreach ($containers as $c) {
        $a = null;
        // bevorzugt Text-Link
        $aList = (new DOMXPath($c->ownerDocument))->query(".//a[contains(@href,'/artikel/')]", $c);
        if ($aList->length > 0) $a = $aList->item(0);
        if (!$a) continue;
        $href = trim($a->getAttribute('href'));
        if ($href === '' || strpos($href, '/video/') !== false) continue;
        $full = (strpos($href, 'http') === 0) ? $href : 'https://www.shz.de' . (strpos($href,'/')===0 ? $href : '/'.$href);

        // Titel aus h3 im Container oder Text des Links
        $h3 = (new DOMXPath($c->ownerDocument))->query(".//h3", $c);
        $title = $h3->length ? trim($h3->item(0)->textContent) : trim($a->textContent);
        if ($title === '') $title = "Ohne Titel";

        $items[] = ['title' => $title, 'url' => $full];
        $picked++;
    }

    // 2) Fallback: grobe Suche nach /lokales/.../artikel/
    if ($picked === 0) {
        $aAll = $xpath->query("//a[contains(@href,'/lokales/') and contains(@href,'/artikel/')]");
        foreach ($aAll as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || strpos($href, '/video/') !== false) continue;
            $full = (strpos($href, 'http') === 0) ? $href : 'https://www.shz.de' . (strpos($href,'/')===0 ? $href : '/'.$href);
            $title = trim($a->textContent);
            if ($title === '') $title = "Ohne Titel";
            $items[] = ['title' => $title, 'url' => $full];
        }
        $picked = count($items);
    }

    echo "[INFO] Teaser auf Seite/Chunk: $picked\n";

    // 3) Nächste AJAX-URL (falls Button vorhanden)
    $btn = $xpath->query("//button[contains(@class,'button-reload') and @data-url]");
    $nextAjaxUrl = null;
    if ($btn->length > 0) {
        $dataUrl = $btn->item(0)->getAttribute('data-url');
        if ($dataUrl) {
            $nextAjaxUrl = (strpos($dataUrl, 'http') === 0) ? $dataUrl : 'https://www.shz.de' . (strpos($dataUrl,'/')===0 ? $dataUrl : '/'.$dataUrl);
        }
    }

    // 4) RSS-Link extrahieren (für sicheren Fallback)
    $rss = $xpath->query("//meta[@name='feed']/@content");
    $rssUrl = $rss->length ? trim($rss->item(0)->nodeValue) : null;

    return [$items, $nextAjaxUrl, $rssUrl];
}

// Falls der AJAX-Response JSON mit HTML-Fragment liefert
function extractHtmlFromMaybeJson($payload) {
    $trim = ltrim($payload);
    if ($trim === '' ) return '';
    if ($trim[0] !== '{' && $trim[0] !== '[') return $payload;

    $json = json_decode($payload, true);
    if (!is_array($json)) return $payload;
    // Häufige Felder, die HTML enthalten könnten
    foreach (['html','content','data','body'] as $k) {
        if (isset($json[$k]) && is_string($json[$k])) return $json[$k];
    }
    // manchmal Array von Chunks
    foreach ($json as $v) {
        if (is_array($v)) {
            foreach (['html','content','data','body'] as $k) {
                if (isset($v[$k]) && is_string($v[$k])) return $v[$k];
            }
        }
    }
    return $payload;
}

// ---------- RSS-Fallback ----------
function fetchFromRss($rssUrl) {
    if (!$rssUrl) return [];
    echo "[INFO] Lese RSS: $rssUrl\n";
    $xml = http_fetch($rssUrl, false, $rssUrl);
    if (!$xml) {
        echo "[WARN] RSS konnte nicht geladen werden\n";
        return [];
    }
    $items = [];
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (@$doc->loadXML($xml)) {
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query("//item") as $item) {
            $linkNode  = $xpath->query(".//link", $item)->item(0);
            $titleNode = $xpath->query(".//title", $item)->item(0);
            if (!$linkNode) continue;
            $u = trim($linkNode->textContent);
            if ($u === '' || strpos($u, '/video/') !== false) continue;
            // Nur Artikel
            if (strpos($u, '/artikel/') === false) continue;
            $t = $titleNode ? trim($titleNode->textContent) : "Ohne Titel";
            $items[] = ['title' => $t, 'url' => $u];
        }
    } else {
        echo "[WARN] RSS XML ungültig\n";
    }
    echo "[INFO] RSS-Artikel gefunden: " . count($items) . "\n";
    return $items;
}

// ---------- 1) Basisseite ----------
echo "[INFO] Lade Basisseite\n";
$baseHtml = http_fetch($NEWS_URL, false, $NEWS_URL);
list($teasersBase, $ajaxUrl, $rssUrl) = parseShzListingPage($baseHtml);

foreach ($teasersBase as $t) {
    if (!isset($seenUrls[$t['url']])) {
        $seenUrls[$t['url']] = true;
        $articles[] = $t;
    }
}

// ---------- 2) AJAX-Nachladen (3–5 Versuche) ----------
$maxLoads = 5;
$loadNum = 0;
while ($ajaxUrl && $loadNum < $maxLoads) {
    $loadNum++;
    echo "[INFO] Lade weitere Inhalte ($loadNum): $ajaxUrl\n";
    $payload = http_fetch($ajaxUrl, true, $NEWS_URL);
    if (!$payload) {
        echo "[WARN] AJAX-Chunk leer\n";
        break;
    }
    $chunkHtml = extractHtmlFromMaybeJson($payload);
    list($teasersChunk, $ajaxUrlNew) = parseShzListingPage($chunkHtml);

    $added = 0;
    foreach ($teasersChunk as $t) {
        if (!isset($seenUrls[$t['url']])) {
            $seenUrls[$t['url']] = true;
            $articles[] = $t;
            $added++;
        }
    }
    echo "[INFO] Zusätzliche Teaser gefunden: $added\n";

    // Wenn 0 gefunden wurde, trotzdem versuchen wir noch 1x weiter – sonst abbrechen
    if ($added === 0 && $ajaxUrlNew === null) {
        // Manche Implementierungen brauchen Parameter-Anpassungen — hier Abbruch.
        break;
    }
    $ajaxUrl = $ajaxUrlNew ?: $ajaxUrl; // wenn die gleiche URL zurückkommt, verhindern wir Endlosschleifen via maxLoads
    usleep(400000);
}

// ---------- 3) RSS-Fallback einbinden ----------
$rssItems = fetchFromRss($rssUrl ?: 'https://www.shz.de/lokales/blaulicht-sh/rss');
$addedRss = 0;
foreach ($rssItems as $t) {
    if (!isset($seenUrls[$t['url']])) {
        $seenUrls[$t['url']] = true;
        $articles[] = $t;
        $addedRss++;
    }
}
echo "[INFO] Aus RSS neu hinzugefügt: $addedRss\n";

// ---------- 4) Artikel verarbeiten ----------
foreach ($articles as $article) {

    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Artikel bereits in Datenbank, übersprungen: {$article['url']}\n";
        continue;
    }

    $contentHtml = http_fetch($article["url"], false, $NEWS_URL);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);

    // Inhalt extrahieren (verschiedene Layoutvarianten + Paywall)
    $paragraphs = $xpath2->query("//div[contains(@class,'article__detail__content')]//p");
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//div[contains(@class,'article-detail')]//p");
    }
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//article//p");
    }

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

    // Keyword-Prüfung (inkl. Hyphen-Varianten tolerant)
    $found = false; $kw = null;
    foreach ($KEYWORDS as $k) {
        $pattern = '/(?<!\pL)'.str_replace('\-','[- ]?',preg_quote($k,'/')).'(?!\pL)/iu';
        if (preg_match($pattern, $contentText) || preg_match($pattern, $article['title'])) {
            $found = true; $kw = $k;
            echo "[DEBUG] Schlüsselwort gefunden: $k\n";
            break;
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

    $type = $gptResult['typ'] ?? "Sonstige";
    $gptOrt = $gptResult['ort'] ?? null;
    $gptLat = $gptResult['koord']['lat'] ?? null;
    $gptLon = $gptResult['koord']['lon'] ?? null;
    $federal = $gptResult['bundesland'] ?? null;

    if ($gptOrt) echo "[GPT] Ort erkannt: $gptOrt\n";
    echo "[GPT] Typ erkannt: $type\n";
    if ($gptLat && $gptLon) echo "[GPT] Koordinaten erkannt: $gptLat, $gptLon\n";

    // Fallback-Logik Ort/Koordinaten/Bundesland
    $location = $gptOrt ?: extractLocation($contentText);
    if (!$location) echo "[WARN] Kein Ort durch GPT oder Fallback-Logik gefunden\n";

    $lat = $gptLat; $lon = $gptLon;
    if ((!$lat || !$lon) && $location) {
        list($lat, $lon) = geocodeLocation($location);
        if ($lat && $lon) echo "[INFO] Fallback-Koordinaten ermittelt: $lat, $lon\n";
        else echo "[WARN] Fallback-Geocoding fehlgeschlagen\n";
    }

    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

    // Veröffentlichungsdatum
    $date = extractIsoDate($xpath2);
    if ($date) echo "[DEBUG] Veröffentlichungsdatum extrahiert: $date\n";
    else {
        echo "[WARN] Veröffentlichungsdatum nicht gefunden, Fallback auf heute\n";
        $date = gmdate("Y-m-d");
    }

    // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    if (strtotime($date) < strtotime("2025-07-01")) {
        echo "[INFO] Artikel zu alt (Datum: $date) – ignoriert.\n";
        continue;
    }

    // Summary
    $summary = ($paragraphs->length > 0) ? buildSummary($paragraphs) : mb_substr($contentText, 0, 300);

    echo "[DEBUG] Speichere Artikel mit Keyword: ".($kw ?? '-')."\n";
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


// ----------------- Hilfsfunktionen aus deinem Bestand + Anpassungen -----------------

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
