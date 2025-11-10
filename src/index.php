<?php

$mysqlHost = 'db';
$mysqlUser = 'root';
$mysqlPass = 'rootpass';
$mysqlDb   = 'rpps';
$pdo = new PDO("mysql:host=$mysqlHost;dbname=$mysqlDb;charset=utf8", $mysqlUser, $mysqlPass);


function getByCoordinates($pdo, $lat, $lon)
{
    $query = "
        SELECT
            *,
            pharmacies.name AS pharmacy_name,
            (6371000 * ACOS(
                COS(RADIANS(:lat)) * COS(RADIANS(latitude)) *
                COS(RADIANS(longitude) - RADIANS(:lon)) +
                SIN(RADIANS(:lat)) * SIN(RADIANS(latitude))
            )) AS distance_m
        FROM addresses
        JOIN pharmacies
            On pharmacies.finess = addresses.finess
        HAVING distance_m < 3000
        ORDER BY distance_m
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([':lat' => $lat, ':lon' => $lon]);
    $results =  $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $results;
}



if (isset($_GET['lat']) && isset($_GET['lon'])) {
    $lat = floatval($_GET['lat']);
    $lon = floatval($_GET['lon']);

    $results = getByCoordinates($pdo, $lat, $lon);
    header('Content-Type: application/json');
    echo json_encode($results);
    exit();
}

if(isset($_GET['pharmacyId'])) {
    $pharmacyId = $_GET['pharmacyId'];

    $query = "
        SELECT
            identities.*
        FROM pharmacies
        JOIN pharmacies_identities
            ON pharmacies.id = pharmacies_identities.pharmacy_id
        JOIN identities
            ON pharmacies_identities.identity_id = identities.id
        WHERE pharmacies.id = :pharmacyId";
    $stmt = $pdo->prepare($query);
    $stmt->execute([':pharmacyId' => $pharmacyId]);
    $identities =  $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pharmacyQuery = "
        SELECT *
        FROM pharmacies
        WHERE id = :pharmacyId";
    $pharmacyStmt = $pdo->prepare($pharmacyQuery);
    $pharmacyStmt->execute([':pharmacyId' => $pharmacyId]);
    $pharmacy =  $pharmacyStmt->fetch(PDO::FETCH_ASSOC);


    $pharmacyAddressesQuery = "
        SELECT *
        FROM addresses
        WHERE finess = :finess";
    $pharmacyAddressesStmt = $pdo->prepare($pharmacyAddressesQuery);
    $pharmacyAddressesStmt->execute([':finess' => $pharmacy['finess']]);
    $pharmacyAddress =  $pharmacyAddressesStmt->fetch(PDO::FETCH_ASSOC);

    $data = [
        'pharmacy' => $pharmacy,
        'address' => $pharmacyAddress,
        'identities' => $identities,
    ];

    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Pour iOS -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Toilettes Paris">

    <!-- Pour Android (Chrome) -->
    <meta name="mobile-web-app-capable" content="yes">


    <title>Pharmacies de France</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <link rel="stylesheet" href="styles.css">

</head>

<body>

    <form onsubmit="searchAddress(event)">
        <div id="search-container">
            <input type="text" id="search" placeholder="Search by address..." />
            <button type="submit">🔍</button>
            <button type="button" onclick="locateUser()">📍</button>
        </div>
    </form>

    <div id="leaflet-map"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <script>
        class PharmaciesMap {
            constructor(map) {
                this.map = map;
                this.markers = new Map();

                this.map.on('moveend', () => this.loadPharmacies());
                this.loadPharmacies();
            }

            loadPharmacies() {
                const bounds = this.map.getBounds();
                let center = bounds.getCenter();
                let lat = center.lat;
                let lon = center.lng;

                this.loadPharmaciesByCoords(lat, lon);
            }



            loadPharmaciesByCoords(lat, lon) {
                fetch(`?lat=${lat}&lon=${lon}`)
                    .then(response => response.json())
                    .then(data => {
                        data.forEach(pharmacie => {
                            let lat = pharmacie.latitude;
                            let lon = pharmacie.longitude;

                            let key = pharmacie.finess;
                            if (this.markers.has(key)) return;

                            const fullAddress = `${pharmacie.address}, ${pharmacie.postcode} ${pharmacie.city}`;

                            const content = `<strong>${pharmacie.pharmacy_name}</strong><br>${fullAddress}`;

                            let marker = L.marker([lat, lon]).addTo(this.map)
                                .bindPopup(content);
                            this.markers.set(key, marker);

                            // add click event on marker to fetch and show identities
                            marker.on('click', () => {
                                fetch(`?pharmacyId=${pharmacie.id}`)
                                    .then(response => response.json())
                                    .then(details => {
                                        let identitiesList = details.identities.map(identity => `
                                            <li>${identity.last_name} ${identity.first_name} (${identity.role})</li>`)
                                            .join('');
                                        let detailsContent = `<strong>${details.pharmacy.name}</strong><br>
                                            ${fullAddress}<hr/>
                                            <strong>Personnes:</strong>
                                            <ul>${identitiesList}</ul>`;
                                        marker.getPopup().setContent(detailsContent).openOn(this.map);
                                    })
                                ;
                            });
                                    


                        });
                    });
            }
        }


        // ==========================================================================
        // ==========================================================================
        // ==========================================================================


        document.addEventListener('DOMContentLoaded', () => {
            let map = L.map('leaflet-map').setView([48.866667, 2.333333], 17);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            let markers = new Map();
            let searchMarker = null;
            let userMarker = null;

            const pharmaciesMap = new PharmaciesMap(map);


            map.on('moveend', () => pharmaciesMap.loadPharmacies());
            pharmaciesMap.loadPharmacies(map, markers);

            // Fonction de recherche d'adresse
            window.searchAddress = function(e) {
                e.preventDefault();
                let query = document.getElementById("search").value;
                if (!query) return;

                fetch(`https://api-adresse.data.gouv.fr/search/?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.features.length === 0) {
                            alert("Adresse non trouvée !");
                            return;
                        }

                        let {
                            coordinates
                        } = data.features[0].geometry;
                        let [lon, lat] = coordinates;

                        pharmaciesMap.loadPharmaciesByCoords(lat, lon);


                        // Supprime l'ancien marqueur de recherche
                        if (searchMarker) {
                            map.removeLayer(searchMarker);
                        }

                        // Ajoute un marqueur sur l'adresse trouvée
                        searchMarker = L.marker([lat, lon])
                            .addTo(map)
                            .bindPopup(`📍 ${query}`)
                            .openPopup();

                        // Centre la carte sur l'adresse trouvée
                        map.setView([lat, lon], 15);
                    })
                    .catch(error => console.error('Erreur lors de la recherche:', error));
            };

            // ==========================================================================
            // ==========================================================================
            // ==========================================================================

            // Fonction pour localiser l'utilisateur
            window.locateUser = function() {
                if (!navigator.geolocation) {
                    alert("Votre navigateur ne supporte pas la géolocalisation.");
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        let {
                            latitude,
                            longitude
                        } = position.coords;

                        console.group('%cindex2.php :: 150 =============================', 'color: #183686; font-size: 1rem');
                        console.log('latitude', latitude);
                        console.log('longitude', longitude);
                        console.groupEnd();

                        // Supprime l'ancien marqueur utilisateur
                        if (userMarker) {
                            map.removeLayer(userMarker);
                        }

                        // Ajoute un marqueur sur la position actuelle
                        userMarker = L.marker([latitude, longitude], {
                                icon: L.icon({
                                    iconUrl: 'https://leafletjs.com/examples/custom-icons/leaf-red.png',
                                    iconSize: [30, 40],
                                    iconAnchor: [15, 40]
                                })
                            })
                            .addTo(map)
                            .bindPopup("📍 Your position")
                            .openPopup();

                        // Centre la carte sur la position actuelle
                        map.setView([latitude, longitude], 15);
                    },
                    (error) => {
                        console.error("Erreur de géolocalisation :", error);
                        alert("Impossible d'obtenir votre position.");
                    }
                );
            };
        });
    </script>
</body>

</html>