<?php

$KEYWORDS = ["razzia", "glücksspiel", "spielhalle", "durchsuchung", "illegal"];
$NEWS_URL = "https://www.presseportal.de/blaulicht";

$html = file_get_contents($NEWS_URL);
if (!$html) {
    exit("Fehler beim Laden der News-Seite\n");
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$nodes = $xpath->query("//a[contains(@class,'teaser-title')]");

$articles = [];
foreach ($nodes as $node) {
    $title = trim($node->textContent);
    $href = $node->getAttribute("href");
    $articles[] = ["title" => $title, "url" => "https://www.presseportal.de$href"];
    if (count($articles) >= 10) break;
}

foreach ($articles as $article) {
    $contentHtml = file_get_contents($article["url"]);
    if (!$contentHtml) continue;

    $dom2 = new DOMDocument();
    $dom2->loadHTML($contentHtml);
    $xpath2 = new DOMXPath($dom2);
    $paragraphs = $xpath2->query("//div[contains(@class,'article-text')]//p");

    $contentText = "";
    foreach ($paragraphs as $p) {
        $contentText .= $p->textContent . "\n";
    }

    $lowerText = mb_strtolower($contentText);
    $found = false;
    foreach ($KEYWORDS as $kw) {
        if (mb_strpos($lowerText, $kw) !== false) {
            $found = true;
            break;
        }
    }
    if (!$found) continue;

    $location = extractLocation($contentText);
    if (!$location) continue;

    list($lat, $lon) = geocodeLocation($location);
    if (!$lat || !$lon) continue;

    $date = gmdate("Y-m-d");
    $summary = mb_substr($contentText, 0, 300) . "...";
    
    saveToSupabase($article["title"], $summary, $date, $location, $lat, $lon, $article["url"]);
    
    echo "Gespeichert: {$article['title']} ($location)\n";
}

function extractLocation($text) {
    // Einfache regelbasierte Orts-Extraktion
    $pattern = "/\b(in|bei|nahe|im|am) ([A-ZÄÖÜ][a-zäöüßA-ZÄÖÜ-]+)/u";
    if (preg_match($pattern, $text, $matches)) {
        return $matches[2];
    }
    return null;
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
    $SUPABASE_URL = getenv("SUPABASE_URL");
    $SUPABASE_KEY = getenv("SUPABASE_KEY");

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
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Supabase insert error: ' . curl_error($ch));
    }
    curl_close($ch);
}
