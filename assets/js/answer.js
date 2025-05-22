

/*function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    window.pendingOffer = data; // Merke dir das Angebot global

    // Zeige den Annehmen-Button an
    document.getElementById('accept-call-btn').style.display = "inline-block";
}*/

function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    window.pendingOffer = data; // Merke dir das Angebot global

    // SOFORT den Medien-Dialog anzeigen:
    document.getElementById('media-select-dialog').style.display = '';
    // Verstecke den alten Annehmen-Button (falls sichtbar)
    document.getElementById('accept-call-btn').style.display = "none";
}




