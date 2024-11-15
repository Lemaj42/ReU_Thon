// Charger l'API Google Maps une seule fois
function loadGoogleMapsScript(address, mapElementId, markerTitle) {
    if (!document.getElementById('map')) {
        const script = document.createElement('script');
        script.id = 'google-maps-script';
        script.src = `https://maps.googleapis.com/maps/api/js?key=${googleMapsApiKey}&callback=initMap&libraries=places`;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);

        // Initialiser la carte une fois que l'API est chargée
        window.initMap = () => {
            initializeMap(address, mapElementId, markerTitle);
        };
    } else {
        console.warn('Google Maps API déjà chargée.');
        if (window.google && window.google.maps) {
            initializeMap(address, mapElementId, markerTitle);
        }
    }
}

function initializeMap(address, mapElementId, markerTitle) {
    const geocoder = new google.maps.Geocoder();
    const mapElement = document.getElementById(mapElementId);

    if (mapElement) {
        geocoder.geocode({ address: address }, function(results, status) {
            if (status === 'OK' && results[0]) {
                const map = new google.maps.Map(mapElement, {
                    zoom: 14,
                    center: results[0].geometry.location
                });
                new google.maps.Marker({
                    map: map,
                    position: results[0].geometry.location,
                    title: markerTitle
                });
            } else {
                console.error(`La géolocalisation pour "${address}" a échoué : ${status}`);
                mapElement.innerHTML = '<p class="text-danger">Impossible de charger la carte.</p>';
            }
        });
    } else {
        console.warn(`Élément avec l'ID "${mapElementId}" introuvable.`);
    }
}
