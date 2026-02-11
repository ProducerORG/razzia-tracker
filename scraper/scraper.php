<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

function sendStatusMail($subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$_ENV['SMTP_PORT'];

        $mail->setFrom($_ENV['SMTP_USER'], 'Razzia-Tracker: Scraper Monitor');

        // Empfänger
        if (!empty($_ENV['SMTP_TO'])) {
            $mail->addAddress($_ENV['SMTP_TO']);
        }
        $mail->addAddress('linus@producer.works');

        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/mail_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

function sendFailureMail($scraper, $exitCode, $output = '') {
    sendStatusMail(
        "Scraper-Fehler: $scraper (Exit $exitCode)",
        "Der Scraper '$scraper' hat sich mit Fehlercode $exitCode beendet.\n\nAusgabe:\n$output"
    );
}

// Startet jeden Scraper als separaten PHP-CLI-Prozess, um Namenskonflikte zu vermeiden.

$dir = __DIR__;
$files = array_values(array_filter(scandir($dir), function($f) {
    return substr($f, -4) === '.php' && $f !== 'scraper.php';
}));

// Optional: feste Reihenfolge (erst presseportal.php, dann der Rest) 
usort($files, function($a, $b) {
    if ($a === 'presseportal.php') return -1;
    if ($b === 'presseportal.php') return 1;
    return strcmp($a, $b);
});

/* ==== SCRAPER START ==== */
//$startTime = date('Y-m-d H:i:s');
//sendStatusMail(
//    'Scraper gestartet',
//    "Der Scraper-Lauf wurde gestartet.\nZeit: $startTime\nQuelle: " . (php_sapi_name() === 'cli' ? 'CLI / Cron' : 'Web')
//);

foreach ($files as $file) {
    $path = $dir . DIRECTORY_SEPARATOR . $file;
    echo "[INFO] Starte Scraper-Prozess: $file\n";

    $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $process = proc_open($cmd, $descriptorSpec, $pipes);
    
    if (is_resource($process)) {
        $output = '';
    
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line === false) break;
            echo $line;
            $output .= $line;
        }
    
        while (!feof($pipes[2])) {
            $line = fgets($pipes[2]);
            if ($line === false) break;
            echo $line;
            $output .= $line;
        }
    
        fclose($pipes[1]);
        fclose($pipes[2]);
    
        $exitCode = proc_close($process);
    
        if ($exitCode !== 0) {
            echo "[WARN] Scraper $file beendete sich mit Code $exitCode\n";
            sendFailureMail($file, $exitCode, $output);
        } else {
            echo "[INFO] Fertig: $file\n\n";
        }
    }    
}

/* ===== SCRAPER ENDE ===== */
//$endTime = date('Y-m-d H:i:s');
//sendStatusMail(
//    'Scraper abgeschlossen',
//    "Der Scraper-Lauf wurde abgeschlossen.\nZeit: $endTime"
//);
