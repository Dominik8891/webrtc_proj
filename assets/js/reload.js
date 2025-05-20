setInterval(function() {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "dein-endpunkt.php", true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            document.getElementById("content").innerHTML = xhr.responseText;
        }
    };
    xhr.send();
}, 5000); // alle 5 Sekunden