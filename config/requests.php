<?php
/**
 * Fristen rund um die Anfrage einer Fuehrung.
 *
 * WORUM ES GEHT
 * -------------
 * Vorher rief ein Kunde den Guide unmittelbar an. Das setzte voraus, dass
 * beide zufaellig im selben Moment koennen - und der Guide ist die knappere
 * Seite: Er muss losgehen, sich Zeit nehmen, vielleicht hinfahren. Statt des
 * Anrufs steht am Anfang jetzt eine ANFRAGE mit einem Wunschzeitpunkt, die
 * der Guide annimmt oder ablehnt.
 *
 * "Jetzt sofort" ist dabei KEIN Sonderfall, sondern ein Wunschzeitpunkt wie
 * jeder andere: der aktuelle. Es gibt deshalb keine Spalte und keine
 * Verzweigung dafuer - nur einen frueheren Zeitpunkt.
 *
 * DIES IST DIE EINE STELLE, an der die Fristen stehen. Sie gehen von hier an
 * vier Verbraucher:
 *
 *   - App\Model\TourRequest       rechnet damit den Ablaufzeitpunkt aus und
 *                                 wertet ihn in jeder Abfrage aus,
 *   - App\Controller\RequestController prueft damit den Wunschzeitpunkt,
 *   - App\Controller\WebRTCController laesst damit den Anruf zu einer
 *                                 angenommenen Anfrage durch,
 *   - cron/check_online_status.php raeumt abgelaufene Anfragen auf.
 *
 * WARUM ABLAUFZEITPUNKTE UND KEINE JA/NEIN-MARKEN
 * -----------------------------------------------
 * Dieselbe Ueberlegung wie bei der Bereitschaft (config/presence.php): Steht
 * der Ablauf in der Zeile, ist "abgelaufen" allein aus der Zeile ablesbar.
 * Der Cronjob ist dann AUFRAEUMEN und keine Pruefung - eine abgelaufene
 * Anfrage wirkt auch dann nicht mehr, wenn der Job gar nicht eingerichtet
 * ist.
 */
return [
    /**
     * Antwortfrist: Wie lange eine offene Anfrage auf eine Antwort wartet.
     *
     * Eine Stunde ist lang genug, dass ein Guide sie auch dann noch sieht,
     * wenn er gerade unterwegs war, und kurz genug, dass ein Kunde nicht den
     * halben Tag auf eine Antwort hofft, die nicht mehr kommt.
     */
    'response_timeout'   => 3600,    // Sekunden

    /**
     * Karenz nach dem Wunschzeitpunkt, nach der eine OFFENE Anfrage verfaellt.
     *
     * Der zweite Ablaufgrund, und der frueheste der beiden gewinnt: Eine
     * Anfrage fuer "jetzt sofort" ist eine Viertelstunde spaeter gegenstandslos,
     * auch wenn die Antwortfrist noch laeuft. Umgekehrt steht eine Anfrage
     * fuer naechsten Samstag nicht eine Woche lang offen - sie verfaellt nach
     * der Antwortfrist.
     */
    'wish_grace'         => 900,     // Sekunden

    /**
     * Wie weit im Voraus sich eine Fuehrung anfragen laesst.
     *
     * Zwei Wochen. Weiter im Voraus ist ein Wunschzeitpunkt keine Verabredung
     * mehr, sondern eine Absichtserklaerung - und der Guide muesste sich fuer
     * etwas festlegen, das er heute nicht ueberblickt.
     */
    'lead_time_max'      => 1209600, // Sekunden (14 Tage)

    /**
     * Das Zeitfenster, in dem eine ANGENOMMENE Anfrage angerufen werden darf.
     *
     * before  wie lange VOR dem Wunschzeitpunkt es losgehen darf,
     * after   wie lange DANACH.
     *
     * Innerhalb dieses Fensters laesst der Server den Anruf auch dann durch,
     * wenn der Bereitschaftsschalter des Guides aus ist
     * (App\Controller\WebRTCController::callRoles). Das ist Absicht: Die
     * Annahme einer Anfrage ist eine ausdrueckliche Zusage fuer genau diesen
     * Zeitpunkt und damit die staerkere Aussage. Der Schalter sagt "ich kann
     * JETZT SOFORT" und bleibt fuer alles daneben zustaendig.
     *
     * Nach dem Fenster ist die Verabredung verstrichen: Die Anfrage laeuft ab
     * wie eine unbeantwortete.
     */
    'call_window_before' => 900,     // Sekunden (15 Minuten)
    'call_window_after'  => 7200,    // Sekunden (2 Stunden)

    /**
     * Wie lange nach dem letzten Auflegen sich WIEDER EINSTEIGEN laesst.
     *
     * DAS PROBLEM DAHINTER: Auflegen ist zweideutig. Es kann heissen "wir
     * sind fertig" - oder "das Netz ist weg". Vorher galt jedes Auflegen als
     * das Ende der Fuehrung, und gleichzeitig blieb der Startknopf beim
     * Kunden stehen, solange das Anruffenster lief: Die Fuehrung war
     * abgeschlossen und liess sich trotzdem beliebig oft neu starten.
     *
     * Beendet wird eine Fuehrung deshalb AUSDRUECKLICH vom Guide
     * (App\Model\TourRequest::finish). Bis dahin gilt sie als unterbrochen,
     * und beide Seiten koennen wieder einsteigen - aber nicht unbegrenzt,
     * sonst bliebe eine vergessene Fuehrung fuer immer offen und der Kunde
     * wuerde nie nach einer Bewertung gefragt.
     *
     * DIE UHR LAEUFT AB DEM LETZTEN AUFLEGEN und nicht ab dem Beginn: Sonst
     * waere eine zweistuendige Fuehrung nach anderthalb Stunden nicht mehr zu
     * retten. Eine halbe Stunde reicht fuer ein Funkloch, einen Akkuwechsel
     * und einen Browserabsturz samt Neustart; laenger ist es keine
     * Unterbrechung mehr, sondern ein neuer Termin.
     *
     * Ausgewertet wird die Frist in JEDER Abfrage (TourRequest::closedSql),
     * nicht erst vom Cronjob - dieselbe Regel wie beim Ablauf einer Anfrage:
     * Sie wirkt auch dann, wenn der Job gar nicht eingerichtet ist.
     */
    'rejoin_window'      => 1800,    // Sekunden (30 Minuten)

    /**
     * Wann eine begonnene Fuehrung ohne Ende als beendet gilt.
     *
     * Der Beginn kommt vom Offer, das Ende vom "hangup" - beides laeuft
     * ohnehin ueber den Server (App\Controller\WebRTCController). Stuerzt ein
     * Browser ab, kommt das Ende nie an; ohne diese Frist bliebe die Fuehrung
     * fuer immer "laufend" und taeuchte weder in der Liste des Guides noch in
     * einer spaeteren Abrechnung richtig auf.
     *
     * Der Cronjob setzt solche Zeilen auf "durchgefuehrt" und laesst ended_at
     * leer: Das Ende ist nicht bekannt, und ein geschaetzter Zeitpunkt waere
     * eine Erfindung.
     *
     * DIE REISSLEINE NEBEN rejoin_window: Diese Frist greift, wenn NIE ein
     * Auflegen ankam - dann gibt es keinen Zeitpunkt, ab dem der
     * Wiedereinstieg zaehlen koennte, und gerechnet wird ab dem Beginn. Sie
     * ist deshalb viel laenger: Sie muss auch die laengste Fuehrung
     * ueberdauern.
     */
    'stale_call'         => 14400,   // Sekunden (4 Stunden)
];
