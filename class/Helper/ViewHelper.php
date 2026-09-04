<?php
namespace App\Helper;

use App\Model\GuideRole;
use App\Model\TourRequest;
use App\Model\User;
use App\Helper\Theme;

/**
 * Hilfsklasse für die View-Generierung.  
 * Fügt Content in HTML-Layouts ein und ersetzt Platzhalter durch dynamische Inhalte.
 */
class ViewHelper
{
    /**
     * Prüft, ob das Template erfolgreich geladen wurde.
     * Gibt bei Fehler einen Log-Eintrag aus und beendet das Skript mit einer Fehlermeldung.
     *
     * @param mixed $out      Rückgabewert von file_get_contents
     * @param string $template Dateipfad des Templates
     */
    public static function checkTemplate($out, $template) {
        if ($out === false) {
            error_log('Template konnte nicht geladen werden: ' . $template);
            die('Interner Fehler. Bitte versuchen Sie es später erneut.');
        }
    }

    /**
     * Laedt eine Vorlage aus assets/html und entfernt den Kommentarblock,
     * mit dem sie dokumentiert ist.
     *
     * WARUM DAS NOETIG IST
     * --------------------
     * Die Vorlagen tragen am Anfang einen Kommentar, der ihre Platzhalter
     * erklaert - dort steht also der Text ###USER_ROWS### auch als
     * Beschreibung. str_replace() kennt aber keine Kommentare: Es ersetzt
     * JEDES Vorkommen, auch das in der Beschreibung. Damit landete der
     * Inhalt zweimal in der Seite.
     *
     * Unsichtbar blieb die zweite Fuellung nur so lange, wie sie selbst
     * kein "-->" enthielt. Genau das war bei der Benutzerliste der Fall:
     * Die eingesetzten Zeilen brachten den Kommentarkopf von
     * list_user_row.html mit, dessen "-->" den aeusseren Kommentar vorzeitig
     * schloss. Ab da war alles sichtbar - die Zeilen ein zweites Mal und der
     * Rest des Kommentartextes als nackter Text ueber der Ueberschrift.
     *
     * Statt die Beschreibungen zu verstuemmeln, wird der Kommentar hier
     * entfernt, bevor irgendetwas ersetzt wird. Die Dokumentation bleibt in
     * der Datei, wo sie hingehoert, und kommt nicht mehr im Browser an.
     * Nebenbei steht der Kommentar einer Zeilenvorlage jetzt nicht mehr
     * einmal pro Tabellenzeile im Dokument.
     *
     * Entfernt werden nur Kommentare VOR dem ersten Element - ein Kommentar
     * mitten im Markup ist eine bewusste Anmerkung an Ort und Stelle und
     * bleibt stehen.
     *
     * @param string $pfad Pfad zur Vorlage, z. B. 'assets/html/login.html'
     * @return string Der Inhalt ohne den einleitenden Kommentarblock
     */
    public static function template(string $pfad): string
    {
        $roh = file_get_contents($pfad);
        self::checkTemplate($roh, $pfad);

        // ^\s*(<!--...-->\s*)+ : ein oder mehrere Kommentarbloecke am Anfang.
        // Das "U" macht .* genuegsam, sonst reichte der Treffer bis zum
        // letzten "-->" der Datei.
        return ltrim(preg_replace('/^\s*(?:<!--.*-->\s*)+/Us', '', $roh));
    }

    /**
     * Baut das Benutzermenue der Kopfleiste.
     *
     * Enthaelt die Eintraege, die zum eigenen Konto gehoeren. Welche Route
     * dahinter wirklich erlaubt ist, entscheidet weiterhin index.php - ein
     * Eintrag hier ist Anzeige, keine Berechtigung.
     *
     * WAS HIER NICHT MEHR STEHT
     * -------------------------
     * "Alle Chats" und "Benutzerliste". Beide fuehrten an der Karte vorbei
     * zu einer Liste von Konten - und die Benutzerliste bot dort zu jedem
     * Konto einen Anruf-Knopf an. Ein so zustande gekommener Anruf hatte
     * keinen Ortsbezug, und der Angerufene wurde im Call zum Guide erklaert,
     * ohne der Rolle je zugestimmt zu haben (App\Controller\WebRTCController).
     * Der Weg ins Gespraech fuehrt jetzt ausschliesslich ueber einen Standort
     * auf der Karte.
     *
     * Ein bestehender Chat geht dadurch nicht verloren: Eine Einladung oeffnet
     * sich weiterhin von selbst als Fenster (assets/js/ui_chat.js).
     *
     * Die Benutzerliste bleibt fuer den Admin stehen - er verwaltet darueber
     * Konten und braucht den Einstieg. Entschieden wird das ueber das Recht
     * user.list, das nur noch er hat, und nicht ueber eine Rollenabfrage.
     *
     * @param string $username Der anzuzeigende Name (wird maskiert)
     * @return string HTML
     */
    private static function userMenu($username): string
    {
        $name = htmlspecialchars($username);

        $eintraege = [
            'index.php?act=settings' => 'Mein Konto',
        ];
        if (Auth::can(Permission::USER_LIST)) {
            $eintraege['index.php?act=list_user'] = 'Benutzerliste';
        }

        $links = '';
        foreach ($eintraege as $ziel => $titel) {
            $links .= '<a class="app-menu__item" href="' . $ziel . '">' . $titel . '</a>';
        }

        return '<details class="app-menu" id="user-menu">'
             .   '<summary class="app-menu__button">'
             .     '<span class="app-menu__avatar" aria-hidden="true">'
             .        htmlspecialchars(mb_strtoupper(mb_substr($username, 0, 1)))
             .     '</span>'
             .     '<span class="app-menu__name">' . $name . '</span>'
             .     '<span class="app-menu__caret" aria-hidden="true"></span>'
             .   '</summary>'
             .   '<div class="app-menu__list" role="menu">'
             .     '<div class="app-menu__head">Angemeldet als <strong>' . $name . '</strong></div>'
             .     $links
             .     '<div class="app-menu__sep"></div>'
             .     '<a class="app-menu__item app-menu__item--danger" href="index.php?act=logout">Abmelden</a>'
             .   '</div>'
             . '</details>';
    }

    /**
     * Baut den Anfragenzaehler der Kopfleiste.
     *
     * WARUM ER IN DER KOPFLEISTE STEHT
     * --------------------------------
     * Weil eine Anfrage sonst verlorengeht. Der Guide sieht sie im Moment des
     * Eintreffens vielleicht nicht - er steht im Supermarkt, der Tab liegt im
     * Hintergrund. Sie muss deshalb an einer Stelle wieder auftauchen, die er
     * im Alltag ohnehin ansteuert, und das ist die Kopfleiste: Sie steht auf
     * jeder Seite der Anwendung. Dieselbe Ueberlegung wie beim
     * Bereitschaftsschalter daneben, mit dem er die Zeile teilt.
     *
     * ZWEI ZAHLEN, EIN ZAEHLER. Er meint immer dasselbe: "hier wartet etwas
     * auf dich".
     *
     *   eingehend   Anfragen an die eigenen Standorte, die noch keine Antwort
     *               haben - der Guide ist am Zug.
     *   ausgehend   eigene Anfragen, die angenommen wurden - der Kunde kann
     *               losgehen.
     *
     * Ein Konto kann beides zugleich sein, und deshalb steht der Zaehler bei
     * JEDEM angemeldeten Konto und nicht nur bei Guides: Auch ein Zuschauer
     * muss sehen, dass seine Anfrage angenommen wurde. Ohne diese Auskunft
     * muesste er die Standortseite offen halten und hoffen.
     *
     * SERVERSEITIG MIT SEINEM STAND AUSGELIEFERT, wie der Schalter daneben:
     * Wer die Seite ohne Skript oeffnet, sieht trotzdem, dass etwas ansteht -
     * nur nachgezogen wird die Zahl dann nicht (assets/js/requests.js holt sie
     * sich aus der Antwort des Heartbeats).
     *
     * @param array{incoming_open:int, outgoing_accepted:int} $zahlen
     * @return string HTML
     */
    private static function requestsBadge(array $zahlen): string
    {
        $eingehend = max(0, (int)($zahlen['incoming_open'] ?? 0));
        $ausgehend = max(0, (int)($zahlen['outgoing_accepted'] ?? 0));
        $summe     = $eingehend + $ausgehend;

        // Der Titel sagt, WAS wartet - die Zahl allein sagt es nicht. Er wird
        // im Browser mit derselben Regel neu gebaut (requests.js), damit an
        // beiden Stellen dasselbe steht.
        $titel = 'Ihre Anfragen';
        if ($eingehend > 0 && $ausgehend > 0) {
            $titel = $eingehend . ' Anfrage(n) warten auf Ihre Antwort, '
                   . $ausgehend . ' Ihrer Anfragen wurde(n) angenommen';
        } elseif ($eingehend > 0) {
            $titel = $eingehend . ' Anfrage(n) warten auf Ihre Antwort';
        } elseif ($ausgehend > 0) {
            $titel = $ausgehend . ' Ihrer Anfragen wurde(n) angenommen';
        }

        return '<a class="app-requests' . ($summe > 0 ? ' app-requests--on' : '') . '"'
             . ' id="requests-badge" href="index.php?act=requests_page"'
             . ' data-incoming="' . $eingehend . '" data-outgoing="' . $ausgehend . '"'
             . ' title="' . htmlspecialchars($titel) . '">'
             .   '<span class="app-requests__text">Anfragen</span>'
             .   '<span class="app-requests__count" id="requests-count"'
             .     ($summe > 0 ? '' : ' hidden') . '>' . $summe . '</span>'
             . '</a>';
    }

    /**
     * Baut den Bereitschaftsschalter der Kopfleiste.
     *
     * WARUM IN DER KOPFLEISTE UND NICHT AUF DER KONTOSEITE
     * ---------------------------------------------------
     * Weil er zwei Aufgaben hat, und die zweite verlangt staendige
     * Sichtbarkeit: Er SCHALTET die Bereitschaft, und er ZEIGT sie an. Ein
     * Schalter in den Einstellungen koennte das Erste, aber nicht das Zweite -
     * ein Guide, dessen Bereitschaft abgelaufen ist, wuerde es dort nie
     * bemerken, weil er die Seite nicht aufhat. Die Kopfleiste steht auf jeder
     * Seite der Anwendung; damit ist die Restzeit immer im Blick.
     *
     * ER STEHT AUSSERHALB DER SCHIEBBAREN AKTIONSZEILE, gleich neben dem
     * Benutzermenue. Auf einem schmalen Geraet duerfen "Standort anbieten" und
     * "Alle Standorte" weggeschoben werden - die Auskunft, ob man gerade
     * anrufbar ist, nicht.
     *
     * ER WIRD SERVERSEITIG MIT SEINEM ZUSTAND AUSGELIEFERT und nicht erst von
     * JavaScript gefuellt. Wer die Seite mit abgeschaltetem oder
     * fehlgeschlagenem Skript oeffnet, sieht damit immer noch richtig, ob er
     * bereit ist - nur der Sekundenzaehler steht dann still.
     *
     * Nur fuer Konten mit dem Recht user.availability. Ein Zuschauer haette
     * hier einen Schalter, der nichts faerbt: Seine Bereitschaft haengt an
     * keinem Standort.
     *
     * @param int $sekunden Verbleibende Bereitschaft; 0 heisst "nicht bereit"
     * @return string HTML
     */
    private static function availabilitySwitch(int $sekunden): string
    {
        $bereit = $sekunden > 0;

        // Der Zustand steht doppelt am Element: als Klasse fuer das Auge und
        // als aria-pressed fuer Vorleseprogramme. Ein Punkt allein waere fuer
        // sie nichts.
        return '<button type="button" class="app-ready' . ($bereit ? ' app-ready--on' : '') . '"'
             . ' id="availability-toggle"'
             . ' aria-pressed="' . ($bereit ? 'true' : 'false') . '"'
             . ' data-seconds="' . $sekunden . '"'
             . ' title="' . ($bereit
                 ? 'Sie sind als Guide anrufbar. Klicken beendet die Bereitschaft.'
                 : 'Sie sind nicht anrufbar. Klicken stellt Sie auf bereit.') . '">'
             .   '<span class="app-ready__dot" aria-hidden="true"></span>'
             .   '<span class="app-ready__text" id="availability-text">'
             .     ($bereit ? 'Bereit' : 'Nicht bereit')
             .   '</span>'
             .   '<span class="app-ready__rest" id="availability-rest"></span>'
             . '</button>';
    }

    /**
     * Ersetzt die ###CONTENT###-Platzhalter im Hauptlayout mit dem übergebenen Content und gibt das HTML aus.
     * Ergänzt außerdem Benutzerstatus, Login/Logout-Links, Call- und Mediensteuerung sowie User-Infos.
     *
     * @param string $in_content Inhalt, der ins Layout eingesetzt wird.
     * 
     * Platzhalter im Template:
     *   ###CONTENT###, ###CALL_CONTROLL###, ###INNER_CALL_CONTROLL###, ###MEDIA###,
     *   ###USERSTATUS###, ###LOGOUT###, ###USER###, ###REGISTER###, ###THEME###
     */
    public static function output($in_content)
    {
        // Hauptlayout laden (enthält die Platzhalter)
        $out = self::template("assets/html/index.html"); 
        $out = str_replace("###CONTENT###", $in_content, $out);

        // Standardlinks (nicht angemeldet)
        // Gruen ist in dieser Anwendung das Zeichen fuer "ein Guide ist jetzt
        // erreichbar" (siehe assets/css/theme.css). Deshalb traegt die
        // Registrierung den Akzent und nicht die Live-Farbe.
        $sign      = "<a href='index.php?act=signup_page' class='btn btn-primary btn-sm'>Registrieren</a>";
        $user_txt  = "";
        $text      = "<a href='index.php?act=login_page' class='btn btn-secondary btn-sm'>Anmelden</a>";
        $menu_html = "";
        $call      = "";
        $inner_call= "";
        $media     = "";
        // Der Bereitschaftsschalter. Fuer Gaeste und fuer alle, die keine
        // Standorte anbieten, bleibt er leer - siehe availabilitySwitch().
        $ready     = "";
        // Der Anfragenzaehler. Fuer Gaeste leer: Wer nicht angemeldet ist, hat
        // keine Anfragen - weder gestellte noch erhaltene.
        $requests  = "";

        // Das Farbprofil des ANGEMELDETEN Kontos - fuer Gaeste bleibt es
        // null. Das ist der Unterschied, den das Boot-Skript braucht:
        // "Konto sagt Indigo" und "niemand angemeldet" muessen unterscheidbar
        // sein, sonst ueberschriebe ein Gastaufruf die lokale Wahl.
        $theme = null;

        $logged_in     = 'false';
        $user_role     = null;
        $user_role_id  = null;
        $user_id_script= null;

        // Prüfen, ob ein Nutzer eingeloggt ist
        if (Auth::isLoggedIn()) {
            $user = new User(Auth::userId());
            $logged_in = 'true';

            // Aus DEM Datensatz, der ohnehin geladen wird - keine zweite
            // Abfrage. Ein Konto ohne Wahl bleibt null: Dann gilt weiterhin
            // die lokale Wahl bzw. die Vorgabe des Betriebssystems, statt
            // dass die Anmeldung sie stillschweigend auf Indigo zurueckstellt.
            $roh   = $user->getTheme();
            $theme = Theme::isValid($roh) ? $roh : null;

            // Das Benutzermenue. Es ersetzt die frueheren Einzelknoepfe
            // "Mein Account", "Benutzerliste" und "Abmelden" in der
            // Kopfleiste - das sind seltene Aktionen, und nebeneinander
            // gestellt sahen sie so wichtig aus wie das Anrufen.
            //
            // Gebaut aus <details>/<summary> und nicht mit JavaScript: So
            // laesst es sich mit der Tastatur bedienen und geht auch dann
            // auf, wenn ein Skript nicht geladen wurde. Das Abmelden darf
            // nicht daran haengen, dass eine Bibliothek erreichbar war.
            // assets/js/ui.js schliesst es nur zusaetzlich beim Klick
            // daneben.
            $menu_html = self::userMenu($user->getUsername());

            // Fuer Gaeste bleiben die beiden Knoepfe; angemeldet sind sie im
            // Menue aufgehoben.
            $text = '';
            $sign = '';

            // Die Rolle kommt als usertype.id aus dem geladenen Benutzer und
            // wird ueber den zentralen Helfer normalisiert. Frueher stand hier
            // getUsertype(), also der rohe Name aus der Datenbank - genau der
            // ging in ui.js gegen kleingeschriebene Literale und traf nie zu
            // (Befund F-5).
            //
            // Gelesen wird die Rolle aus der Sitzung und nicht aus dem eben
            // geladenen Datensatz: Beides ist derselbe Wert, aber die Sitzung
            // ist die Quelle, gegen die auch index.php prueft. Zwei Quellen
            // koennten auseinanderlaufen.
            $user_role_id = Auth::roleId();
            $user_role    = Role::name($user_role_id);

            // Zusätzliche Steuerelemente für eingeloggte User laden
            $call        = self::template('assets/html/call_controll.html');
            self::checkTemplate($call, 'assets/html/call_controll.html');

            $inner_call  = self::template('assets/html/inner_call_controll.html');
            self::checkTemplate($inner_call, 'assets/html/inner_call_controll.html');

            $media       = self::template('assets/html/media.html');
            self::checkTemplate($media, 'assets/html/media.html');

            // User-ID als JS-Variable bereitstellen
            $user_id_script = '<script>window.userId = ' . Auth::userId() . ';</script>';

            // Heartbeat-Takt aus derselben Konfiguration, aus der sich auch
            // der Cronjob seinen Timeout holt (config/presence.php). Sonst
            // waeren Takt und Timeout zwei unabhaengige Zahlen in zwei
            // Dateien, die niemand zusammen pflegt.
            $presence = require __DIR__ . '/../../config/presence.php';
            $user_id_script .= '<script>window.heartbeatIntervalMs = '
                . ((int)$presence['heartbeat_interval'] * 1000) . ';</script>';

            // DIE BEREITSCHAFT. Sie ist etwas anderes als der Heartbeat
            // darueber: Der meldet ein laufendes Programm, diese hier ist eine
            // Entscheidung des Guides (siehe config/presence.php).
            //
            // Gefragt wird das Recht und nicht die Rolle - dasselbe Kriterium,
            // ueber das ein Standort auf die Karte kommt. Wer keine Standorte
            // anbietet, bekommt den Schalter nicht.
            // DER ANFRAGENZAEHLER - fuer jedes angemeldete Konto, auch fuer
            // eines ohne Standorte: Es kann selbst angefragt haben, und die
            // Zusage darauf soll es nicht verpassen. Gefragt wird deshalb das
            // Recht request.list und nicht location.offer.
            if (Auth::can(Permission::REQUEST_LIST)) {
                $zahlen   = TourRequest::counters(Auth::userId());
                $requests = self::requestsBadge($zahlen);

                // Die beiden Zahlen gehen als Startwert mit. Ohne sie muesste
                // das Skript beim Seitenaufbau erst einmal fragen, was der
                // Server gerade ausgeliefert hat.
                $user_id_script .= '<script>window.requestCounts = '
                    . json_encode($zahlen) . ';</script>';
            }

            if (Auth::can(Permission::USER_AVAILABILITY)) {
                // Der Zustand kommt aus der Datenbank und nicht aus der
                // Sitzung: Er kann seit dem Anmelden abgelaufen sein, und die
                // Frist laeuft an der Uhr der Datenbank.
                $sekunden = User::availableSeconds(Auth::userId());
                $ready    = self::availabilitySwitch($sekunden);

                // Zwei Zahlen fuer den Browser: die Restzeit von jetzt an und
                // die volle Frist. Die zweite braucht er, um den Balken nach
                // dem Einschalten sofort richtig zu zeichnen, ohne auf den
                // naechsten Heartbeat zu warten.
                $user_id_script .= '<script>'
                    . 'window.availableSeconds = ' . (int)$sekunden . ';'
                    . 'window.availabilityTimeoutMs = '
                    . ((int)$presence['availability_timeout'] * 1000) . ';'
                    . '</script>';
            }
        }

        // JavaScript-Variablen für Frontend bereitstellen (Login-Status, User-ID, Rolle)
        //
        // Neben Name und ID der Rolle gehen die Rechte mit ins Frontend. Sie
        // kommen aus derselben Rechtetabelle, gegen die index.php prueft -
        // eine zweite Rollentabelle in JavaScript koennte auseinanderlaufen.
        //
        // window.userCan entscheidet nur ueber die ANZEIGE. Ein Knopf, der
        // hier nicht erscheint, ist keine Absicherung: Die verbindliche
        // Pruefung steht in index.php und passiert erneut, wenn die Route
        // wirklich aufgerufen wird.
        $can = [
            'offerLocation' => Auth::can(Permission::LOCATION_OFFER),
            // Darf dieses Konto sich auf bereit stellen? Der Schalter selbst
            // wird serverseitig gebaut; das Skript braucht die Auskunft, um
            // sich bei allen anderen gar nicht erst einzuhaengen.
            'setAvailability' => Auth::can(Permission::USER_AVAILABILITY),
            'becomeGuide'   => Role::mayBecomeGuide($user_role_id),
            'blockLocation' => Auth::can(Permission::LOCATION_BLOCK),
            'manageUsers'   => Auth::can(Permission::USER_MANAGE),
            // Guide, dessen Zustimmung eine aeltere Fassung der Bedingungen
            // traegt (App\Model\GuideRole::TERMS_VERSION). Er darf weiterhin
            // alles, was ein Guide darf - nur sein naechster Standort geht
            // erst durch, wenn er zugestimmt hat
            // (GuideController::requireCurrentTerms). Der Knopf der Kopfleiste
            // sagt ihm das, bevor er am gesperrten Formular ankommt.
            //
            // Nur fuer Guides: Ein Trial-Konto meldet needsDecision() ebenfalls,
            // aber bei ihm ist nichts "veraltet" - es hat die Frage schlicht
            // noch nicht beantwortet, und dafuer gibt es becomeGuide.
            'termsOutdated' => Role::isGuide($user_role_id)
                && GuideRole::needsDecision(Auth::userId(), $user_role_id),
        ];

        $logged_in_script = '<script>window.isLoggedIn = ' . $logged_in . ';</script>' . $user_id_script;
        $user_role_script = '<script>'
            . 'window.userRole = ' . json_encode($user_role) . ';'
            . 'window.userRoleId = ' . ($user_role_id === null ? 'null' : (int)$user_role_id) . ';'
            . 'window.userCan = ' . json_encode($can) . ';'
            . '</script>' . $logged_in_script;

        // Platzhalter im Template ersetzen
        $out = str_replace("###CALL_CONTROLL###"       , $call             , $out);
        $out = str_replace("###INNER_CALL_CONTROLL###" , $inner_call       , $out);
        $out = str_replace("###MEDIA###"               , $media            , $out);
        $out = str_replace("###USERSTATUS###"          , $user_role_script , $out);
        $out = str_replace("###LOGOUT###"              , $text             , $out);
        $out = str_replace("###USER###"                , $menu_html        , $out);
        $out = str_replace("###REGISTER###"            , $sign             , $out);
        $out = str_replace("###AVAILABILITY###"        , $ready            , $out);
        $out = str_replace("###REQUESTS###"            , $requests         , $out);
        // Das Farbprofil. Zwei Stellen, und beide sind noetig:
        //
        //   ###THEME###      das Attribut am <html>-Element. Angemeldet steht
        //                    hier der Kontowert, damit die Seite schon
        //                    richtig ausgeliefert wird. Fuer Gaeste bleibt es
        //                    leer.
        //   ###THEME_BOOT### ein kleines Skript im <head>, das fuer Gaeste
        //                    den lokalen Wert bzw. die Vorgabe des
        //                    Betriebssystems einsetzt - und beim Anmelden den
        //                    lokalen Wert auf den Kontowert zieht.
        //
        // Beides laeuft vor dem ersten Zeichnen. Ein Skript am Seitenende
        // waere zu spaet: Der Nutzer saehe die helle Seite aufblitzen.
        $out = str_replace("###THEME###"     , $theme ?? Theme::DEFAULT      , $out);
        $out = str_replace("###THEME_BOOT###", Theme::bootScript($theme)     , $out);

        // Ausgabe und Script-Beendigung
        die($out); 
    }

}
