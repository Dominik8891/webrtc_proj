<?php

use App\Helper\Https;

/**
 * Startet die Sitzung und setzt die Merkmale ihres Cookies.
 *
 * WARUM DIESE DATEI ERST NACH config/env.php GELADEN WIRD
 * -------------------------------------------------------
 * Wegen einer einzigen Zeile: 'secure'. Das Merkmal sagt dem Browser, dass er
 * das Cookie NUR ueber HTTPS zurueckschicken darf. Auf einem echten Server ist
 * das richtig und unverzichtbar - ohne es wandert die Sitzungskennung beim
 * ersten Klartextaufruf mit ueber die Leitung, und wer sie hat, ist angemeldet.
 *
 * Auf einem Entwicklungsrechner ohne Zertifikat war es dagegen eine Sperre:
 * Das Cookie wurde gesetzt und nie zurueckgeschickt, jede Anmeldung lief ins
 * Leere, und niemand sah, warum. Wer FORCE_HTTPS ausschaltet, um lokal ueber
 * http:// zu arbeiten, muss auch dieses Merkmal loswerden - sonst ist der
 * Schalter nur ein halber.
 *
 * Der Wert kommt deshalb aus derselben Quelle wie die Weiterleitung
 * (App\Helper\Https), und die braucht die .env. Darum steht das require
 * dieser Datei in index.php hinter config/env.php; die Begruendung steht dort
 * noch einmal.
 *
 * DIE REGEL LAUTET: secure genau dann, wenn die Anwendung ueber HTTPS laeuft
 * ODER ohnehin dorthin umleitet. Ausgeschaltet ist das Merkmal also nur in
 * genau einem Fall - Klartextverbindung UND FORCE_HTTPS aus -, und das ist
 * die lokale Entwicklung, um die es geht.
 */
session_set_cookie_params([
    'httponly'  => true,                                    // Cookie kann nicht per JavaScript ausgelesen werden (Schutz vor XSS)
    'secure'    => Https::istSicher() || Https::erzwungen(), // Cookie nur über HTTPS (Schutz vor MITM) - siehe oben
    'samesite'  => 'Strict'                                 // Cookie wird nur bei gleicher Domain gesendet (Schutz vor CSRF)
]);

// Startet die PHP-Session (erzeugt oder übernimmt ein Session-Cookie)
session_start();
