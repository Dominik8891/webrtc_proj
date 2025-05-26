window.playSound = function(audio_in, loop = true) {
    var audio = document.getElementById(audio_in);

    if (audio) {
        audio.currentTime = 0;  // Immer von vorne
        audio.loop = loop;      // Endlosschleife
        audio.play();
        console.log("[Ringtone] Klingelton gestartet.");
    } else {
        console.warn("[Ringtone] Audio-Element nicht gefunden!");
    }
};

window.stopSound = function(audio_in) {
    var audio = document.getElementById(audio_in);
    if (audio) {
        audio.pause();
        audio.currentTime = 0;
        console.log("[Ringtone] Klingelton gestoppt.");
    } else {
        console.warn("[Ringtone] Audio-Element nicht gefunden!");
    }
};
