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
 * DIE SPERRE SOLL MINDESTENS SO LANG SEIN WIE IHR FENSTER. Ist sie kuerzer,
 * wird SIE zur eigentlichen Taktung: Wer eine Sperre abgesessen hat, faengt
 * bei eins an (siehe naechster Absatz), bekommt also nach Ablauf der Sperre
 * sofort das volle Kontingent des Fensters. Eine Tagesgrenze mit einstuendiger
 * Sperre ist damit keine Tagesgrenze mehr, sondern eine Stundengrenze - und
 * eine, die neben einer echten Stundengrenze nichts mehr beitraegt. Deshalb
 * steht in dieser Datei ueberall 'sperre' == 'fenster'.
 *
 * FENSTER UND SPERRE DUERFEN TROTZDEM BELIEBIG ZUEINANDER STEHEN. Wer eine Sperre
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

    // =====================================================================
    // BEFUND N-10: die sechs Endpunkte, die ein ANGEMELDETES Konto
    // unbegrenzt oft aufrufen konnte
    // =====================================================================
    //
    // Bis hierher begrenzte keiner von ihnen, wie oft ein Konto ihn aufruft.
    // Der Zugang war geregelt - ein Recht in config/routes.php und, wo noetig,
    // eine Beteiligungspruefung in der WHERE-Klausel -, die HAEUFIGKEIT nicht.
    //
    // GEZAEHLT WIRD AM KONTO, nicht an der IP. Der Handelnde ist hier
    // angemeldet; wer viele Konten haben will, laeuft zuerst in die
    // Registrierungsbremse ('signup' weiter oben). Eine IP-Schranke wuerde
    // dagegen ein Buero oder ein Mobilfunk-NAT treffen, hinter dem viele
    // ehrliche Nutzer sitzen. Die zwei Ausnahmen stehen unten, und beide aus
    // demselben Grund: Dort kostet ein Aufruf GELD AUSSERHALB DIESES SERVERS.
    //
    // 'konto' ist dabei die UserID aus der Sitzung. Der Aufrufer kann sie
    // nicht waehlen - das ist der Unterschied zum Login, wo der Kontoteil des
    // Schluessels aus dem Formular kommt.
    //
    // GEZAEHLT WIRD JEDER AUFRUF, nicht nur der erfolgreiche - anders als bei
    // der Registrierung. Dort war der Fehlversuch der Tippfehler eines
    // Menschen an einem Formular; hier ruft die Anwendung selbst auf, und ein
    // fehlschlagender Aufruf ist eher das Abklopfen fremder Kennungen als ein
    // Versehen. Ein vom Zaehler ABGEWIESENER Aufruf zaehlt nicht mit: Sonst
    // koennte ein Client, der stur weiterprobiert, seine eigene Sperre
    // endlos verlaengern.

    /**
     * Eine Fuehrung anfragen (RequestController::create).
     *
     * DER BEFUND: Je Standort ist nur EINE laufende Anfrage moeglich - aber es
     * gibt beliebig viele Standorte. Ein Konto konnte jeden Guide der
     * Plattform gleichzeitig anfragen, und bei jedem klingelte es.
     *
     * ZEHN JE STUNDE. Wer eine Fuehrung sucht, fragt zwei oder drei Guides und
     * wartet deren Antwort ab - die Antwortfrist ist eine Stunde
     * (config/requests.php). Zehn laesst Raum fuer Absagen und einen zweiten
     * Anlauf und ist weit von "jeder Guide der Plattform" entfernt.
     *
     * KEINE TAGESGRENZE. Zehn je Stunde sind rund um die Uhr 240 - fuer einen
     * Menschen unerreichbar viel, fuer den Missbrauch, um den es geht (ein
     * Klingeln bei allen gleichzeitig), viel zu langsam. Eine zweite Zahl
     * daneben wuerde nur eine zweite Stelle zum Pflegen schaffen.
     */
    'request_create' => [
        'konto' => ['teile' => ['konto'], 'versuche' => 10, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * Eine Fuehrung bewerten (ReviewController::create).
     *
     * JE FUEHRUNG IST OHNEHIN NUR EINE BEWERTUNG MOEGLICH - das steht als
     * eindeutiger Schluessel in der Tabelle (migrations/016) und nicht nur im
     * Controller. Was hier begrenzt wird, ist deshalb nicht das Bewerten,
     * sondern das ABKLOPFEN: Die Route nimmt eine beliebige Fuehrungskennung
     * entgegen und antwortet auf "gibt es nicht", "gehoert dir nicht", "hat
     * nie stattgefunden" und "schon bewertet" bewusst gleich. Das ist richtig
     * so - aber ohne Bremse laesst sich der Kennungsraum trotzdem absuchen,
     * weil der ERFOLGSFALL sich unterscheidet.
     *
     * ZEHN JE STUNDE. Bewertet wird nach einer Fuehrung, und eine Fuehrung
     * dauert. Wer zehn in einer Stunde abgibt, holt Versaeumtes nach - mehr
     * als zehn tut niemand.
     */
    'review_create' => [
        'konto' => ['teile' => ['konto'], 'versuche' => 10, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * Einen Chat oeffnen - beide Einstiege (ChatController::startChat und
     * ::startDirectChat).
     *
     * EINE AKTION FUER BEIDE, weil es derselbe Vorgang ist: einen Chat
     * anlegen. Sie unterscheiden sich nur in der Quelle fuer das Gegenueber -
     * der Standort beim Kunden, die Benutzerliste beim Admin. Zwei Zaehler
     * daneben waeren zwei Namen fuer dieselbe Frage.
     *
     * DER BEFUND WAR EIN ANDERER, UND ER IST BEHOBEN: Die Route nahm eine
     * beliebige Kontokennung entgegen (Befund N-12) - ein Skript konnte damit
     * jedem Konto der Plattform eine Nachricht ins Postfach legen. Seit
     * Migration 019 sagt der STANDORT, wer das Gegenueber ist; die freie Wahl
     * gibt es nicht mehr.
     *
     * DIESE GRENZE WAR NIE DIE ANTWORT DARAUF und ist es auch jetzt nicht.
     * Sie bleibt, was sie war: eine Obergrenze gegen die Masse. Wer wen
     * anschreiben darf, ist keine Frage der Haeufigkeit.
     *
     * SECHZIG JE STUNDE - und damit auffaellig grosszuegig. Der Grund steht im
     * Client: assets/js/ui_chat.js ruft diese Route bei JEDEM Oeffnen eines
     * Chatfensters auf, nicht nur beim Anlegen (es ist ein findOrCreate). Wer
     * zwischen seinen Gespraechen hin und her wechselt, erzeugt echte
     * Aufrufe, und eine enge Grenze wuerde die normale Bedienung abwuergen,
     * nicht den Missbrauch.
     */
    'chat_start' => [
        'konto' => ['teile' => ['konto'], 'versuche' => 60, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * Eine Chatnachricht senden (ChatController::sendMessage).
     *
     * ZWEI SCHRANKEN, WEIL ES ZWEI VERSCHIEDENE FRAGEN SIND: Wie schnell darf
     * jemand schreiben, und wie viel insgesamt.
     *
     * ZWANZIG JE MINUTE ist die Frage nach dem Tempo. Zwanzig Nachrichten in
     * sechzig Sekunden schreibt kein Mensch mehr, der etwas mitteilen will -
     * das ist die Geschwindigkeit eines Skripts. Die Sperre ist mit einer
     * Minute bewusst kurz: Das ist ein "langsamer" und kein Ausschluss, und
     * wer wirklich nur schnell getippt hat, merkt kaum etwas davon.
     *
     * FUENFHUNDERT JE STUNDE ist die Frage nach der Menge. Eine lebhafte
     * zweistuendige Fuehrung kommt auf ein paar hundert Zeilen; fuenfhundert
     * in EINER Stunde ist mehr, als ein Gespraech hergibt. Diese Schranke
     * begrenzt den Speicherverbrauch, der sonst je Konto unbegrenzt waere
     * (Befund N-7).
     *
     * BEIDE ZAHLEN SIND GESCHAETZT und nicht gemessen - es gibt noch keinen
     * Betrieb, an dem sich ablesen liesse, was ein lebhaftes Gespraech
     * wirklich erzeugt. Sie stehen hier, damit sie sich an einer Stelle
     * nachziehen lassen, sobald es die Zahlen gibt.
     */
    'chat_message' => [
        'konto_minute' => ['teile' => ['konto'], 'versuche' => 20,  'fenster' => 60,   'sperre' => 60,   'erfolg_loescht' => false],
        'konto_stunde' => ['teile' => ['konto'], 'versuche' => 500, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * TURN-Zugangsdaten holen (TurnController::getTurnCredentials).
     *
     * DIE ERSTE DER BEIDEN AUSNAHMEN MIT IP-SCHRANKE. Jeder Aufruf loest einen
     * ausgehenden HTTPS-Request an Metered aus
     * (App\Model\MeteredTurnService::fetch_turn_credentials) und verbraucht
     * ein bezahltes Kontingent. Ein unbegrenzter Endpunkt ist hier nicht nur
     * Last, sondern ein FREMDFINANZIERTER VERSTAERKER - und das Kontingent
     * ist am Ende auch dann leer, wenn der Missbrauch von einem einzigen
     * Konto ausging.
     *
     * DREISSIG JE STUNDE UND KONTO. Der Client holt die Liste bei der
     * anrufenden Seite einmal und behaelt sie (assets/js/rtc.js: nur wenn
     * noch nicht geladen oder auf die Notfallliste zurueckgefallen), bei der
     * annehmenden Seite bei jedem Anruf neu. Ein Gespraech kostet also ein bis
     * zwei Aufrufe; dreissig decken zehn Gespraeche samt Wiedereinstiegen ab.
     *
     * HUNDERT JE STUNDE UND IP als zweite Linie, falls jemand die Aufrufe auf
     * mehrere Konten verteilt. Hoch genug fuer ein Buero, in dem mehrere
     * Menschen gleichzeitig telefonieren.
     *
     * WICHTIG: Das Erreichen dieser Grenze ist KEIN Fehler fuer den Anrufer.
     * Der Endpunkt hat fuer den Ausfall des TURN-Dienstes bereits einen Weg -
     * die STUN-Liste mit turnAvailable=false -, und genau den nimmt er auch
     * hier. Ein Anruf im einfachen Netz gelingt weiterhin; nur der teure Weg
     * nach draussen unterbleibt. Siehe dort.
     */
    'turn_credentials' => [
        'konto' => ['teile' => ['konto'], 'versuche' => 30,  'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
        'ip'    => ['teile' => ['ip'],    'versuche' => 100, 'fenster' => 3600, 'sperre' => 3600, 'erfolg_loescht' => false],
    ],

    /**
     * Bestaetigungsmail anfordern (EmailVerificationController::sendVerification).
     *
     * DIE ZWEITE AUSNAHME MIT IP-SCHRANKE, aus demselben Grund: Jeder Aufruf
     * verschickt eine E-Mail. Ohne Bremse ist das ein Mailversand-Verstaerker
     * - und der schadet nicht nur diesem Server, sondern seinem Ruf bei den
     * Empfaengerservern, was sich nicht durch Abschalten reparieren laesst.
     *
     * Der Versand ist im Registrierungsablauf derzeit auskommentiert (kein
     * eigener SMTP-Server, Befund N-4). DIE ROUTE send_email_verify IST
     * TROTZDEM ERREICHBAR und verschickt. Die Bremse gehoert deshalb jetzt
     * hierher und nicht erst, wenn der Versand wieder eingeschaltet wird.
     *
     * DREI JE STUNDE deckt den einzigen ehrlichen Fall ab: "die Mail kam
     * nicht an, nochmal". Wer dreimal in einer Stunde keine bekommen hat,
     * dem hilft ein vierter Versuch auch nicht - dann liegt es woanders.
     *
     * ZEHN JE TAG mit einer Tagessperre daneben, weil drei je Stunde allein
     * 72 Mails am Tag an dieselbe Adresse erlauben wuerden. Das ist die
     * Zahl, die beim Empfaenger als Belaestigung ankommt.
     *
     * ZWANZIG JE STUNDE UND IP fuer den Fall, dass jemand die Aufrufe ueber
     * mehrere frisch angelegte Konten verteilt.
     */
    'email_verify_send' => [
        'konto_stunde' => ['teile' => ['konto'], 'versuche' => 3,  'fenster' => 3600,  'sperre' => 3600,  'erfolg_loescht' => false],
        'konto_tag'    => ['teile' => ['konto'], 'versuche' => 10, 'fenster' => 86400, 'sperre' => 86400, 'erfolg_loescht' => false],
        'ip'           => ['teile' => ['ip'],    'versuche' => 20, 'fenster' => 3600,  'sperre' => 3600,  'erfolg_loescht' => false],
    ],
];
