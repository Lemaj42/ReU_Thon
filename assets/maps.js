// Fonction pour charger l'API Google Maps une seule fois
function loadGoogleMapsScript() {
    if (!window.google || !window.google.maps) {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${googleMapsApiKey}&callback=initMap&libraries=marker`;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    } else {
        initMap();
    }
}

// Fonction pour initialiser les cartes et les marqueurs
function initMap() {
    const geocoder = new google.maps.Geocoder();

    // Première adresse (meeting.place)
    const address1 = meetingPlace;
    const mapElement1 = document.getElementById('map1');
    if (mapElement1) {
        geocoder.geocode({ address: address1 }, function(results, status) {
            if (status === 'OK') {
                const map1 = new google.maps.Map(mapElement1, {
                    zoom: 14,
                    center: results[0].geometry.location
                });
                new google.maps.marker.AdvancedMarkerElement({
                    map: map1,
                    position: results[0].geometry.location,
                    title: "Lieu de la réunion"
                });
            } else {
                console.error('La géolocalisation de la première adresse a échoué : ' + status);
                mapElement1.innerHTML = '<p class="text-danger">Impossible de charger la carte.</p>';
            }
        });
    }

    // Deuxième adresse (statique)
    const address2 = "Ecole Albert Camus, 9 Bis Impasse Albert CAMUS, 42160 ANDREZIEUX BOUTHEON";
    const mapElement2 = document.getElementById('map2');
    if (mapElement2) {
        geocoder.geocode({ address: address2 }, function(results, status) {
            if (status === 'OK') {
                const map2 = new google.maps.Map(mapElement2, {
                    zoom: 14,
                    center: results[0].geometry.location
                });
                new google.maps.marker.AdvancedMarkerElement({
                    map: map2,
                    position: results[0].geometry.location,
                    title: "Ecole Albert Camus"
                });
            } else {
                console.error('La géolocalisation de la deuxième adresse a échoué : ' + status);
                mapElement2.innerHTML = '<p class="text-danger">Impossible de charger la carte.</p>';
            }
        });
    }
}

// Appel de la fonction pour charger Google Maps
loadGoogleMapsScript();