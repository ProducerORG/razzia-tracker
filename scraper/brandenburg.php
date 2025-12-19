<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/**
 * Konfiguration
 * - $NEWS_URL: Einstiegsliste (Seite 1)
 * - Pagination: Folgeseiten gemäß Muster .../kategorie/null/{SEITE}/1?reset=1
 */
$KEYWORDS = json_decode($_ENV['KEYWORDS'], true) ?? [];
$SUPABASE_URL  = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY  = $_ENV['SUPABASE_KEY'] ?? '';
$NEWS_URL      = 'https://polizei.brandenburg.de/suche/typ/Meldungen/kategorie?reset=1';

echo "[INFO][BB] News-URL: $NEWS_URL\n";
echo "[INFO][BB] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

/**
 * Seiten abrufen
 * Strategie:
 * - Seite 1: $NEWS_URL
 * - Seite 2..N: https://polizei.brandenburg.de/suche/typ/Meldungen/kategorie/null/{page}/1?reset=1
 * - $pageCount konfigurierbar
 */
$articles      = [];
$seenUrls      = [];
$listRegions   = []; // Fallback-Region aus der Trefferliste
$pageCount     = 10; // Anzahl Seiten, anpassbar
$pauseMs       = 500000; // 0.5s

for ($page = 1; $page <= $pageCount; $page++) {
    if ($page === 1) {
        $url = $NEWS_URL;
    } else {
        $url = "https://polizei.brandenburg.de/suche/typ/Meldungen/kategorie/null/$page/1?reset=1";
    }

    echo "[INFO][BB] Lade Seite $page: $url\n";
    $html = @file_get_contents($url);
    if (!$html) {
        echo "[WARN][BB] Fehler beim Laden: $url\n";
        usleep($pauseMs);
        continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);

    // Trefferliste-Items (h4/a führt auf Detailseite)
    $nodes = $xp->query("//div[@id='pbb-search-result-pager']//ul[contains(@class,'pbb-searchlist')]//h4/a");
    echo "[INFO][BB] Treffer auf Seite $page: " . $nodes->length . "\n";

    foreach ($nodes as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '') continue;

        $fullUrl = (strpos($href, 'http') === 0) ? $href : "https://polizei.brandenburg.de$href";

        // Aus der gleichen <li> zusätzlich Region als Fallback ziehen
        $li = $a->parentNode;
        while ($li && $li->nodeName !== 'li') $li = $li->parentNode;

        if ($li) {
            $liXp = new DOMXPath($dom);
            $regNode = $liXp->query(".//p//a[contains(@href,'/suche/typ/Meldungen/landkreis/')]", $li)->item(0);
            if ($regNode) {
                $listRegions[$fullUrl] = trim($regNode->textContent);
            }
        }

        // Titel grob aus Liste (Detailseite liefert oft sauberer)
        $title = preg_replace('/\s+/', ' ', trim($a->textContent));
        if (isset($seenUrls[$fullUrl])) continue;
        $seenUrls[$fullUrl] = true;

        $articles[] = ["title" => $title, "url" => $fullUrl];
    }

    usleep($pauseMs);
}

/**
 * Detailseiten verarbeiten
 */
$relevantCount = 0;

foreach ($articles as $article) {
    $detailUrl = $article['url'];

    if (urlExistsInDatabaseBB($detailUrl, $SUPABASE_URL, $SUPABASE_KEY)) {
        echo "[INFO][BB] Bereits in DB, übersprungen: $detailUrl\n";
        continue;
    }

    $contentHtml = @file_get_contents($detailUrl);
    if (!$contentHtml) {
        echo "[WARN][BB] Detail konnte nicht geladen werden: $detailUrl\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xp2 = new DOMXPath($dom2);

    // Titel aus <h1> (falls vorhanden)
    $h1 = $xp2->query("//h1")->item(0);
    $title = $h1 ? trim(preg_replace('/\s+/', ' ', $h1->textContent)) : $article['title'];

    // Fließtext robust einsammeln
    $paragraphs = $xp2->query(
        "(" .
            // häufige Container auf der Seite
            "//div[contains(@class,'pbb-content')]//p | " .
            "//div[@id='pbb-subcontent']//p" .
        ")[not(ancestor::footer)]"
    );
    if ($paragraphs->length === 0) {
        $paragraphs = $xp2->query("//p");
    }
    if ($paragraphs->length === 0) {
        echo "[WARN][BB] Kein Artikeltext gefunden: $detailUrl\n";
        continue;
    }

    // Reinen Text aufbauen
    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if ($line === '') continue;

        // Footer/Meta abschneiden
        if (preg_match('/^(rückfragen bitte an|kontakt|impressum|datenschutz)/i', $line)) {
            break;
        }
        $contentText .= $line . "\n";
    }

    // Keyword-Check
    $matchedKw = null;
    foreach ($KEYWORDS as $kw) {
        if (preg_match('/' . $kw . '/i', $contentText)) {
            $matchedKw = $kw;
            echo "[DEBUG][BB] Schlüsselwort gefunden: $kw\n";
            break;
        }
    }
    if (!$matchedKw) {
        echo "[INFO][BB] Kein relevantes Schlüsselwort, übersprungen: $detailUrl\n";
        continue;
    }

    // GPT-Metadaten
    $gpt = extractMetadataWithGPTBB($contentText);
    if (!$gpt || !is_array($gpt) || ($gpt['illegal'] ?? false) !== true) {
        echo "[INFO][BB] Kein Fall von illegalem Glücksspiel – verworfen: $detailUrl\n";
        continue;
    }

    $type   = $gpt['typ'] ?? 'Sonstige';
    $gptOrt = $gpt['ort'] ?? null;
    $gptLat = $gpt['koord']['lat'] ?? null;
    $gptLon = $gpt['koord']['lon'] ?? null;
    $federal = $gpt['bundesland'] ?? null;

    if ($gptOrt) echo "[GPT][BB] Ort: $gptOrt\n";
    echo "[GPT][BB] Typ: $type\n";
    if ($gptLat && $gptLon) echo "[GPT][BB] Koord: $gptLat, $gptLon\n";

    // Fallback-Ort: GPT -> Regex -> aus Trefferliste (Landkreis)
    $location = $gptOrt ?: extractLocationBB($contentText) ?: ($listRegions[$detailUrl] ?? null);
    if (!$location) echo "[WARN][BB] Kein Ort ermittelbar (GPT/Regex/Liste)\n";

    // Koordinaten
    $lat = $gptLat;
    $lon = $gptLon;
    if (!$lat || !$lon) {
        if ($location) {
            list($lat, $lon) = geocodeLocationBB($location);
            if ($lat && $lon) echo "[INFO][BB] Fallback-Koordinaten: $lat, $lon\n";
            else echo "[WARN][BB] Geocoding fehlgeschlagen ($location)\n";
        }
    }

    // Bundesland
    if (!$federal && $lat && $lon) {
        $federal = getFederalStateBB($lat, $lon);
        if ($federal) echo "[INFO][BB] Bundesland (Fallback): $federal\n";
    }
    if ($federal) echo "[INFO][BB] Bundesland: $federal\n";

    // Veröffentlichungsdatum
    $date = extractDateBB($xp2, $contentHtml);
    echo "[DEBUG][BB] Veröffentlichungsdatum: $date\n";

    /* // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    $limitDate = date("Y-m-d", strtotime("-60 days"));
    if (strtotime($date) < strtotime($limitDate)) {
        echo "[INFO] Artikel zu alt (Datum: $date, Limit: $limitDate) – ignoriert.\n";
        continue;
    } */

    // Summary (erste brauchbare längere Zeile)
    $summary = buildSummaryBB($paragraphs);

    // Debug-Ausgabe mit Snippet
    $lower = mb_strtolower($contentText);
    $pos   = mb_strpos($lower, mb_strtolower($matchedKw));
    if ($pos !== false) {
        $start   = max(0, $pos - 20);
        $snippet = mb_substr($contentText, $start, 60);
        echo "[DEBUG][BB] Keyword-Kontext: $snippet\n";
    } else {
        echo "[DEBUG][BB] Kein Keyword-Kontext gefunden\n";
    }

    // Speichern
    saveToSupabaseBB($title, $summary, $date, $location, $lat, $lon, $detailUrl, $federal, $type, $SUPABASE_URL, $SUPABASE_KEY);
    $relevantCount++;
}

echo "[INFO][BB] Gesamt verarbeitete relevante Artikel: $relevantCount\n";


/* ========================= Helferfunktionen (BB-spezifisch) ========================= */

function extractLocationBB($text) {
    preg_match_all('/\b(?:in|bei|nahe)\s+(?!Richtung\b)(?!Höhe\b)([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u', $text, $m);
    $found = [];
    foreach ($m[1] as $loc) {
        if (preg_match('/Polizei|Kriminalpolizei|Staatsanwaltschaft|Feuerwehr/i', $loc)) continue;
        if (!in_array($loc, $found, true)) $found[] = $loc;
    }
    return $found[0] ?? null;
}

function geocodeLocationBB($location) {
    $encodedLocation = urlencode($location . ", Deutschland");
    $url = "https://nominatim.openstreetmap.org/search?q=$encodedLocation&format=json&limit=1";
    sleep(1);
    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $ctx = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [null, null];
    $data = json_decode($json, true);
    if (is_array($data) && count($data) > 0) return [$data[0]['lat'] ?? null, $data[0]['lon'] ?? null];
    return [null, null];
}

function saveToSupabaseBB($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type, $SUPABASE_URL, $SUPABASE_KEY) {
    $apiUrl = rtrim($SUPABASE_URL, '/') . "/rest/v1/raids";
    $payload = json_encode([
        "title"   => $title,
        "summary" => $summary,
        "date"    => $date,
        "location"=> $location,
        "lat"     => $lat,
        "lon"     => $lon,
        "url"     => $url,
        "federal" => $federal,
        "type"    => $type,
        "scraper" => true
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log('[ERROR][BB] Supabase CURL error: ' . curl_error($ch));
    } elseif ($httpCode >= 400) {
        echo "[ERROR][BB] Supabase HTTP $httpCode\n";
        echo "[ERROR][BB] Response: $response\n";
        echo "[ERROR][BB] Payload: $payload\n";
    } else {
        echo "[SUCCESS][BB] Gespeichert: $title ($location, $federal)\n";
    }
    curl_close($ch);
}

function buildSummaryBB($paragraphs) {
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if ($line === '') continue;
        if (preg_match('/^(mehr themen|[\d]{2}\.\d{2}\.\d{4}|rückfragen bitte an|^kreispolizeibehörde|^original-content|^pdf-version|^druckversion)/i', $line)) continue;
        if (mb_strlen($line) > 80) {
            return mb_substr($line, 0, 300) . (mb_strlen($line) > 300 ? "..." : "");
        }
    }
    return "";
}

function getFederalStateBB($lat, $lon) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=$lat&lon=$lon";
    sleep(1);
    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $ctx = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    return $data['address']['state'] ?? null;
}

function extractMetadataWithGPTBB($text) {
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
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

function urlExistsInDatabaseBB($url, $SUPABASE_URL, $SUPABASE_KEY) {
    $queryUrl = rtrim($SUPABASE_URL, '/') . "/rest/v1/raids?url=eq." . urlencode($url) . "&select=url&limit=1";
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
        echo "[WARN][BB] Fehler bei DB-URL-Prüfung für $url\n";
        return false; // Im Zweifel nicht überspringen
    }

    $data = json_decode($response, true);
    return !empty($data);
}

/**
 * Veröffentlichungsdatum extrahieren
 * - bevorzugt <time datetime="YYYY-MM-DD ...">
 * - ansonsten "Artikel vom dd.mm.yyyy" im HTML
 * - Fallback: heutiges Datum (UTC)
 */
function extractDateBB(DOMXPath $xp2, string $rawHtml) : string {
    $node = $xp2->query("//time[@datetime]")->item(0);
    if ($node) {
        $dt = $node->getAttribute('datetime');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
    }
    if (preg_match('/Artikel\s+vom\s+(\d{2})\.(\d{2})\.(\d{4})/u', $rawHtml, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    // weitere generische Datums-Suche optional
    return gmdate("Y-m-d");
}
