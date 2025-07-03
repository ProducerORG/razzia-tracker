<?php

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
} elseif ($path === '/api/report') {
    handleReport();
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}

// Supabase-Daten abrufen
function handleGetRaids() {
    $SUPABASE_URL = getenv("SUPABASE_URL") ?: "https://rbxjghygifiaxgfpybgz.supabase.co";
    $SUPABASE_KEY = getenv("SUPABASE_KEY");

    if (!$SUPABASE_KEY) {
        http_response_code(500);
        echo json_encode(['error' => 'Supabase key not set']);
        exit();
    }
    
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

// E-Mail-Report verarbeiten
function handleReport() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['message'], $data['source'], $data['captcha'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Alle Felder müssen ausgefüllt sein.']);
        exit();
    }

    $message = trim($data['message']);
    $source = trim($data['source']);
    $captcha = trim($data['captcha']);

    if (empty($message) || empty($source) || empty($captcha)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Eingabedaten.']);
        exit();
    }

    // Captcha prüfen
    $captchaResult = verifyCaptcha($captcha);
    if ($captchaResult !== true) {
        http_response_code(403);
        echo json_encode(['error' => $captchaResult]);
        exit();
    }

    // E-Mail senden
    $result = sendEmail($message, $source);

    if ($result !== true) {
        http_response_code(500);
        echo json_encode(['error' => $result]);
        exit();
    }

    echo json_encode(['status' => 'ok']);
}

function sendEmail($message, $source) {
    $SMTP_SERVER = getenv("SMTP_HOST") ?: "k75s74.meinserver.io";
    $SMTP_PORT   = getenv("SMTP_PORT") ?: 587;
    $SMTP_USER   = getenv("SMTP_USER") ?: "no-reply@glueckswirtschaft.de";
    $SMTP_PASS   = getenv("SMTP_PASSWORD") ?: getenv("SMTP_KEY"); // Fallback für ältere env
    $SMTP_TO     = getenv("SMTP_TO") ?: "linus@producer.works";

    if (!$SMTP_PASS) {
        return "SMTP-Key fehlt.";
    }

    $subject = "Neue Razzia-Meldung";
    $body = "Neue Meldung eingegangen:\n\nMeldung:\n$message\n\nQuelle:\n$source";

    require_once 'vendor/autoload.php';
    $smtp = new Swift_SmtpTransport($SMTP_SERVER, $SMTP_PORT, 'tls');
    $smtp->setUsername($SMTP_USER);
    $smtp->setPassword($SMTP_PASS);
    $mailer = new Swift_Mailer($smtp);

    $messageObj = (new Swift_Message($subject))
        ->setFrom([$SMTP_USER => 'Razzia-Tracker'])
        ->setTo([$SMTP_TO])
        ->setBody($body);

    try {
        $result = $mailer->send($messageObj);
        return true;
    } catch (Exception $e) {
        return "E-Mail Fehler: " . $e->getMessage();
    }
}

function verifyCaptcha($token) {
    $secret = getenv("RECAPTCHA_SECRET_KEY");
    if (!$secret) {
        return "reCAPTCHA-Secret nicht gesetzt.";
    }

    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $postData = http_build_query([
        'secret' => $secret,
        'response' => $token
    ]);

    $opts = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $postData
    ]];
    $context = stream_context_create($opts);
    $response = file_get_contents($verifyUrl, false, $context);

    if ($response === false) {
        return "Captcha-Verifizierung fehlgeschlagen (kein Antwort).";
    }

    $result = json_decode($response, true);
    if (!isset($result['success']) || !$result['success']) {
        return "Captcha ungültig.";
    }

    if (isset($result['score']) && $result['score'] < 0.5) {
        return "Captcha-Score zu niedrig.";
    }

    return true;
}

?>
