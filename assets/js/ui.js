// assets/js/ui.js
window.webrtcApp.ui = {
    setEndCallButtonVisible(visible) {
        const btn = document.getElementById('end-call-btn');
        if (btn) btn.style.display = visible ? '' : 'none';
    },
    initChatUI() {
        const sendBtn = document.getElementById("chat-send-btn");
        const chatInput = document.getElementById("chat-input");
        if (sendBtn && chatInput) {
            sendBtn.addEventListener('click', function() {
                const msg = chatInput.value;
                if (msg) {
                    window.webrtcApp.chat.send(msg);
                    chatInput.value = "";
                }
            });
            chatInput.addEventListener('keydown', function(e) {
                if (e.key === "Enter") sendBtn.click();
            });
        }
        const fileInput = document.getElementById("file-input");
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length) window.webrtcApp.chat.sendFile(e.target.files[0]);
            });
        }
    },
    showLocationButton() {
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
    showAllLocationsButton() {
        var browseLocationButtonDiv = document.getElementById('browse-locations-button');
        browseLocationButtonDiv.innerHTML = '';

        // Button für ALLE eingeloggten User, Rolle egal!
        if (window.isLoggedIn) {
            browseLocationButtonDiv.innerHTML = `<a href="index.php?act=show_locations_page" class="btn btn-primary" style="margin-bottom:10px;">Alle Locations durchsuchen</a>`;
            browseLocationButtonDiv.style.display = '';
        } else {
            browseLocationButtonDiv.style.display = 'none';
        }
    }
};
window.webrtcApp.ui.confirmDelete = function(in_url) {
    if (window.confirm("Wollen Sie den Datensatz wirklich löschen?")) {
        window.location.href = in_url;
    } else {
        alert("Löschen abgebrochen");
    }
}
