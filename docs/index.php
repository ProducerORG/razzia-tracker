<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

if (isset($_GET['route'])) {
    require_once __DIR__ . '/../api/main.php';
    exit;
}

loadEnv(__DIR__ . '/../.env');

$recaptchaKey = getenv('RECAPTCHA_SITE_KEY') ?: '';
$apiUrl = '/api';

echo "<!-- DEBUG recaptchaKey: " . var_export(getenv('RECAPTCHA_SITE_KEY'), true) . " -->";
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Razzientracker</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <?php if (!empty($recaptchaKey)) : ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptchaKey, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php else: ?>
        <!-- WARNUNG: Kein RECAPTCHA_SITE_KEY gesetzt -->
    <?php endif; ?>

<style>
        :root {
            --gold: #bfa46f;
            --light-gray: #f2f2f2;
            --white: #ffffff;
            --text-dark: #333333;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            height: 100vh;
            background-color: var(--white);
            color: var(--text-dark);
        }

        h3, h4 {
            font-family: 'Playfair Display', serif;
            color: var(--gold);
            margin-top: 1rem;
        }

        .sidebar, .info-panel, .map-container {
            box-sizing: border-box;
            padding: 1.5rem;
        }

        .sidebar {
            background-color: var(--light-gray);
            flex: 1 1 300px;
            min-width: 250px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        } 

        .map-container {
            flex: 4 1 400px;
            border-left: 1px solid #ddd;
            border-right: 1px solid #ddd;
            padding: 0;
            height: 100vh;
            position: relative;
        }

        #map {
            width: 100%;
            height: 100%;
        }

        .info-panel {
            background-color: var(--light-gray);
            flex: 1 1 300px;
            min-width: 250px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            position: relative;
        }

        .info-panel .top-right-info {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        input[type="date"] {
            width: 80%;
            padding: 0.6rem;
            margin-bottom: 1rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            background-color: var(--white);
        }

        label {
            margin-top: 0rem;
            margin-bottom: 0.5rem;
        }

        p {
            margin: 0 0 1rem 0;
        }

        .leaflet-popup-content-wrapper {
            border-radius: 0 !important;
        }

        .leaflet-popup-content .popup-link-container {
            margin-top: 10px;
        }

        a,
        a:link,
        a:visited,
        a:active {
            color: white;
            background-color: #003300;
            text-decoration: none;
            padding: 5px 5px;
            border-radius: 0;
            display: inline-block;
        }

        a:hover {
            color: white;
            background-color: #004d00;
        }

        .federal-item {
            cursor: pointer;
            margin-bottom: 0.3rem;
            padding: 0.4rem;
            border: 1px solid #ccc;
            background-color: #ffffff;
            border-radius: 4px;
            font-size: 0.95rem;
            user-select: none;
        }

        .federal-item.active {
            background-color: #bfa46f;
            color: white;
        }

        #toggleFederalButton {
            padding: 0.6rem 1rem;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            background-color: var(--white);
            color: var(--text-dark);
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        #toggleFederalButton:hover {
            background-color: #e6e6e6;
        }

        .spinner {
            display: none;
            margin-top: 1rem;
            margin-left: auto;
            margin-right: auto;
            width: 25px;
            height: 25px;
            border: 5px solid #ccc;
            border-top-color: #003300;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        #mapLoadingOverlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255,255,255,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            pointer-events: none;
        }

        .loader-circle {
            width: 60px;
            height: 60px;
            border: 8px solid #ccc;
            border-top: 8px solid #003300;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }


        @media (max-width: 768px) {
            body {
                flex-direction: column;
                height: auto;
            }
            .map-container {
                height: 400px;
            }
        }
    
    </style>
</head>
<body>
    <div class="sidebar">
<!--    <img src="https://glueckswirtschaft.de/media/site/813e60f13a-1743167695/glueckswirtschaft-logo.svg" alt="Glückswirtschaft Logo" style="width: 100%; max-width: 160px; margin-bottom: 1rem;">--> 
        <h3>Was ist der Razzia-Tracker?</h3>
        <p>Der Razzia-Tracker der <i>GlücksWirtschaft</i> dokumentiert behördliche Maßnahmen gegen illegales Glücksspiel in Deutschland. Ob Spielhallen, Hinterzimmer oder digitale Plattformen – hier finden Sie verlässliche und aktuelle Informationen über Durchsuchungen, Beschlagnahmungen und Ermittlungen. Unsere Redaktion sammelt und verifiziert fortlaufend Berichte aus offiziellen Quellen, Medien und Polizeimeldungen.</p>
        <h3>Warum das wichtig ist:</h3>
        <p>Illegales Glücksspiel entzieht sich der staatlichen Kontrolle und Besteuerung, gefährdet Spieler und untergräbt – im Falle der Sportwette – die Integrität des sportlichen Wettbewerbs. Mit dem Razzia-Tracker schaffen wir erstmals überregional Transparenz – für Behörden, Politik, Branche und Öffentlichkeit.</p>

        <br><br>
        <div style="margin-top: 0.5rem; padding: 1rem; border: 1px solid #ccc; background-color: #ffffff; border-radius: 8px;">
            <h3>Meldung einreichen</h3>
            <form id="reportForm" style="display: flex; flex-direction: column; gap: 0.5rem;">
                <div style="display: flex; flex-direction: column;">
                    <label for="message" style="margin-bottom: 0.5rem;">Wo war eine Razzia?</label>
                    <textarea id="message" name="message" rows="5"
                        style="box-sizing: border-box; width: 100%; padding: 0.6rem; border: 1px solid #ccc; border-radius: 4px; font-family: 'Inter', sans-serif; resize: none; overflow-y: auto; background-color: #f9f9f9;"></textarea>
                </div>

                <div style="display: flex; flex-direction: column;">
                    <label for="source" style="margin-bottom: 0.5rem;">Quelle?</label>
                    <input type="text" id="source" name="source"
                        style="box-sizing: border-box; width: 100%; padding: 0.6rem; border: 1px solid #ccc; border-radius: 4px; font-family: 'Inter', sans-serif; background-color: #f9f9f9;">
                </div>

                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <button type="submit" 
                        style="color: white; background-color: #003300; padding: 8px 16px; border: none; border-radius: 0; font-family: 'Inter', sans-serif; cursor: pointer;">
                        Senden
                    </button>
                    <div id="spinner" class="spinner"></div>
                </div>

                <div id="formResponse" style="margin-top: 0.5rem; color: green;"></div>
            </form>
        </div>
    </div>
    <div class="map-container">
        <div id="map"></div>
        <div id="mapLoadingOverlay">
            <div class="loader-circle"></div>
        </div> 
    </div>
    <div class="info-panel">
        <div class="top-right-info">Beobachtung seit 01.03.2025</div>

        <h3>Zeitraum filtern</h3>
        <label for="startDate">Von:</label>
        <input type="date" id="startDate">
        <label for="endDate">Bis:</label>
        <input type="date" id="endDate">
        <br><br>

        <h3>Bundesländer filtern</h3>
        <div id="federalFilterContainer">
            <button id="toggleFederalButton">Auswählen</button>
            <div id="federalList" style="display:none; margin-top: 1rem;">
                <div id="federalSelectAll" class="federal-item active">Alle abwählen</div>
                <div class="federal-item active" data-name="Baden-Württemberg">✔ Baden-Württemberg</div>
                <div class="federal-item active" data-name="Bayern">✔ Bayern</div>
                <div class="federal-item active" data-name="Berlin">✔ Berlin</div>
                <div class="federal-item active" data-name="Brandenburg">✔ Brandenburg</div>
                <div class="federal-item active" data-name="Bremen">✔ Bremen</div>
                <div class="federal-item active" data-name="Hamburg">✔ Hamburg</div>
                <div class="federal-item active" data-name="Hessen">✔ Hessen</div>
                <div class="federal-item active" data-name="Mecklenburg-Vorpommern">✔ Mecklenburg-Vorpommern</div>
                <div class="federal-item active" data-name="Niedersachsen">✔ Niedersachsen</div>
                <div class="federal-item active" data-name="Nordrhein-Westfalen">✔ Nordrhein-Westfalen</div>
                <div class="federal-item active" data-name="Rheinland-Pfalz">✔ Rheinland-Pfalz</div>
                <div class="federal-item active" data-name="Saarland">✔ Saarland</div>
                <div class="federal-item active" data-name="Sachsen">✔ Sachsen</div>
                <div class="federal-item active" data-name="Sachsen-Anhalt">✔ Sachsen-Anhalt</div>
                <div class="federal-item active" data-name="Schleswig-Holstein">✔ Schleswig-Holstein</div>
                <div class="federal-item active" data-name="Thüringen">✔ Thüringen</div>
            </div>
        </div>
        <br><br>

        <h3>Summe der Einträge</h3>
        <p><span id="entryCount">0</span></p>
        <!-- <br><br><h3>Legende</h3>
        <p><div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <div style="width: 20px; height: 30px;"> 
                <svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M24 12C24 18.0965 18.9223 23.1316 13.4384 28.5696C12.961 29.043 12.4805 29.5195 12 30C11.5219 29.5219 11.0411 29.0452 10.5661 28.5741C5.08215 23.1361 0 18.0965 0 12C0 5.37258 5.37258 0 12 0C18.6274 0 24 5.37258 24 12ZM12 16.5C14.4853 16.5 16.5 14.4853 16.5 12C16.5 9.51472 14.4853 7.5 12 7.5C9.51472 7.5 7.5 9.51472 7.5 12C7.5 14.4853 9.51472 16.5 12 16.5Z" fill="#8b1e2e"/>
                </svg>
            </div>
            <span>&nbsp;&nbsp;Automatenspiel</span>
            </div>

            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <div style="width: 20px; height: 30px;"> 
                <svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M24 12C24 18.0965 18.9223 23.1316 13.4384 28.5696C12.961 29.043 12.4805 29.5195 12 30C11.5219 29.5219 11.0411 29.0452 10.5661 28.5741C5.08215 23.1361 0 18.0965 0 12C0 5.37258 5.37258 0 12 0C18.6274 0 24 5.37258 24 12ZM12 16.5C14.4853 16.5 16.5 14.4853 16.5 12C16.5 9.51472 14.4853 7.5 12 7.5C9.51472 7.5 7.5 9.51472 7.5 12C7.5 14.4853 9.51472 16.5 12 16.5Z" fill="#b89e1d"/>
                </svg>
            </div>
            <span>&nbsp;&nbsp;Wetten</span>
            </div>

            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <div style="width: 20px; height: 30px;"> 
                <svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M24 12C24 18.0965 18.9223 23.1316 13.4384 28.5696C12.961 29.043 12.4805 29.5195 12 30C11.5219 29.5219 11.0411 29.0452 10.5661 28.5741C5.08215 23.1361 0 18.0965 0 12C0 5.37258 5.37258 0 12 0C18.6274 0 24 5.37258 24 12ZM12 16.5C14.4853 16.5 16.5 14.4853 16.5 12C16.5 9.51472 14.4853 7.5 12 7.5C9.51472 7.5 7.5 9.51472 7.5 12C7.5 14.4853 9.51472 16.5 12 16.5Z" fill="#2c3e75"/>
                </svg>
            </div>
            <span>&nbsp;&nbsp;Online-Spiele</span>
            </div>

            <div style="display: flex; align-items: center; gap: 0.5rem; ">
            <div style="width: 20px; height: 30px;"> 
                <svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M24 12C24 18.0965 18.9223 23.1316 13.4384 28.5696C12.961 29.043 12.4805 29.5195 12 30C11.5219 29.5219 11.0411 29.0452 10.5661 28.5741C5.08215 23.1361 0 18.0965 0 12C0 5.37258 5.37258 0 12 0C18.6274 0 24 5.37258 24 12ZM12 16.5C14.4853 16.5 16.5 14.4853 16.5 12C16.5 9.51472 14.4853 7.5 12 7.5C9.51472 7.5 7.5 9.51472 7.5 12C7.5 14.4853 9.51472 16.5 12 16.5Z" fill="#b3541e"/>
                </svg>
            </div>
            <span>&nbsp;&nbsp;Sonstige</span>
            </div> 
        </p> -->
    </div>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster/dist/leaflet.markercluster.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.Default.css" />
    <!-- <script>
        const apiUrl = "<?= htmlspecialchars($apiUrl) ?>";
        const recaptchaKey = "<?= htmlspecialchars($recaptchaKey) ?>";

        document.getElementById('reportForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const message = document.getElementById('message').value.trim();
            const source = document.getElementById('source').value.trim();
            const spinner = document.getElementById('spinner');
            const responseBox = document.getElementById('formResponse');

            if (!message || !source) {
                responseBox.style.color = "red";
                responseBox.textContent = "Bitte alle Felder ausfüllen.";
                return;
            }

            spinner.style.display = 'block';
            responseBox.textContent = "";

            if (!recaptchaKey) {
                console.error("Kein reCAPTCHA Site-Key konfiguriert");
                responseBox.style.color = "red";
                responseBox.textContent = "Fehler: Kein reCAPTCHA-Key gesetzt.";
                spinner.style.display = 'none';
                return;
            }

            grecaptcha.ready(function () {
                console.log("recaptchaKey:", recaptchaKey);
                console.log("grecaptcha Objekt:", window.grecaptcha);
                grecaptcha.ready(() => {
                    console.log("Bin in grecaptcha.ready, versuche execute...");
                    grecaptcha.execute(recaptchaKey, { action: 'submit' })
                    .then(token => {
                        console.log("Token erhalten:", token);
                    })
                    .catch(err => {
                        console.error("execute-Fehler:", err);
                    });
                });

                grecaptcha.execute(recaptchaKey, { action: 'submit' })
                .then(function (token) {
                    if (!token) {
                        throw new Error("Kein reCAPTCHA-Token erhalten");
                    }
                    return fetch('/index.php?route=report', {   // Route angeben
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            message,
                            source,
                            captcha: token
                        })
                    });
                })
                .then(r => {
                    spinner.style.display = 'none';
                    if (!r.ok) throw new Error("Fehler beim Senden");
                    return r.json();
                })
                .then(data => {
                    responseBox.style.color = "green";
                    responseBox.textContent = "Meldung erfolgreich übermittelt.";
                    document.getElementById('reportForm').reset();
                })
                .catch(err => {
                    spinner.style.display = 'none';
                    responseBox.style.color = "red";
                    responseBox.textContent = "Fehler beim Senden: " + err.message;
                    console.error(err);
                });
            });
        }); 
    </script> -->
    <script>
        window.apiUrl = "<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>";
        window.recaptchaKey = "<?= htmlspecialchars($recaptchaKey, ENT_QUOTES, 'UTF-8') ?>";
    </script>
    <script src="map.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const startInput = document.getElementById('startDate');
            const endInput = document.getElementById('endDate');

            const today = new Date();
            const formatDate = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            startInput.value = '2025-03-01';
            endInput.value = formatDate(today);
        });
    </script>
</body>
</html>