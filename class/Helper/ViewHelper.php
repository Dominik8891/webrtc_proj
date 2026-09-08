<?php
namespace App\Helper;

use App\Model\Chat;
use App\Model\GuideRole;
use App\Model\TourRequest;
use App\Model\TourReview;
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
     * Maskiert Text fuer die Ausgabe in HTML.
     *
     * ZWEI DINGE, nicht eines:
     *
     * 1. htmlspecialchars mit ENT_QUOTES. Der uebliche Teil - spitze
     *    Klammern und beide Anfuehrungszeichen, damit ein Text weder ein
     *    Element noch ein Attribut beenden kann.
     *
     * 2. Drei Rautenzeichen werden unschaedlich gemacht. DAS IST DER TEIL,
     *    DEN MAN VERGISST: Diese Anwendung baut ihre Seiten mit str_replace
     *    ueber Platzhalter der Form ###NAME###, und output() weiter unten
     *    laeuft NACH jedem Controller ueber das gesamte Dokument. Ein Text,
     *    in dem jemand "###USER###" schreibt, bekaeme sonst an dieser Stelle
     *    das Benutzermenue eingesetzt - Fremdeingabe, die eine Ersetzung des
     *    Servers ausloest. htmlspecialchars sieht das nicht: An einer Raute
     *    ist nichts gefaehrlich, gefaehrlich ist sie nur in DIESEM
     *    Bauverfahren.
     *
     *    Ersetzt wird durch die HTML-Entitaet: Im Browser steht danach
     *    wieder "###USER###", im Dokument aber nicht mehr das Muster, auf
     *    das str_replace anspringt.
     *
     * DIE EINE FASSUNG DIESER REGEL. App\Helper\LocationView::esc() und
     * App\Helper\GuideView::esc() rufen sie auf, statt sie nachzubauen -
     * eine zweite Fassung waere eine zweite Gelegenheit, den zweiten Teil zu
     * vergessen.
     *
     * @param mixed $in_wert
     * @return string
     */
    public static function esc($in_wert): string
    {
        $text = is_scalar($in_wert) ? (string)$in_wert : '';
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return str_replace('###', '&#35;&#35;&#35;', $text);
    }

    /**
     * Die Rueckmeldung nach dem Speichern - Erfolg oder Fehler.
     *
     * BEIDE WERTE KOMMEN AUS DER ADRESSZEILE, und das ist Absicht: Nach dem
     * Absenden eines Formulars wird weitergeleitet (Post/Redirect/Get),
     * damit ein Neuladen nicht ein zweites Mal speichert. Der Preis dafuer
     * ist, dass die Meldung die Weiterleitung ueberstehen muss - und das tut
     * sie in der Adresse.
     *
     * DER TEXT IST FREMDEINGABE, sobald er dort steht: Jeder kann sich eine
     * Adresse mit beliebigem "fehler=" bauen. Er wird deshalb maskiert (esc)
     * und gekuerzt - ein Kasten mit zweitausend Zeichen waere keine Meldung
     * mehr, sondern eine Flaeche.
     *
     * @param string $in_fehler      Meldung, oder Leerstring
     * @param bool   $in_gespeichert Erfolgreich gespeichert?
     * @return string HTML oder Leerstring
     */
    public static function hinweisHtml(string $in_fehler, bool $in_gespeichert): string
    {
        $fehler = trim($in_fehler);
        if ($fehler !== '') {
            return '<div class="alert alert-danger" role="alert">'
                 . self::esc(mb_substr($fehler, 0, 200)) . '</div>';
        }

        if ($in_gespeichert) {
            return '<div class="alert alert-success" role="alert">Gespeichert.</div>';
        }

        return '';
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
     * Die Chatuebersicht ist trotzdem erreichbar - ueber den
     * Nachrichtenzaehler in der Leiste daneben (chatBadge). Das ist der
     * Unterschied zu einem Menueeintrag: Er steht nicht als Angebot da,
     * sondern faerbt sich, wenn dort etwas wartet.
     *
     * DER EINZIGE EINTRAG, DEN NICHT JEDER SIEHT, ist "Verwaltung" - und
     * genau deshalb steht dort seit dem Umbau EIN Eintrag und nicht mehr die
     * Benutzerliste: Er fuehrt in einen eigenen Bereich mit eigener
     * Navigation (App\Helper\AdminView), in dem alles zusammensteht, was
     * vorher als Sonderfall in der Kundenoberflaeche verteilt lag. Wer ihn
     * sieht, entscheidet das Recht system.admin und keine Rollenabfrage.
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
        if (Auth::can(Permission::SYSTEM_ADMIN)) {
            $eintraege['index.php?act=admin'] = 'Verwaltung';
        }

        $links = '';
        foreach ($eintraege as $ziel => $titel) {
            $links .= '<a class="app-menu__item" href="' . $ziel . '">' . $titel . '</a>';
        }

        return '<details class="app-menu" id="user-menu">'
             .   '<summary class="app-menu__button">'
             // Die Initialen kommen aus App\Helper\Avatar - derselben
             // Klasse, die sie auf der Standort- und der Profilseite baut.
             // Frueher stand hier ein eigenes mb_substr; seitdem gibt es
             // Avatare mit Bild, und "wie sieht der Ersatz aus" ist eine
             // Frage, die nur einmal beantwortet werden darf.
             .     Avatar::html($username, null, 'app-menu__avatar')
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
     * DREI ZAHLEN, EIN ZAEHLER. Er meint immer dasselbe: "hier wartet etwas
     * auf dich".
     *
     *   eingehend   Anfragen an die eigenen Standorte, die noch keine Antwort
     *               haben - der Guide ist am Zug.
     *   ausgehend   eigene Anfragen, die angenommen wurden - der Kunde kann
     *               losgehen.
     *   laufend     eigene Fuehrungen, die begonnen und nicht beendet sind.
     *               Der Guide muss sie beenden; solange er das nicht tut,
     *               bleibt der Startknopf beim Kunden stehen und die Bewertung
     *               wird nie faellig. Wer die Karte nach dem Auflegen
     *               weggeklickt hat, findet die Fuehrung ueber diese Zahl
     *               wieder.
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
     * @param array{incoming_open:int, outgoing_accepted:int, tours_running:int} $zahlen
     * @return string HTML
     */
    private static function requestsBadge(array $zahlen): string
    {
        $eingehend = max(0, (int)($zahlen['incoming_open'] ?? 0));
        $ausgehend = max(0, (int)($zahlen['outgoing_accepted'] ?? 0));
        $laufend   = max(0, (int)($zahlen['tours_running'] ?? 0));
        $summe     = $eingehend + $ausgehend + $laufend;

        // Der Titel sagt, WAS wartet - die Zahl allein sagt es nicht. Er wird
        // im Browser mit derselben Regel neu gebaut (requests.js), damit an
        // beiden Stellen dasselbe steht.
        $teile = [];
        if ($eingehend > 0) {
            $teile[] = $eingehend . ' Anfrage(n) warten auf Ihre Antwort';
        }
        if ($ausgehend > 0) {
            $teile[] = $ausgehend . ' Ihrer Anfragen wurde(n) angenommen';
        }
        // Zuletzt, aber am dringendsten: Eine nicht beendete Fuehrung haelt
        // den Startknopf beim Kunden offen.
        if ($laufend > 0) {
            $teile[] = $laufend . ' Führung(en) sind noch nicht beendet';
        }
        $titel = $teile === [] ? 'Ihre Anfragen' : implode(', ', $teile);

        return '<a class="app-requests' . ($summe > 0 ? ' app-requests--on' : '') . '"'
             . ' id="requests-badge" href="index.php?act=requests_page"'
             . ' data-incoming="' . $eingehend . '" data-outgoing="' . $ausgehend . '"'
             . ' data-running="' . $laufend . '"'
             . ' title="' . htmlspecialchars($titel) . '">'
             .   '<span class="app-requests__text">Anfragen</span>'
             .   '<span class="app-requests__count" id="requests-count"'
             .     ($summe > 0 ? '' : ' hidden') . '>' . $summe . '</span>'
             . '</a>';
    }

    /**
     * Baut den Nachrichtenzaehler der Kopfleiste.
     *
     * WARUM ES IHN GIBT
     * -----------------
     * Weil eine Nachricht sonst nicht ankommt. Ein Guide sah bisher nur dann,
     * dass jemand geschrieben hat, wenn zufaellig gerade ein Chatfenster
     * offen war - die Fenster baut assets/js/ui_chat.js, und wer die Seite
     * gewechselt oder den Tab im Hintergrund liegen hatte, erfuhr nichts. Fuer
     * "ich bekomme Rueckfragen zu meinen Standorten" ist das zu wenig.
     *
     * DIESELBE UEBERLEGUNG WIE BEIM ANFRAGENZAEHLER DANEBEN, mit dem er die
     * Zeile teilt: Was auf jemanden wartet, muss an einer Stelle wieder
     * auftauchen, die er im Alltag ohnehin ansteuert - und das ist die
     * Kopfleiste, denn sie steht auf jeder Seite.
     *
     * EINE ZAHL, EINE BEDEUTUNG: ungelesene Nachrichten ueber alle nicht
     * beendeten Chats. Sie zaehlt NACHRICHTEN und nicht Gespraeche - "drei
     * ungelesene" sagt mehr als "in einem Chat wartet etwas". Beim
     * Anfragenzaehler sind es drei Zahlen, weil dort drei verschiedene Dinge
     * warten; hier gibt es nur eines.
     *
     * ER FUEHRT AUF DIE CHATUEBERSICHT und nicht in ein Fenster: Ein Klick
     * soll auch dann etwas zeigen, wenn das Popup gerade nicht offen ist.
     *
     * SERVERSEITIG MIT SEINEM STAND AUSGELIEFERT, wie die beiden Elemente
     * daneben: Wer die Seite ohne Skript oeffnet, sieht trotzdem, dass etwas
     * ansteht - nur nachgezogen wird die Zahl dann nicht (assets/js/
     * chat_badge.js holt sie sich aus der Antwort des Heartbeats).
     *
     * FUER JEDES ANGEMELDETE KONTO mit dem Recht chat.list, nicht nur fuer
     * Guides: Der Kunde bekommt die Antwort auf seine Frage, und die soll er
     * genauso wenig verpassen.
     *
     * @param array{unread:int} $zahlen
     * @return string HTML
     */
    private static function chatBadge(array $zahlen): string
    {
        $ungelesen = max(0, (int)($zahlen['unread'] ?? 0));

        // Der Titel sagt, WAS wartet - die Zahl allein sagt es nicht. Er wird
        // im Browser mit derselben Regel neu gebaut (chat_badge.js), damit an
        // beiden Stellen dasselbe steht.
        $titel = $ungelesen > 0
            ? $ungelesen . ' ungelesene Nachricht(en)'
            : 'Ihre Nachrichten';

        return '<a class="app-chats' . ($ungelesen > 0 ? ' app-chats--on' : '') . '"'
             . ' id="chats-badge" href="index.php?act=get_all_chats"'
             . ' data-unread="' . $ungelesen . '"'
             . ' title="' . htmlspecialchars($titel) . '">'
             .   '<span class="app-chats__text">Nachrichten</span>'
             .   '<span class="app-chats__count" id="chats-count"'
             .     ($ungelesen > 0 ? '' : ' hidden') . '>' . $ungelesen . '</span>'
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

        // DER HINWEIS AUF DIE UNBESTAETIGTE ADRESSE steht VOR dem Inhalt und
        // nicht in der Kopfleiste: Er ist keine Anzeige wie der
        // Anfragenzaehler, sondern eine offene Aufgabe, und die gehoert
        // dorthin, wo gelesen wird. Er kommt hier ins Layout und nicht in die
        // einzelnen Seiten, weil er auf jeder stehen soll - auch auf denen,
        // die nichts mit Anfragen, Chat oder Bildern zu tun haben.
        //
        // Leer, solange MAIL_VERIFY_REQUIRED aus ist (App\Helper\MailGate) -
        // dann faellt dafuer auch keine Abfrage an.
        $out = str_replace("###CONTENT###", MailGate::streifen() . $in_content, $out);

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
        // Der Nachrichtenzaehler. Aus demselben Grund fuer Gaeste leer: Ein
        // Chat setzt zwei Konten voraus.
        $chats     = "";

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

            // DER NACHRICHTENZAEHLER - ebenfalls fuer jedes angemeldete
            // Konto. Gefragt wird chat.list und nicht location.offer: Der
            // Kunde bekommt die Antwort auf seine Frage, und die soll er
            // genauso wenig verpassen wie der Guide die Frage.
            if (Auth::can(Permission::CHAT_LIST)) {
                $chatZahlen = Chat::counters(Auth::userId());
                $chats      = self::chatBadge($chatZahlen);

                // Der Startwert geht mit, aus demselben Grund wie beim
                // Anfragenzaehler: Sonst muesste das Skript beim Seitenaufbau
                // erst einmal fragen, was der Server gerade ausgeliefert hat.
                $user_id_script .= '<script>window.chatCounts = '
                    . json_encode($chatZahlen) . ';</script>';
            }

            // DIE SKALA DER BEWERTUNG geht mit ins Frontend.
            //
            // WARUM: Das Bewertungsformular baut der Browser
            // (assets/js/review.js) - es erscheint nach dem Auflegen auf
            // irgendeiner Seite, ohne dass der Server dafuer eine Seite
            // ausliefert. Die Woerter der Skala, ihre Grenzen und die
            // erlaubte Textlaenge stehen trotzdem an EINER Stelle
            // (App\Model\TourReview) und nicht ein zweites Mal in
            // JavaScript: Zwei Fassungen einer Skala waeren eine zu viel,
            // und die zweite waere die, die beim naechsten Aendern vergessen
            // wird.
            //
            // Nur fuer Konten, die bewerten duerfen. Wer das Recht nicht hat,
            // bekommt kein Formular und braucht auch die Skala nicht.
            if (Auth::can(Permission::REVIEW_CREATE)) {
                $user_id_script .= '<script>window.reviewScale = ' . json_encode([
                    'min'     => TourReview::STARS_MIN,
                    'max'     => TourReview::STARS_MAX,
                    'names'   => TourReview::starNames(),
                    'bodyMax' => TourReview::BODY_MAX,
                ]) . ';</script>';
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
        //
        // HIER STANDEN blockLocation UND manageUsers. Beide sind mit dem
        // Verwaltungsbereich entfallen, und der Grund ist derselbe: Sie
        // steuerten Knoepfe, die es in der Kundenoberflaeche nicht mehr gibt.
        // blockLocation blendete in der Standortliste zwei Symbolknoepfe ein
        // (assets/js/locations_table.js); gesperrt und freigegeben wird jetzt
        // in der Standortliste des Bereichs. manageUsers wurde ueberhaupt
        // nirgends gelesen - ein Wert, der seit einem Umbau mitfuhr, ohne
        // etwas zu tun.
        //
        // Was hier noch steht, betrifft ausschliesslich Knoepfe, die JEDES
        // Konto sehen kann - nur eben mit verschiedener Beschriftung.
        $can = [
            'offerLocation' => Auth::can(Permission::LOCATION_OFFER),
            // Darf dieses Konto sich auf bereit stellen? Der Schalter selbst
            // wird serverseitig gebaut; das Skript braucht die Auskunft, um
            // sich bei allen anderen gar nicht erst einzuhaengen.
            'setAvailability' => Auth::can(Permission::USER_AVAILABILITY),
            'becomeGuide'   => Role::mayBecomeGuide($user_role_id),
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
        $out = str_replace("###CHATS###"               , $chats            , $out);
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
