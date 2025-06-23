<?php
// Setzt die Parameter für das Session-Cookie
session_set_cookie_params([
    'httponly'  => true,      // Cookie kann nicht per JavaScript ausgelesen werden (Schutz vor XSS)
    'secure'    => true,      // Cookie wird nur über HTTPS übertragen (Schutz vor MITM)
    'samesite'  => 'Strict'   // Cookie wird nur bei gleicher Domain gesendet (Schutz vor CSRF)
]);

// Startet die PHP-Session (erzeugt oder übernimmt ein Session-Cookie)
session_start();
