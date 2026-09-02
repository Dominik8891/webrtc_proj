window.webrtcApp = window.webrtcApp || {};

/**
 * UI-Modul für allgemeine Buttons und Dialoge (z.B. Standort-Button, Alle Locations, Lösch-Dialog).
 */
window.webrtcApp.ui = {
    /**
     * Zeigt den "Neue Lokation hinzufügen"-Button (für Admin/Guide)
     * oder "Jetzt Tour-Guide werden!" (für Zuschauer) je nach Rolle an.
     * Blendet den Button bei fehlender Berechtigung oder wenn nicht eingeloggt aus.
     *
     * Entschieden wird über window.userCan, das der Server aus
     * App\Helper\Role ableitet (ViewHelper::output). Hier stand früher ein
     * Vergleich gegen 'admin'/'guide'/'tourist'; window.userRole trägt aber
     * die Schreibweise aus usertype.name ('Admin', 'Guide', 'User', 'Trial'),
     * und 'tourist' gibt es dort überhaupt nicht. Der Button war dadurch für
     * jede Rolle unsichtbar (Befund F-5).
     *
     * Die beiden Beschriftungen führen an verschiedene Stellen, und das ist
     * der Kern der Sache: "Neue Lokation hinzufügen" öffnet das
     * Standortformular, "Jetzt Tour-Guide werden!" dagegen die Frage nach der
     * Guide-Rolle. Früher führten beide zum Formular - und wer es ausfüllte,
     * war anschließend Guide, ohne je gefragt worden zu sein.
     */
    showLocationButton: function() {
        var locationButtonDiv = document.getElementById('location-button');
        locationButtonDiv.innerHTML = '';
        let text = '';
        let target = '';
        if (window.isLoggedIn && window.userCan) {
            if (window.userCan.offerLocation) {
                text = 'Neue Lokation hinzufügen';
                target = 'index.php?act=set_location_page';
            } else if (window.userCan.becomeGuide) {
                text = 'Jetzt Tour-Guide werden!';
                target = 'index.php?act=guide_role_page';
            }
            if (text) {
                locationButtonDiv.innerHTML = `<a href="${target}" class="btn btn-primary btn-sm">${text}</a>`;
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
            browseLocationButtonDiv.innerHTML = `<a href="index.php?act=show_locations_page" class="btn btn-secondary btn-sm">Alle Standorte</a>`;
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
