<?php
// Wrapper, um alle Scraper-Skripte im Ordner auszuführen (außer sich selbst)

$dir = __DIR__;
$files = scandir($dir);

foreach ($files as $file) {
    if (substr($file, -4) === '.php' && $file !== 'scraper.php') {
        echo "[INFO] Starte Scraper: $file\n";
        require_once $dir . '/' . $file;
        echo "[INFO] Fertig: $file\n\n";
    }
}