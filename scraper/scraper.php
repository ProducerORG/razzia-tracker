<?php
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

    // Ausgabe direkt durchreichen
    passthru($cmd, $exitCode);

    if ($exitCode !== 0) {
        echo "[WARN] Scraper $file beendete sich mit Code $exitCode\n";
    } else {
        echo "[INFO] Fertig: $file\n\n";
    }
}