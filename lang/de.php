<?php
/**
 * Der deutsche Sprachkatalog.
 *
 * GELESEN WIRD ER VON App\Helper\I18n. Dort steht, in welcher Reihenfolge
 * die Kataloge befragt werden und was passiert, wenn ein Schluessel fehlt.
 *
 * DIE REGELN FUER DIESE DATEI
 * ---------------------------
 * 1. lang/de.php und lang/en.php haben DIESELBEN SCHLUESSEL. Auch dieselben
 *    Pluralformen. Ein Test in tests/server_test.php haelt das fest - ein
 *    Schluessel, den nur eine Sprache kennt, ist ein Text, der in der
 *    anderen fehlt, und das faellt sonst erst dem Nutzer auf.
 *
 * 2. DER SCHLUESSEL BESCHREIBT DEN ORT, NICHT DEN TEXT. Also
 *    'sprache.titel' und nicht 'sprache_waehlen'. Wer den Text aendert,
 *    soll nicht den Schluessel aendern muessen - sonst heisst ein Schluessel
 *    nach einer Formulierung, die es nicht mehr gibt.
 *
 * 3. PLATZHALTER heissen {name} und werden von I18n::einsetzen() gefuellt.
 *    Ein Platzhalter, den niemand uebergibt, bleibt sichtbar stehen.
 *
 * 4. ZAEHLBARES steht als Array mit 'one' und 'other' und wird ueber
 *    I18n::plural() geholt. {n} ist dort immer verfuegbar.
 *
 * 5. HIER STEHT KEIN HTML. Ein Katalogtext wird nicht maskiert und geht
 *    unveraendert in die Seite; Markup gehoert in die Vorlage unter
 *    assets/html, der Text hierher.
 *
 * WAS HIER STEHT UND WAS NOCH NICHT
 * ---------------------------------
 * Die Sprachwahl selbst - und seit der Stufe der Kataloge und Formate alles,
 * was ZUSAMMENGESETZT wird: Monatsnamen, Wochentage, Tagesabschnitte,
 * Zustandswoerter, Dauern, relative Zeitangaben, die beiden E-Mails. Das sind
 * die Texte, bei denen ein Aufrufer sonst die deutsche Grammatik in den Code
 * schreibt - die Wortstellung von "vor drei Stunden", die Einzahl von
 * "1 Minute", die Reihenfolge von Monat und Jahr.
 *
 * NOCH NICHT hier steht der Bestand der SEITEN. Er zieht Schluessel fuer
 * Schluessel nach; dass dabei nichts Neues dazukommt, haelt der Test fest,
 * der neue nackte deutsche Literale meldet (tests/i18n_scan.php).
 */
return [

    // -----------------------------------------------------------------
    // Die Sprachwahl. Der Umschalter in der Fusszeile (fuer Gaeste) und
    // der Bereich auf der Kontoseite (App\Controller\SettingsController)
    // benutzen dieselben Schluessel - es ist dieselbe Entscheidung an
    // zwei Orten.
    // -----------------------------------------------------------------
    'sprache.titel'       => 'Sprache',
    'sprache.hinweis'     => 'Gilt für die Oberfläche. Der Text wird vom Server erzeugt, die Seite lädt deshalb neu.',
    'sprache.konto'       => 'Gilt für dieses Konto und ist beim nächsten Anmelden wieder da.',
    'sprache.gast'        => 'Wird in diesem Browser gemerkt.',
    'sprache.wechseln_zu' => 'Oberfläche auf {sprache} umstellen',
    'sprache.aktiv'       => 'Aktuelle Sprache: {sprache}',
    'sprache.gespeichert' => 'Sprache gespeichert.',
    'sprache.unbekannt'   => 'Unbekannte Sprache.',

    // -----------------------------------------------------------------
    // Ein Beispiel mit Formen - und zugleich das, woran der Test die
    // Formengleichheit beider Kataloge prueft. Es steht hier nicht als
    // Platzhalter, sondern weil die Fusszeile die Zahl der Sprachen nennt.
    // -----------------------------------------------------------------
    'sprache.anzahl' => [
        'one'   => '{n} Sprache',
        'other' => '{n} Sprachen',
    ],

    // -----------------------------------------------------------------
    // DER KALENDER
    //
    // Die Monatsnamen stehen hier und kommen nicht aus strftime(): Das
    // haengt an der Locale des Servers, und die ist auf einem gemieteten
    // Server oft englisch - dann stuende auf einer deutschen Seite "March".
    // Gelesen werden sie ueber die Nummer des Monats
    // (App\Helper\GuideView::dabeiSeit).
    // -----------------------------------------------------------------
    'datum.monat.1'  => 'Januar',
    'datum.monat.2'  => 'Februar',
    'datum.monat.3'  => 'März',
    'datum.monat.4'  => 'April',
    'datum.monat.5'  => 'Mai',
    'datum.monat.6'  => 'Juni',
    'datum.monat.7'  => 'Juli',
    'datum.monat.8'  => 'August',
    'datum.monat.9'  => 'September',
    'datum.monat.10' => 'Oktober',
    'datum.monat.11' => 'November',
    'datum.monat.12' => 'Dezember',

    // Monat und Jahr als eine Angabe. Ein eigener Schluessel und keine
    // Verkettung im Code: In welcher Reihenfolge die beiden stehen und was
    // dazwischen gehoert, ist eine Frage der Sprache.
    'datum.monat_jahr' => '{monat} {jahr}',

    'datum.heute'   => 'Heute',
    'datum.gestern' => 'Gestern',

    // ZWEI FORMATE, ZWEI ADRESSATEN:
    //
    //   datum.mit_uhrzeit  ist ein Muster fuer date() in PHP. Es steht im
    //                      Katalog, weil "9.9.2026" und "9/9/2026" dieselbe
    //                      Angabe in zwei Sprachen sind.
    //   datum.locale       ist die Kennung fuer Intl im Browser
    //                      (toLocaleDateString). Sie traegt die REGION, die
    //                      das Sprachkuerzel nicht hat: 'en' allein ergaebe
    //                      die amerikanische Reihenfolge Monat/Tag.
    //
    // Beide sind Technik und kein Satz - und stehen trotzdem hier, weil sie
    // sich mit der Sprache aendern und sonst im Code festgeschrieben waeren.
    'datum.mit_uhrzeit' => 'd.m.Y H:i',
    'datum.locale'      => 'de-DE',

    // -----------------------------------------------------------------
    // DIE WOCHENTAGE
    //
    // Kurz und lang, weil beides gebraucht wird: Das Zeitraster des
    // Standortformulars hat sieben schmale Spalten (App\Helper\Availability),
    // das Vorleseprogramm daneben liest den ganzen Namen.
    //
    // Der Schluessel traegt die deutsche Abkuerzung als KENNUNG - sie steht
    // so im gespeicherten Muster und im Formularfeld und ist damit Technik
    // und keine Beschriftung. Uebersetzt wird der Wert, nicht der Schluessel.
    // -----------------------------------------------------------------
    'zeit.tag.mo.kurz' => 'Mo',
    'zeit.tag.mo.lang' => 'Montag',
    'zeit.tag.di.kurz' => 'Di',
    'zeit.tag.di.lang' => 'Dienstag',
    'zeit.tag.mi.kurz' => 'Mi',
    'zeit.tag.mi.lang' => 'Mittwoch',
    'zeit.tag.do.kurz' => 'Do',
    'zeit.tag.do.lang' => 'Donnerstag',
    'zeit.tag.fr.kurz' => 'Fr',
    'zeit.tag.fr.lang' => 'Freitag',
    'zeit.tag.sa.kurz' => 'Sa',
    'zeit.tag.sa.lang' => 'Samstag',
    'zeit.tag.so.kurz' => 'So',
    'zeit.tag.so.lang' => 'Sonntag',

    // -----------------------------------------------------------------
    // DIE TAGESABSCHNITTE
    //
    // Die GRENZEN stehen nicht hier, sondern in App\Helper\Availability:
    // Wann der Abend anfaengt, ist keine Frage der Sprache. Hier steht nur,
    // wie er heisst.
    // -----------------------------------------------------------------
    'zeit.abschnitt.nacht'      => 'nachts',
    'zeit.abschnitt.vormittag'  => 'vormittags',
    'zeit.abschnitt.nachmittag' => 'nachmittags',
    'zeit.abschnitt.abend'      => 'abends',

    // "Mo-Fr abends" - Tage und Abschnitt als ein Satzstueck.
    'zeit.tage_abschnitt' => '{tage} {abschnitt}',

    // -----------------------------------------------------------------
    // DAUERN
    //
    // AUSGESCHRIEBEN fuer die Kundenseiten, ABGEKUERZT fuer die
    // Verwaltungslisten - dort steht die Dauer in einer schmalen Spalte
    // neben anderen Angaben und soll die Zeile nicht laenger machen.
    // -----------------------------------------------------------------
    'dauer.minuten' => [
        'one'   => '{n} Minute',
        'other' => '{n} Minuten',
    ],
    'dauer.stunden' => [
        'one'   => '{n} Stunde',
        'other' => '{n} Stunden',
    ],
    'dauer.tage' => [
        'one'   => '{n} Tag',
        'other' => '{n} Tage',
    ],
    // "1 Stunde 30 Minuten". Beide Teile sind bereits fertige Texte.
    'dauer.stunden_minuten' => '{stunden} {minuten}',

    'dauer.kurz.minuten'         => '{n} Min',
    'dauer.kurz.stunden'         => '{n} Std',
    'dauer.kurz.tage'            => '{n} Tg',
    'dauer.kurz.stunden_minuten' => '{stunden} {minuten}',
    'dauer.kurz.tage_stunden'    => '{tage} {stunden}',

    // -----------------------------------------------------------------
    // RELATIVE ZEITANGABEN
    //
    // WARUM DIE RICHTUNG IM SCHLUESSEL STECKT und nicht als "vor {dauer}"
    // davorgesetzt wird: Im Englischen steht sie HINTEN ("3 hours ago").
    // Wer die Richtung im Code an die Dauer klebt, schreibt damit die
    // deutsche Wortstellung fest, und die naechste Sprache bekommt sie
    // aufgezwungen. Der ganze Satzteil gehoert deshalb in den Katalog.
    //
    // Und deshalb steht hier auch die Dauer noch einmal: "in drei Tagen"
    // ist der Dativ, "drei Tage" der Nominativ - dieselbe Zahl, dieselbe
    // Einheit, ein anderes Wort.
    // -----------------------------------------------------------------
    'zeit.in.minuten' => [
        'one'   => 'in {n} Minute',
        'other' => 'in {n} Minuten',
    ],
    'zeit.in.stunden' => [
        'one'   => 'in {n} Stunde',
        'other' => 'in {n} Stunden',
    ],
    'zeit.in.tagen' => [
        'one'   => 'in {n} Tag',
        'other' => 'in {n} Tagen',
    ],
    'zeit.vor.minuten' => [
        'one'   => 'vor {n} Minute',
        'other' => 'vor {n} Minuten',
    ],
    'zeit.vor.stunden' => [
        'one'   => 'vor {n} Stunde',
        'other' => 'vor {n} Stunden',
    ],
    'zeit.vor.tagen' => [
        'one'   => 'vor {n} Tag',
        'other' => 'vor {n} Tagen',
    ],

    'zeit.jetzt'       => 'jetzt',
    'zeit.gerade_eben' => 'gerade eben',
    'zeit.unbekannt'   => 'unbekannt',
    'zeit.vereinbart'  => 'den vereinbarten Zeitpunkt',

    // Der Zonenunterschied auf der Standortseite. Der Abstand ist ein
    // fertiger Text ("2 Stunden"), die Richtung steckt im Schluessel -
    // aus demselben Grund wie oben.
    'zeit.zone.spaeter' => 'dort ist es {dauer} später als bei Ihnen',
    'zeit.zone.frueher' => 'dort ist es {dauer} früher als bei Ihnen',

    // -----------------------------------------------------------------
    // DIE ZUSTAENDE EINER ANFRAGE
    //
    // Die Schluessel sind die Werte der Spalte request.status
    // (App\Model\TourRequest). Gelesen werden sie an drei Stellen - in der
    // Anfragenliste des Browsers, in der Verwaltung und auf der
    // Standortseite -, und vor dieser Stufe standen die Woerter an zwei
    // davon getrennt.
    // -----------------------------------------------------------------
    'anfrage.status.open'      => 'offen',
    'anfrage.status.accepted'  => 'angenommen',
    'anfrage.status.declined'  => 'abgelehnt',
    'anfrage.status.expired'   => 'abgelaufen',
    'anfrage.status.done'      => 'durchgeführt',
    'anfrage.status.cancelled' => 'abgebrochen',

    // -----------------------------------------------------------------
    // DIE BEIDEN E-MAILS
    //
    // SIE STEHEN IN DER SPRACHE DES EMPFAENGERS, nicht in der dessen, der
    // sie ausloest - geholt werden sie deshalb mit I18n::tIn() und der
    // Sprache aus user.lang. Eine Bestaetigungsmail geht an ein Konto und
    // nicht an eine Sitzung.
    //
    // NUR TEXT UND KEIN HTML. Der Versand schickt sie als reinen Text
    // (App\Model\Email), und der Zeilenumbruch ist hier deshalb der
    // Umbruch der Mail und keine Zierde.
    // -----------------------------------------------------------------
    'mail.bestaetigung.betreff' => 'E-Mail-Adresse bestätigen',
    'mail.bestaetigung.text'    => "Hallo,\n\nBitte bestätige deine E-Mail durch Klick auf diesen Link:\n\n{link}\n\nDieser Link ist 24 Stunden gültig.",
    'mail.passwort.betreff'     => 'Passwort zurücksetzen',
    'mail.passwort.text'        => "Hallo,\n\nKlicke auf den folgenden Link, um dein Passwort zu ändern:\n\n{link}\n\nDieser Link ist 1 Stunde gültig.",
];
