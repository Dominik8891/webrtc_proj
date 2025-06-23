/**
 * Sound-Modul: Steuert das Abspielen und Stoppen von Audio-Elementen (z.B. Klingeltöne, Soundeffekte)
 */
window.webrtcApp.sound = {
    /**
     * Spielt das gewünschte Audio-Element ab.
     * @param {string} audio_in - Die ID des Audio-Elements im DOM
     * @param {boolean} [loop=true] - Soll die Wiedergabe wiederholt werden?
     * @param {number} [volume=1.0] - Lautstärke (0.0 - 1.0)
     */
    play(audio_in, loop = true, volume = 1.0) {
        const audio = document.getElementById(audio_in);
        if (audio) {
            audio.currentTime = 0;   // Setzt auf Start
            audio.loop = loop;       // Loop aktivieren/deaktivieren
            audio.volume = volume;   // Lautstärke setzen
            audio.play();            // Abspielen
            console.log("[Ringtone] Klingelton gestartet.");
        } else {
            console.warn("[Ringtone] Audio-Element nicht gefunden!");
        }
    },

    /**
     * Stoppt die Wiedergabe eines Audio-Elements und setzt auf Anfang zurück.
     * @param {string} audio_in - Die ID des Audio-Elements im DOM
     */
    stop(audio_in) {
        const audio = document.getElementById(audio_in);
        if (audio) {
            audio.pause();           // Stoppen
            audio.currentTime = 0;   // Auf Anfang zurücksetzen
            console.log("[Ringtone] Klingelton gestoppt.");
        } else {
            console.warn("[Ringtone] Audio-Element nicht gefunden!");
        }
    }
};
