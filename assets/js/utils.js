window.webrtcApp.utils = window.webrtcApp.utils || {};

window.webrtcApp.utils.showSuccessAlertIfNeeded = function(param, value, message) {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get(param) === value) {
        alert(message);
        urlParams.delete('success');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, document.title, newUrl);
    }
};


