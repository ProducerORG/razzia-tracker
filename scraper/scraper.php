<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

function sendFailureMail($scraper, $exitCode, $output = '') {
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
        $mail->addAddress($_ENV['linus@producer.works']);

        $mail->Subject = "Scraper-Fehler: $scraper (Exit $exitCode)";
        $mail->Body = "Der Scraper '$scraper' hat sich mit Fehlercode $exitCode beendet.\n\nAusgabe:\n$output";

        $mail->send();
    } catch (Exception $e) {
        // hier bewusst kein Abbruch, Logging reicht
        file_put_contents(__DIR__ . '/mail_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
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

foreach ($files as $file) {
    $path = $dir . DIRECTORY_SEPARATOR . $file;
    echo "[INFO] Starte Scraper-Prozess: $file\n";

    // PHP_BINARY ist i.d.R. gesetzt. Fallback auf 'php'.
    $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

    // Aufruf ohne Shell-Injection-Risiko
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);

    $descriptorSpec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w']  // stderr
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