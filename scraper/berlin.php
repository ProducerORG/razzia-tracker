<?php
// Datei: scraper/berlin.php
// Zweck: Scraper für https://www.berlin.de/polizei/polizeimeldungen/
// Läuft eigenständig (Wrapper ruft diese Datei auf). Nutzt dieselbe .env-Konfiguration wie presseportal.php.
// Kollisionssicher: keine globalen Funktionsdefinitionen, nur Closures/Inline-Logik.

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Konfiguration aus .env
$KEYWORDS      = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
$SUPABASE_URL  = $_ENV['SUPABASE_URL'] ?? '';
$SUPABASE_KEY  = $_ENV['SUPABASE_KEY'] ?? '';
$OPENAI_APIKEY = $_ENV['OPENAI_API_KEY'] ?? '';

$BASE_URL  = 'https://www.berlin.de';
$START_URL = $BASE_URL . '/polizei/polizeimeldungen/';

echo "[INFO] Quelle: Berlin.de Polizeimeldungen\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";

// -------------------------------------------------------------
// Hilfsfunktionen als Closures (keine globalen Funktionsnamen)
// -------------------------------------------------------------
$urlExistsInDatabase = function (string $url) use ($SUPABASE_URL, $SUPABASE_KEY): bool {
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
};

$saveToSupabase = function ($title, $summary, $date, $location, $lat, $lon, $url, $federal, $type) use ($SUPABASE_URL, $SUPABASE_KEY) {
    $apiUrl = $SUPABASE_URL . "/rest/v1/raids";
    $payload = json_encode([
        "title"    => $title,
        "summary"  => $summary,
        "date"     => $date,
        "location" => $location,
        "lat"      => $lat,
        "lon"      => $lon,
        "url"      => $url,
        "federal"  => $federal,
        "type"     => $type
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
        error_log('[ERROR] Supabase CURL error: ' . curl_error($ch));
    } elseif ($httpCode >= 400) {
        echo "[ERROR] Supabase returned HTTP $httpCode\n";
        echo "[ERROR] Response: $response\n";
        echo "[ERROR] Payload: $payload\n";
    } else {
        echo "[SUCCESS] Gespeichert: $title ($location, $federal)\n";
    }
    curl_close($ch);
};

$extractLocationFallback = function (string $text): ?string {
    preg_match_all('/\b(?:in|bei|nahe)\s+(?!Richtung\b)(?!Höhe\b)([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u', $text, $matches);
    $found = [];
    foreach ($matches[1] as $loc) {
        if (preg_match('/Polizei|Kriminalpolizei|Staatsanwaltschaft|Feuerwehr/i', $loc)) continue;
        if (!in_array($loc, $found)) $found[] = $loc;
    }
    return $found[0] ?? null;
};

$geocodeLocation = function (?string $location): array {
    if (!$location) return [null, null];
    $encoded = urlencode($location . ", Deutschland");
    $url = "https://nominatim.openstreetmap.org/search?q=$encoded&format=json&limit=1";
    sleep(1);
    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $ctx  = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [null, null];
    $data = json_decode($json, true);
    if (count($data) > 0) return [$data[0]['lat'] ?? null, $data[0]['lon'] ?? null];
    return [null, null];
};

$getFederalState = function ($lat, $lon): ?string {
    if (!$lat || !$lon) return null;
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=$lat&lon=$lon";
    sleep(1);
    $opts = ["http" => ["header" => "User-Agent: razzia-map/1.0"]];
    $ctx  = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    return $data['address']['state'] ?? null;
};

$buildSummary = function (DOMNodeList $paragraphs): string {
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if (preg_match('/^(mehr themen|[\d]{2}\.\d{2}\.\d{4}|rückfragen bitte an|^kreispolizeibehörde|^original-content|^pdf-version|^druckversion)/i', $line)) continue;
        if (mb_strlen($line) > 80) {
            return mb_substr($line, 0, 300) . (mb_strlen($line) > 300 ? "..." : "");
        }
    }
    return "";
};

$extractMetadataWithGPT = function (string $text) use ($OPENAI_APIKEY) {
    if (!$OPENAI_APIKEY) return null;
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
        "Authorization: Bearer $OPENAI_APIKEY",
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
};

// robust: Textknoten zu String
$getNodeText = function (?DOMNode $node): string {
    if (!$node) return '';
    return trim(preg_replace('/\s+/u', ' ', $node->textContent));
};

// robust: absolute URL bauen
$toAbsolute = function (string $href) use ($BASE_URL): string {
    if (strpos($href, 'http://') === 0 || strpos($href, 'https://') === 0) return $href;
    if ($href && $href[0] === '/') return $BASE_URL . $href;
    return rtrim($BASE_URL, '/') . '/' . ltrim($href, '/');
};

// robust: "Nächste/Weiter"-Seite finden
$findNextPage = function (DOMXPath $xp) use ($toAbsolute): ?string {
    // Versuche rel="next"
    $n = $xp->query("//a[@rel='next' or contains(@class,'next') or contains(@class,'pagination__next')]");
    if ($n && $n->length > 0) {
        return $toAbsolute($n->item(0)->getAttribute('href'));
    }
    // Fallback: Text enthält „weiter“/„nächste“
    $n = $xp->query("//a[contains(translate(normalize-space(.),'ÄÖÜABCDEFGHIJKLMNOPQRSTUVWXYZ','äöüabcdefghijklmnopqrstuvwxyz'),'weiter') or contains(translate(normalize-space(.),'ÄÖÜABCDEFGHIJKLMNOPQRSTUVWXYZ','äöüabcdefghijklmnopqrstuvwxyz'),'nächste')]");
    if ($n && $n->length > 0) {
        return $toAbsolute($n->item(0)->getAttribute('href'));
    }
    // Fallback: pagin-Container
    $n = $xp->query("//nav[contains(@class,'pagin') or contains(@class,'pagination')]//a[contains(@class,'next')]");
    if ($n && $n->length > 0) {
        return $toAbsolute($n->item(0)->getAttribute('href'));
    }
    return null;
};

// -------------------------------------------------------------
// 1) Listing-Seiten einsammeln (mit Pagination)
// -------------------------------------------------------------
$articles = [];   // ['title'=>..., 'url'=>..., 'list_date'=>..., 'event_location'=>...]
$seenUrls = [];
$pageLimit = 40; // Sicherheitslimit
$pageNum   = 0;
$listUrl   = $START_URL;

while ($listUrl && $pageNum < $pageLimit) {
    echo "[INFO] Lade Listing: $listUrl\n";
    $html = @file_get_contents($listUrl);
    if (!$html) {
        echo "[WARN] Fehler beim Laden: $listUrl\n";
        break;
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);

    // Jeder Eintrag: <ul class="list--tablelist"><li>...</li></ul>
    $lis = $xp->query("//ul[contains(@class,'list--tablelist')]/li");
    echo "[INFO] Einträge auf Seite: " . $lis->length . "\n";

    foreach ($lis as $li) {
        $dateNode = $xp->query(".//div[contains(@class,'date')]", $li)->item(0);
        $textNode = $xp->query(".//div[contains(@class,'text')]", $li)->item(0);
        $aNode    = $xp->query(".//a", $textNode)->item(0);
        if (!$aNode) continue;

        $title = trim($aNode->textContent);
        $href  = $aNode->getAttribute('href');
        $full  = $toAbsolute($href);

        if (isset($seenUrls[$full])) continue;
        $seenUrls[$full] = true;

        $listDate = trim($dateNode ? $dateNode->textContent : '');
        // Ereignisort (falls vorhanden)
        $eventLoc = '';
        $catNode  = $xp->query(".//span[contains(@class,'category')]", $li)->item(0);
        if ($catNode) {
            // Beispiel: "<strong>Ereignisort: </strong>Pankow"
            $eventLoc = trim(preg_replace('/^Ereignisort:\s*/i', '', strip_tags($catNode->textContent)));
        }

        $articles[] = [
            "title" => $title,
            "url"   => $full,
            "list_date" => $listDate,
            "event_location" => $eventLoc ?: null
        ];
    }

    // Nächste Seite ermitteln
    $next = $findNextPage($xp);
    if ($next && $next !== $listUrl) {
        $listUrl = $next;
        $pageNum++;
        usleep(400000); // 0,4s
    } else {
        $listUrl = null;
    }
}

// -------------------------------------------------------------
// 2) Artikel verarbeiten
// -------------------------------------------------------------
$relevantCount = 0;

foreach ($articles as $article) {
    if ($urlExistsInDatabase($article["url"])) {
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
    $xp2 = new DOMXPath($dom2);

    // Haupttext robust holen
    // Häufig in Content-Bereichen mit textile/text, article-text etc.
    $paragraphs =
        $xp2->query("(//div[contains(@class,'textile') or contains(@class,'article') or contains(@class,'content') or contains(@class,'modul-text')])[1]//p");
    if ($paragraphs->length === 0) {
        $paragraphs = $xp2->query("//article//p");
    }
    if ($paragraphs->length === 0) {
        $paragraphs = $xp2->query("//p");
    }
    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden für: {$article['title']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        // Berlin.de hat teils Service-/Kontaktblöcke am Ende
        if (preg_match('/^(kontakt|rückfragen|service|weitere meldungen|verwandte themen)/i', $line)) {
            break;
        }
        $contentText .= $line . "\n";
    }

    // Keyword-Filter
    $found = false;
    $kwHit = null;
    foreach ($KEYWORDS as $kw) {
        if (preg_match('/' . $kw . '/i', $contentText)) {
            $found = true;
            $kwHit = $kw;
            echo "[DEBUG] Schlüsselwort gefunden: $kw\n";
            break;
        }
    }
    if (!$found) {
        echo "[INFO] Kein relevantes Schlüsselwort, übersprungen.\n";
        continue;
    }

    // GPT-Metadaten
    $gptResult = $extractMetadataWithGPT($contentText);
    if (!$gptResult || !is_array($gptResult) || ($gptResult['illegal'] ?? false) !== true) {
        echo "[INFO] Kein Fall von illegalem Glücksspiel – Artikel verworfen\n";
        continue;
    }

    $type    = $gptResult['typ'] ?? "Sonstige";
    $gptOrt  = $gptResult['ort'] ?? null;
    $gptLat  = $gptResult['koord']['lat'] ?? null;
    $gptLon  = $gptResult['koord']['lon'] ?? null;
    $federal = $gptResult['bundesland'] ?? null;

    if ($gptOrt) echo "[GPT] Ort erkannt: $gptOrt\n";
    echo "[GPT] Typ erkannt: $type\n";
    if ($gptLat && $gptLon) echo "[GPT] Koordinaten erkannt: $gptLat, $gptLon\n";

    // Ort bestimmen: GPT > Ereignisort aus Liste > Fallback-RegEx
    $location = $gptOrt ?: ($article['event_location'] ?? null);
    if (!$location) {
        $location = $extractLocationFallback($contentText);
        if (!$location) echo "[WARN] Kein Ort durch GPT/Liste/Fallback gefunden\n";
    }

    // Koordinaten
    $lat = $gptLat;
    $lon = $gptLon;
    if (!$lat || !$lon) {
        if ($location) {
            [$lat, $lon] = $geocodeLocation($location);
            if ($lat && $lon) {
                echo "[INFO] Fallback-Koordinaten ermittelt: $lat, $lon\n";
            } else {
                echo "[WARN] Fallback-Geocoding fehlgeschlagen\n";
            }
        }
    }

    // Bundesland
    if (!$federal && $lat && $lon) {
        $federal = $getFederalState($lat, $lon);
        if ($federal) echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

    // Veröffentlichungsdatum:
    // 1) aus Listing (Format z.B. "14.08.2025 15:44 Uhr") -> in Y-m-d umwandeln
    // 2) aus <meta name="dcterms.date"> oder <time datetime=""> auf Artikel-Seite
    $date = null;
    if (!empty($article['list_date'])) {
        $d = trim(preg_replace('/\s*Uhr$/', '', $article['list_date']));
        $d = preg_replace('/\s+/', ' ', $d);
        // Erwartet "dd.mm.yyyy hh:mm"
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+\d{2}:\d{2}/', $d, $m)) {
            $date = "{$m[3]}-{$m[2]}-{$m[1]}";
            echo "[DEBUG] Datum (Listing) extrahiert: $date\n";
        }
    }
    if (!$date) {
        $meta = $xp2->query("//meta[@name='dcterms.date']")->item(0);
        if ($meta) {
            $dateRaw = $meta->getAttribute('content');
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateRaw, $m)) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]}";
                echo "[DEBUG] Datum (Meta) extrahiert: $date\n";
            }
        }
    }
    if (!$date) {
        $timeNode = $xp2->query("//time[@datetime]")->item(0);
        if ($timeNode) {
            $dt = $timeNode->getAttribute("datetime");
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]}";
                echo "[DEBUG] Datum (time) extrahiert: $date\n";
            }
        }
    }
    if (!$date) {
        echo "[WARN] Veröffentlichungsdatum nicht gefunden, Fallback auf heute\n";
        $date = gmdate("Y-m-d");
    }

    // Artikel ignorieren, wenn Datum vor dem 1. Juli 2025 liegt
    $limitDate = date("Y-m-d", strtotime("-60 days"));
    if (strtotime($date) < strtotime($limitDate)) {
        echo "[INFO] Artikel zu alt (Datum: $date, Limit: $limitDate) – ignoriert.\n";
        continue;
    }

    $summary = $buildSummary($paragraphs);

    echo "[DEBUG] Speichere Artikel mit Keyword: $kwHit\n";
    echo "[DEBUG] Titel: {$article['title']}\n";
    echo "[DEBUG] URL: {$article['url']}\n";

    if ($kwHit) {
        $lowerText = mb_strtolower($contentText);
        $pos = mb_strpos($lowerText, mb_strtolower($kwHit));
        if ($pos !== false) {
            $start = max(0, $pos - 20);
            $snippet = mb_substr($contentText, $start, 60);
            echo "[DEBUG] Kontext des Schlüsselwortes: $snippet\n";
        }
    }

    $saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"], $federal, $type);
    $relevantCount++;
    usleep(300000); // 0,3s
}

echo "[INFO] Gesamt verarbeitete relevante Artikel (Berlin): $relevantCount\n";
