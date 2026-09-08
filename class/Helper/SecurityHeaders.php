<?php
namespace App\Helper;

/**
 * Die Sicherheitskopfzeilen. Eine Stelle, jede Antwort.
 *
 * WOZU
 * ----
 * Bisher schickte die Anwendung keine einzige. Fuenf Kopfzeilen fehlten, und
 * jede davon deckt eine Luecke ab, die man im Code allein nicht schliessen
 * kann - sie beschreiben dem BROWSER, was er mit dieser Seite tun darf:
 *
 *   Content-Security-Policy     woher Skripte, Stile, Bilder kommen duerfen
 *   X-Frame-Options             die Seite darf nicht in einen Rahmen
 *   X-Content-Type-Options      der Browser raet den Dateityp nicht
 *   Referrer-Policy             wohin die eigene Adresse weitergegeben wird
 *   Permissions-Policy          welche Geraete die Seite ansprechen darf
 *
 * DER RAHMENSCHUTZ IST HIER KEIN NEBENPUNKT.
 * Diese Anwendung fragt Kamera und Mikrofon ab. Steht sie in einem fremden
 * iframe, sieht der Benutzer die Freigabeabfrage seines Browsers ueber einer
 * fremden Seite - und gibt seine Kamera an etwas frei, das er fuer etwas
 * anderes haelt. Deshalb DENY und nicht SAMEORIGIN: Die Anwendung baut
 * selbst keinen einzigen iframe, also gibt es keinen Fall, in dem ein Rahmen
 * richtig waere.
 *
 * WO GERUFEN WIRD
 * ---------------
 * In index.php, unmittelbar hinter der HTTPS-Weiterleitung und vor allem
 * anderen. index.php ist der einzige Einstieg der Anwendung; damit traegt
 * JEDE Antwort diese Kopfzeilen - die Fehlerseiten aus deny() und die
 * JSON-Antworten der Schnittstellen eingeschlossen.
 *
 * WAS SIE NICHT ERREICHT
 * ----------------------
 * Die Dateien unter assets/ liefert der Webserver direkt aus, ohne PHP. Sie
 * bekommen diese Kopfzeilen nicht. Das ist hinnehmbar - es sind eigene,
 * unveraenderliche Dateien, und die Regeln, die zaehlen (CSP, Rahmenschutz),
 * gelten fuer das DOKUMENT, nicht fuer die Datei, die es nachlaedt. Wer sie
 * trotzdem ueberall haben will, setzt sie zusaetzlich im Webserver; die
 * README nennt den Block fuer Apache und nginx.
 */
class SecurityHeaders
{
    // -----------------------------------------------------------------------
    // DIE CDNs, VON DENEN DIE ANWENDUNG TATSAECHLICH LAEDT.
    //
    // Ausgezaehlt aus assets/html/index.html - das ist die einzige Stelle, an
    // der externe Adressen im <head> stehen. Wer dort eine Bibliothek
    // hinzufuegt, traegt sie HIER ein, sonst blockiert der Browser sie
    // (bzw. meldet sie, solange CSP_MODE auf "melden" steht).
    //
    //   ajax.googleapis.com   jQuery 3.6.0
    //   unpkg.com             Leaflet, leaflet-pip
    //   cdn.jsdelivr.net      select2, Bootstrap 5.3.6
    //   cdn.datatables.net    DataTables + Responsive
    //
    // Vier Quellen sind vier Stellen, an denen ein fremdes Skript in die
    // Anwendung geraet, wenn dort jemand einbricht. Der saubere Weg waere,
    // die Bibliotheken mitzuliefern und nur noch 'self' zu erlauben - das ist
    // eine Umbauentscheidung und gehoert nicht in diese Aenderung. Bis dahin
    // steht hier wenigstens die abgeschlossene Liste.
    // -----------------------------------------------------------------------

    /** Herkunft der eingebundenen Skripte. */
    private const CDN_SKRIPTE = [
        'https://ajax.googleapis.com',
        'https://unpkg.com',
        'https://cdn.jsdelivr.net',
        'https://cdn.datatables.net',
    ];

    /** Herkunft der eingebundenen Stilvorlagen. */
    private const CDN_STILE = [
        'https://unpkg.com',
        'https://cdn.jsdelivr.net',
        'https://cdn.datatables.net',
    ];

    /**
     * Die Kartenkacheln.
     *
     * Leaflet baut die Adressen aus '{s}.tile.openstreetmap.org' zusammen -
     * das {s} wird zu a, b oder c. Deshalb der Platzhalter statt drei
     * Eintraegen; die Adresse steht in assets/js/map.js, home_map.js,
     * location_page.js und locations_table.js.
     */
    private const KARTEN_KACHELN = 'https://*.tile.openstreetmap.org';

    /** Die erlaubten Werte von CSP_MODE. */
    private const MODI = ['aus', 'melden', 'scharf'];

    /**
     * Setzt alle Kopfzeilen.
     *
     * @return void
     */
    public static function senden(): void
    {
        // Auf der Kommandozeile gibt es keine Kopfzeilen (Cronjob, Tests).
        if (PHP_SAPI === 'cli') return;

        // Ist die Ausgabe schon unterwegs, kommt kein Header mehr durch. Das
        // waere ein Fehler im Ablauf (eine Ausgabe vor dieser Stelle) und
        // gehoert ins Log, nicht in eine PHP-Warning auf der Seite.
        if (headers_sent()) {
            error_log('SecurityHeaders: Die Ausgabe hat bereits begonnen - '
                . 'die Sicherheitskopfzeilen fehlen in dieser Antwort.');
            return;
        }

        // --- Der Rahmenschutz. Zweimal dasselbe, mit Absicht ---------------
        // frame-ancestors (unten in der CSP) ist der Nachfolger und kennt
        // mehr Faelle; X-Frame-Options ist der Vorgaenger und wird von
        // aelteren Browsern noch verstanden. Der Vorgaenger steht hier
        // AUSSERHALB der CSP, damit er auch dann wirkt, wenn die CSP nur
        // meldet oder ganz aus ist - der Rahmenschutz ist der eine Punkt,
        // der bei dieser Anwendung nicht warten darf.
        header('X-Frame-Options: DENY');

        // Der Browser haelt sich an den gemeldeten Dateityp und raet nicht.
        // Ohne das kann eine Datei, die als text/plain ausgeliefert wird, als
        // HTML oder JavaScript interpretiert werden - der klassische Weg,
        // ueber den ein Upload zu einem Skript wird.
        header('X-Content-Type-Options: nosniff');

        // Beim Klick auf einen fremden Link (und beim Laden der Kartenkacheln)
        // erfaehrt die Gegenseite nur noch die Herkunft, nicht den ganzen
        // Pfad. Eine Standort-Adresse wie index.php?act=location_page&id=42
        // wandert damit nicht mehr in die Logs fremder Server.
        header('Referrer-Policy: strict-origin-when-cross-origin');

        header('Permissions-Policy: ' . self::permissionsPolicy());

        $hsts = Https::hstsHeader();
        if ($hsts !== null) header('Strict-Transport-Security: ' . $hsts);

        $modus = self::modus();
        if ($modus === 'aus') return;

        $name = ($modus === 'scharf')
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        header($name . ': ' . self::csp());
    }

    /**
     * Welcher CSP-Modus gilt?
     *
     * DREI ZUSTAENDE, UND DER MITTLERE IST DIE VORGABE:
     *
     *   aus     Kein Header. Fuer den Fall, dass die Regel etwas blockiert,
     *           was gebraucht wird, und der Betrieb nicht warten kann.
     *   melden  Content-Security-Policy-Report-Only. Der Browser BLOCKIERT
     *           NICHTS und schreibt jeden Verstoss in seine Konsole.
     *   scharf  Content-Security-Policy. Der Browser blockiert.
     *
     * WARUM "melden" UND NICHT "scharf" DIE VORGABE IST
     * -------------------------------------------------
     * Eine zu enge CSP legt die Anwendung lahm, und zwar lautlos: Der Browser
     * blockiert, die Seite bleibt halb leer, im Serverlog steht nichts. Bei
     * dieser Anwendung ist der heikelste Punkt nicht einmal sichtbar - Chrome
     * prueft die TURN- und STUN-Server eines RTCPeerConnection gegen
     * connect-src. Eine Regel, die dort etwas vergisst, nimmt keiner Seite
     * ihr Aussehen, sondern jedem zweiten Anruf seine Verbindung.
     *
     * Deshalb laeuft die Regel erst mit, bevor sie greift. Der Weg auf
     * "scharf" steht in der README (Abschnitt "Sicherheitskopfzeilen").
     *
     * @return string 'aus', 'melden' oder 'scharf'
     */
    public static function modus(): string
    {
        return Env::auswahl('CSP_MODE', self::MODI, 'melden');
    }

    /**
     * Baut die Content-Security-Policy.
     *
     * Oeffentlich, weil die README und die Tests denselben Text brauchen wie
     * der Browser: Eine Regel, die man nachschlagen muss, um sie zu pruefen,
     * wird nicht geprueft.
     *
     * @return string
     */
    public static function csp(): string
    {
        $skripte = implode(' ', self::CDN_SKRIPTE);
        $stile   = implode(' ', self::CDN_STILE);

        $regeln = [
            // Grundregel: Alles, was keine eigene Zeile hat, kommt von hier.
            "default-src 'self'",

            // -------------------------------------------------------------
            // SKRIPTE. 'unsafe-inline' ist hier keine Bequemlichkeit, sondern
            // der Ist-Zustand: Die Anwendung setzt acht Skriptbloecke direkt
            // ins Dokument - das Farbprofil vor dem ersten Zeichnen
            // (App\Helper\Theme::bootScript), die Uebergabewerte an den
            // Client (window.userId, heartbeatIntervalMs, requestCounts,
            // chatCounts, reviewScale in App\Helper\ViewHelper), die Daten
            // der Standortseite (App\Helper\LocationView) und ein
            // window.requestsPage in assets/html/requests_page.html - dazu
            // ein onclick-Attribut in App\Controller\UserController.
            //
            // WAS DAS BEDEUTET: Gegen eingeschleustes Inline-JavaScript
            // schuetzt diese Regel NICHT. Sie schuetzt gegen das Nachladen
            // von einer fremden Adresse - der haeufigere Fall.
            //
            // DER WEG ZUR STRENGEN REGEL waere ein Nonce je Anfrage: Jeder
            // der acht Bloecke bekaeme ihn mitgegeben, das onclick-Attribut
            // muesste einem Event-Listener weichen (ein Nonce wirkt nicht auf
            // Attribute). Das ist eine Codeaenderung an acht Stellen und
            // steht bewusst nicht in dieser Aenderung.
            // -------------------------------------------------------------
            "script-src 'self' 'unsafe-inline' $skripte",

            // STILE. 'unsafe-inline' aus demselben Grund: In den
            // HTML-Bausteinen stehen style-Attribute (call_controll.html,
            // settings.html, set_location.html und weitere), und Bootstrap
            // wie select2 setzen zur Laufzeit eigene. Ein Stilangriff ist
            // deutlich harmloser als ein Skriptangriff.
            "style-src 'self' 'unsafe-inline' $stile",

            // BILDER. data: fuer die Symbole in assets/css/theme.css - die
            // liegen als SVG in den CSS-Variablen und nicht als Datei.
            // Dazu die Kartenkacheln.
            "img-src 'self' data: " . self::KARTEN_KACHELN,

            // SCHRIFTEN. Die Anwendung bindet keine externe Schrift ein
            // (kein @font-face, kein fonts.googleapis.com) - also 'self'.
            "font-src 'self'",

            // TOENE. assets/audio/*.mp3 liegen im Projekt. blob: steht
            // daneben, weil der Chat empfangene Dateien ueber
            // URL.createObjectURL anbietet (assets/js/chat.js).
            "media-src 'self' blob:",

            // -------------------------------------------------------------
            // VERBINDUNGEN. 'self' deckt alles ab, was der Client an den
            // Server schickt - Signaling, Heartbeat, Chat, alles laeuft ueber
            // fetch auf index.php.
            //
            // stun:, turn: und turns: stehen daneben, WEIL CHROME DIE
            // ICE-SERVER EINER RTCPeerConnection GEGEN connect-src PRUEFT.
            // Ohne diese drei Schemata kaeme keine Verbindung ueber ein
            // fremdes Netz zustande, und der Fehler saehe aus wie ein
            // Netzproblem.
            //
            // WARUM DAS GANZE SCHEMA UND KEINE EINZELNEN ADRESSEN: Die
            // TURN-Zugaenge holt der Server zur Laufzeit bei Metered ab
            // (App\Model\MeteredTurnService); welcher Host antwortet, steht
            // vorher nicht fest und aendert sich. Eine Liste hier waere eine
            // Liste, die irgendwann nicht mehr stimmt - und dann fallen
            // Anrufe aus, ohne dass jemand diese Datei im Verdacht hat.
            // -------------------------------------------------------------
            "connect-src 'self' stun: turn: turns:",

            // Kein <object>, kein <embed>, kein Flash-Erbe.
            "object-src 'none'",

            // Der Nachfolger von X-Frame-Options - siehe senden().
            "frame-ancestors 'none'",

            // Ein eingeschleustes <base href="..."> koennte sonst jeden
            // relativen Pfad der Seite auf einen fremden Server umbiegen.
            "base-uri 'self'",

            // Formulare gehen an die eigene Anwendung. Ein untergeschobenes
            // action="https://fremd.example" traegt sonst Anmeldedaten hinaus.
            "form-action 'self'",
        ];

        return implode('; ', $regeln);
    }

    /**
     * Baut die Permissions-Policy.
     *
     * KAMERA UND MIKROFON MUESSEN ERLAUBT BLEIBEN - ohne sie gibt es keine
     * Fuehrung. "self" heisst: nur das eigene Dokument, kein eingebetteter
     * Fremdinhalt. Ein leeres Klammerpaar heisst "niemand".
     *
     * Aufgezaehlt wird, was die Anwendung BRAUCHT, und danach das, was sie
     * ausdruecklich nicht braucht. Was hier gar nicht steht, regelt der
     * Browser nach seiner eigenen Vorgabe - die Liste ist eine Aussage ueber
     * diese Anwendung, kein Versuch, jede Browserfunktion aufzuzaehlen.
     *
     * @return string
     */
    private static function permissionsPolicy(): string
    {
        return implode(', ', [
            // Gebraucht: der Kern der Anwendung.
            'camera=(self)',
            'microphone=(self)',

            // Gebraucht: "Mein Standort" auf der Karte
            // (navigator.geolocation in assets/js/map.js).
            'geolocation=(self)',

            // Gebraucht: die Tonsignale der Steuerung (assets/js/sound.js)
            // und der Klingelton bei einer eingehenden Anfrage.
            'autoplay=(self)',

            // Nicht gebraucht: Die Anwendung teilt keinen Bildschirm - sie
            // uebertraegt die Kamera. Ein getDisplayMedia-Aufruf kommt im
            // ganzen Projekt nicht vor.
            'display-capture=()',

            // Nicht gebraucht, und im Missbrauchsfall besonders unangenehm.
            'payment=()',
            'usb=()',
            'serial=()',
            'midi=()',

            // Nicht gebraucht: Die Blickrichtung steuert der Zuschauer ueber
            // Tasten, nicht ueber die Lage des Geraets (siehe PROTOKOLL.md).
            'accelerometer=()',
            'gyroscope=()',
            'magnetometer=()',
        ]);
    }
}
