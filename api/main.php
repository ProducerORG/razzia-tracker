<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);
//ini_set('log_errors', 1);
//ini_set('error_log', __DIR__ . '/../php-error.log');
header("Access-Control-Allow-Origin: *"); // Falls du CORS brauchst
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value, " \t\n\r\0\x0B"));
        }
    }
}
loadEnv(__DIR__ . '/../.env');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($_GET['route'] === 'raids') {
    header('Content-Type: application/json');
    handleGetRaids();
    exit;
}

if ($_GET['route'] === 'report') {
    header('Content-Type: application/json');
    handleReport();
    exit;
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

    if (empty($response)) {
        echo json_encode([]);
        exit;
    }

    echo $response;
    exit;
}

// E-Mail-Report verarbeiten
function handleReport() {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
            return;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        if (!isset($data['message'], $data['source'], $data['captcha'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Alle Felder müssen ausgefüllt sein.']);
            return;
        }

        $message = trim($data['message']);
        $source  = trim($data['source']);
        $captcha = trim($data['captcha']);

        if ($message === '' || $source === '' || $captcha === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige Eingabedaten.']);
            return;
        }

        $captchaResult = verifyCaptcha($captcha);
        if ($captchaResult !== true) {
            http_response_code(403);
            echo json_encode(['error' => $captchaResult]);
            return;
        }

        /* $result = sendEmail($message, $source);
        if ($result !== true) {
            error_log("sendEmail Fehler: " . $result);
            http_response_code(500);
            echo json_encode(['error' => $result]);
            return;
        } */

        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        error_log("handleReport Fatal: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Serverfehler']);
    }
}

function sendEmail($message, $source) {
    $SMTP_SERVER = getenv("SMTP_HOST") ?: "k75s74.meinserver.io";
    $SMTP_PORT   = (int)(getenv("SMTP_PORT") ?: 587);
    $SMTP_USER   = getenv("SMTP_USER") ?: "no-reply@glueckswirtschaft.de";
    $SMTP_PASS   = getenv("SMTP_PASSWORD") ?: getenv("SMTP_KEY");
    $SMTP_TO     = getenv("SMTP_TO") ?: "vladimir-ribic@storming-studios.com";

    if (!$SMTP_PASS) {
        return "SMTP-Key fehlt.";
    }

    // Composer Autoload prüfen
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log("autoload.php fehlt: $autoload");
        return "Mailer nicht installiert (autoload.php fehlt).";
    }
    require_once $autoload;

    // Klassenverfügbarkeit prüfen
    if (!class_exists('Swift_SmtpTransport') || !class_exists('Swift_Mailer') || !class_exists('Swift_Message')) {
        error_log("SwiftMailer-Klassen fehlen (Composer-Abhängigkeit nicht installiert).");
        return "Mailer nicht installiert (SwiftMailer fehlt).";
    }

    $subject = "Neue Razzia-Meldung";
    $body = "Neue Meldung eingegangen:\n\nMeldung:\n$message\n\nQuelle:\n$source";

    try {
        $smtp = new Swift_SmtpTransport($SMTP_SERVER, $SMTP_PORT, 'tls');
        $smtp->setUsername($SMTP_USER);
        $smtp->setPassword($SMTP_PASS);
        $mailer = new Swift_Mailer($smtp);

        $messageObj = (new Swift_Message($subject))
            ->setFrom([$SMTP_USER => 'Razzia-Tracker'])
            ->setTo([$SMTP_TO])
            ->setBody($body);

        $mailer->send($messageObj);
        return true;
    } catch (Throwable $e) { // fängt auch Fatal Errors innerhalb Swift ab
        error_log("SwiftMailer Fehler: " . $e->getMessage());
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
