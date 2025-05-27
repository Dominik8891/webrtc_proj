

function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    window.pendingOffer = data; // Merke dir das Angebot global

    // SOFORT den Medien-Dialog anzeigen:
    var dialog = document.getElementById('media-select-dialog');
    if (dialog) {
        dialog.style.display = '';
    }
    // Verstecke den alten Annehmen-Button (falls sichtbar)
    var btn = document.getElementById('accept-call-btn');
    if (btn) btn.style.display = "none";

    window.playSound('incomming_call_ringtone'); 
}




