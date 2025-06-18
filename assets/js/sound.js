// assets/js/sound.js
window.webrtcApp.sound = {
    play(audio_in, loop = true, volume = 1.0) {
        const audio = document.getElementById(audio_in);
        if (audio) {
            audio.currentTime = 0;
            audio.loop = loop;
            audio.volume = volume;
            audio.play();
            console.log("[Ringtone] Klingelton gestartet.");
        } else {
            console.warn("[Ringtone] Audio-Element nicht gefunden!");
        }
    },
    stop(audio_in) {
        const audio = document.getElementById(audio_in);
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
            console.log("[Ringtone] Klingelton gestoppt.");
        } else {
            console.warn("[Ringtone] Audio-Element nicht gefunden!");
        }
    }
};
