function askLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            fetch('index.php?act=save_location', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    lat: position.coords.latitude,
                    lon: position.coords.longitude
                })
            }).then(response => {
                window.location.href = "index.php";
            });
        }, function() {
            alert("Standort konnte nicht ermittelt werden.");
            window.location.href = "index.php";
        });
    } else {
        alert("Geolocation wird von diesem Browser nicht unterstützt.");
        window.location.href = "index.php";
    }
}