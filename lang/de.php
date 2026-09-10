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
 * Die Sprachwahl selbst; alles, was ZUSAMMENGESETZT wird (Monatsnamen,
 * Wochentage, Tagesabschnitte, Zustandswoerter, Dauern, relative
 * Zeitangaben, die beiden E-Mails); die Saetze der SEITEN, soweit PHP sie
 * erzeugt (Standortseite, Guide-Profil, Bewertungsblock,
 * Verwaltungsbereich, Kopfleiste, die Meldungen der Controller und des
 * Bildspeichers) - und seit der Stufe der Vorlagen jeder Text aus
 * assets/html, geholt ueber den Marker {{t:schluessel}}.
 *
 * SEIT DER STUFE DES BROWSERS auch die Meldungen aus assets/js: alles, was
 * erst durch ein Ereignis entsteht und deshalb beim Ausliefern der Seite noch
 * gar nicht dastand ("Anruf abgelehnt", "Datei zu gross"), dazu die
 * Sprachbloecke der beiden Bibliotheken DataTables und select2. Geholt wird
 * das ueber window.webrtcApp.t() und plural() (assets/js/i18n.js); der
 * Katalog geht als window.appI18n mit der Seite mit (I18n::bootScript).
 *
 * UND SEIT DER NACHARBEIT auch das, was die Controller selbst
 * zusammensetzen: die Seiten der Zwei-Faktor-Anmeldung, die Bestaetigung der
 * E-Mail-Adresse, die Fehlseite des Guide-Profils und jede JSON-Antwort, die
 * im Browser als Hinweis erscheint - Chat, Bewertung, Anruf, TURN.
 *
 * DAMIT IST DER UMZUG DURCH. Was noch deutsch im Code steht, steht dort mit
 * Absicht - siehe den naechsten Absatz -, und dass nichts Neues dazukommt,
 * haelt der Test fest, der neue nackte deutsche Literale meldet
 * (tests/i18n_scan.php).
 *
 * WAS AUSDRUECKLICH NICHT HIERHER GEHOERT: Logmeldungen und die Texte
 * geworfener Ausnahmen. Sie richten sich an den Betreiber und nicht an den
 * Benutzer - dieselbe Grenze, die auch der Scanner zieht. Ebenso wenig
 * technische Schluessel, die nie jemand liest: der Vergleichswert 'keine' in
 * assets/js/location_page.js etwa ist kein Text, sondern eine Kennung.
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
    // DIE ANFRAGENLISTE IM BROWSER (assets/js/requests.js)
    //
    // Die Seite selbst kommt vom Server; diese Zeilen zieht das Skript nach,
    // sobald der Heartbeat etwas Neues meldet.
    // -----------------------------------------------------------------
    'anfrage.neu'                => 'Neue Anfrage für eine Ihrer Führungen.',
    'anfrage.angenommen'         => 'Ihre Anfrage wurde angenommen.',
    'anfrage.zugesagt'           => 'Angenommen. Der Kunde startet die Führung zum vereinbarten Zeitpunkt – Sie werden dann angerufen.',
    'anfrage.abgelehnt'          => 'Abgelehnt.',
    'anfrage.zurueckgenommen'    => 'Zurückgenommen.',
    'anfrage.zurueckziehen'      => 'Zurückziehen',
    'anfrage.annehmen'           => 'Annehmen',
    'anfrage.ablehnen'           => 'Ablehnen',
    'anfrage.partner_unbekannt'  => 'Unbekannt',

    // Die Kopfzeile einer Zeile. Der Name steht als Platzhalter darin und
    // wird nicht davorgesetzt: "Angefragt von Anna" und "Ihr Guide: Anna"
    // sind zwei Saetze mit zwei Wortstellungen.
    'anfrage.zeile.von'              => 'Angefragt von {name}',
    'anfrage.zeile.guide'            => 'Ihr Guide: {name}',
    'anfrage.zeile.wunschzeit'       => 'Wunschzeitpunkt: {zeit}',
    'anfrage.zeile.fuehrung'         => 'Führung',
    'anfrage.zeile.nichts_zu_tun'    => 'Nichts mehr zu tun.',
    'anfrage.zeile.bewertet'         => 'Bewertet: {sterne}',
    'anfrage.zeile.bewertung_eigen'  => 'Ihre Bewertung: {sterne}',

    // Wie lange der Wiedereinstieg noch offen steht. Die Dauer kommt fertig
    // aus dauer.kurz.* und steht als Platzhalter im Satz.
    'anfrage.rest.ohne_frist' => 'Läuft noch. Beenden Sie sie, wenn Sie fertig sind.',
    'anfrage.rest.abgelaufen' => 'Der Wiedereinstieg ist abgelaufen.',
    'anfrage.rest.offen'      => 'Wiedereinstieg noch {dauer} möglich.',

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
    // =================================================================
    // DIE SEITEN
    //
    // Ab hier stehen die Texte der Oberflaeche selbst - die Stufe nach den
    // Katalogen und Formaten. Die Ordnung ist die der Anwendung und nicht
    // die der Dateien: 'standort.*' gilt fuer die Standortseite, gleich ob
    // App\Helper\LocationView sie baut oder
    // App\Controller\LocationController eine Meldung dazu schickt. Wer
    // einen Text sucht, weiss, wo er ihn SIEHT - nicht immer, welche Klasse
    // ihn erzeugt.
    // =================================================================

    // -----------------------------------------------------------------
    // ZAHLEN
    //
    // Technik wie datum.mit_uhrzeit weiter oben, und aus demselben Grund
    // hier: "1.234,5" und "1,234.5" sind dieselbe Zahl in zwei Sprachen.
    // Fest eingetragen waere das eine deutsche Schreibweise auf einer
    // englischen Seite - und die liest sich als eine andere Zahl.
    // -----------------------------------------------------------------
    'zahl.dezimaltrenner'    => ',',
    'zahl.tausendertrenner'  => '.',

    // -----------------------------------------------------------------
    // WAS UEBERALL GEBRAUCHT WIRD
    // -----------------------------------------------------------------
    'allgemein.gespeichert'     => 'Gespeichert.',
    'allgemein.interner_fehler' => 'Interner Fehler. Bitte versuchen Sie es später erneut.',

    // Woerter und Saetze, die an vielen Stellen gleich lauten. Sie stehen
    // hier und nicht je Seite noch einmal: "Löschen" ist ueberall dasselbe
    // Wort, und "Keine Verbindung" ueberall dieselbe Auskunft.
    'allgemein.loeschen'          => 'Löschen',
    'allgemein.keine_verbindung'  => 'Keine Verbindung. Bitte erneut versuchen.',
    'allgemein.nicht_geklappt'    => 'Das hat nicht geklappt.',
    'allgemein.unbekannter_fehler'=> 'unbekannter Fehler',
    'allgemein.bearbeiten'        => 'Bearbeiten',
    // Der Leereintrag einer Auswahlliste (App\Controller\SystemController).
    'allgemein.keine_auswahl'     => '— keine —',

    // -----------------------------------------------------------------
    // DIE DIALOGE DES BROWSERS (assets/js/notify.js)
    //
    // Sie ersetzen alert(), confirm() und prompt(). Was hier steht, sind die
    // VORGABEN: Ein Aufrufer, der einen eigenen Titel oder eine eigene
    // Knopfbeschriftung mitgibt, ueberschreibt sie.
    // -----------------------------------------------------------------
    'dialog.hinweis'             => 'Hinweis',
    'dialog.hinweis_schliessen'  => 'Hinweis schließen',
    'dialog.verstanden'          => 'Verstanden',
    'dialog.sicher'              => 'Sind Sie sicher?',
    'dialog.ja'                  => 'Ja',
    'dialog.abbrechen'           => 'Abbrechen',
    'dialog.eingabe'             => 'Eingabe',
    'dialog.speichern'           => 'Speichern',
    'dialog.pflicht'             => 'Bitte etwas eintragen.',
    'dialog.loeschen.titel'      => 'Datensatz löschen?',
    'dialog.loeschen.text'       => 'Das lässt sich nicht rückgängig machen.',

    // -----------------------------------------------------------------
    // DIE KOPFLEISTE (App\Helper\ViewHelper)
    //
    // DIE ZAEHLER STEHEN ALS FORMEN DA und nicht mehr als "Anfrage(n)".
    // Die Klammerform ist eine deutsche Notloesung fuer ein Problem, das
    // der Katalog loest - und im Englischen gaebe es sie gar nicht erst.
    // -----------------------------------------------------------------
    'kopf.anmelden'              => 'Anmelden',
    'kopf.menue.konto'           => 'Mein Konto',
    'kopf.menue.verwaltung'      => 'Verwaltung',
    'kopf.menue.angemeldet_als'  => 'Angemeldet als {name}',
    'kopf.menue.abmelden'        => 'Abmelden',

    'kopf.anfragen.text'   => 'Anfragen',
    'kopf.anfragen.titel'  => 'Ihre Anfragen',
    'kopf.anfragen.eingehend' => [
        'one'   => 'Eine Anfrage wartet auf Ihre Antwort',
        'other' => '{n} Anfragen warten auf Ihre Antwort',
    ],
    'kopf.anfragen.ausgehend' => [
        'one'   => 'Eine Ihrer Anfragen wurde angenommen',
        'other' => '{n} Ihrer Anfragen wurden angenommen',
    ],
    'kopf.anfragen.laufend' => [
        'one'   => 'Eine Führung ist noch nicht beendet',
        'other' => '{n} Führungen sind noch nicht beendet',
    ],

    'kopf.nachrichten.text'  => 'Nachrichten',
    'kopf.nachrichten.titel' => 'Ihre Nachrichten',
    'kopf.nachrichten.ungelesen' => [
        'one'   => '{n} ungelesene Nachricht',
        'other' => '{n} ungelesene Nachrichten',
    ],

    'kopf.bereit.an'         => 'Bereit',
    'kopf.bereit.aus'        => 'Nicht bereit',
    'kopf.bereit.titel_an'   => 'Sie sind als Guide anrufbar. Klicken beendet die Bereitschaft.',
    'kopf.bereit.titel_aus'  => 'Sie sind nicht anrufbar. Klicken stellt Sie auf bereit.',

    // Die beiden Knoepfe, die assets/js/ui.js in die Leiste schreibt. Welcher
    // davon erscheint, entscheidet window.userCan und nicht der Katalog.
    'kopf.knopf.bedingungen'    => 'Neue Bedingungen bestätigen',
    'kopf.knopf.standort_neu'   => 'Neue Lokation hinzufügen',
    'kopf.knopf.guide_werden'   => 'Jetzt Tour-Guide werden!',
    'kopf.knopf.alle_standorte' => 'Alle Standorte',

    // -----------------------------------------------------------------
    // DIE STANDORTSEITE (App\Helper\LocationView)
    // -----------------------------------------------------------------
    'standort.titel.fuehrung_in' => 'Führung in {ort}',
    'standort.titel.ohne_ort'    => 'Standort',

    'standort.zustand.eigen'             => 'Ihr Standort',
    'standort.zustand.eigen_bereit'      => 'Sie sind bereit – dieser Standort ist gerade anrufbar.',
    'standort.zustand.eigen_nicht_bereit'=> 'Sie sind nicht bereit – dieser Standort wird gedämpft angezeigt.',
    'standort.zustand.live'              => 'Jetzt verfügbar',
    'standort.zustand.busy'              => 'Im Gespräch',
    'standort.zustand.idle'              => 'Kein Guide vor Ort',

    'standort.sperre.wort'  => 'Gesperrt.',
    'standort.sperre.eigen' => 'Ihr Standort ist gesperrt und für andere Nutzer nicht sichtbar.',
    'standort.sperre.fremd' => 'Dieser Standort ist gesperrt und für andere Nutzer nicht sichtbar.',
    'standort.sperre.grund' => 'Grund: {grund}',
    // DASSELBE WORT ZWEIMAL, und der Punkt ist der Unterschied: Auf der
    // Standortseite steht es als SATZ ueber dem Ort, in der Standortliste als
    // MARKE in einer Zelle. Eine Marke mit Punkt sieht aus wie ein
    // abgeschnittener Satz.
    'standort.sperre.wort_kurz' => 'Gesperrt',

    'standort.bilder.titel' => 'Bilder vom Ort',
    'standort.bilder.alt'   => '{titel} – Bild {nr}',

    'standort.beschreibung.leer' => 'Der Guide hat noch keine ausführliche Beschreibung hinterlegt.',

    'standort.zeiten.titel'      => 'Meistens unterwegs',
    'standort.zeiten.titel_leer' => 'Übliche Zeiten',
    // {bearbeiten} traegt die Beschriftung des Knopfes, auf den der Satz
    // zeigt - hervorgehoben und deshalb als Platzhalter. Der ganze Satz
    // steht hier, damit die Wortstellung im Katalog entschieden wird.
    'standort.zeiten.leer'       => 'Sie haben noch keine angegeben. Kunden sehen dann nicht, wann sich eine Anfrage lohnt – über {knopf} lässt sich das nachtragen.',
    'standort.zeiten.bearbeiten' => 'Bearbeiten',
    'standort.zeiten.ortszeit'   => 'Ortszeit: {zone}',

    'standort.fakten.dauer'    => 'Dauer',
    'standort.fakten.sprachen' => 'Sprachen',
    'standort.fakten.ort'      => 'Ort',

    'standort.aktion.eigen'         => 'Den eigenen Standort fragt man nicht an. Was hier ansteht, sehen Sie unter {liste}; ob Sie gerade sofort anrufbar sind, entscheidet Ihr Bereitschaftsschalter in der Kopfleiste.',
    'standort.aktion.anfragen'      => 'Anfragen',
    'standort.aktion.gesperrt'      => 'Dieser Standort ist gesperrt. Von hier aus lässt sich keine Führung anfragen.',
    'standort.aktion.konto_noetig'  => 'Für eine Anfrage brauchen Sie ein Konto – der Guide muss wissen, wem er zusagt.',
    'standort.aktion.anmelden'      => 'Anmelden und anfragen',
    'standort.aktion.konto_anlegen' => 'Konto anlegen',

    'standort.frage.knopf'   => 'Frage an den Guide',
    'standort.frage.hinweis' => 'Rückfragen zum Ort oder zu möglichen Zeiten – der Guide antwortet Ihnen im Chat.',

    'standort.anfrage.hinweis_bereit'  => 'Der Guide ist gerade bereit – „jetzt sofort“ hat gute Aussichten.',
    'standort.anfrage.hinweis_offline' => 'Gerade ist niemand vor Ort. Das ist kein Hindernis: Fragen Sie für später an, und der Guide sagt zu oder ab.',
    'standort.anfrage.jetzt'           => 'Jetzt sofort',
    'standort.anfrage.in_1h'           => 'In 1 Stunde',
    'standort.anfrage.in_3h'           => 'In 3 Stunden',
    'standort.anfrage.morgen'          => 'Morgen um diese Zeit',
    'standort.anfrage.vorgaben'        => 'Vorgaben für den Wunschzeitpunkt',
    'standort.anfrage.wunschzeit'      => 'Wunschzeitpunkt',
    'standort.anfrage.absenden'        => 'Führung anfragen',

    'standort.anfrage.offen_marke'      => 'Anfrage offen',
    'standort.anfrage.offen_text'       => 'Ihre Anfrage für {wann} ist beim Guide. Sobald er antwortet, sehen Sie es hier und am Zähler in der Kopfleiste.',
    'standort.anfrage.zurueckziehen'    => 'Anfrage zurückziehen',
    'standort.anfrage.absagen'          => 'Absagen',
    'standort.anfrage.marke_laeuft'     => 'Läuft',
    'standort.anfrage.marke_angenommen' => 'Angenommen',
    'standort.anfrage.laeuft_abgerissen'=> 'Die Führung läuft noch – die Verbindung ist abgerissen. Sie können wieder einsteigen; Ihr Guide beendet die Führung, wenn Sie fertig sind.',
    'standort.anfrage.laeuft'           => 'Die Führung läuft noch. Ihr Guide beendet sie, wenn Sie fertig sind.',
    'standort.anfrage.startbereit'      => 'Der Guide hat zugesagt. Sie können jetzt starten – er wird angerufen.',
    'standort.anfrage.zugesagt'         => 'Der Guide hat für {wann} zugesagt. Kurz vorher lässt sich die Führung von hier aus starten.',
    'standort.anfrage.einsteigen'       => 'Wieder einsteigen',
    'standort.anfrage.starten'          => 'Führung starten',

    // Was der Browser waehrend des Anfragens sagt (assets/js/location_page.js).
    // Die Saetze darueber baut der Server beim ersten Aufbau der Seite; diese
    // hier entstehen erst durch eine Handlung und haben deshalb dort kein
    // Gegenstueck.
    'standort.anfrage.zeit_fehlt'       => 'Bitte einen Wunschzeitpunkt wählen – „Jetzt sofort“ ist auch einer.',
    'standort.anfrage.fehler'           => 'Die Anfrage konnte nicht gestellt werden.',
    'standort.anfrage.gestellt'         => 'Anfrage gestellt. Der Guide antwortet – Sie sehen es hier und am Zähler oben.',
    'standort.anfrage.zurueck_frage'    => 'Anfrage zurückziehen?',
    'standort.anfrage.zurueck_text'     => 'Der Guide sieht dann, dass die Führung nicht stattfindet.',
    'standort.anfrage.zurueck_knopf'    => 'Zurückziehen',
    'standort.anfrage.zurueckgezogen'   => 'Anfrage zurückgezogen.',
    'standort.anfrage.nicht_mehr_offen' => 'Ihre Anfrage ist nicht mehr offen. Was daraus geworden ist, steht unter „Anfragen“.',
    // Die beiden Hinweise unter dem Wunschzeitpunkt. Die Zeit und die
    // ueblichen Zeiten stehen als Platzhalter IM Satz - die Hervorhebung
    // setzt der Aufrufer ein (webrtcApp.tHtml).
    'standort.anfrage.ortszeit'         => 'Das ist {zeit} Ortszeit am Treffpunkt.',
    'standort.anfrage.ausserhalb'       => 'Das liegt außerhalb der üblichen Zeiten ({zeiten}). Anfragen können Sie trotzdem – der Guide entscheidet.',

    // Das Bearbeitungsformular. Was in der Vorlage steht
    // (assets/html/location_edit.html), holt sich diese ueber den Marker
    // {{t:schluessel}} - hier steht, was App\Helper\LocationView selbst
    // baut. Dieselben Schluessel benutzt assets/js/location_page.js, wenn es
    // eine Bildkachel nachtraegt: derselbe Knopf, derselbe Text.
    'standort.bearbeiten.raster_spalte'    => 'Diesen Abschnitt an allen Tagen an- oder abwählen',
    'standort.bearbeiten.raster_zeile'     => 'Diesen Tag ganz an- oder abwählen',
    'standort.bearbeiten.raster_feld'      => '{tag} {abschnitt}',
    'standort.bearbeiten.kein_titelbild'   => 'Noch kein Titelbild gewählt. Der Kopf der Seite zeigt so lange nur Titel und Ort. Wählen Sie unten eines Ihrer Bilder aus – am besten ein sehr breites mit ruhigen Flächen, auf denen die Schrift steht.',
    'standort.bearbeiten.titelbild_alt'    => 'Titelbild',
    'standort.bearbeiten.titelbild_zurueck'=> 'Zurück in die Galerie',
    'standort.bearbeiten.keine_bilder'     => 'Noch keine Beispielbilder hochgeladen.',
    'standort.bearbeiten.bild_alt'         => 'Bild {nr}',
    'standort.bearbeiten.bild_titelbild'   => 'Bild {nr} als Titelbild verwenden',
    'standort.bearbeiten.bild_vor'         => 'Bild {nr} nach vorne',
    'standort.bearbeiten.bild_zurueck'     => 'Bild {nr} nach hinten',
    'standort.bearbeiten.bild_loeschen'    => 'Bild {nr} löschen',
    'standort.bearbeiten.kurz_titelbild'   => 'Als Titelbild',
    'standort.bearbeiten.kurz_vor'         => 'Nach vorne',
    'standort.bearbeiten.kurz_zurueck'     => 'Nach hinten',
    'standort.bearbeiten.kurz_loeschen'    => 'Löschen',

    // Die Seite, die es nicht gibt (App\Controller\LocationController).
    'standort.fehlseite.titel' => 'Standort nicht gefunden',
    'standort.fehlseite.text'  => 'Dieser Standort wurde entfernt, gesperrt oder hat es nie gegeben.',
    'standort.fehlseite.karte' => 'Zur Karte',

    // Die Pruefmeldungen des Bearbeitungsformulars. Die Zahlen kommen aus
    // den Grenzen des Controllers und stehen nicht im Text: Wer die Grenze
    // aendert, soll nicht zwei Kataloge nachziehen muessen.
    'standort.fehler.titel_kurz'     => 'Der Titel muss mindestens {n} Zeichen lang sein.',
    'standort.fehler.titel_lang'     => 'Der Titel darf höchstens {n} Zeichen lang sein.',
    'standort.fehler.kurz_kurz'      => 'Die Kurzbeschreibung muss mindestens {n} Zeichen lang sein.',
    'standort.fehler.kurz_lang'      => 'Die Kurzbeschreibung darf höchstens {n} Zeichen lang sein.',
    'standort.fehler.lang_lang'      => 'Die ausführliche Beschreibung darf höchstens {n} Zeichen lang sein.',
    'standort.fehler.dauer_zahl'     => 'Die Dauer muss eine Zahl in Minuten sein.',
    'standort.fehler.dauer_bereich'  => 'Die Dauer muss zwischen {min} und {max} Minuten liegen.',
    'standort.fehler.nicht_gespeichert' => 'Die Änderung konnte nicht gespeichert werden.',
    'standort.fehler.keine_id'       => 'Kein Standort angegeben.',
    'standort.fehler.nicht_gefunden' => 'Standort nicht gefunden.',
    'standort.fehler.loeschen'       => 'Fehler beim Löschen.',
    'standort.fehler.grund_fehlt'    => 'Bitte einen Grund angeben.',
    // Die beiden Ablehnungen, die es nur beim ANLEGEN gibt: Land, Stadt und
    // Koordinaten lassen sich spaeter nicht mehr aendern, es gibt also auch
    // keine Pruefung dafuer beim Bearbeiten.
    'standort.fehler.kein_punkt'     => 'Nicht gespeichert: Bitte den Standort auf der Karte auswählen.',
    'standort.fehler.nicht_angelegt' => 'Der Standort konnte nicht angelegt werden.',

    'standort.bild.zu_viele'            => 'Mehr als {n} Bilder sind an einem Standort nicht möglich.',
    'standort.bild.keine_datei'         => 'Es wurde keine Datei geschickt.',
    'standort.bild.nicht_gespeichert'   => 'Das Bild konnte nicht gespeichert werden.',
    'standort.bild.keine_id'            => 'Kein Bild angegeben.',
    'standort.bild.nicht_gefunden'      => 'Bild nicht gefunden.',
    'standort.bild.keine_reihenfolge'   => 'Keine Reihenfolge angegeben.',
    'standort.bild.reihenfolge_fehler'  => 'Die Reihenfolge konnte nicht gespeichert werden.',
    'standort.bild.titelbild_fehler'    => 'Das Titelbild konnte nicht geändert werden.',

    // Was der Browser beim Verwalten der Bilder sagt
    // (assets/js/location_page.js). Die Pruefung der Datei ist dort eine
    // Hoeflichkeit - verbindlich ist die des Servers darueber.
    'standort.bild.titelbild_nicht_gesetzt' => 'Das Titelbild konnte nicht gesetzt werden.',
    'standort.bild.format'              => 'Dieses Bildformat wird nicht angenommen (JPEG, PNG oder WebP).',
    'standort.bild.zu_gross_mb'         => 'Die Datei ist zu groß – erlaubt sind {n} MB.',
    'standort.bild.hinzugefuegt'        => 'Bild hinzugefügt.',
    'standort.bild.loeschen_frage'      => 'Bild löschen?',
    'standort.bild.loeschen_text'       => 'Das Bild verschwindet von der Standortseite. Das lässt sich nicht rückgängig machen.',
    'standort.bild.nicht_geloescht'     => 'Das Bild konnte nicht gelöscht werden.',
    'standort.bild.grenze_erreicht'     => 'Die Obergrenze von {n} Bildern ist erreicht (Titelbild mitgezählt).',
    'standort.bild.noch_moeglich' => [
        'one'   => 'Noch ein Bild von {grenze} möglich, Titelbild mitgezählt.',
        'other' => 'Noch {n} von {grenze} Bildern möglich, Titelbild mitgezählt.',
    ],

    // -----------------------------------------------------------------
    // DER GUIDE (App\Helper\GuideView)
    // -----------------------------------------------------------------
    'guide.streifen.eigen'      => 'Sie bieten diese Führung an',
    'guide.streifen.fremd'      => 'Ihr Guide',
    'guide.streifen.mehr_eigen' => 'Ihr Profil',
    'guide.streifen.mehr'       => 'Profil ansehen',

    'guide.meta.seit'    => 'Guide seit {monat}',
    'guide.meta.spricht' => 'Spricht {sprachen}',

    'guide.ueber.titel' => 'Über mich',
    'guide.ueber.leer'  => 'Sie haben noch nichts über sich geschrieben. Ein paar Sätze darüber, wer Sie sind und warum Sie diese Orte zeigen, stehen auf jeder Ihrer Standortseiten – und sie sind das, was ein Kunde vor seiner Anfrage liest.',

    // "{name} zeigt Ihnen" steht als GANZER Satzkopf im Katalog: Im
    // Englischen steht der Name an anderer Stelle, und wer ihn im Code
    // davorsetzt, schreibt die deutsche Wortstellung fest.
    'guide.angebote.eigen'      => 'Ihre Standorte',
    'guide.angebote.fremd'      => '{name} zeigt Ihnen',
    'guide.angebote.leer_eigen' => 'Sie bieten noch keinen Standort an. Über „{anbieten}“ wird aus diesem Profil ein Angebot.',
    'guide.angebote.anbieten'   => 'Standort anbieten',
    'guide.angebote.leer_fremd' => 'Dieser Guide bietet gerade keinen Standort an.',
    'guide.angebot.ohne_titel'  => 'Führung',
    'guide.angebot.gesperrt'    => 'Gesperrt',

    'guide.werkzeuge.marke'       => 'Ihr Profil',
    'guide.werkzeuge.bearbeiten'  => 'Profil bearbeiten',

    'guide.formular.bild'                   => 'Bild',
    'guide.formular.bild_hinweis'           => 'Ein Bild von Ihnen, quadratisch zugeschnitten. Bis zu {mb} MB. Ohne Bild stehen Ihre Initialen dort – das ist besser als ein leerer Kreis, aber schlechter als ein Gesicht.',
    'guide.formular.bild_entfernen'         => 'Bild entfernen',
    'guide.formular.bild_entfernen_titel'   => 'Profilbild entfernen?',
    'guide.formular.bild_entfernen_frage'   => 'Das Bild wird gelöscht. Auf Ihren Standortseiten und in Ihrem Profil stehen danach wieder Ihre Initialen. Ein neues Bild können Sie jederzeit hochladen.',
    'guide.formular.bild_entfernen_ok'      => 'Entfernen',
    'guide.formular.anzeigename'            => 'Anzeigename',
    // {name} bringt sein eigenes Leerzeichen mit oder ist leer: Ein
    // Konto ohne Benutzernamen soll keine doppelte Luecke im Satz
    // hinterlassen. Siehe App\Helper\GuideView::formularHtml.
    'guide.formular.anzeigename_hinweis'    => 'Der Name, unter dem Kunden Sie sehen – auf Ihren Standortseiten, in der Standortliste und hier. Ihr Benutzername{name} bleibt davon unberührt; mit ihm melden Sie sich weiterhin an, und ein Kunde bekommt ihn nicht zu sehen. Ohne Anzeigenamen steht er dort allerdings weiterhin.',
    'guide.formular.ueber'                  => 'Über mich',
    'guide.formular.ueber_hinweis'          => 'Ein paar Sätze über sich. Der ERSTE SATZ steht auf jeder Ihrer Standortseiten neben Ihrem Bild – schreiben Sie ihn so, dass er allein schon etwas sagt.',
    'guide.formular.sprachen'               => 'Sprachen',
    'guide.formular.sprachen_hinweis'       => 'Die Sprachen, die Sie sprechen. Welche Sprachen für eine einzelne Führung gelten, steht weiterhin am Standort – das ist nicht dasselbe.',
    'guide.formular.speichern'              => 'Profil speichern',

    // -----------------------------------------------------------------
    // DIE GUIDE-FRAGE (App\Controller\GuideController)
    //
    // Das Rollenwort steht als Platzhalter im Satz, weil es hervorgehoben
    // wird - und weil im Englischen ein Artikel davor gehoert, den es im
    // Deutschen nicht gibt. Genau dafuer ist der ganze Satz im Katalog.
    // -----------------------------------------------------------------
    'guide.rolle.wort_guide'     => 'Guide',
    'guide.rolle.wort_zuschauer' => 'Zuschauer',
    'guide.rolle.status_bedingungen' => 'Sie sind {rolle}. Die Bedingungen haben sich geändert - bitte bestätigen Sie die neue Fassung. Bis dahin können Sie keine weiteren Standorte anlegen; Ihre bestehenden bleiben unberührt.',
    'guide.rolle.status_guide'       => 'Sie sind {rolle} und können Standorte anbieten.',
    'guide.rolle.status_zuschauer'   => 'Sie sind {rolle}. Sie können Führungen buchen, aber keine Standorte anbieten.',
    'guide.rolle.bestaetigen'        => 'Neue Bedingungen bestätigen',
    'guide.rolle.zurueckgeben'       => 'Guide-Rolle zurückgeben',
    'guide.rolle.ja'                 => 'Ja, ich möchte Guide werden',
    'guide.rolle.nein'               => 'Nein, ich möchte nur zuschauen',
    'guide.rolle.spaeter'            => 'Später entscheiden',
    'guide.rolle.locations'          => 'Meine Locations',
    'guide.rolle.hinweis_bedingungen'=> 'Die Bestätigung gilt ab sofort. An Ihren bestehenden Standorten ändert sich dadurch nichts.',
    'guide.rolle.hinweis_guide'      => 'Solange Sie noch Standorte anbieten, lässt sich die Rolle nicht zurückgeben - löschen Sie diese zuerst unter „{locations}“.',
    'guide.rolle.hinweis_offen'      => 'Sie können diese Entscheidung jederzeit in Ihren Einstellungen ändern.',
    'guide.rolle.zurueck'            => 'Zurück zu den Einstellungen',

    // -----------------------------------------------------------------
    // DAS GUIDE-PROFIL (App\Controller\GuideProfileController)
    // -----------------------------------------------------------------
    'guide.profil.nicht_gespeichert'      => 'Das Profil konnte nicht gespeichert werden.',
    'guide.profil.bild_nicht_gespeichert' => 'Das Bild konnte nicht gespeichert werden.',
    'guide.profil.bild_nicht_entfernt'    => 'Das Bild konnte nicht entfernt werden.',
    'guide.profil.bild_nicht_gefunden'    => 'Bild nicht gefunden.',

    // Die Seite fuer ein Profil, das es nicht gibt. Derselbe Aufbau wie
    // standort.fehlseite.* - es ist dieselbe Auskunft ueber eine andere Sache.
    'guide.fehlseite.titel' => 'Guide nicht gefunden',
    'guide.fehlseite.text'  => 'Dieses Profil gibt es nicht mehr, oder es hat es nie gegeben.',
    'guide.fehlseite.karte' => 'Zur Karte',
    'guide.rolle.fehler.uebernehmen' => 'Die Guide-Rolle konnte nicht übernommen werden. Bitte versuchen Sie es später erneut.',
    'guide.rolle.fehler.zurueckgeben'=> 'Die Guide-Rolle konnte nicht zurückgegeben werden. Bitte versuchen Sie es später erneut.',
    'guide.rolle.fehler.zustand'     => 'Für diese Antwort ist Ihr Konto nicht im richtigen Zustand.',
    'guide.rolle.fehler.antwort'     => 'Ihre Antwort konnte nicht gespeichert werden.',
    'guide.rolle.fehler.standorte'   => 'Sie bieten noch Standorte an. Bitte löschen Sie diese zuerst in Ihren Einstellungen unter „{locations}“ - danach können Sie die Guide-Rolle zurückgeben.',

    // -----------------------------------------------------------------
    // DIE BEWERTUNGEN (App\Helper\ReviewView, App\Model\TourReview)
    //
    // DER SATZ ANSTELLE EINES DURCHSCHNITTS steht als ganzer Satz hier und
    // wird nicht mehr aus Stuecken zusammengeklebt. Vorher hing im Code
    // eine Kette aus "durchgeführt", ", eine davon bewertet. " und
    // "Ab 3 Bewertungen ..." - jedes Stueck fuer sich uebersetzbar, der
    // Satz als Ganzes nicht.
    // -----------------------------------------------------------------
    'bewertung.block.titel'       => 'Bewertungen',
    'bewertung.block.titel_eigen' => 'Ihre Bewertungen',
    'bewertung.marke.keine'       => 'Noch keine Bewertung',
    'bewertung.marke.wenige'      => 'Noch wenige Bewertungen',

    'bewertung.jung.fuehrungen' => [
        'one'   => 'Eine Führung durchgeführt.',
        'other' => '{n} Führungen durchgeführt.',
    ],
    'bewertung.jung.fuehrungen_bewertet' => [
        'one'   => 'Eine Führung durchgeführt, {bewertet}.',
        'other' => '{n} Führungen durchgeführt, {bewertet}.',
    ],
    'bewertung.jung.davon_bewertet' => [
        'one'   => 'eine davon bewertet',
        'other' => '{n} davon bewertet',
    ],
    'bewertung.jung.keine_eigen'   => 'Bewertet hat noch niemand. Ab {min} Bewertungen steht hier ein Durchschnitt.',
    'bewertung.jung.keine_texte'   => 'Geschrieben hat darüber noch niemand.',
    'bewertung.jung.keine_fuehrung'=> 'Hier hat noch keine Führung stattgefunden.',
    'bewertung.jung.wenige_eigen'  => 'Ein Durchschnitt erscheint ab {min} Bewertungen – noch {fehlend}. Bis dahin steht hier keine Zahl: Eine einzelne Stimme sieht aus wie ein Urteil und ist keins.',
    'bewertung.jung.wenige_fremd'  => 'Für einen Durchschnitt sind es noch zu wenige – er erscheint ab {min} Bewertungen.',

    'bewertung.anzahl' => [
        'one'   => '{n} Bewertung',
        'other' => '{n} Bewertungen',
    ],
    'bewertung.sterne.label' => '{wert} von {max} Sternen',
    'bewertung.eintrag.zu'   => 'Zu „{titel}“',

    // Die Beschriftung der fuenf Stufen (App\Model\TourReview::starNames).
    'bewertung.stern.1' => 'Enttäuschend',
    'bewertung.stern.2' => 'Weniger gut',
    'bewertung.stern.3' => 'In Ordnung',
    'bewertung.stern.4' => 'Gut',
    'bewertung.stern.5' => 'Großartig',

    // Die Frage nach der Fuehrung (assets/js/review.js). Sie erscheint nach
    // dem Auflegen und in der Anfragenliste.
    'bewertung.frage.titel'            => 'Führung bewerten',
    'bewertung.frage.lead'             => 'Wie war die Führung mit {guide}?',
    'bewertung.frage.lead_titel'       => 'Wie war die Führung mit {guide}? – {titel}',
    'bewertung.frage.guide_unbekannt'  => 'Ihrem Guide',
    'bewertung.frage.sterne'           => 'Sterne',
    'bewertung.frage.text_label'       => 'Wenn Sie mögen, ein paar Sätze',
    'bewertung.frage.text_platzhalter' => 'Was sollten andere wissen?',
    'bewertung.frage.spaeter'          => 'Später',
    'bewertung.frage.absenden'         => 'Absenden',
    'bewertung.frage.fuss'             => 'Ihr Name steht nicht dabei. Der Guide kann die Bewertung nicht ändern und nicht löschen.',
    'bewertung.frage.sterne_zuerst'    => 'Bitte wählen Sie zuerst die Sterne.',
    'bewertung.frage.fehler'           => 'Die Bewertung konnte nicht gespeichert werden.',
    'bewertung.frage.danke'            => 'Danke – Ihre Bewertung steht beim Guide.',

    // Der Rueckfall, wenn der Server keine Stufenbeschriftung mitschickt.
    'bewertung.sterne.anzahl' => [
        'one'   => '{n} Stern',
        'other' => '{n} Sterne',
    ],

    // Die kurze Zeile in Liste und Kartenfenster, solange es keinen
    // Durchschnitt gibt. Dasselbe Muster wie bewertung.jung.* darueber: Der
    // Satz MIT Zusatz ist ein eigener Eintrag und nicht der Satz ohne ihn
    // plus Komma.
    'bewertung.kurz.fuehrungen' => [
        'one'   => 'Neu · eine Führung',
        'other' => 'Neu · {n} Führungen',
    ],
    'bewertung.kurz.fuehrungen_bewertet' => [
        'one'   => 'Neu · eine Führung, {bewertet}',
        'other' => 'Neu · {n} Führungen, {bewertet}',
    ],
    'bewertung.kurz.bewertungen' => [
        'one'   => 'eine Bewertung',
        'other' => '{n} Bewertungen',
    ],

    // Die Ablehnungen des Bewertungsendpunkts
    // (App\Controller\ReviewController). Sie erscheinen im Browser als
    // Hinweis ueber der Bewertungskarte.
    'bewertung.fehler.post'             => 'Diese Angabe wird nur per POST entgegengenommen.',
    'bewertung.fehler.zu_viele'         => 'Zu viele Bewertungen in kurzer Zeit. Bitte {warten} warten.',
    'bewertung.fehler.keine_fuehrung'   => 'Es fehlt die Führung.',
    'bewertung.fehler.keine_bewertung'  => 'Es fehlt die Bewertung.',
    'bewertung.fehler.sterne'           => 'Bitte wählen Sie zwischen einem und fünf Sternen.',
    'bewertung.fehler.nicht_bewertbar'  => 'Diese Führung lässt sich nicht bewerten. Vielleicht haben Sie sie schon bewertet.',
    'bewertung.fehler.nicht_entfernbar' => 'Diese Bewertung lässt sich nicht entfernen. Vielleicht ist sie es bereits.',

    // -----------------------------------------------------------------
    // DIE VERWALTUNG (App\Helper\AdminView)
    // -----------------------------------------------------------------
    'verwaltung.titel'      => 'Verwaltung',
    'verwaltung.untertitel' => 'Konten, Standorte und Bewertungen der Plattform.',
    'verwaltung.nav'        => 'Verwaltung',

    'verwaltung.reiter.uebersicht'  => 'Übersicht',
    'verwaltung.reiter.benutzer'    => 'Benutzer',
    'verwaltung.reiter.anfragen'    => 'Anfragen',
    'verwaltung.reiter.standorte'   => 'Standorte',
    'verwaltung.reiter.bewertungen' => 'Bewertungen',

    'verwaltung.vorrat.haengend' => [
        'one'   => 'Führung hängt',
        'other' => 'Führungen hängen',
    ],
    'verwaltung.vorrat.haengend_text' => 'Begonnen und von niemandem beendet. Solange das so bleibt, steht beim Kunden der Startknopf, und die Bewertung wird nicht fällig.',
    'verwaltung.vorrat.unbeantwortet' => [
        'one'   => 'Anfrage ohne Antwort',
        'other' => 'Anfragen ohne Antwort',
    ],
    'verwaltung.vorrat.unbeantwortet_text' => 'Verfallen, ohne dass der Guide zu- oder abgesagt hat – in den letzten {tage} Tagen. Der Kunde hat gewartet und nichts bekommen.',
    'verwaltung.vorrat.gesperrt' => [
        'one'   => 'Standort gesperrt',
        'other' => 'Standorte gesperrt',
    ],
    'verwaltung.vorrat.gesperrt_text' => 'Ein Vorgang, den jemand eröffnet hat und den jemand wieder schließen muss – oder bestätigen.',
    'verwaltung.vorrat.unvollstaendig' => [
        'one'   => 'Angebot unvollständig',
        'other' => 'Angebote unvollständig',
    ],
    'verwaltung.vorrat.unvollstaendig_text' => 'Ohne Nadel auf der Karte, ohne Bild, ohne Titel, ohne ausführliche Beschreibung oder ohne übliche Zeiten. Für den Guide sieht das fertig aus – er weiß ja, was er anbietet.',
    'verwaltung.vorrat.cron' => [
        'one'   => 'Konto hängt auf „online“',
        'other' => 'Konten hängen auf „online“',
    ],
    'verwaltung.vorrat.cron_text' => 'Seit über einer Viertelstunde kein Lebenszeichen, trotzdem nicht offline gesetzt: cron/check_online_status.php läuft nicht. Solange das so bleibt, steht in der Benutzerliste jedes Konto als erreichbar.',
    'verwaltung.vorrat.leer' => 'Nichts offen: keine hängende Führung, keine unbeantwortete Anfrage, kein gesperrter Standort, kein unvollständiges Angebot – und der Aufräumjob läuft.',

    'verwaltung.kachel.konten'              => 'Konten',
    'verwaltung.kachel.konten_neu'          => '{n} neu in {tage} Tagen',
    'verwaltung.kachel.konten_keine_neuen'  => 'keine neuen in {tage} Tagen',
    'verwaltung.kachel.standorte'           => 'Standorte',
    'verwaltung.kachel.standorte_anbieter'  => [
        'one'   => '{n} Konto bietet an',
        'other' => '{n} Konten bieten an',
    ],
    'verwaltung.kachel.standorte_gesperrt'  => '{n} davon gesperrt',
    'verwaltung.kachel.fuehrungen'          => 'Führungen',
    'verwaltung.kachel.fuehrungen_zeitraum' => 'in {tage} Tagen durchgeführt',
    'verwaltung.kachel.fuehrungen_fuss'     => '{gesamt} insgesamt · {offen} laufen gerade',
    'verwaltung.kachel.bewertungen'         => 'Bewertungen',
    'verwaltung.kachel.kein_schnitt'        => 'noch kein Durchschnitt',
    'verwaltung.kachel.schnitt'             => 'Durchschnitt {wert}',
    'verwaltung.kachel.entfernt'            => '{n} entfernt',

    'verwaltung.geloescht_schalter' => 'Gelöschte Konten',

    'verwaltung.ohne_titel'      => 'Ohne Titel',
    'verwaltung.konto_geloescht' => 'Konto gelöscht',

    'verwaltung.standorte.leer' => 'Keine Standorte.',
    'verwaltung.zustand.live'   => 'verfügbar',
    'verwaltung.zustand.busy'   => 'im Gespräch',
    'verwaltung.zustand.idle'   => 'nicht da',
    'verwaltung.mangel.ort'     => 'keine Nadel',
    'verwaltung.mangel.bild'    => 'kein Bild',
    'verwaltung.mangel.titel'   => 'kein Titel',
    'verwaltung.mangel.text'    => 'kein Text',
    'verwaltung.mangel.zeiten'  => 'keine Zeiten',
    'verwaltung.vollstaendig'   => 'vollständig',
    'verwaltung.gesperrt'       => 'gesperrt',
    'verwaltung.freigeben'      => 'Freigeben',
    'verwaltung.sperren'        => 'Sperren',

    'verwaltung.anfragen.leer'       => 'Nichts in dieser Ansicht.',
    'verwaltung.anfragen.haengt'     => 'hängt',
    'verwaltung.anfragen.laeuft_seit'=> 'läuft seit {spanne}',
    'verwaltung.anfragen.verfallen'  => 'verfallen {wann}',
    'verwaltung.anfragen.wunsch'     => 'Wunsch: {wann}',
    'verwaltung.chat_mit'            => 'Chat mit {name}',
    'verwaltung.guide_anschreiben'   => 'Guide anschreiben',

    // Die Benutzerliste (App\Controller\UserController). Die Zustandswoerter
    // sind ANDERE als in standort.zustand.*: Dort geht es um ein Angebot
    // ("kein Guide vor Ort"), hier um ein Konto ("nicht angemeldet").
    'verwaltung.benutzer.online'          => 'Online',
    'verwaltung.benutzer.offline'         => 'Offline',
    'verwaltung.benutzer.im_gespraech'    => 'Im Gespräch',
    'verwaltung.benutzer.bereit_titel'    => 'Hat sich auf bereit gestellt',
    'verwaltung.benutzer.anrufen'         => 'Anrufen',
    'verwaltung.benutzer.anschreiben'     => 'Anschreiben',
    'verwaltung.benutzer.bearbeiten_label'=> 'Benutzer {name} bearbeiten',
    'verwaltung.benutzer.loeschen_label'  => 'Benutzer {name} löschen',

    'verwaltung.bewertungen.leer'           => 'Keine Bewertungen.',
    'verwaltung.bewertungen.ohne_text'      => 'ohne Text',
    'verwaltung.bewertungen.entfernt_grund' => 'Entfernt: {grund}',
    'verwaltung.bewertungen.ohne_grund'     => 'ohne Grund',
    'verwaltung.bewertungen.entfernt'       => 'entfernt',
    'verwaltung.bewertungen.entfernen'      => 'Entfernen',
    'verwaltung.bewertungen.konto'          => '{rolle}: {name}',
    'verwaltung.rolle.guide'                => 'Guide',
    'verwaltung.rolle.kunde'                => 'Kunde',

    // Die drei Rueckfragen des Verwaltungsbereichs (assets/js/admin.js). Der
    // Titel des Standorts steht als Platzhalter IM Satz - samt der
    // Anfuehrungszeichen, denn die setzt jede Sprache anders.
    'verwaltung.sperre.titel'       => 'Standort sperren',
    'verwaltung.sperre.text'        => '„{titel}“ verschwindet aus Karte und Liste. Der Guide bekommt diesen Text in seiner Standortliste zu sehen. Gelöscht wird nichts.',
    'verwaltung.sperre.grund'       => 'Grund',
    'verwaltung.sperre.platzhalter' => 'Warum wird gesperrt?',
    'verwaltung.sperre.pflicht'     => 'Ohne Grund ist die Sperre für den Guide nicht nachvollziehbar.',
    'verwaltung.sperre.erledigt'    => 'Gesperrt.',

    'verwaltung.freigabe.titel'    => 'Sperre aufheben?',
    'verwaltung.freigabe.text'     => '„{titel}“ erscheint danach wieder auf der Karte und in der Liste.',
    'verwaltung.freigabe.erledigt' => 'Freigegeben.',

    'verwaltung.bewertung_weg.titel'    => 'Bewertung entfernen?',
    'verwaltung.bewertung_weg.text'     => 'Die Bewertung verschwindet von der Standortseite und vom Profil des Guides und zählt nicht mehr im Durchschnitt. Gelöscht wird sie nicht – sie bleibt hier nachvollziehbar stehen. Der Kunde kann diese Führung danach nicht erneut bewerten.',
    'verwaltung.bewertung_weg.erledigt' => 'Entfernt.',

    // -----------------------------------------------------------------
    // DER BILDSPEICHER (App\Helper\ImageStore)
    //
    // Diese Meldungen gehen als 'error' an den Browser und stehen dort in
    // einem Kasten neben dem Feld - sie sind Text der Oberflaeche und
    // keine Logzeile.
    // -----------------------------------------------------------------
    'bild.fehler.zu_gross'          => 'Die Datei ist zu groß.',
    'bild.fehler.nicht_empfangen'   => 'Die Datei konnte nicht empfangen werden.',
    'bild.fehler.grenze'            => 'Die Datei ist zu groß – erlaubt sind {mb} MB.',
    'bild.fehler.kein_bild'         => 'Das ist keine Bilddatei.',
    'bild.fehler.format'            => 'Dieses Bildformat wird nicht angenommen (JPEG, PNG oder WebP).',
    'bild.fehler.masse'             => 'Das Bild ist zu groß – erlaubt sind bis zu {kante} Punkte Kantenlänge.',
    'bild.fehler.nicht_lesbar'      => 'Das Bild konnte nicht gelesen werden.',
    'bild.fehler.nicht_gespeichert' => 'Das Bild konnte nicht gespeichert werden.',

    // -----------------------------------------------------------------
    // DIE KONTOSEITE (App\Controller\SettingsController)
    // -----------------------------------------------------------------
    'konto.2fa.aktiv'          => 'Aktiviert',
    'konto.2fa.inaktiv'        => 'Nicht aktiviert',
    'konto.2fa.deaktivieren'   => '2FA deaktivieren',
    'konto.2fa.einrichten'     => '2FA einrichten',

    'konto.guide.aktiv'            => 'Aktiv',
    'konto.guide.inaktiv'          => 'Nicht aktiv',
    'konto.guide.aktiv_offen'      => 'Aktiv {zusatz}',
    'konto.guide.offen_hinweis'    => '– neue Bedingungen offen',
    'konto.guide.knopf_bestaetigen'=> 'Neue Bedingungen bestätigen',
    'konto.guide.knopf_aendern'    => 'Guide-Rolle ändern',
    'konto.guide.knopf_werden'     => 'Guide werden',

    'konto.mail.label'  => 'E-Mail bestätigt',
    'konto.mail.ja'     => 'Bestätigt',
    'konto.mail.nein'   => 'Nicht bestätigt',
    'konto.mail.senden' => 'Bestätigungsmail senden',

    'konto.profil.titel'   => 'Mein Guide-Profil',
    'konto.profil.ansehen' => 'Öffentliches Profil ansehen',
    'konto.profil.hinweis' => 'Das sieht ein Kunde, bevor er eine Führung anfragt: auf jeder Ihrer Standortseiten und auf Ihrer eigenen Seite, die Sie weitergeben können. Ein Benutzername und ein farbiger Punkt sind keine Grundlage dafür, einem Fremden Geld zu geben.',

    'konto.farbprofil.unbekannt' => 'Unbekanntes Farbprofil.',
    'konto.farbprofil.fehler'    => 'Farbprofil konnte nicht gespeichert werden.',
    'konto.farbprofil.gespeichert' => 'Farbprofil gespeichert.',
    'konto.farbprofil.fehler_netz' => 'Farbprofil konnte nicht gespeichert werden. Bitte später erneut versuchen.',

    // -----------------------------------------------------------------
    // DIE REGISTRIERUNG (App\Controller\SignupController)
    // -----------------------------------------------------------------
    'registrierung.fehler.benutzername_vergeben'  => 'Der Benutzername ist bereits vergeben.',
    'registrierung.fehler.email_vergeben'         => 'Die E-Mail-Adresse ist bereits vergeben.',
    'registrierung.fehler.benutzername_geloescht' => 'Dieser Benutzername gehört zu einem gelöschten Konto und lässt sich nicht neu vergeben. Bitte wählen Sie einen anderen.',
    'registrierung.fehler.email_geloescht'        => 'Zu dieser E-Mail-Adresse gab es bereits ein Konto, das gelöscht wurde. Sie lässt sich deshalb nicht erneut verwenden. Bitte nutzen Sie eine andere Adresse oder wenden Sie sich an den Betreiber.',
    'registrierung.fehler.rennen'                 => 'Benutzername oder E-Mail-Adresse wurde soeben vergeben. Bitte versuchen Sie es noch einmal.',
    'registrierung.fehler.passwoerter'            => 'Die Passwörter stimmen nicht überein.',
    'registrierung.fehler.benutzername_ungueltig' => 'Ungültiger Benutzername. Nur Buchstaben/Zahlen/Unterstrich, 3-20 Zeichen.',
    'registrierung.fehler.email_ungueltig'        => 'Bitte gib eine gültige E-Mail-Adresse ein.',
    'registrierung.fehler.passwort_kurz'          => 'Das Passwort muss mindestens {n} Zeichen lang sein.',
    'registrierung.fehler.gesperrt'               => 'Zu viele Registrierungsversuche. Bitte {warten} warten.',
    'registrierung.fehler.unbekannt'              => 'Ein unbekannter Fehler ist aufgetreten.',

    // -----------------------------------------------------------------
    // DIE ANFRAGE (App\Controller\RequestController)
    // -----------------------------------------------------------------
    'anfrage.fehler.post'                 => 'Nur per POST.',
    'anfrage.fehler.zu_viele'             => 'Zu viele Anfragen in kurzer Zeit. Bitte {warten} warten.',
    'anfrage.fehler.standort_fehlt'       => 'Es fehlt der Standort.',
    'anfrage.fehler.zu_weit'              => 'So weit im Voraus lässt sich eine Führung nicht anfragen.',
    'anfrage.fehler.keine_anfragen'       => 'Dieser Standort nimmt keine Anfragen an.',
    'anfrage.fehler.eigener_standort'     => 'Den eigenen Standort fragt man nicht an.',
    'anfrage.fehler.bereits_offen'        => 'Für diesen Standort läuft bereits eine Anfrage von Ihnen.',
    'anfrage.fehler.nicht_gestellt'       => 'Die Anfrage konnte nicht gestellt werden.',
    'anfrage.fehler.anfrage_fehlt'        => 'Es fehlt die Anfrage.',
    'anfrage.fehler.nicht_beantwortbar'   => 'Diese Anfrage lässt sich nicht mehr beantworten.',
    'anfrage.fehler.nicht_zuruecknehmbar' => 'Diese Anfrage lässt sich nicht mehr zurücknehmen.',
    'anfrage.fehler.fuehrung_fehlt'       => 'Es fehlt die Führung.',
    'anfrage.fehler.nicht_beendbar'       => 'Diese Führung lässt sich nicht mehr beenden.',

    // -----------------------------------------------------------------
    // DIE WARTEZEIT EINER BREMSE (App\Model\RateLimit)
    //
    // EIN GANZER SATZTEIL UND KEINE ZAHL MIT EINHEIT: Der Aufrufer setzt
    // ihn in "Bitte {warten} warten." ein. Im Englischen steht dort
    // "another 3 minutes" - ein vorangestelltes "noch" waere die deutsche
    // Wortstellung im Code.
    // -----------------------------------------------------------------
    'warten.gleich' => 'gleich wieder',
    'warten.sekunden' => [
        'one'   => 'noch {n} Sekunde',
        'other' => 'noch {n} Sekunden',
    ],
    'warten.minuten' => [
        'one'   => 'noch {n} Minute',
        'other' => 'noch {n} Minuten',
    ],
    'warten.stunden' => [
        'one'   => 'noch {n} Stunde',
        'other' => 'noch {n} Stunden',
    ],
    // =================================================================
    // DIE VORLAGEN
    //
    // Ab hier stehen die Texte aus assets/html - die Stufe nach den Texten,
    // die PHP selbst erzeugt. Geholt werden sie mit dem Marker
    // {{t:schluessel}}, den App\Helper\ViewHelper::template() beim LADEN der
    // Datei aufloest.
    //
    // WAS NICHT ALS MARKER GEHT: ein Satz mit einem Platzhalter oder einer
    // Hervorhebung mittendrin. Der Marker nimmt keine Werte entgegen - das
    // ist Absicht, denn sonst waere er ein zweites Bauverfahren neben den
    // ###RAUTEN###. Solche Saetze stehen deshalb hier UND werden in PHP
    // gebaut (ViewHelper::tHtml); der Aufrufer steht jeweils im Kommentar
    // der Vorlage.
    // =================================================================

    // -----------------------------------------------------------------
    // DIE FUSSZEILE (assets/html/index.html)
    //
    // Der Name der Anwendung steht dort weiterhin als Text: Ein Produktname
    // wird nicht uebersetzt.
    // -----------------------------------------------------------------
    'fuss.rechtliches' => 'Rechtliches',
    'fuss.impressum'   => 'Impressum',
    'fuss.datenschutz' => 'Datenschutz',
    'fuss.kontakt'     => 'Kontakt',

    // -----------------------------------------------------------------
    // DER UMSCHALTER KARTE / LISTE / ANFRAGEN
    //
    // EIN Bauteil auf drei Seiten (Startseite, Standortliste,
    // Anfragenseite) - deshalb EIN Satz Schluessel. Drei Fassungen
    // desselben Wortes waeren drei Gelegenheiten, es verschieden zu
    // uebersetzen.
    // -----------------------------------------------------------------
    'ansicht.label'    => 'Ansicht',
    'ansicht.karte'    => 'Karte',
    'ansicht.liste'    => 'Liste',
    'ansicht.anfragen' => 'Anfragen',

    // Die Spalten der Standorttabelle. Sie steht auf der Standortliste und
    // noch einmal auf der Kontoseite (dort ohne die Spalte "Guide") - auch
    // hier: ein Bauteil, ein Satz Schluessel.
    'tabelle.spalte.status'        => 'Status',
    'tabelle.spalte.guide'         => 'Guide',
    'tabelle.spalte.bewertung'     => 'Bewertung',
    'tabelle.spalte.land'          => 'Land',
    'tabelle.spalte.stadt'         => 'Stadt',
    'tabelle.spalte.beschreibung'  => 'Beschreibung',
    'tabelle.spalte.aktionen'      => 'Aktionen',

    // DER SPRACHBLOCK VON DataTables (assets/js/locations_table.js).
    //
    // _MENU_, _START_, _END_, _TOTAL_ und _MAX_ sind KEINE Platzhalter dieser
    // Anwendung: DataTables setzt sie selbst ein, und I18n::einsetzen() laesst
    // sie unangetastet stehen. Sie heissen deshalb bewusst nicht {n} - ein
    // {n} wuerde hier gefuellt, und DataTables faende nichts mehr vor.
    'tabelle.suchen'          => 'Suchen',
    'tabelle.laenge'          => '_MENU_ Einträge',
    'tabelle.info'            => '_START_–_END_ von _TOTAL_',
    'tabelle.info_leer'       => 'Keine Einträge',
    'tabelle.info_gefiltert'  => '(gefiltert aus _MAX_)',
    'tabelle.nichts_gefunden' => 'Nichts gefunden.',
    'tabelle.erste'           => 'Erste',
    'tabelle.letzte'          => 'Letzte',
    'tabelle.weiter'          => 'Weiter',
    'tabelle.zurueck'         => 'Zurück',
    'tabelle.sort_auf'        => ': aufsteigend sortieren',
    'tabelle.sort_ab'         => ': absteigend sortieren',

    // Das Kreuz an jedem Dialog. Es steht in vier Vorlagen und meint
    // ueberall dasselbe.
    'allgemein.schliessen' => 'Schließen',

    // -----------------------------------------------------------------
    // ANMELDEN UND REGISTRIEREN
    // -----------------------------------------------------------------
    'anmelden.titel'        => 'Anmelden',
    'anmelden.untertitel'   => 'Weiter zu Ihren Führungen.',
    'anmelden.benutzername' => 'Benutzername',
    'anmelden.passwort'     => 'Passwort',
    'anmelden.knopf'        => 'Anmelden',
    'anmelden.kein_konto'   => 'Noch kein Konto?',
    'anmelden.registrieren' => 'Jetzt registrieren',

    // Die Absagen des Anmeldeformulars (App\Controller\LoginController). Die
    // Sperrmeldung gilt auch fuer den zweiten Faktor - es ist dieselbe
    // Anmeldung und dieselbe Bremse.
    'anmelden.fehler.falsch'   => 'Benutzername oder Passwort falsch.',
    'anmelden.fehler.gesperrt' => 'Zu viele Fehlversuche. Bitte {warten} warten.',

    'registrierung.titel'        => 'Konto anlegen',
    'registrierung.untertitel'   => 'Danach können Sie Führungen buchen – und selbst welche anbieten.',
    'registrierung.benutzername' => 'Benutzername',
    'registrierung.email'        => 'E-Mail-Adresse',
    'registrierung.passwort'     => 'Passwort',
    'registrierung.passwort_wdh' => 'Passwort wiederholen',
    'registrierung.knopf'        => 'Konto anlegen',
    'registrierung.schon_dabei'  => 'Schon registriert?',
    'registrierung.anmelden'     => 'Anmelden',

    // -----------------------------------------------------------------
    // DAS PASSWORT
    // -----------------------------------------------------------------
    'passwort.vergessen.titel'   => 'Passwort vergessen',
    'passwort.vergessen.text'    => 'Wir schicken Ihnen einen Link an die hinterlegte Adresse.',
    'passwort.vergessen.email'   => 'E-Mail-Adresse',
    'passwort.vergessen.knopf'   => 'Link anfordern',
    'passwort.vergessen.zurueck' => 'Zurück zur Anmeldung',

    'passwort.neu.titel'    => 'Neues Passwort setzen',
    'passwort.neu.feld'     => 'Neues Passwort',
    'passwort.neu.feld_wdh' => 'Neues Passwort wiederholen',
    'passwort.neu.knopf'    => 'Passwort ändern',

    'passwort.aendern.titel'      => 'Passwort ändern',
    // Der Name steht MITTEN im Satz und ist hervorgehoben - gebaut wird die
    // Zeile deshalb in App\Controller\PasswordController::angemeldetHtml().
    'passwort.aendern.angemeldet' => 'Angemeldet als {name}',
    'passwort.aendern.alt'        => 'Altes Passwort',
    'passwort.aendern.knopf'      => 'Passwort ändern',
    'passwort.aendern.abbrechen'  => 'Abbrechen',

    // Was beim Zuruecksetzen und beim Wechseln schiefgehen kann
    // (App\Controller\PasswordController).
    //
    // DIE ANTWORT AUF "PASSWORT VERGESSEN" IST IMMER DIESELBE - ob die
    // Adresse bekannt ist oder nicht. Sonst waere das Formular eine Auskunft
    // darueber, welche Adressen ein Konto haben.
    'passwort.vergessen.antwort'  => 'Falls diese E-Mail-Adresse hinterlegt ist, erhalten Sie eine Nachricht zum Zurücksetzen.',
    'passwort.fehler.alt_falsch'  => 'Das alte Passwort ist nicht korrekt.',
    'passwort.fehler.paar'        => 'Die Passwörter stimmen nicht überein oder sind zu kurz.',
    'passwort.fehler.link'        => 'Der Link ist ungültig oder abgelaufen.',

    // -----------------------------------------------------------------
    // DIE ZWEI-FAKTOR-ANMELDUNG (App\Controller\TwoFactorController)
    // -----------------------------------------------------------------
    'zweifaktor.schon_aktiv'          => 'Die Zwei-Faktor-Anmeldung ist bereits aktiviert.',
    'zweifaktor.aktiviert'            => 'Die Zwei-Faktor-Anmeldung ist aktiviert.',
    'zweifaktor.deaktiviert'          => 'Die Zwei-Faktor-Anmeldung ist abgeschaltet.',
    'zweifaktor.zurueck'              => 'Zurück',
    'zweifaktor.zurueck_einstellungen'=> 'Zurück zu den Einstellungen',
    'zweifaktor.zur_anmeldung'        => 'Zur Anmeldung',

    'zweifaktor.einrichten.titel'  => 'Zwei-Faktor-Anmeldung einrichten',
    'zweifaktor.einrichten.text'   => 'QR-Code mit der Authenticator-App scannen und den angezeigten sechsstelligen Code eintragen.',
    'zweifaktor.einrichten.qr_alt' => 'QR-Code für die Authenticator-App',
    'zweifaktor.einrichten.code'   => 'Code aus der App',
    'zweifaktor.einrichten.knopf'  => 'Aktivieren',

    'zweifaktor.pruefen.titel' => 'Bestätigungscode',
    'zweifaktor.pruefen.text'  => 'Der sechsstellige Code aus Ihrer Authenticator-App.',
    'zweifaktor.pruefen.code'  => 'Code',
    'zweifaktor.pruefen.knopf' => 'Anmelden',

    'zweifaktor.fehler.titel'    => 'Anmeldung nicht abgeschlossen',
    'zweifaktor.fehler.code'     => 'Ungültiger Code. Bitte erneut versuchen.',
    'zweifaktor.fehler.qr'       => 'Bitte den QR-Code erneut scannen.',
    'zweifaktor.fehler.login'    => 'Die Anmeldung mit dem zweiten Faktor ist fehlgeschlagen.',
    // Eigener Satz und nicht anmelden.fehler.gesperrt: Hier ist die Sitzung
    // verworfen, es muss also von vorn angefangen werden.
    'zweifaktor.fehler.gesperrt' => 'Zu viele Fehlversuche. Bitte {warten} warten und dann neu anmelden.',

    // -----------------------------------------------------------------
    // DIE BESTAETIGUNG DER E-MAIL-ADRESSE
    // (App\Controller\EmailVerificationController)
    // -----------------------------------------------------------------
    'mailbestaetigung.keine_mail'     => 'Keine neue Mail verschickt',
    'mailbestaetigung.gebremst'       => 'Es wurde bereits eine Bestätigungsmail verschickt. Bitte sehen Sie auch im Spam-Ordner nach. Für einen neuen Versand bitte {warten} warten.',
    'mailbestaetigung.zur_startseite' => 'Zur Startseite',

    // Die Rueckmeldung nach dem Wechsel. Sie kommt als Marke in der Adresse
    // zurueck (change=1) und wird im Browser gezeigt - siehe
    // assets/js/main.js.
    'passwort.geaendert' => 'Passwort geändert.',

    // -----------------------------------------------------------------
    // DIE STARTSEITE
    // -----------------------------------------------------------------
    'startseite.titel'       => 'Wohin möchten Sie heute?',
    'startseite.untertitel'  => 'Wählen Sie einen Ort auf der Karte. Ein Guide vor Ort nimmt Sie mit – live, und Sie sagen, wohin.',
    'startseite.erklaerung'  => 'Wie funktioniert das?',
    'startseite.karte_label' => 'Karte der angebotenen Standorte',
    'startseite.laden'       => 'Standorte werden geladen …',

    'startseite.legende.live'  => 'Guide jetzt verfügbar',
    'startseite.legende.busy'  => 'Guide im Gespräch',
    'startseite.legende.idle'  => 'Standort ohne Guide',
    'startseite.legende.eigen' => 'Ihr Standort',

    'startseite.gast.eyebrow'  => 'Live geführt statt vorbeigescrollt',
    'startseite.gast.titel'    => 'Jemand geht für Sie los.',
    'startseite.gast.text'     => 'Wie eine Straßenansicht – nur in echt und in diesem Moment. Ein Mensch vor Ort überträgt sein Bild, Sie sagen ihm, wo es langgeht. Melden Sie sich an, um zu sehen, wer gerade unterwegs ist.',
    'startseite.gast.konto'    => 'Konto anlegen',
    'startseite.gast.anmelden' => 'Anmelden',
    'startseite.gast.fuss'     => 'Sie sind selbst irgendwo, das andere sehen wollen? Nach der Anmeldung können Sie Ihren Standort anbieten und Führungen geben.',

    'startseite.leer.eyebrow' => 'Noch nichts auf der Karte',
    'startseite.leer.titel'   => 'Hier ist noch niemand unterwegs.',
    'startseite.leer.text'    => 'Es sind bislang keine Standorte eingetragen. So funktioniert es, sobald jemand einen anbietet – und Sie können der Erste sein.',
    'startseite.leer.guide'   => 'Guide werden',
    'startseite.leer.liste'   => 'Zur Listenansicht',
    'startseite.leer.fuss'    => 'Als Guide tragen Sie einen Ort ein, an dem Sie sich auskennen. Wenn Sie online sind, erscheint er hervorgehoben auf dieser Karte – und Sie werden angerufen.',

    'startseite.fehler.eyebrow' => 'Karte nicht geladen',
    'startseite.fehler.titel'   => 'Die Standorte sind gerade nicht abrufbar.',
    'startseite.fehler.text'    => 'Der Server hat nicht geantwortet. Das liegt meist an der Verbindung und ist nach einem erneuten Versuch behoben.',
    'startseite.fehler.knopf'   => 'Erneut versuchen',

    'startseite.schritt1.titel' => 'Ort wählen',
    'startseite.schritt1.text'  => 'Auf der Karte sehen Sie, wo gerade ein Guide bereitsteht.',
    'startseite.schritt2.titel' => 'Anrufen',
    'startseite.schritt2.text'  => 'Ein Klick auf die Nadel startet das Gespräch. Ton und Bild kommen direkt von unterwegs.',
    'startseite.schritt3.titel' => 'Führen',
    'startseite.schritt3.text'  => 'Mit den Pfeiltasten geben Sie die Richtung vor. Der Guide hört und sieht Ihren Wunsch und geht dorthin.',

    // Das Fenster an einer Kartennadel (assets/js/home_map.js). Die
    // Zustandsmarken darin kommen aus standort.zustand.* - es ist derselbe
    // Zustand desselben Standorts.
    'startseite.karte.eigen_bereit'      => 'Sie sind bereit – für andere ist dieser Standort gerade hervorgehoben.',
    'startseite.karte.eigen_nicht_bereit'=> 'Solange Sie nicht bereit sind, wird dieser Standort gedämpft angezeigt.',
    'startseite.karte.eigen_knopf'       => 'Standort ansehen und bearbeiten',
    'startseite.karte.gesperrt'          => 'Gesperrt. Der Standort ist für andere nicht sichtbar.',
    'startseite.karte.gesperrt_grund'    => 'Gesperrt: {grund}. Der Standort ist für andere nicht sichtbar.',
    'startseite.karte.busy'              => 'Der Guide ist gerade in einer anderen Führung.',
    'startseite.karte.idle'              => 'Gerade ist niemand vor Ort. Der Standort bleibt buchbar, sobald der Guide bereit ist.',
    'startseite.karte.knopf'             => 'Standort ansehen',
    'startseite.karte.knopf_live'        => 'Führung ansehen',

    // Die beiden Zaehler ueber der Karte.
    'startseite.karte.guides' => [
        'one'   => '1 Guide verfügbar',
        'other' => '{n} Guides verfügbar',
    ],
    'startseite.karte.standorte' => [
        'one'   => '1 Standort',
        'other' => '{n} Standorte',
    ],

    // -----------------------------------------------------------------
    // DIE STANDORTLISTE
    // -----------------------------------------------------------------
    'standortliste.titel'      => 'Alle Standorte',
    'standortliste.untertitel' => 'Dieselben Standorte wie auf der Karte, hier zum Durchsuchen und Sortieren.',

    // Die Tabelle selbst (assets/js/locations_table.js).
    //
    // DIE ZUSTANDSWOERTER SIND HIER ANDERE als in standort.zustand.*, und das
    // ist Absicht: Die Spalte ist schmal und beantwortet eine engere Frage -
    // "kann ich hier jetzt eine Fuehrung bekommen".
    'standortliste.zustand.live' => 'Verfügbar',
    'standortliste.zustand.busy' => 'Im Gespräch',
    'standortliste.zustand.idle' => 'Nicht verfügbar',

    'standortliste.leer'                 => 'Keine Standorte vorhanden.',
    'standortliste.fehler_laden'         => 'Fehler beim Laden der Daten.',
    'standortliste.falsch_konfiguriert'  => 'Diese Tabelle ist falsch konfiguriert.',
    'standortliste.loeschen_label'       => 'Standort {ort} löschen',
    'standortliste.loeschen_frage'       => 'Standort löschen?',
    'standortliste.loeschen_text'        => 'Der Standort verschwindet von der Karte und aus allen Listen. Das lässt sich nicht rückgängig machen.',
    'standortliste.geloescht'            => 'Standort gelöscht.',
    'standortliste.loeschen_fehler'      => 'Der Standort konnte nicht gelöscht werden.',
    'standortliste.nicht_zugeordnet'     => 'Der Standort konnte nicht zugeordnet werden.',
    'standortliste.ansehen'              => 'Ansehen',

    // -----------------------------------------------------------------
    // DAS ANLEGEFORMULAR MIT KARTE (assets/js/map.js)
    // -----------------------------------------------------------------
    'standort.karte.select2_fehlt'    => 'Die Auswahlfelder konnten nicht geladen werden, weil eine benötigte Bibliothek (select2) fehlt. Bitte die Seite neu laden. Besteht das Problem weiter, ist vermutlich die Internetverbindung oder ein Werbeblocker die Ursache.',
    'standort.karte.laender_leer'     => 'Es konnten keine Länder geladen werden. Ohne Land ist keine Städtesuche möglich. Bitte an den Administrator wenden – die Länderdaten fehlen in der Datenbank.',
    'standort.karte.laender_fehler'   => 'Die Länderliste konnte nicht geladen werden. Bitte die Seite neu laden. Besteht das Problem weiter, ist der Server nicht erreichbar oder die Datenbank nicht verfügbar.',
    'standort.karte.punkt_fehlt'      => 'Es fehlt der Punkt auf der Karte. Bitte in die Karte klicken, eine Stadt wählen oder „Aktuellen Standort verwenden“ – erst dann lässt sich der Standort speichern.',
    'standort.karte.keine_ansicht'    => 'Für dieses Land steht keine Kartenansicht zur Verfügung.',
    'standort.karte.land_zuerst'      => 'Bitte zuerst ein Land wählen',
    'standort.karte.keine_ortung'     => 'Ihr Browser unterstützt keine Standortbestimmung.',
    'standort.karte.ortung_fehler'    => 'Standort konnte nicht ermittelt werden: {grund}',
    'standort.karte.keine_stadt_am_ort' => 'keine Stadt am Standort',

    // Der Sprachblock von select2. Die Zahl im ersten Satz kommt aus
    // derselben Konstante wie minimumInputLength (map.STADT_MIN_ZEICHEN) und
    // steht deshalb als Platzhalter da.
    'stadtsuche.zu_kurz'          => 'Bitte mindestens {n} Buchstaben eingeben.',
    'stadtsuche.keine_stadt'      => 'Keine Stadt gefunden.',
    'stadtsuche.land_zuerst'      => 'Bitte zuerst ein Land wählen.',
    'stadtsuche.nicht_erreichbar' => 'Die Städtesuche ist gerade nicht erreichbar. Bitte einen Moment warten und noch einmal tippen.',

    // -----------------------------------------------------------------
    // DER SCHALTER "BEREIT" (assets/js/availability.js)
    //
    // Der Knopf selbst kommt vom Server und benutzt kopf.bereit.*; hier steht
    // nur, was durch eine Handlung oder durch den Ablauf der Frist entsteht.
    // -----------------------------------------------------------------
    'bereit.jetzt_anrufbar' => 'Sie sind jetzt als Guide anrufbar – {rest}.',
    'bereit.beendet'        => 'Bereitschaft beendet. Ihre Standorte sind nicht mehr anrufbar.',
    'bereit.fehler'         => 'Die Bereitschaft konnte nicht geändert werden. Bitte erneut versuchen.',
    'bereit.abgelaufen'     => 'Ihre Bereitschaft ist abgelaufen – Sie sind nicht mehr anrufbar. Zum Weiterführen wieder auf „Bereit“ stellen.',
    'bereit.titel_an_rest'  => 'Sie sind als Guide anrufbar ({rest}). Klicken beendet die Bereitschaft.',

    // Die Restzeit. Der Doppelpunkt zwischen Stunden und Minuten steht MIT im
    // Text - er ist ein Trennzeichen und keine Rechenvorschrift.
    'bereit.rest.aus'      => 'nicht bereit',
    'bereit.rest.sekunden' => 'noch {n} Sek',
    'bereit.rest.minuten'  => 'noch {n} Min',
    'bereit.rest.stunden'  => 'noch {std}:{min} Std',

    // -----------------------------------------------------------------
    // DIE LAUFENDE FUEHRUNG (assets/js/tour.js, assets/js/requests.js)
    // -----------------------------------------------------------------
    'fuehrung.beenden'  => 'Führung beenden',
    'fuehrung.beendet'  => 'Führung beendet.',

    'fuehrung.karte.titel'            => 'Laufende Führung',
    'fuehrung.karte.kunde_unbekannt'  => 'Ihrem Kunden',
    'fuehrung.karte.lead'             => 'Ihre Führung mit {kunde} ist noch nicht beendet.',
    'fuehrung.karte.lead_titel'       => 'Ihre Führung mit {kunde} – {titel} – ist noch nicht beendet.',
    'fuehrung.karte.hinweis'          => 'Aufgelegt heißt nicht beendet: Solange die Führung offen ist, können Sie und Ihr Kunde wieder einsteigen.',
    'fuehrung.karte.hinweis_frist'    => 'Aufgelegt heißt nicht beendet: Solange die Führung offen ist, können Sie und Ihr Kunde wieder einsteigen – {rest}.',
    'fuehrung.karte.spaeter'          => 'Später',
    'fuehrung.karte.fuss'             => 'Erst nach dem Beenden ist die Führung abgeschlossen. Ihr Kunde kann sie dann nicht mehr neu starten und wird nach einer Bewertung gefragt.',

    'fuehrung.beenden_frage.titel' => 'Führung beenden?',
    'fuehrung.beenden_frage.text'  => 'Danach ist die Führung abgeschlossen: Sie und Ihr Kunde können nicht mehr einsteigen, der Startknopf verschwindet, und Ihr Kunde wird nach einer Bewertung gefragt. Rückgängig geht das nicht.',
    'fuehrung.beenden_frage.knopf' => 'Beenden',

    'fuehrung.rest.unter_minute' => 'noch weniger als eine Minute',
    'fuehrung.rest.minuten' => [
        'one'   => 'noch etwa eine Minute',
        'other' => 'noch etwa {n} Minuten',
    ],
    'fuehrung.rest.stunden' => [
        'one'   => 'noch etwa eine Stunde',
        'other' => 'noch etwa {n} Stunden',
    ],

    // -----------------------------------------------------------------
    // STANDORT ANBIETEN UND BEARBEITEN
    //
    // Beide Formulare benutzen dieselben Feldbeschriftungen: Es sind
    // dieselben Felder, geprueft von derselben Methode.
    // -----------------------------------------------------------------
    'standort.anbieten.titel'      => 'Standort anbieten',
    'standort.anbieten.untertitel' => 'Der Ort, an dem Sie führen. Er erscheint auf der Karte – hervorgehoben, sobald Sie online sind.',

    'standort.formular.land'              => 'Land',
    'standort.formular.land_waehlen'      => 'Land wählen …',
    'standort.formular.stadt'             => 'Stadt',
    'standort.formular.stadt_waehlen'     => 'Stadt wählen …',
    'standort.formular.titel'             => 'Titel',
    'standort.formular.titel_platzhalter' => 'Worum geht es bei dieser Führung?',
    'standort.formular.kurz'              => 'Kurzbeschreibung',
    'standort.formular.kurz_platzhalter'  => 'Eine Zeile – sie steht auf der Karte und in der Liste',
    'standort.formular.kurz_hinweis'      => 'Diese Zeile sehen andere im Kartenfenster und in der Standortliste. Der ausführliche Text steht auf der Standortseite.',
    'standort.formular.lang'              => 'Ausführliche Beschreibung',
    'standort.formular.lang_platzhalter'  => 'Was zeigen Sie? Wo treffen wir uns? Was sollte man wissen?',
    'standort.formular.lang_hinweis'      => 'Kann auch später ergänzt werden – auf der Standortseite.',
    'standort.formular.dauer'             => 'Typische Dauer (Minuten)',
    'standort.formular.dauer_platzhalter' => 'z. B. 45',
    'standort.formular.dauer_hinweis'     => 'Leer lassen, wenn es keine übliche Dauer gibt.',
    'standort.formular.sprachen'          => 'Sprachen, in denen Sie führen',
    'standort.formular.punkt'             => 'Punkt auf der Karte',
    'standort.formular.aktueller_ort'     => 'Aktuellen Standort verwenden',
    'standort.formular.breitengrad'       => 'Breitengrad',
    'standort.formular.laengengrad'       => 'Längengrad',
    'standort.formular.osm'               => 'Ort laut OpenStreetMap',
    'standort.formular.speichern'         => 'Standort speichern',
    'standort.formular.abbrechen'         => 'Abbrechen',

    'standort.bearbeiten.titel'          => 'Ihr Standort',
    'standort.bearbeiten.umschalten'     => 'Bearbeiten',
    'standort.bearbeiten.kurz_hinweis'   => 'Diese Zeile sehen andere im Kartenfenster und in der Standortliste. Der ausführliche Text steht nur hier.',
    'standort.bearbeiten.zeiten_frage'   => 'Wann sind Sie üblicherweise unterwegs?',
    'standort.bearbeiten.zeiten_hinweis' => 'Grobe Orientierung für Kunden – keine feste Zusage. Anfragen zu anderen Zeiten bleiben möglich.',
    'standort.bearbeiten.zone'           => 'Zeitzone des Ortes',
    'standort.bearbeiten.zone_hinweis'   => 'Ihre Zeiten gelten am Ort der Führung. Kunden in anderen Zeitzonen sehen beides. Vorbelegt ist die Zone, die sich aus Land und Koordinaten ergibt.',
    'standort.bearbeiten.speichern'      => 'Speichern',
    'standort.bearbeiten.abbrechen'      => 'Abbrechen',
    'standort.bearbeiten.titelbild'      => 'Titelbild',
    'standort.bearbeiten.galerie'        => 'Bilder vom Ort',
    // ZWEI ZAHLEN IN EINEM SATZ - deshalb kein Marker, sondern gebaut in
    // App\Helper\LocationView::bearbeitenHtml().
    'standort.bearbeiten.zahl'           => 'Bis zu {max} Bilder insgesamt, Titelbild mitgezählt – derzeit {bisher}.',
    'standort.bearbeiten.hinzufuegen'    => 'Bild hinzufügen',

    'standort.zurueck'              => 'Zurück zur Übersicht',
    'standort.treffpunkt'           => 'Treffpunkt',
    'standort.lightbox.vorheriges'  => 'Vorheriges Bild',
    'standort.lightbox.naechstes'   => 'Nächstes Bild',

    // -----------------------------------------------------------------
    // DIE ANFRAGENSEITE
    // -----------------------------------------------------------------
    'anfrage.seite.titel'          => 'Anfragen',
    'anfrage.seite.untertitel'     => 'Was an Ihre Standorte gerichtet ist – und was Sie selbst angefragt haben.',
    'anfrage.seite.eingehend'      => 'An meine Standorte',
    'anfrage.seite.eingehend_leer' => 'Hier ist gerade nichts offen. Sobald jemand eine Führung an einem Ihrer Standorte anfragt, steht sie hier – und der Zähler in der Kopfleiste sagt es Ihnen.',
    'anfrage.seite.ausgehend'      => 'Meine Anfragen',
    'anfrage.seite.ausgehend_leer' => 'Sie haben noch keine Führung angefragt. Suchen Sie sich auf der Karte einen Ort aus – auf seiner Seite fragen Sie die Führung mit Ihrem Wunschzeitpunkt an.',
    'anfrage.seite.fehler'         => 'Die Anfragen konnten nicht geladen werden. Das liegt meist an der Verbindung.',

    // -----------------------------------------------------------------
    // DIE GUIDE-FRAGE
    //
    // Die beiden Absaetze tragen eine Hervorhebung MITTEN im Satz und
    // stehen deshalb nicht als Marker in der Vorlage - gebaut werden sie in
    // App\Controller\GuideController. Der Absatz zu den Kosten dagegen hat
    // seine Hervorhebung am ANFANG; dort genuegen zwei Marker nebeneinander.
    // -----------------------------------------------------------------
    'guide.rolle.frage'                 => 'Möchten Sie Guide werden?',
    'guide.rolle.was_guide.titel'       => 'Was ein Guide macht',
    'guide.rolle.was_guide.text'        => 'Als {rolle} bieten Sie Standorte an: Orte, an denen Sie sich auskennen und an denen Sie unterwegs sein können. Bucht jemand eine Führung, sind Sie mit Kamera und Ton vor Ort - und {regie}. Er sagt Ihnen über ein Steuerkreuz, wohin Sie gehen und wohin Sie schauen sollen. Sie entscheiden dabei jederzeit selbst, ob Sie einem Befehl folgen, und können die Steuerung im laufenden Call sperren.',
    'guide.rolle.was_guide.regie'       => 'die Regie hat der Zuschauer',
    'guide.rolle.was_zuschauer.titel'   => 'Was ein Zuschauer macht',
    'guide.rolle.was_zuschauer.text'    => 'Als {rolle} suchen Sie sich einen Standort auf der Karte aus und lassen sich von einem Guide vor Ort herumführen. Dafür brauchen Sie nichts anzubieten und Ihre eigene Position spielt keine Rolle.',
    'guide.rolle.kosten.titel'          => 'Hinweis zu späteren Kosten:',
    'guide.rolle.kosten.text'           => 'Führungen sind derzeit kostenlos. Sie werden künftig kostenpflichtig - Zuschauer zahlen für eine Führung, Guides erhalten dafür eine Vergütung. Bevor das in Kraft tritt, legen wir Ihnen die dann geltenden Bedingungen erneut zur Zustimmung vor. Ohne Ihre Zustimmung entstehen weder Kosten noch Ansprüche.',

    // -----------------------------------------------------------------
    // DIE KONTOSEITE
    // -----------------------------------------------------------------
    'konto.titel'      => 'Mein Konto',
    'konto.untertitel' => 'Anmeldedaten, Sicherheit und die eigenen Standorte.',

    'konto.angaben.titel'                => 'Angaben',
    'konto.angaben.benutzername'         => 'Benutzername',
    'konto.angaben.benutzername_hinweis' => 'Nur für die Anmeldung. Kunden sehen Ihren Anzeigenamen aus dem Guide-Profil.',
    'konto.angaben.email'                => 'E-Mail-Adresse',
    'konto.angaben.zweifaktor'           => 'Zwei-Faktor-Anmeldung',
    'konto.angaben.guide_rolle'          => 'Guide-Rolle',
    'konto.passwort_aendern'             => 'Passwort ändern',

    'konto.farbprofil.titel'   => 'Farbprofil',
    'konto.farbprofil.hinweis' => 'Gilt für dieses Konto und ist beim nächsten Anmelden wieder da. Die Farben der Kartennadeln bleiben in jedem Profil gleich.',

    'konto.standorte.titel'    => 'Meine Standorte',
    'konto.standorte.anbieten' => 'Standort anbieten',

    // Der Platzhalter, der fuer ein geloeschtes Konto stehenbleibt
    // (App\Model\User::nameGeloescht).
    'konto.geloescht' => 'Gelöschtes Konto',

    // -----------------------------------------------------------------
    // DIE FARBPROFILE
    //
    // Sie standen als 'name' und 'text' in App\Helper\Theme::PROFILE - zwei
    // deutsche Saetze in einer Konstanten, die sonst nur Farbwerte fuehrt.
    // Der Schluessel hier ist derselbe wie dort; die Vorschaufarben bleiben
    // im Code, denn sie sind Kopien aus assets/css/theme.css.
    // -----------------------------------------------------------------
    'farbprofil.indigo.name'     => 'Indigo',
    'farbprofil.indigo.text'     => 'Die Vorgabe. Kühles Grau mit indigoblauem Akzent.',
    'farbprofil.himmelblau.name' => 'Himmelblau',
    'farbprofil.himmelblau.text' => 'Hell und freundlich, mit leicht blauer Grundfläche.',
    'farbprofil.dunkel.name'     => 'Dunkel',
    'farbprofil.dunkel.text'     => 'Dunkle Flächen für Abende und dunkle Räume.',
    'farbprofil.neutral.name'    => 'Neutral',
    'farbprofil.neutral.text'    => 'Sehr zurückhaltend, ohne farbigen Akzent.',

    // -----------------------------------------------------------------
    // DER HINWEIS AUF DIE UNBESTAETIGTE ADRESSE (App\Helper\MailGate)
    // -----------------------------------------------------------------
    'mailhinweis.gesperrt'        => 'Bitte bestätige zuerst deine E-Mail-Adresse. Danach steht diese Funktion wieder zur Verfügung.',
    'mailhinweis.streifen.titel'  => 'E-Mail-Adresse noch nicht bestätigt.',
    'mailhinweis.streifen.text'   => 'Anfragen, Chat und das Hochladen von Bildern sind bis dahin gesperrt.',
    'mailhinweis.streifen.knopf'  => 'Bestätigungsmail senden',

    // -----------------------------------------------------------------
    // DIE VERWALTUNG - Vorlagen und die Stuecke, die daneben stehen
    //
    // Spaltenkoepfe und Filterleisten stehen teils in der Vorlage, teils im
    // Controller (weil sie an einem Recht haengen). Beide sind hier
    // mitgezogen: eine halb uebersetzte Kopfzeile waere schlimmer als beides.
    // -----------------------------------------------------------------
    'verwaltung.uebersicht.vorrat'  => 'Braucht Aufmerksamkeit',
    'verwaltung.uebersicht.bestand' => 'Bestand',

    'verwaltung.benutzer.titel'      => 'Benutzer',
    'verwaltung.benutzer.untertitel' => 'Anrufen und schreiben lässt sich, wer gerade online ist.',
    'verwaltung.benutzer.neu'        => 'Neuer Benutzer',
    'verwaltung.benutzer.spalte.status'       => 'Status',
    'verwaltung.benutzer.spalte.anrufen'      => 'Anrufen',
    'verwaltung.benutzer.spalte.benutzername' => 'Benutzername',
    'verwaltung.benutzer.spalte.nachricht'    => 'Nachricht',
    'verwaltung.benutzer.spalte.email'        => 'E-Mail',
    'verwaltung.benutzer.spalte.aktionen'     => 'Aktionen',

    // Kennung und Name stehen MITTEN in der Ueberschrift - gebaut wird sie
    // in App\Controller\UserController.
    'verwaltung.benutzer.formular.anlegen'      => 'Benutzer neu anlegen',
    'verwaltung.benutzer.formular.bearbeiten'   => 'Benutzer {id} ({name}) bearbeiten',
    'verwaltung.benutzer.formular.rolle'        => 'Rolle',
    'verwaltung.benutzer.formular.benutzername' => 'Benutzername',
    'verwaltung.benutzer.formular.email'        => 'E-Mail-Adresse',
    'verwaltung.benutzer.formular.passwort'     => 'Passwort',
    'verwaltung.benutzer.formular.speichern'    => 'Speichern',
    'verwaltung.benutzer.formular.abbrechen'    => 'Abbrechen',

    'verwaltung.standorte.titel'           => 'Standorte',
    'verwaltung.standorte.spalte.standort' => 'Standort',
    'verwaltung.standorte.spalte.guide'    => 'Guide',
    'verwaltung.standorte.spalte.zustand'  => 'Zustand',
    'verwaltung.standorte.spalte.fehlt'    => 'Fehlt',
    'verwaltung.standorte.spalte.sperre'   => 'Sperre',
    'verwaltung.standorte.spalte.aktion'   => 'Aktion',

    'verwaltung.anfragen.titel'           => 'Anfragen',
    'verwaltung.anfragen.spalte.zustand'  => 'Zustand',
    'verwaltung.anfragen.spalte.fuehrung' => 'Führung',
    'verwaltung.anfragen.spalte.guide'    => 'Guide',
    'verwaltung.anfragen.spalte.kunde'    => 'Kunde',
    'verwaltung.anfragen.spalte.aktion'   => 'Aktion',

    'verwaltung.bewertungen.titel'            => 'Bewertungen',
    'verwaltung.bewertungen.spalte.sterne'    => 'Sterne',
    'verwaltung.bewertungen.spalte.text'      => 'Text',
    'verwaltung.bewertungen.spalte.fuehrung'  => 'Führung',
    'verwaltung.bewertungen.spalte.abgegeben' => 'Abgegeben',
    'verwaltung.bewertungen.spalte.aktion'    => 'Aktion',

    'verwaltung.filter.label'          => 'Filter',
    'verwaltung.filter.alle'           => 'Alle',
    'verwaltung.filter.haengend'       => 'Hängende Führungen',
    'verwaltung.filter.unbeantwortet'  => 'Ohne Antwort',
    'verwaltung.filter.offen'          => 'Offen',
    'verwaltung.filter.gesperrt'       => 'Gesperrte',
    'verwaltung.filter.unvollstaendig' => 'Unvollständige',
    'verwaltung.filter.sichtbar'       => 'Sichtbar',
    'verwaltung.filter.schwach'        => '1–2 Sterne',
    'verwaltung.filter.entfernt'       => 'Entfernt',

    // -----------------------------------------------------------------
    // DIE CHATS
    // -----------------------------------------------------------------
    'chat.titel'           => 'Chats',
    'chat.untertitel'      => 'Unterhaltungen aus vergangenen und laufenden Führungen.',
    'chat.spalte.status'   => 'Status',
    'chat.spalte.partner'  => 'Partner',
    'chat.spalte.letzte'   => 'Letzte Nachricht',
    'chat.spalte.verlauf'  => 'Verlauf',
    'chat.verlauf.titel'   => 'Verlauf',
    'chat.verlauf.zurueck' => 'Zurück zu allen Chats',

    // Die Chatfenster im Browser (assets/js/chat.js, ui_chat.js, chat_badge.js).
    'chat.neue_nachricht'      => 'Neue Nachricht.',
    'chat.fehler.zu_lang'      => 'Nachricht nicht gesendet – sie ist zu lang.',
    'chat.fehler.uebertragung' => 'Nachricht nicht gesendet – Übertragungsfehler.',
    'chat.fehler.start'        => 'Der Chat konnte nicht gestartet werden.',
    'chat.fehler.weg'          => 'Dieser Chat existiert nicht mehr.',

    // Der Zustand einer Zeile in der Chatliste
    // (App\Controller\ChatController::getAllChats).
    'chat.zustand.aktiv'   => 'Aktiv',
    'chat.zustand.beendet' => 'Beendet',

    // DIE AKTION DER ZEILE STEHT ALS TEXT DA und nicht als Uhrsymbol: Sie ist
    // die einzige der Zeile, und ein Symbol allein erklaert sich nicht. Der
    // Name steht nur im aria-label - sichtbar wiederholte er sich in jeder
    // Zeile neben der Spalte, die ihn ohnehin nennt.
    'chat.verlauf.oeffnen' => 'Verlauf öffnen',
    'chat.verlauf.von'     => 'Verlauf mit {name} öffnen',

    // Wenn der Name des Gegenuebers nicht zu ermitteln ist.
    'chat.partner_unbekannt' => 'Unbekannt',
    'chat.partner_nummer'    => 'Konto {n}',

    // Die Ablehnungen der Chatrouten. Sie kommen als JSON zurueck und werden
    // im Browser als Hinweis gezeigt (assets/js/ui_chat.js) - hier standen
    // vorher teils englische Brocken wie "Invalid request", die ein deutscher
    // Nutzer genauso zu sehen bekam.
    'chat.fehler.ungueltig'         => 'Ungültige Anfrage.',
    'chat.fehler.nicht_angemeldet'  => 'Nicht angemeldet.',
    'chat.fehler.kein_zugriff'      => 'Kein Zugriff.',
    'chat.fehler.nicht_gefunden'    => 'Chat nicht gefunden.',
    'chat.fehler.nicht_erstellt'    => 'Der Chat konnte nicht erstellt werden.',
    'chat.fehler.zum_standort'      => 'Zu diesem Standort ist kein Chat möglich.',
    'chat.fehler.eigener_standort'  => 'Das ist Ihr eigener Standort.',
    'chat.fehler.selbst'            => 'Mit sich selbst chattet niemand.',
    'chat.fehler.konto_weg'         => 'Dieses Konto gibt es nicht mehr.',
    'chat.fehler.konto_weg_verlauf' => 'Dieses Konto gibt es nicht mehr. Der Verlauf bleibt erhalten.',
    'chat.fehler.zu_viele_chats'       => 'Zu viele Chats in kurzer Zeit. Bitte {warten} warten.',
    'chat.fehler.zu_viele_nachrichten' => 'Zu viele Nachrichten in kurzer Zeit. Bitte {warten} warten.',

    // Dateien im Gespraechschat (assets/js/chat.js). Der Dateiname ist der
    // Vorschlag fuer das Speichern-Fenster des Browsers.
    'chat.datei.gesendet'      => 'Datei gesendet: {name}',
    'chat.datei.herunterladen' => 'Datei herunterladen',
    'chat.datei.name'          => 'empfangene_datei',

    // -----------------------------------------------------------------
    // DAS GESPRAECH
    //
    // Die Steuerung traegt ihre Beschriftung im aria-label und im title -
    // gesteuert wird ueber Tasten und Tonsignale, nicht ueber Sprache, aber
    // ein Vorleseprogramm liest sonst "Schaltflaeche" und sonst nichts.
    // -----------------------------------------------------------------
    'gespraech.kein_video'       => 'Kein Videobild',
    'gespraech.kamera_aus'       => 'Kamera aus',
    'gespraech.auflegen'         => 'Auflegen',
    'gespraech.zweck.titel'      => 'Anruf der Administration',
    'gespraech.zweck.text'       => 'Keine Führung – es wird nicht gesteuert.',
    'gespraech.sperre.hinweis'   => 'Steuerung gesperrt – der Guide hat sie angehalten.',
    'gespraech.blick'            => 'Blick',
    'gespraech.blick_oben'       => 'Blick nach oben',
    'gespraech.blick_unten'      => 'Blick nach unten',
    'gespraech.vorwaerts'        => 'Vorwärts',
    'gespraech.links'            => 'Nach links',
    'gespraech.rechts'           => 'Nach rechts',
    'gespraech.rueckwaerts'      => 'Rückwärts',
    'gespraech.sperren'          => 'Steuerung sperren',
    'gespraech.mikrofon'         => 'Mikrofon',
    'gespraech.mikrofon_schalter'=> 'Mikrofon an/aus',
    'gespraech.kamera'           => 'Kamera',
    'gespraech.kamera_schalter'  => 'Kamera an/aus',
    'gespraech.geraete'          => 'Geräte',
    'gespraech.chat'             => 'Chat',
    'gespraech.ungelesen'        => 'Ungelesene Nachrichten',
    'gespraech.chat_schliessen'  => 'Chat schließen',
    'gespraech.nachricht'        => 'Nachricht',
    'gespraech.senden'           => 'Senden',

    'gespraech.anruf.eingehend'  => 'Eingehender Anruf',
    'gespraech.anruf.zweck_text' => 'Das ist keine Führung – es wird nicht gesteuert. Ton und Bild laufen in beide Richtungen.',
    'gespraech.anruf.video'      => 'Video senden',
    'gespraech.anruf.ton'        => 'Ton senden',
    'gespraech.anruf.annehmen'   => 'Annehmen',
    'gespraech.anruf.ablehnen'   => 'Ablehnen',
    'gespraech.anruf.medien_noetig' => 'Bitte mindestens Ton oder Video auswählen, um den Anruf anzunehmen.',

    // -----------------------------------------------------------------
    // WAS WAEHREND EINES ANRUFS ENTSTEHT (assets/js/rtc.js, control.js,
    // media.js, signaling.js)
    //
    // Kein Satz hier wird zusammengesetzt. Wo ein Grund dazugehoert, gibt es
    // ihn als Platzhalter - und wo es ihn auch ohne Grund gibt, zwei
    // Eintraege statt eines Klammerzusatzes im Code.
    // -----------------------------------------------------------------
    'gespraech.anruf_mit'      => 'Anruf mit {name}',
    'gespraech.kein_anruf_hier'=> 'Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.',
    'gespraech.tippen'         => 'Bitte einmal auf das Bild tippen, damit Ton und Bild starten.',
    'gespraech.freigeben'      => 'Steuerung freigeben',

    'gespraech.fehler.aufbau_grund'      => 'Der Anruf konnte nicht aufgebaut werden: {grund}',
    'gespraech.fehler.nicht_zugestellt'  => 'Der Anruf konnte nicht zugestellt werden. Bitte später erneut versuchen.',
    'gespraech.fehler.anruf_weg'         => 'Der Anruf ist nicht mehr da.',
    'gespraech.fehler.nicht_angenommen'  => 'Der Anruf wurde nicht angenommen.',
    'gespraech.fehler.verbindung'        => 'Die Verbindung konnte nicht aufgebaut werden.',
    'gespraech.fehler.verbindung_grund'  => 'Die Verbindung konnte nicht aufgebaut werden: {grund}',
    'gespraech.fehler.gegenseite_beendet'=> 'Der andere Teilnehmer hat die Verbindung beendet.',
    'gespraech.fehler.partner_beendet'   => 'Die Verbindung zum Gesprächspartner wurde beendet.',
    'gespraech.fehler.kein_wiederaufbau' => 'Die Verbindung zum Gesprächspartner konnte nicht wiederhergestellt werden.',
    'gespraech.fehler.start_keine_medien'=> 'Der Anruf konnte nicht gestartet werden: Die Gegenseite hat weder Ton noch Bild ausgewählt.',
    'gespraech.fehler.start_verbindung'  => 'Der Anruf konnte nicht gestartet werden: Die Verbindung ließ sich nicht aufbauen.',

    'gespraech.fehler.mikro_an'         => 'Das Mikrofon ließ sich nicht einschalten: {grund}',
    'gespraech.fehler.mikro_aus'        => 'Das Mikrofon ließ sich nicht stummschalten: {grund}',
    'gespraech.fehler.kamera_an'        => 'Die Kamera ließ sich nicht einschalten: {grund}',
    'gespraech.fehler.kamera_aus'       => 'Die Kamera ließ sich nicht abschalten: {grund}',
    'gespraech.fehler.geraet_wechsel'   => 'Das Gerät ließ sich nicht übernehmen: {grund}',
    'gespraech.fehler.kein_mikrokanal'  => 'Es ist kein Mikrofonkanal ausgehandelt.',
    'gespraech.fehler.kein_kamerakanal' => 'Für die Kamera wurde beim Verbindungsaufbau kein Kanal ausgehandelt.',
    'gespraech.fehler.kein_geraetekanal'=> 'Für dieses Gerät ist kein Kanal ausgehandelt.',

    'gespraech.hinweis.ohne_eigenen_ton' => 'Der Anruf läuft ohne eigenen Ton weiter; der Chat bleibt nutzbar.',

    // Die Absagen von getUserMedia - JE GERAET EIN GANZER SATZ. Vorher stand
    // im Code "der Zugriff auf " plus "die Kamera"/"das Mikrofon", dazu ein
    // grossgeschriebenes "Die"/"Das" fuer den Satzanfang und ein "sie"/"es"
    // fuer den Rueckbezug: drei Formen desselben Wortes, die es so nur im
    // Deutschen gibt.
    'gespraech.medien.abgelehnt_kamera' => 'Der Zugriff auf die Kamera wurde abgelehnt. Bitte erlauben Sie ihn in den Einstellungen des Browsers und versuchen Sie es erneut.',
    'gespraech.medien.abgelehnt_mikro'  => 'Der Zugriff auf das Mikrofon wurde abgelehnt. Bitte erlauben Sie ihn in den Einstellungen des Browsers und versuchen Sie es erneut.',
    'gespraech.medien.fehlt_kamera'     => 'Es wurde keine Kamera gefunden. Ohne Kamera lässt sich kein Bild übertragen.',
    'gespraech.medien.fehlt_mikro'      => 'Es wurde kein Mikrofon gefunden. Ohne Mikrofon lässt sich kein Gespräch führen.',
    'gespraech.medien.belegt_kamera'    => 'Die Kamera lässt sich nicht öffnen. Vermutlich benutzt sie gerade ein anderes Programm.',
    'gespraech.medien.belegt_mikro'     => 'Das Mikrofon lässt sich nicht öffnen. Vermutlich benutzt es gerade ein anderes Programm.',
    'gespraech.medien.fehler_kamera'    => 'Die Kamera konnte nicht verwendet werden: {grund}',
    'gespraech.medien.fehler_mikro'     => 'Das Mikrofon konnte nicht verwendet werden: {grund}',
    'gespraech.medien.ohne_bild'        => 'Der Anruf läuft ohne Bild weiter.',
    'gespraech.medien.ohne_ton'         => 'Der Anruf läuft ohne Ton weiter; der Chat bleibt nutzbar.',

    // Die Geraeteliste im Anrufdialog.
    'gespraech.geraet.keine_freigabe'      => 'Das Gerät lässt sich noch nicht auswählen. Bitte erlauben Sie den Zugriff auf Kamera und Mikrofon und öffnen Sie die Geräteliste erneut.',
    'gespraech.geraet.kamera_aus_hinweis'  => 'Die Kamera ist aus. Die Auswahl gilt, sobald Sie sie einschalten.',
    'gespraech.geraet.mikro_stumm_hinweis' => 'Das Mikrofon ist stumm. Die Auswahl gilt, sobald Sie es einschalten.',
    'gespraech.geraet.keine_kamera'        => 'Keine Kamera gefunden',
    'gespraech.geraet.kein_mikrofon'       => 'Kein Mikrofon gefunden',
    'gespraech.geraet.kamera_nr'           => 'Kamera {n}',
    'gespraech.geraet.mikrofon_nr'         => 'Mikrofon {n}',

    // Die Titel der beiden Umschalter in der Leiste.
    'gespraech.mikrofon_stumm'  => 'Mikrofon stummschalten',
    'gespraech.mikrofon_an'     => 'Mikrofon einschalten',
    'gespraech.kamera_aus_titel'=> 'Kamera ausschalten',
    'gespraech.kamera_an'       => 'Kamera einschalten',

    // Die ICE-Server. Der Grund kommt teils vom Server und steht deshalb als
    // Platzhalter im Satz.
    'gespraech.ice.keine_daten'     => 'Die Verbindungsdaten konnten nicht geladen werden.',
    'gespraech.ice.hinweis'         => 'Hinweis: {text}',
    'gespraech.ice.kein_turn'       => 'Hinweis: Es ist kein TURN-Server verfügbar. Der Anruf klappt nur, wenn beide Seiten in einfachen Netzen sind.',
    'gespraech.ice.kein_turn_grund' => 'Hinweis: Es ist kein TURN-Server verfügbar. Der Anruf klappt nur, wenn beide Seiten in einfachen Netzen sind. ({grund})',

    // Der sichtbare Verbindungszustand.
    'gespraech.zustand.aufbau'           => 'Verbindung wird aufgebaut',
    'gespraech.zustand.verbunden'        => 'Verbunden',
    'gespraech.zustand.instabil'         => 'Verbindung instabil',
    'gespraech.zustand.wieder'           => 'Wiederverbindung …',
    'gespraech.zustand.getrennt'         => 'Verbindung getrennt',
    'gespraech.zustand.wiederhergestellt'=> 'Verbindung wiederhergestellt.',

    // DIE RICHTUNGSANZEIGE beim Guide. GROSSGESCHRIEBEN mit Absicht: Sie
    // steht bildschirmfuellend da und wird im Gehen mit einem Blick gelesen.
    'gespraech.richtung.forward'   => 'VORWÄRTS',
    'gespraech.richtung.backward'  => 'ZURÜCK',
    'gespraech.richtung.left'      => 'LINKS',
    'gespraech.richtung.right'     => 'RECHTS',
    'gespraech.richtung.look_up'   => 'BLICK HOCH',
    'gespraech.richtung.look_down' => 'BLICK RUNTER',

    // Die Steuerung.
    'gespraech.steuerung.nicht_stabil'        => 'Steuerbefehl nicht gesendet – die Verbindung ist gerade nicht stabil.',
    'gespraech.steuerung.uebertragung'        => 'Steuerbefehl nicht gesendet – Übertragungsfehler.',
    'gespraech.steuerung.verworfen'           => 'Steuerbefehl verworfen – die Verbindung war unterbrochen.',
    'gespraech.steuerung.keine_bestaetigung'  => 'Keine Bestätigung für den Steuerbefehl erhalten.',
    'gespraech.steuerung.sperre_fehler'       => 'Sperre konnte nicht übermittelt werden.',
    'gespraech.steuerung.gesperrt'            => 'Der Guide hat die Steuerung gesperrt.',
    'gespraech.steuerung.gesperrt_grund'      => 'Der Guide hat die Steuerung gesperrt. ({grund})',
    'gespraech.steuerung.freigegeben'         => 'Der Guide hat die Steuerung wieder freigegeben.',

    // Die Ablehnungsgruende aus dem Protokoll - JEDER TRAEGT DIE GANZE
    // MELDUNG. Vorher stand hier nur der Grund und der Code setzte
    // "Steuerbefehl abgelehnt – " davor.
    'gespraech.abgelehnt.unstable'  => 'Steuerbefehl abgelehnt – die Verbindung war nicht stabil.',
    'gespraech.abgelehnt.locked'    => 'Steuerbefehl abgelehnt – der Guide hat die Steuerung gesperrt.',
    'gespraech.abgelehnt.duplicate' => 'Steuerbefehl abgelehnt – der Befehl war eine Wiederholung.',
    'gespraech.abgelehnt.no_role'   => 'Steuerbefehl abgelehnt – die Gegenseite kennt ihre Rolle nicht.',
    'gespraech.abgelehnt.invalid'   => 'Steuerbefehl abgelehnt – der Befehl war ungültig.',
    'gespraech.abgelehnt.unbekannt' => 'Steuerbefehl abgelehnt – der Grund ist unbekannt.',

    // Was der Server zum Anruf sagt (App\Controller\WebRTCController,
    // App\Controller\TurnController). Die ICE-Hinweise reist als "warning"
    // in der Antwort und landen in gespraech.ice.hinweis darueber.
    'gespraech.fehler.signaling'         => 'Ungültige Signaling-Anfrage.',
    'gespraech.fehler.signaling_intern'  => 'Der Anruf konnte nicht vermittelt werden.',
    'gespraech.fehler.guide_nicht_bereit'=> 'Dieser Guide ist gerade nicht bereit für eine Führung. Fragen Sie die Führung auf der Standortseite an – mit einem Wunschzeitpunkt, der Ihnen beiden passt.',
    'gespraech.fehler.kein_guide'        => 'Dieser Benutzer bietet keine Führungen an und kann deshalb nicht angerufen werden.',

    'gespraech.ice.gebremst'           => 'Zu viele Verbindungsversuche in kurzer Zeit. Der Anruf wird ohne Relay-Server aufgebaut.',
    'gespraech.ice.keine_zugangsdaten' => 'Der TURN-Dienst hat keine verwertbaren Zugangsdaten geliefert.',
    'gespraech.ice.nicht_erreichbar'   => 'Der TURN-Server ist derzeit nicht erreichbar.',

    // -----------------------------------------------------------------
    // DIE ERGEBNISSEITEN
    // -----------------------------------------------------------------
    'ergebnis.mail_bestaetigt.titel' => 'E-Mail-Adresse bestätigt',
    'ergebnis.mail_bestaetigt.text'  => 'Sie können sich jetzt anmelden.',
    'ergebnis.mail_bestaetigt.knopf' => 'Zur Anmeldung',

    'ergebnis.mail_fehler.titel'        => 'Link ungültig oder abgelaufen',
    'ergebnis.mail_fehler.text'         => 'Der Bestätigungslink lässt sich nicht mehr einlösen. Bestätigungslinks haben eine begrenzte Gültigkeit.',
    'ergebnis.mail_fehler.registrieren' => 'Erneut registrieren',
    'ergebnis.mail_fehler.anmelden'     => 'Zur Anmeldung',

    'ergebnis.mail_verschickt.titel' => 'Bestätigungsmail verschickt',
    'ergebnis.mail_verschickt.text'  => 'Bitte klicken Sie auf den Link in der E-Mail. Der Link ist 24 Stunden gültig; sehen Sie auch im Spam-Ordner nach.',
    'ergebnis.mail_verschickt.knopf' => 'Zur Startseite',

    'ergebnis.registriert.titel' => 'Registrierung erfolgreich',
    'ergebnis.registriert.text'  => 'Ihr Konto wurde angelegt. Wir haben Ihnen eine E-Mail zur Bestätigung Ihrer Adresse geschickt — bitte sehen Sie auch im Spam-Ordner nach. Anmelden können Sie sich sofort.',
    'ergebnis.registriert.knopf' => 'Zur Anmeldung',
];
