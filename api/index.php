<?php

// Datei: api/index.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/api/raids') {
    handleGetRaids();
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}

function handleGetRaids() {
    $SUPABASE_URL = getenv("SUPABASE_URL") ?: "https://rbxjghygifiaxgfpybgz.supabase.co";
    $SUPABASE_KEY = getenv("SUPABASE_KEY");
    
    $url = $SUPABASE_URL . "/rest/v1/raids?select=*";

    $headers = [
        "apikey: $SUPABASE_KEY",
        "Authorization: Bearer $SUPABASE_KEY"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        http_response_code(500);
        echo json_encode(['error' => 'Curl error: ' . curl_error($ch)]);
        exit();
    }

    curl_close($ch);
    echo $response;
}