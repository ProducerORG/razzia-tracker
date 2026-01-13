const initialMinZoom = window.innerWidth < 768 ? 3 : 6;
const initialZoom = window.innerWidth < 768 ? 4 : 6;
const loadingOverlay = document.getElementById("mapLoadingOverlay");

const map = L.map('map', {
    minZoom: initialMinZoom,
    maxZoom: 16,
    maxBounds: [
        [47.0, 5.5],
        [55.1, 15.5]
    ]
}).setView([51.1657, 10.4515], initialZoom);

L.tileLayer('https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; OpenStreetMap & Carto',
    subdomains: 'abcd',
    maxZoom: 19
}).addTo(map);

let geoLayer;
fetch('bundeslaender.geojson')
    .then(res => res.json())
    .then(data => {
        geoLayer = L.geoJSON(data, {
            style: {
                color: '#333333',
                weight: 1.5,
                opacity: 0.8,
                fillOpacity: 0.3,
                fillColor: '#cccccc'
            }
        }).addTo(map);
        filterAndRender();
    });

// Event Listener für Bundesländer-Filter

document.getElementById("toggleFederalButton").addEventListener("click", () => {
    const list = document.getElementById("federalList");
    list.style.display = (list.style.display === "none") ? "block" : "none";
});

document.querySelectorAll(".federal-item").forEach(item => {
    if (item.id === 'federalSelectAll') return;
    item.addEventListener("click", () => {
        item.classList.toggle("active");
        item.innerText = item.classList.contains("active") ? "✔ " + item.dataset.name : item.dataset.name;

        const allItems = Array.from(document.querySelectorAll(".federal-item"))
            .filter(i => i.id !== 'federalSelectAll');
        const allActive = allItems.every(i => i.classList.contains('active'));

        const toggleButton = document.getElementById("federalSelectAll");
        toggleButton.textContent = allActive ? "Alle abwählen" : "Alle auswählen";

        if (allActive) {
            toggleButton.classList.add("active");
        } else {
            toggleButton.classList.remove("active");
        }

        filterAndRender();
    });
});

document.getElementById("federalSelectAll").addEventListener("click", () => {
    const items = Array.from(document.querySelectorAll(".federal-item"))
        .filter(item => item.id !== 'federalSelectAll');
    const allActive = items.every(item => item.classList.contains("active"));

    items.forEach(item => {
        if (allActive) {
            item.classList.remove("active");
            item.innerText = item.dataset.name;
        } else {
            item.classList.add("active");
            item.innerText = "✔ " + item.dataset.name;
        }
    });

    const toggleButton = document.getElementById("federalSelectAll");
    toggleButton.textContent = allActive ? "Alle auswählen" : "Alle abwählen";

    if (allActive) {
        toggleButton.classList.remove("active");
    } else {
        toggleButton.classList.add("active");
    }

    filterAndRender();
});

function getSelectedFederals() {
    const selected = [];
    document.querySelectorAll(".federal-item.active").forEach(item => {
        selected.push(item.dataset.name);
    });
    return selected;
}

function getColor(type) {
    switch (type) {
        case "Automatenspiel":
        case "A":
            return "#8b1e2e";  // #8b1e2e dunkelrot
        case "Wetten":
        case "W":
            return "#8b1e2e";  // #b89e1d senfgelb
        case "Online-Spiele":
        case "O":
            return "#8b1e2e";  // #2c3e75 dunkelblau


        case "Sonstige":
            return "#8b1e2e";  // #b3541e orangebraun
        case "":
            return "#8b1e2e";  // #b3541e orangebraun
        case null:
            return "#8b1e2e";  // #b3541e orangebraun
        default:
            return "#8b1e2e";  // #b3541e orangebraun
    }
}

let allData = [];
let markers = [];

function clearMarkers() {
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];
}

function getColoredIcon() {
    const svgIcon = `<svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
<path d="M11.8452 30C11.6592 30 11.5082 29.8493 11.5082 29.663V13.6873C11.5082 13.5013 11.6592 13.3503 11.8452 13.3503C12.0312 13.3503 12.1822 13.5013 12.1822 13.6873V29.663C12.1822 29.8493 12.0312 30 11.8452 30Z" fill="#66676B"/>
<path d="M11.8456 13.6874C15.6252 13.6874 18.6892 10.6233 18.6892 6.84368C18.6892 3.06402 15.6252 0 11.8456 0C8.0659 0 5.00188 3.06402 5.00188 6.84368C5.00188 10.6233 8.0659 13.6874 11.8456 13.6874Z" fill="#114732"/>
<path d="M14.2039 6.17072C15.1347 6.17072 15.8892 5.41617 15.8892 4.48539C15.8892 3.5546 15.1347 2.80005 14.2039 2.80005C13.2731 2.80005 12.5185 3.5546 12.5185 4.48539C12.5185 5.41617 13.2731 6.17072 14.2039 6.17072Z" fill="#E5F5EF"/>
</svg>`;

/*     ALTES SYMBOL:
    const svgIcon = `<svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">
<path fill-rule="evenodd" clip-rule="evenodd" d="M24 12C24 18.0965 18.9223 23.1316 13.4384 28.5696C12.961 29.043 12.4805 29.5195 12 30C11.5219 29.5219 11.0411 29.0452 10.5661 28.5741C5.08215 23.1361 0 18.0965 0 12C0 5.37258 5.37258 0 12 0C18.6274 0 24 5.37258 24 12ZM12 16.5C14.4853 16.5 16.5 14.4853 16.5 12C16.5 9.51472 14.4853 7.5 12 7.5C9.51472 7.5 7.5 9.51472 7.5 12C7.5 14.4853 9.51472 16.5 12 16.5Z" fill="${color}"/>
</svg>`;
 */    return L.divIcon({
        className: '',
        html: svgIcon,
        iconSize: [30, 40],
        iconAnchor: [15, 40],
        popupAnchor: [0, -35]
    });
}

showLoading();
fetch('/index.php?route=raids', { cache: "no-store" })
    .then(res => res.json())
    .then(data => {
        allData = data;
        filterAndRender();
        hideLoading();
    })
    .catch(err => {
        console.error("Fehler beim Laden der Daten:", err);
        hideLoading();
    });

hideLoading();


function filterAndRender() {
    if (!geoLayer) return;

    showLoading()  // Ladeoverlay einblenden

    setTimeout(() => {
        const startDateInput = document.getElementById("startDate").value;
        const endDateInput = document.getElementById("endDate").value;
        const startDate = startDateInput ? new Date(startDateInput) : null;
        const endDate = endDateInput ? new Date(endDateInput) : new Date();
        const selectedFederals = getSelectedFederals();

        clearMarkers();

        let count = 0;
        let filteredDates = [];

        const positionOffsetMap = new Map();

        allData.forEach(entry => {
            if (entry.federal && !selectedFederals.includes(entry.federal)) return;
            const lat = parseFloat(entry.lat);
            const lon = parseFloat(entry.lon);
            if (isNaN(lat) || isNaN(lon)) return;

            const entryDate = new Date(entry.date);
            if (startDate && entryDate < startDate) return;
            if (endDate && entryDate > endDate) return;

            filteredDates.push(entryDate);

            const key = `${lat},${lon}`;
            const offsetIndex = positionOffsetMap.get(key) || 0;
            positionOffsetMap.set(key, offsetIndex + 1);

            let hash = 0;
            for (let i = 0; i < key.length; i++) {
                hash = (hash * 31 + key.charCodeAt(i)) | 0;
            }
            const baseAngle = (hash % 360 + 360) % 360;

            const angle = (baseAngle + offsetIndex * 45) * (Math.PI / 180);
            const ring = Math.floor(offsetIndex / 10);
            const radius = 0.03 + 0.03 * ring;
            const latOffset = lat + radius * Math.cos(angle);
            const lonOffset = lon + radius * Math.sin(angle);

            const marker = L.marker([latOffset, lonOffset], {
                icon: getColoredIcon()
            });

            marker.bindPopup(`
                <b>${entry.title}</b>
                <div style="padding-bottom: 8px;">${entry.summary}</div>
                <div style="display: flex; justify-content: flex-end;">
                    <a href="${entry.url}" target="_blank">Mehr erfahren</a>
                </div>
            `);
            
            const zIndex = 10000 - Math.floor((new Date() - entryDate) / (1000 * 60 * 60 * 24));
            marker.setZIndexOffset(zIndex);

            marker.addTo(map);
            markers.push(marker);
            count++;
        });

        let infoText = "";
        if (filteredDates.length > 0) {
            const minDate = new Date(document.getElementById("startDate").value);
            const maxDate = new Date(Math.max(...filteredDates));
            const days = Math.floor((maxDate - minDate) / (1000 * 60 * 60 * 24)) + 1;
            const formattedMinDate = minDate.toLocaleDateString('de-DE');
            infoText = `${count} in ${days} Tagen`;
        } else {
            infoText = `${count} Einträge`;
        }

        const entryDisplay = document.getElementById("entryCount");
        entryDisplay.innerText = infoText;
        entryDisplay.style.fontSize = "1.2rem";

        geoLayer.eachLayer(layer => {
            const name = layer.feature.properties.NAME_1;
            if (selectedFederals.includes(name)) {
                layer.setStyle({ opacity: 0.8, fillOpacity: 0.3 });
            } else {
                layer.setStyle({ opacity: 0.2, fillOpacity: 0.1 });
            }
        });

        hideLoading(); // Ladeoverlay hier sicher entfernen
    }, 100); // Kurzes Timeout, damit UI den Wechsel sauber registriert
}

document.getElementById("startDate").addEventListener("change", filterAndRender);
document.getElementById("endDate").addEventListener("change", filterAndRender);

function showLoading() {
    loadingOverlay.style.display = "flex";
}

function hideLoading() {
    loadingOverlay.style.display = "none";
}

/* MELDEFORMULAR */

document.getElementById("reportForm").addEventListener("submit", function(e) {
    e.preventDefault();

    const message = document.getElementById("message").value.trim();
    const source = document.getElementById("source").value.trim();
    const formResponse = document.getElementById("formResponse");
    const spinner = document.getElementById("spinner");

    formResponse.innerText = "";

    if (!message || !source) {
        formResponse.style.color = "red";
        formResponse.innerText = "Bitte fülle alle Felder aus.";
        return;
    }

    spinner.style.display = "block";

    if (!window.recaptchaKey || !window.grecaptcha) {
        spinner.style.display = "none";
        formResponse.style.color = "red";
        formResponse.innerText = "Fehler: Kein reCAPTCHA-Key vorhanden oder Library nicht geladen.";
        console.error("recaptchaKey:", window.recaptchaKey, "grecaptcha:", window.grecaptcha);
        return;
    }

    grecaptcha.ready(async function () {
        try {
            const token = await grecaptcha.execute(window.recaptchaKey, { action: "submit" });
            const payload = { message, source, captcha: token };

            const res = await fetch('/index.php?route=report', {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });
            
            // Rohtext lesen, damit du Backend-Fehler siehst
            const text = await res.text();
            spinner.style.display = "none";
            
            if (!res.ok) {
                console.error("Serverantwort (Fehler):", text);
                formResponse.style.color = "red";
                formResponse.innerText = text ? ("Fehler beim Senden: " + text) : ("Fehler beim Senden (HTTP " + res.status + ")");
                return;
            }
            
            // Nur wenn ok -> JSON parsen
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error("JSON-Parse-Fehler, Rohtext:", text);
                formResponse.style.color = "red";
                formResponse.innerText = "Antwortformat ungültig.";
                return;
            }
            
            formResponse.style.color = "green";
            formResponse.innerText = "Meldung erfolgreich gesendet!";
            document.getElementById("reportForm").reset();
            
        } catch (err) {
            spinner.style.display = "none";
            formResponse.style.color = "red";
            formResponse.innerText = "Serverfehler.";
            console.error("Fehler:", err);
        }

        setTimeout(() => { formResponse.innerText = ""; }, 5000);
    });
});
