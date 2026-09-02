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
/**
 * Zeigt eine Rueckmeldung, die als Parameter in der Adresse steht, und
 * entfernt den Parameter danach aus der Adresszeile.
 *
 * @param {string} param   Name des Parameters
 * @param {string} value   Wert, bei dem gemeldet wird
 * @param {string} message Der Text
 * @param {string} [art]   'success' (Vorgabe), 'error' oder 'info'
 */
window.webrtcApp.utils.showSuccessAlertIfNeeded = function(param, value, message, art) {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get(param) === value) {
        // Parameter entfernen und URL bereinigen
        urlParams.delete('success');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, document.title, newUrl);
        // Der Hinweis erscheint kurz und verschwindet von selbst.
        window.webrtcApp.notify.toast(message, art || 'success');
    }
};
