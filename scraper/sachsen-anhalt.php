<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
$NEWS_URL = 'https://www.sachsen-anhalt.de/bs/pressemitteilungen/';
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';
$PAGE_LIMIT = (int)($_ENV['SA_PAGE_LIMIT'] ?? 15); // wie viele Listen-Seiten gecrawlt werden

echo "[INFO] Basis-URL: $NEWS_URL\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";
echo "[INFO] Seitenlimit: $PAGE_LIMIT\n";

$articles = [];
$seenUrls = [];
$relevantCount = 0;

// --- Pagination: Seite 1 ist die Basis-URL, Folgeseiten über ?tx_tsarssinclude_pi1[page]=N ---
for ($page = 1; $page <= $PAGE_LIMIT; $page++) {
    $listUrl = ($page === 1)
        ? $NEWS_URL
        : $NEWS_URL . '?tx_tsarssinclude_pi1%5Baction%5D=list&tx_tsarssinclude_pi1%5Bcontroller%5D=Base&tx_tsarssinclude_pi1%5Bpage%5D=' . $page;

    echo "[INFO] Lade Listen-Seite: $listUrl\n";
    $html = @file_get_contents($listUrl);
    if (!$html) {
        echo "[WARN] Fehler beim Laden: $listUrl\n";
        continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Artikelblöcke: .tx-rssdisplay-newslist -> darin h2/a für Titel + Link
    $nodes = $xpath->query("//div[contains(@class,'tx-rssdisplay-newslist')]//h2//a");
    echo "[INFO] Einträge auf Seite $page: " . $nodes->length . "\n";

    foreach ($nodes as $a) {
        $title = trim(preg_replace('/\s+/', ' ', $a->textContent));
        $href = $a->getAttribute('href');
        $fullUrl = buildFullUrl($href);

        if (isset($seenUrls[$fullUrl])) continue;
        $seenUrls[$fullUrl] = true;

        $articles[] = ["title" => $title, "url" => $fullUrl];
    }

    usleep(500000); // 0.5s Pause
}

// --- Artikel verarbeiten ---
foreach ($articles as $article) {
    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Bereits in DB, übersprungen: {$article['url']}\n";
        continue;
    }

    echo "[INFO] Lade Detail-Seite: {$article['url']}\n";
    $contentHtml = @file_get_contents($article["url"]);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);

    // Inhalt: robust über verschiedene Container
    // 1) Häufig sind Texte im Hauptbereich (#content) unter p
    // 2) Fallback: alle p im Dokument
    $paragraphs = $xpath2->query("//div[@id='content']//p");
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//p");
    }
    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden: {$article['title']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if ($line === '') continue;

        // Stoppe bei typischen Footer-/Navigationszeilen
        if (preg_match('/^(weiterlesen|zur.*übersicht|kontakt|rückfragen|mehr zum thema|pdf-version|druckversion)/i', $line)) {
            break;
        }
        $contentText .= $line . "\n";
    }

    // Keyword-Check
    $found = false;
    $hitKw = null;
    foreach ($KEYWORDS as $kw) {
        if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $contentText)) {
            $found = true;
            $hitKw = $kw;
            echo "[DEBUG] Schlüsselwort gefunden: $kw\n";
            break;
        }
    }
    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort, übersprungen.\n";
        continue;
    }

    // GPT-Metadaten
    $gptResult = extractMetadataWithGPT($contentText);
    if (!$gptResult || !is_array($gptResult) || ($gptResult['illegal'] ?? false) !== true) {
        echo "[INFO] Kein Fall von illegalem Glücksspiel – Artikel verworfen\n";
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
    if (!$location) echo "[WARN] Kein Ort durch GPT/Fallback\n";

    // Koordinaten
    $lat = $gptLat;
    $lon = $gptLon;
    if (!$lat || !$lon) {
        if ($location) {
            list($lat, $lon) = geocodeLocation($location);
            if ($lat && $lon) echo "[INFO] Fallback-Koordinaten: $lat, $lon\n";
            else echo "[WARN] Fallback-Geocoding fehlgeschlagen\n";
        }
    }

    // Bundesland
    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

    // Veröffentlichungsdatum:
    // 1) Versuche auf Detailseite einen Datums-Container zu finden
    // 2) Regex-Fallback (dd.mm.yyyy) im gesamten Dokument
    $date = null;

    // Spezifische Box aus Listen-/Detailansicht
    $dateNode = $xpath2->query("//div[contains(@class,'tx-rssdisplay-item-meta-date')] | //p[contains(@class,'date')] | //time");
    if ($dateNode->length > 0) {
        $raw = trim($dateNode[0]->textContent);
        $date = extractDateYmd($raw);
        if ($date) echo "[DEBUG] Veröffentlichungsdatum (Box): $date\n";
    }
    if (!$date) {
        // time datetime-Attribut (falls vorhanden)
        $timeAttrNode = $xpath2->query("//time[@datetime]");
        if ($timeAttrNode->length > 0) {
            $dt = $timeAttrNode[0]->getAttribute("datetime");
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]}";
                echo "[DEBUG] Veröffentlichungsdatum (time@datetime): $date\n";
            }
        }
    }
    if (!$date) {
        // globaler Regex-Fallback
        $date = extractDateYmd($contentHtml);
        if ($date) echo "[DEBUG] Veröffentlichungsdatum (Regex): $date\n";
    }
    if (!$date) {
        echo "[WARN] Veröffentlichungsdatum nicht gefunden, Fallback auf heute\n";
        $date = gmdate("Y-m-d");
    }

    // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    if (strtotime($date) < strtotime("2025-07-01")) {
        echo "[INFO] Artikel zu alt (Datum: $date) – ignoriert.\n";
        continue;
    }
    
    $summary = buildSummary($paragraphs);

    echo "[DEBUG] Speichere Artikel mit Keyword: $hitKw\n";
    echo "[DEBUG] Titel: {$article['title']}\n";
    echo "[DEBUG] URL: {$article['url']}\n";
    $lowerText = mb_strtolower($contentText);
    $pos = mb_strpos($lowerText, mb_strtolower($hitKw));
    if ($pos !== false) {
        $start = max(0, $pos - 20);
        $snippet = mb_substr($contentText, $start, 60);
        echo "[DEBUG] Kontext: $snippet\n";
    } else {
        echo "[DEBUG] Kein Kontext des Schlüsselwortes\n";
    }

    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"], $federal, $type);
    $relevantCount++;
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";

// ----------------- Hilfsfunktionen -----------------

function buildFullUrl($href) {
    if (!$href) return null;
    if (strpos($href, 'http') === 0) return $href;
    // Seiten liefern häufig relative Pfade wie "/bs/pressemitteilungen?...":
    return "https://www.sachsen-anhalt.de" . (substr($href, 0, 1) === '/' ? $href : '/' . $href);
}

function extractDateYmd($text) {
    // Muster: 14.08.2025 oder 14.08.2025, 15:35 Uhr
    if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/u', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
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
