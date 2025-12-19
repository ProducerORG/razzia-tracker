<?php
/**
 * scraper/sachsen.php
 *
 * Scraper für https://www.polizei.sachsen.de/de/presseinfo_aktuell.asp
 * Liest die neuesten Medieninfos je Polizeidirektion (Chemnitz, Dresden, Görlitz, Leipzig, Zwickau)
 * über den Medienservice Sachsen und verarbeitet sie identisch wie presseportal.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration
$KEYWORDS = json_decode($_ENV['KEYWORDS'], true) ?? [];
$SUPABASE_URL = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY = $_ENV['SUPABASE_KEY'] ?? '';

echo "[INFO] Quelle: Medienservice Sachsen (alle PDs)\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

// Polizeidirektionen (IDs laut Links auf polizei.sachsen.de)
$PD_SOURCES = [
    'Chemnitz' => 'https://medienservice.sachsen.de/medien/?search[institution_ids][]=10996',
    'Dresden'  => 'https://medienservice.sachsen.de/medien/?search[institution_ids][]=10997',
    'Görlitz'  => 'https://medienservice.sachsen.de/medien/?search[institution_ids][]=10998',
    'Leipzig'  => 'https://medienservice.sachsen.de/medien/?search[institution_ids][]=10976',
    'Zwickau'  => 'https://medienservice.sachsen.de/medien/?search[institution_ids][]=10999',
];

// Wie viele Listenseiten je PD prüfen (Page-Param ist üblich, konservativ 2 Seiten)
$LIST_PAGES_PER_PD = 2;

$articles = [];
$seenUrls = [];
$relevantCount = 0;

/**
 * --- 1) Listen je PD einlesen und Artikellinks einsammeln ---
 */
foreach ($PD_SOURCES as $pdName => $baseUrl) {
    echo "[INFO] Polizeidirektion: $pdName\n";
    for ($page = 1; $page <= $LIST_PAGES_PER_PD; $page++) {
        $listUrl = $baseUrl . '&page=' . $page;
        echo "[INFO] Lade Liste: $listUrl\n";
        $html = @file_get_contents($listUrl);
        if (!$html) {
            echo "[WARN] Fehler beim Laden: $listUrl\n";
            continue;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Heuristik: Links zu Einzelmeldungen enthalten i.d.R. /medien/ in der URL und KEINE weiteren search[...] Parameter
        $nodes = $xpath->query("//a[contains(@href, '/medien/') and not(contains(@href,'search['))]");

        echo "[INFO] Gefundene Linkkandidaten (roh): {$nodes->length}\n";

        foreach ($nodes as $a) {
            $href = $a->getAttribute('href');
            $title = trim($a->textContent);
            // Absolut-URL bilden
            $fullUrl = absolutizeUrl($href, 'https://medienservice.sachsen.de');

            // Grobe Filter: nur Detailseiten (meist /medien/*-<id>.html o.ä., nicht /medien/ ohne Slug)
            if (!preg_match('#/medien/[^/?]+#', parse_url($fullUrl, PHP_URL_PATH) ?? '')) {
                continue;
            }

            // Doppelte vermeiden
            if (isset($seenUrls[$fullUrl])) continue;
            $seenUrls[$fullUrl] = true;

            $articles[] = [
                "title" => $title ?: "(Ohne Titel)",
                "url"   => $fullUrl,
                "pd"    => $pdName
            ];
        }

        usleep(400000); // Server schonen
    }
}

echo "[INFO] Gesamt gesammelte Artikel-Links: " . count($articles) . "\n";

/**
 * --- 2) Einzelartikel laden, Keywords/GPT prüfen, speichern ---
 */
foreach ($articles as $article) {
    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Bereits in DB, übersprungen: {$article['url']}\n";
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

    // Inhalt: verschiedene mögliche Container abdecken
    $paragraphs = $xpath2->query("//article//p");
    if ($paragraphs->length === 0) {
        $paragraphs = $xpath2->query("//div[contains(@class,'content') or contains(@class,'article') or contains(@class,'ms-') or contains(@id,'content')]//p");
        if ($paragraphs->length === 0) {
            $paragraphs = $xpath2->query("//p");
        }
    }

    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden: {$article['url']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if ($line === '') continue;

        // Häufige Footer/Service-Passagen abbrechen
        if (preg_match('/^(rückfragen bitte an|kontakt|mehr infos|original-?content|druckversion|pdf-version)/i', $line)) {
            break;
        }
        $contentText .= $line . "\n";
    }

    // Keywords
    $found = false;
    $kw = null;
    foreach ($KEYWORDS as $kwTry) {
        if (preg_match('/' . $kwTry . '/i', $contentText)) {
            $found = true;
            $kw = $kwTry;
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
        echo "[INFO] Kein Fall von illegalem Glücksspiel – verworfen\n";
        continue;
    }

    $type   = $gptResult['typ'] ?? "Sonstige";
    $gptOrt = $gptResult['ort'] ?? null;
    $gptLat = $gptResult['koord']['lat'] ?? null;
    $gptLon = $gptResult['koord']['lon'] ?? null;
    $federal = $gptResult['bundesland'] ?? null;

    if ($gptOrt) echo "[GPT] Ort erkannt: $gptOrt\n";
    echo "[GPT] Typ erkannt: $type\n";
    if ($gptLat && $gptLon) echo "[GPT] Koordinaten: $gptLat, $gptLon\n";

    // Fallback-Ort
    $location = $gptOrt ?: extractLocation($contentText);
    if (!$location) echo "[WARN] Kein Ort aus GPT/Fallback\n";

    $lat = $gptLat;
    $lon = $gptLon;
    if (!$lat || !$lon) {
        if ($location) {
            list($lat, $lon) = geocodeLocation($location);
            if ($lat && $lon) echo "[INFO] Fallback-Koordinaten: $lat, $lon\n";
            else echo "[WARN] Fallback-Geocoding fehlgeschlagen\n";
        }
    }

    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) echo "[INFO] Bundesland (Fallback): $federal\n";
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

    // Veröffentlichungsdatum robust extrahieren
    $date = extractPublishDate($xpath2);
    echo "[DEBUG] Veröffentlichungsdatum: $date\n";

    /* // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    $limitDate = date("Y-m-d", strtotime("-60 days"));
    if (strtotime($date) < strtotime($limitDate)) {
        echo "[INFO] Artikel zu alt (Datum: $date, Limit: $limitDate) – ignoriert.\n";
        continue;
    } */

    $summary = buildSummary($paragraphs);

    echo "[DEBUG] Speichere Artikel mit Keyword: $kw\n";
    echo "[DEBUG] Titel: {$article['title']}\n";
    echo "[DEBUG] URL: {$article['url']}\n";
    $lowerText = mb_strtolower($contentText);
    $pos = mb_strpos($lowerText, mb_strtolower($kw));
    if ($pos !== false) {
        $start = max(0, $pos - 20);
        $snippet = mb_substr($contentText, $start, 60);
        echo "[DEBUG] Kontext: $snippet\n";
    } else {
        echo "[DEBUG] Kein Kontext-Ausschnitt\n";
    }

    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"], $federal, $type);

    $relevantCount++;
    usleep(300000); // Schonung
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";

/**
 * -----------------------
 * Hilfsfunktionen
 * -----------------------
 */

function absolutizeUrl($href, $base) {
    if (!$href) return null;
    if (preg_match('#^https?://#i', $href)) return $href;
    // Query-only oder hash
    if ($href[0] === '?') return $base . $href;
    if ($href[0] === '#') return $base . '/' . $href;
    // Normale relative Pfade
    return rtrim($base, '/') . '/' . ltrim($href, '/');
}

function extractPublishDate(DOMXPath $xpath) {
    // 1) <time datetime="YYYY-MM-DD ...">
    $n = $xpath->query("//time[@datetime]");
    if ($n->length > 0) {
        $dt = $n[0]->getAttribute('datetime');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
    }
    // 2) sichtbares <time>
    $n = $xpath->query("//time");
    if ($n->length > 0) {
        $txt = trim($n[0]->textContent);
        $d = parseGermanDate($txt);
        if ($d) return $d;
    }
    // 3) generische Datums-Spans
    $candidates = $xpath->query("//*[contains(@class,'date') or contains(@class,'datum')]");
    foreach ($candidates as $node) {
        $txt = trim($node->textContent);
        $d = parseGermanDate($txt);
        if ($d) return $d;
    }
    // 4) Fallback heute (UTC)
    return gmdate("Y-m-d");
}

function parseGermanDate($s) {
    // Formate: 14.08.2025, 14. August 2025, 2025-08-14
    $s = trim($s);
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $s, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    // 14. August 2025
    $months = [
        'januar'=>1,'februar'=>2,'märz'=>3,'maerz'=>3,'april'=>4,'mai'=>5,'juni'=>6,'juli'=>7,
        'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12
    ];
    if (preg_match('/^(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)\s+(\d{4})/u', mb_strtolower($s), $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $monKey = str_replace(['ä','ö','ü'], ['ae','oe','ue'], $m[2]);
        $monKey2 = mb_strtolower($monKey);
        $mon = $months[$monKey2] ?? null;
        if ($mon) {
            $mon = str_pad((string)$mon, 2, '0', STR_PAD_LEFT);
            return "{$m[3]}-{$mon}-{$d}";
        }
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

function saveToSupabase($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type) {
    global $SUPABASE_URL, $SUPABASE_KEY;

    $apiUrl = $SUPABASE_URL . "/rest/v1/raids";
    $data = json_encode([
        "title"    => $title,
        "summary"  => $summary,
        "date"     => $date,
        "location" => $location,
        "lat"      => $lat,
        "lon"      => $lon,
        "url"      => $url,
        "federal"  => $federal,
        "type"     => $type,
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log('[ERROR] Supabase CURL error: ' . curl_error($ch));
    } elseif ($httpCode >= 400) {
        echo "[ERROR] Supabase HTTP $httpCode\n";
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
    return !empty($data);
}
