window.webrtcApp.utils = window.webrtcApp.utils || {};

/**
 * Prüft, ob in der URL ein bestimmter Parameter (z.B. success=1) vorhanden ist
 * und zeigt dann eine Erfolgsmeldung per alert an.
 * Anschließend wird der Parameter aus der URL entfernt (ohne Neuladen).
 *
 * @param {string} param   - Name des URL-Parameters (z.B. 'success')
 * @param {string} value   - Wert, auf den geprüft werden soll (z.B. '1')
 * @param {string} message - Nachricht, die angezeigt werden soll
 */
window.webrtcApp.utils.showSuccessAlertIfNeeded = function(param, value, message) {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get(param) === value) {
        // Parameter entfernen und URL bereinigen
        urlParams.delete('success');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, document.title, newUrl);
        // Erfolgsmeldung anzeigen
        alert(message);
    }
};
