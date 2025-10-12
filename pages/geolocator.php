<?php

declare(strict_types=1);

require dirname(__DIR__) . '/settings.inc.php';
require_once dirname(__DIR__) . '/functions.inc.php';

$renderer = new GalleryTemplateRenderer();

$initialLat = 38.029306; // Downtown Charlottesville
$initialLon = -78.476678;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Photo Geolocator</title>
    <link rel="stylesheet" href="/assets/styles.css">
    <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
    <style>
        body {
            padding: 0;
        }
        #geolocator-wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px;
        }
        #geolocator-map {
            width: 100%;
            height: 70vh;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        #yaml-output {
            background: #1e1e1e;
            color: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
            font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
            font-size: 0.95em;
        }
        #decimal-output {
            font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
            margin-bottom: 12px;
        }
        @media (max-width: 768px) {
            #geolocator-wrapper {
                padding: 16px;
            }
            #geolocator-map {
                height: 60vh;
            }
        }
        @media (max-width: 480px) {
            #geolocator-wrapper {
                padding: 12px;
            }
            #geolocator-map {
                height: 55vh;
            }
        }
    </style>
</head>
<body>
    <?php echo $renderer->render('_menu.html.twig', []); ?>
    <div id="geolocator-wrapper">
        <h1>Photo Geolocator</h1>
        <p><a href="/">← Back to gallery</a></p>
        <p>Drag the marker to the desired location. Copy the YAML block below into <code>library.yml</code>.</p>
        <div id="geolocator-map"></div>
        <div id="decimal-output"></div>
        <pre id="yaml-output"></pre>
    </div>

    <script src="/assets/vendor/leaflet/leaflet.js"></script>
    <script>
        const initialLat = <?php echo json_encode($initialLat); ?>;
        const initialLon = <?php echo json_encode($initialLon); ?>;

        const map = L.map('geolocator-map').setView([initialLat, initialLon], 16);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors'
        }).addTo(map);

        const marker = L.marker([initialLat, initialLon], { draggable: true }).addTo(map);

        const decimalOutput = document.getElementById('decimal-output');
        const yamlOutput = document.getElementById('yaml-output');

        function toFraction(value, denominator) {
            const numerator = Math.round(value * denominator);
            return `${numerator}/${denominator}`;
        }

        function decimalToYaml(lat, lon) {
            const yamlLines = [];

            const latRef = lat >= 0 ? 'N' : 'S';
            const lonRef = lon >= 0 ? 'E' : 'W';

            const latComponents = decimalToDms(Math.abs(lat));
            const lonComponents = decimalToDms(Math.abs(lon));

            yamlLines.push(`    GPSLatitudeRef: '${latRef}'`);
            yamlLines.push('    GPSLatitude:');
            yamlLines.push(`      - ${latComponents[0]}/1`);
            yamlLines.push(`      - ${latComponents[1]}/1`);
            yamlLines.push(`      - ${toFraction(latComponents[2], 100)}`);
            yamlLines.push(`    GPSLongitudeRef: '${lonRef}'`);
            yamlLines.push('    GPSLongitude:');
            yamlLines.push(`      - ${lonComponents[0]}/1`);
            yamlLines.push(`      - ${lonComponents[1]}/1`);
            yamlLines.push(`      - ${toFraction(lonComponents[2], 100)}`);

            return yamlLines.join('\n');
        }

        function decimalToDms(decimal) {
            let degrees = Math.floor(decimal);
            let minutesFull = (decimal - degrees) * 60;
            let minutes = Math.floor(minutesFull);
            let seconds = (minutesFull - minutes) * 60;

            // Normalize in case seconds rounds up to 60
            if (seconds >= 59.995) {
                seconds = 0;
                minutes += 1;
            }
            if (minutes >= 60) {
                minutes = 0;
                degrees += 1;
            }

            return [degrees, minutes, seconds];
        }

        function updateOutputs(lat, lon) {
            decimalOutput.textContent = `Latitude: ${lat.toFixed(6)}, Longitude: ${lon.toFixed(6)}`;
            yamlOutput.textContent = decimalToYaml(lat, lon);
        }

        marker.on('drag', function (event) {
            const position = event.target.getLatLng();
            updateOutputs(position.lat, position.lng);
        });

        updateOutputs(initialLat, initialLon);
    </script>
</body>
</html>
