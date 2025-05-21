

function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    window.pendingOffer = data; // Merke dir das Angebot global

    // Zeige den Annehmen-Button an
    document.getElementById('accept-call-btn').style.display = "inline-block";
}





