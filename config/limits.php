<?php
/**
 * Die Bremse: wie oft etwas versucht werden darf, und was danach passiert.
 *
 * WORUM ES GEHT
 * -------------
 * Drei Stellen dieser Anwendung nahmen bisher unbegrenzt viele Versuche an:
 *
 *   1. DER LOGIN hatte zwar eine Bremse, aber sie lag in $_SESSION - also im
 *      Cookie des Angreifers. Wer das Cookie verwarf, fing bei null an; ein
 *      Skript, das gar keine Cookies annimmt, hatte nie ein Limit. Gezaehlt
 *      wurde damit nicht der Angreifer, sondern der ehrliche Nutzer, der
 *      sein Passwort dreimal falsch tippt.
 *   2. DIE ZWEI-FAKTOR-PRUEFUNG hatte ueberhaupt keinen Zaehler. Sechs
 *      Stellen sind eine Million Moeglichkeiten, ein TOTP-Code gilt 30
 *      Sekunden - ohne Bremse ist das kein zweiter Faktor, sondern eine
 *      Verzoegerung.
 *   3. DIE REGISTRIERUNG hatte keine Bremse. Konten waren unbegrenzt und
 *      kostenlos, und daran haengt mehr als es zunaechst scheint: Jeder
 *      spaetere Missbrauch - Anfragen, Bewertungen, Nachrichten - faengt mit
 *      einem Konto an.
 *
 * DIES IST DIE EINE STELLE, an der die Grenzen stehen. Ausgewertet werden
 * sie ausschliesslich von App\Model\RateLimit; kein Controller kennt eine
 * Zahl. Wer eine Grenze aendert, aendert sie hier - und wer eine NEUE Bremse
 * braucht (Anfragen, Bewertungen), traegt sie hier ein und ruft im
 * Controller dieselben vier Methoden auf wie alle anderen. Es kommt dafuer
 * keine Zeile Logik dazu.
 *
 * DER AUFBAU
 * ----------
 * Oberste Ebene ist die AKTION ('login', 'signup', ...). Darunter stehen
 * ihre SCHRANKEN - jede eine eigene Bremse mit eigenem Zaehler:
 *
 *   'teile'    Aus welchen Angaben der Schluessel gebaut wird. Der Aufrufer
 *              uebergibt sie benannt (['konto' => ..., 'ip' => ...]); welche
 *              davon eine Schranke benutzt, entscheidet allein diese Datei.
 *              Fehlt ein benoetigter Teil, greift diese Schranke nicht - die
 *              anderen schon.
 *   'versuche' Wie viele Versuche im Fenster erlaubt sind. Beim Erreichen
 *              dieser Zahl faellt die Sperre.
 *   'fenster'  Ueber welchen Zeitraum gezaehlt wird (Sekunden). Nach Ablauf
 *              beginnt der Zaehler bei eins - ein festes Fenster, kein
 *              gleitendes: Ein einzelner Zaehlerstand je Schluessel statt
 *              einer Zeile je Versuch.
 *   'sperre'   Wie lange nach dem Erreichen der Grenze abgewiesen wird.
 *   'erfolg_loescht'
 *              Ob ein erfolgreicher Vorgang diesen Zaehler wegraeumt
 *              (RateLimit::zuruecksetzen). Bei Schranken, die am KONTO
 *              haengen: ja - wer sein Passwort im vierten Anlauf trifft, soll
 *              beim naechsten Mal wieder fuenf Versuche haben. Bei reinen
 *              IP-Schranken: NEIN. Sie zaehlen die Adresse und nicht dieses
 *              Konto; wuerde ein erfolgreicher Login sie loeschen, koennte
 *              ein Angreifer mit einem einzigen eigenen Konto seinen
 *              IP-Zaehler beliebig oft zuruecksetzen - und damit genau die
 *              Schranke aushebeln, die das Durchprobieren von BENUTZERNAMEN
 *              begrenzt.
 *
 * FENSTER UND SPERRE DUERFEN BELIEBIG ZUEINANDER STEHEN. Wer eine Sperre
 * abgesessen hat, faengt bei eins an - der Zaehler wird nicht nur vom Ablauf
 * des Fensters zurueckgesetzt, sondern auch vom Ende der Sperre
 * (App\Model\RateLimit::NEUES_FENSTER). Ohne das haette eine Sperre, die
 * kuerzer ist als ihr Fenster, eine Falle: Der erste Versuch danach wuerde
 * sofort wieder sperren.
 *
 * WARUM MEHRERE SCHRANKEN JE AKTION
 * ---------------------------------
 * Weil eine einzelne immer falsch ist. Eine reine Kontosperre ist eine
 * Waffe gegen den Kontoinhaber: Wer einen Benutzernamen kennt, sperrt das
 * Konto mit fuenf falschen Passwoertern aus. Eine reine IP-Sperre bremst ein
 * Botnetz nicht, das jedes Passwort von einer anderen Adresse schickt.
 *
 * Deshalb sind die Schranken beim Login gestaffelt: ENG auf dem PAAR aus
 * Konto und IP - das ist der Fall, der wirklich durchprobiert -, WEIT auf
 * dem Konto allein und auf der IP allein. Ein Fremder kann damit ein Konto
 * nicht mehr gezielt aussperren (er erreicht nur sein eigenes Paar), waehrend
 * verteiltes Durchprobieren trotzdem an der weiten Kontoschranke endet.
 *
 * WAS GEZAEHLT WIRD
 * -----------------
 * Bei Login und 2FA nur FEHLVERSUCHE; ein erfolgreicher Login setzt den
 * Zaehler zurueck (RateLimit::zuruecksetzen). Bei der Registrierung gibt es
 * keinen Fehlversuch - dort zaehlt der Vorgang selbst.
 */
return [

    /**
     * Der Login.
     *
     * Teile: 'konto' (der eingegebene Benutzername) und 'ip'.
     *
     * FUENF VERSUCHE JE KONTO-UND-IP sind das, was ein Mensch braucht, der
     * sich zwischen zwei Passwoertern nicht sicher ist - und viel zu wenig
     * fuer jemanden, der eine Liste durchgeht. Eine Viertelstunde Sperre ist
     * kurz genug, dass der ehrliche Fall nicht den Support braucht, und lang
     * genug, dass 5 Versuche je 15 Minuten kein Verfahren mehr sind.
     *
     * FUENFZIG JE KONTO aus beliebigen Adressen fangen das Botnetz ab. Die
     * Zahl liegt bewusst zehnmal hoeher als die enge Schranke: Sie soll nur
     * dann greifen, wenn erkennbar VIELE Adressen dasselbe Konto angehen -
     * ein einzelner Fremder erreicht sie nicht und kann deshalb niemanden
     * aussperren.
     *
     * HUNDERT JE IP begrenzen das Durchprobieren von BENUTZERNAMEN. Ohne
     * diese Schranke koennte ein Angreifer mit jedem Versuch einen anderen
     * Namen schicken und beide Kontoschranken umgehen - jeder Schluessel
     * waere neu. Die Zahl ist hoch angesetzt, weil hinter einer Adresse ein
     * ganzes Buero oder ein Mobilfunk-NAT stecken kann.
     */
    'login' => [
        'konto_und_ip' => ['teile' => ['konto', 'ip'], 'versuche' => 5,   'fenster' => 900,  'sperre' => 900,  'erfolg_loescht' => true],
        'konto'        => ['teile' => ['konto'],       'versuche' => 50,  'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => true],
        'ip'           => ['teile' => ['ip'],          'versuche' => 100, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * Die Pruefung des zweiten Faktors.
     *
     * Teile: 'konto' (die UserID aus $_SESSION['2fa_userid'], NICHT der
     * Benutzername) und 'ip'.
     *
     * DIE KONTOSCHRANKE DARF HIER STRENG SEIN, und zwar aus einem Grund, der
     * beim Login nicht gilt: Wer hier ankommt, hat das Passwort bereits
     * richtig eingegeben. Die Sperre trifft also niemanden, der nicht ohnehin
     * schon halb angemeldet ist - ein Fremder kann damit kein Konto
     * aussperren, weil er gar nicht bis hierher kommt.
     *
     * FUENF VERSUCHE bei sechs Stellen: Ein Rateversuch trifft mit einer
     * Wahrscheinlichkeit von 1:1.000.000. Fuenf je Viertelstunde heisst, dass
     * ein vollstaendiges Durchprobieren mehrere Jahrhunderte dauert. Ein
     * Mensch, der sich vertippt oder dessen Uhr abweicht, hat trotzdem einen
     * zweiten und dritten Versuch.
     */
    '2fa' => [
        'konto' => ['teile' => ['konto'], 'versuche' => 5,  'fenster' => 900,  'sperre' => 900,  'erfolg_loescht' => true],
        'ip'    => ['teile' => ['ip'],    'versuche' => 50, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * ANGELEGTE Konten. Gezaehlt wird erst NACH dem erfolgreichen Anlegen.
     *
     * Teile: 'ip'.
     *
     * WARUM ERST DANACH: Sonst kostet jeder Tippfehler ein Konto aus dem
     * Kontingent. Wer sein Passwort dreimal falsch wiederholt, koennte sich
     * anschliessend nicht mehr registrieren - fuer einen Missbrauch, den er
     * nicht begangen hat. Das Haemmern gegen die Validierung bremst die
     * Aktion 'signup_formular' darunter; diese hier begrenzt das, worum es
     * geht: die Zahl der Konten.
     *
     * DREI JE STUNDE UND ZEHN JE TAG. Drei deckt den echten Fall ab, der
     * ueberhaupt vorkommt: eine Familie oder ein Buero an einem Anschluss.
     * Die Tagesschranke steht daneben, weil drei je Stunde allein 72 Konten
     * am Tag erlauben wuerden - genug fuer alles, was mit vielen Konten
     * anfaengt.
     *
     * Die Sperrzeiten entsprechen den Fenstern: Wer die Stundengrenze
     * erreicht, wartet eine Stunde, nicht laenger.
     */
    'signup' => [
        'ip_stunde' => ['teile' => ['ip'], 'versuche' => 3,  'fenster' => 3600,  'sperre' => 3600,  'erfolg_loescht' => false],
        'ip_tag'    => ['teile' => ['ip'], 'versuche' => 10, 'fenster' => 86400, 'sperre' => 86400, 'erfolg_loescht' => false],
    ],

    /**
     * ABGESCHICKTE Registrierungsformulare, ob gueltig oder nicht.
     *
     * Teile: 'ip'.
     *
     * Die zweite Haelfte der Registrierungsbremse. Ohne sie liesse sich das
     * Formular beliebig oft abschicken, solange nur nie ein Konto entsteht -
     * und genau das ist der Weg, auf dem sich pruefen laesst, WELCHE
     * Benutzernamen und E-Mail-Adressen es schon gibt: Das Formular
     * antwortet darauf unterschiedlich ("bereits vergeben").
     *
     * ZWANZIG JE STUNDE ist grosszuegig gegenueber dem Menschen, der sich
     * mehrfach vertippt, und eng gegenueber dem Skript, das eine Namensliste
     * abgleicht.
     */
    'signup_formular' => [
        'ip' => ['teile' => ['ip'], 'versuche' => 20, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],
];
