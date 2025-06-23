window.webrtcApp = window.webrtcApp || {};

/**
 * UI-Modul für allgemeine Buttons und Dialoge (z.B. Standort-Button, Alle Locations, Lösch-Dialog).
 */
window.webrtcApp.ui = {
    /**
     * Zeigt den "Neue Lokation hinzufügen"-Button (für Admin/Guide)
     * oder "Jetzt Tour-Guide werden!" (für Touristen) je nach Rolle an.
     * Blendet den Button bei fehlender Berechtigung oder wenn nicht eingeloggt aus.
     */
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
                locationButtonDiv.innerHTML = `<a href="index.php?act=set_location_page" class="btn btn-success text-light">${text}</a>`;
                locationButtonDiv.style.display = '';
            } else {
                locationButtonDiv.style.display = 'none';
            }
        } else {
            locationButtonDiv.style.display = 'none';
        }
    },

    /**
     * Zeigt den "Alle Locations durchsuchen"-Button, wenn eingeloggt.
     * Blendet ihn sonst aus.
     */
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

    /**
     * Zeigt einen Confirm-Dialog beim Löschen und leitet ggf. weiter.
     * @param {string} in_url - Ziel-URL für das Löschen
     */
    confirmDelete: function(in_url) {
        if (window.confirm("Wollen Sie den Datensatz wirklich löschen?")) {
            window.location.href = in_url;
        } else {
            alert("Löschen abgebrochen");
        }
    },

    /**
     * Wechselt den Display zustand des Elements.
     * @param {number} id    - Id des Ziel Elements
     * @param {string} value - Display zustand des Elements
     */
    setDisplay: function(id, value) {
        const el = document.getElementById(id);
        if (el) el.style.display = value;
    }
};
