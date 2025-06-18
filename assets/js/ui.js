window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.ui = {
    showLocationButton: function() {
        var locationButtonDiv = document.getElementById('location-button');
        locationButtonDiv.innerHTML = '';
        let text = '';
        if (window.isLoggedIn && window.userRole) {
            if (window.userRole === 'admin' || window.userRole === 'guide') {
                text = 'Neue Lokation hinzufügen';
            } else if (window.userRole === 'tourist') {
                text = 'Jetzt Tour-Guide werden!';
            }
            if (text) {
                locationButtonDiv.innerHTML = `<a href="index.php?act=set_location_page">${text}</a>`;
                locationButtonDiv.style.display = '';
            } else {
                locationButtonDiv.style.display = 'none';
            }
        } else {
            locationButtonDiv.style.display = 'none';
        }
    },

    showAllLocationsButton: function() {
        var browseLocationButtonDiv = document.getElementById('browse-locations-button');
        browseLocationButtonDiv.innerHTML = '';
        if (window.isLoggedIn) {
            browseLocationButtonDiv.innerHTML = `<a href="index.php?act=show_locations_page" class="btn btn-primary text-light" style="margin-bottom:10px;">Alle Locations durchsuchen</a>`;
            browseLocationButtonDiv.style.display = '';
        } else {
            browseLocationButtonDiv.style.display = 'none';
        }
    },

    confirmDelete: function(in_url) {
        if (window.confirm("Wollen Sie den Datensatz wirklich löschen?")) {
            window.location.href = in_url;
        } else {
            alert("Löschen abgebrochen");
        }
    }
};
