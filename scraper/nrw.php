<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/* ----------------- Hilfsfunktionen ZUERST (mit Guards) ----------------- */

if (!function_exists('http_get')) {
function http_get($url, $headers = []) {
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => array_merge([
                "User-Agent: razzia-map/1.0",
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            ], $headers),
            "ignore_errors" => true,
            "timeout" => 30
        ]
    ];
    $ctx = stream_context_create($opts);
    return @file_get_contents($url, false, $ctx);
}
}

if (!function_exists('http_post_form')) {
function http_post_form($url, $fields, $headers = []) {
    $body = http_build_query($fields, '', '&');
    $opts = [
        "http" => [
            "method" => "POST",
            "header" => array_merge([
                "User-Agent: razzia-map/1.0",
                "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With: XMLHttpRequest",
                "Accept: application/json, text/javascript, */*; q=0.01",
                "Origin: https://polizei.nrw",
                "Referer: https://polizei.nrw/presse/pressemitteilungen",
            ], $headers),
            "content" => $body,
            "ignore_errors" => true,
            "timeout" => 30
        ]
    ];
    $ctx = stream_context_create($opts);
    return @file_get_contents($url, false, $ctx);
}
}

if (!function_exists('extract_drupal_view_context')) {
function extract_drupal_view_context($html) {
    $ctx = [
        'view_name' => null,
        'view_display_id' => null,
        'view_args' => '',
        'view_path' => '',
        'view_base_path' => '',
        'view_dom_id' => null,
        'pager_element' => 0,
        'ajax_theme' => null,
        'ajax_libraries' => null,
    ];

    // <script type="application/json" data-drupal-selector="drupal-settings-json">...</script>
    if (!preg_match('#<script[^>]+data-drupal-selector="drupal-settings-json"[^>]*>(.*?)</script>#si', $html, $m)) {
        return $ctx;
    }
    $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $settings = json_decode($json, true);
    if (!is_array($settings)) return $ctx;

    // ajaxPageState
    if (isset($settings['ajaxPageState']['theme'])) $ctx['ajax_theme'] = $settings['ajaxPageState']['theme'];
    if (isset($settings['ajaxPageState']['libraries'])) $ctx['ajax_libraries'] = $settings['ajaxPageState']['libraries'];

    // views.ajaxViews: wir nehmen den ersten Eintrag
    if (isset($settings['views']['ajaxViews']) && is_array($settings['views']['ajaxViews'])) {
        $first = reset($settings['views']['ajaxViews']);
        if (is_array($first)) {
            $ctx['view_name'] = $first['view_name'] ?? null;
            $ctx['view_display_id'] = $first['view_display_id'] ?? null;
            $ctx['view_args'] = $first['view_args'] ?? '';
            $ctx['view_path'] = $first['view_path'] ?? '';
            $ctx['view_base_path'] = $first['view_base_path'] ?? '';
            $ctx['view_dom_id'] = $first['view_dom_id'] ?? null;
            $ctx['pager_element'] = $first['pager_element'] ?? 0;
        }
    }
    return $ctx;
}
}

if (!function_exists('parse_list_items_from_html')) {
function parse_list_items_from_html($html) {
    $items = [];
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//div[contains(@class,'view-content')]//div[contains(@class,'views-row')]//h2[contains(@class,'field-title')]/a");
    if (!$nodes || $nodes->length === 0) {
        // Fallback: direkter h2/a
        $nodes = $xpath->query("//h2[contains(@class,'field-title')]/a");
    }
    foreach ($nodes as $node) {
        $title = trim($node->textContent);
        $href  = $node->getAttribute('href') ?: '';
        if ($href !== '' && strpos($href, 'http') !== 0) {
            $href = "https://polizei.nrw" . (substr($href, 0, 1) === '/' ? '' : '/') . $href;
        }
        if ($href) {
            // evtl. Datum aus Nachbar-<time>
            $timeNode = $xpath->query("ancestor::div[contains(@class,'fields-wrapper')]//time", $node)->item(0);
            $dateList = null;
            if ($timeNode) {
                $dt = $timeNode->getAttribute('datetime');
                if ($dt && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $mm)) {
                    $dateList = "{$mm[1]}-{$mm[2]}-{$mm[3]}";
                }
            }
            $items[] = ['title' => $title, 'url' => $href, 'rssDate' => $dateList];
        }
    }
    return $items;
}
}

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

// Konfiguration
$KEYWORDS       = array_filter(array_map('trim', explode(',', $_ENV['KEYWORDS'] ?? '')));
$TARGET_ITEMS   = max(1, intval($_ENV['NRW_TARGET_ITEMS'] ?? 100));
$MAX_RSS_PAGES  = max(1, intval($_ENV['NRW_RSS_PAGES'] ?? 2));   // RSS
$MAX_AJAX_PAGES = max(1, intval($_ENV['NRW_AJAX_PAGES'] ?? 10)); // AJAX „mehr Ergebnisse“

$RSS_BASE   = 'https://polizei.nrw/presse/pressemitteilungen/rss/all/all/all/all';
$LIST_BASE  = 'https://polizei.nrw/presse/pressemitteilungen';
$AJAX_URL   = 'https://polizei.nrw/views/ajax';

echo "[INFO] RSS-URL: $RSS_BASE\n";
echo "[INFO] Listen-URL: $LIST_BASE\n";
echo "[INFO] Schlüsselwörter: " . implode(', ', $KEYWORDS) . "\n";
echo "[INFO] Ziel: $TARGET_ITEMS Einträge\n";

$articles = [];
$seenUrls = [];
$totalFetched = 0;

libxml_use_internal_errors(true);

/* --- 1) RSS sammeln --- */
for ($page = 0; $page < $MAX_RSS_PAGES && $totalFetched < $TARGET_ITEMS; $page++) {
    $rssUrl = $RSS_BASE . ($page > 0 ? '?page=' . $page : '');
    echo "[INFO] Lade RSS-Seite $page: $rssUrl\n";

    $rssXml = http_get($rssUrl);
    if (!$rssXml) {
        echo "[WARN] RSS konnte nicht geladen werden: $rssUrl\n";
        continue;
    }
    $xml = @simplexml_load_string($rssXml);
    if (!$xml || !isset($xml->channel->item)) {
        echo "[WARN] Keine Items auf RSS-Seite $page\n";
        if ($page > 0) break;
        continue;
    }

    $newOnThisPage = 0;
    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link  = trim((string)$item->link);
        $pub   = trim((string)$item->pubDate);

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
        if ($totalFetched >= $TARGET_ITEMS) break;
    }

    echo "[INFO] RSS-Seite $page: neu gesammelt: $newOnThisPage, gesamt: $totalFetched\n";
    if ($newOnThisPage === 0) break;
    usleep(300000);
}

/* --- 2) AJAX „mehr Ergebnisse“: echte Views-AJAX Requests --- */
if ($totalFetched < $TARGET_ITEMS) {
    $firstHtml = http_get($LIST_BASE);
    if (!$firstHtml) {
        echo "[WARN] Listen-HTML konnte nicht geladen werden: $LIST_BASE\n";
    } else {
        $ctx = extract_drupal_view_context($firstHtml);
        if (!$ctx['view_name'] || !$ctx['view_display_id'] || !$ctx['view_dom_id']) {
            echo "[WARN] Drupal-View-Context unvollständig – AJAX-Nachladen fällt aus.\n";
        } else {
            echo "[INFO] Drupal-View erkannt: {$ctx['view_name']} / {$ctx['view_display_id']} / dom_id={$ctx['view_dom_id']}\n";
            for ($page = 1; $page <= $MAX_AJAX_PAGES && $totalFetched < $TARGET_ITEMS; $page++) {
                $fields = [
                    'view_name' => $ctx['view_name'],
                    'view_display_id' => $ctx['view_display_id'],
                    'view_args' => $ctx['view_args'],
                    'view_path' => $ctx['view_path'],
                    'view_base_path' => $ctx['view_base_path'],
                    'view_dom_id' => $ctx['view_dom_id'],
                    'pager_element' => (string)$ctx['pager_element'],
                    'page' => (string)$page,
                    // wichtige AJAX-Page-State Felder
                    'ajax_page_state[theme]' => $ctx['ajax_theme'] ?? 'police',
                    'ajax_page_state[libraries]' => $ctx['ajax_libraries'] ?? '',
                ];

                echo "[INFO] AJAX-Page $page POST an $AJAX_URL\n";
                $resp = http_post_form($AJAX_URL, $fields);
                if (!$resp) {
                    echo "[WARN] AJAX-Antwort leer für page=$page\n";
                    break;
                }

                $json = json_decode($resp, true);
                if (!is_array($json)) {
                    echo "[WARN] AJAX-JSON ungültig für page=$page\n";
                    break;
                }

                // 'insert'-Kommandos einsammeln
                $htmlCombined = '';
                foreach ($json as $cmd) {
                    if (isset($cmd['command']) && $cmd['command'] === 'insert' && !empty($cmd['data'])) {
                        $htmlCombined .= $cmd['data'] . "\n";
                    }
                }
                if ($htmlCombined === '') {
                    echo "[INFO] Keine weiteren Einträge in AJAX page=$page\n";
                    break;
                }

                $pageItems = parse_list_items_from_html($htmlCombined);
                $added = 0;
                foreach ($pageItems as $it) {
                    if (isset($seenUrls[$it['url']])) continue;
                    $seenUrls[$it['url']] = true;
                    $articles[] = $it;
                    $added++;
                    $totalFetched++;
                    if ($totalFetched >= $TARGET_ITEMS) break;
                }
                echo "[INFO] AJAX-Seite $page: neu gesammelt: $added, gesamt: $totalFetched\n";
                if ($added === 0) break;

                usleep(300000);
            }
        }
    }
}

$relevantCount = 0;

/* ----------------- Verarbeitung ----------------- */

foreach ($articles as $article) {
    if (urlExistsInDatabase($article["url"])) {
        echo "[INFO] Artikel bereits in Datenbank, übersprungen: {$article['url']}\n";
        continue;
    }

    $contentHtml = http_get($article["url"]);
    if (!$contentHtml) {
        echo "[WARN] Artikel konnte nicht geladen werden: {$article['url']}\n";
        continue;
    }

    $dom2 = new DOMDocument();
    @$dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);

    $paragraphs = $xpath2->query(
        "(//main//div[contains(@class,'node__content')]//p)
         | (//main//div[contains(@class,'field')][contains(@class,'body') or contains(@class,'text')]//p)
         | (//div[contains(@class,'article')]//p)"
    );
    if ($paragraphs->length === 0) $paragraphs = $xpath2->query("//p");
    if ($paragraphs->length === 0) {
        echo "[WARN] Kein Artikeltext gefunden für: {$article['title']}\n";
        continue;
    }

    $contentText = "";
    foreach ($paragraphs as $p) {
        $line = trim($p->textContent);
        if (preg_match('/^(hinweise erbeten|mehr zum thema|kontakt|impressum|datenschutzerkl|teilen:)/i', $line)) break;
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

    $location = $gptOrt ?: extractLocation($contentText);
    if (!$location) echo "[WARN] Kein Ort durch GPT oder Fallback-Logik gefunden\n";

    $lat = $gptLat;
    $lon = $gptLon;

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

    if (!$federal && $lat && $lon) {
        $federal = getFederalState($lat, $lon);
        if ($federal) echo "[INFO] Bundesland (Koordinaten-Fallback): $federal\n";
    }
    if ($federal) echo "[INFO] Bundesland: $federal\n";

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
        echo "[DEBUG] Veröffentlichungsdatum (Fallback): $date\n";
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

    usleep(300000);
}

echo "[INFO] Gesamt verarbeitete Artikel mit passendem Keyword: $relevantCount\n";
