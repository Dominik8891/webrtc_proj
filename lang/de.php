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
 * Zeitangaben, die beiden E-Mails) - und seit der Stufe der PHP-Texte die
 * Saetze der SEITEN, soweit PHP sie erzeugt: Standortseite, Guide-Profil,
 * Bewertungsblock, Verwaltungsbereich, Kopfleiste, dazu die Meldungen der
 * Controller und die des Bildspeichers.
 *
 * NOCH NICHT hier stehen die Vorlagen unter assets/html und die Meldungen
 * des Browsers unter assets/js. Sie ziehen in den naechsten Stufen nach;
 * dass dabei nichts Neues dazukommt, haelt der Test fest, der neue nackte
 * deutsche Literale meldet (tests/i18n_scan.php).
 *
 * WAS AUSDRUECKLICH NICHT HIERHER GEHOERT: Logmeldungen und die Texte
 * geworfener Ausnahmen. Sie richten sich an den Betreiber und nicht an den
 * Benutzer - dieselbe Grenze, die auch der Scanner zieht.
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

    // Das Bearbeitungsformular. Was in der Vorlage steht
    // (assets/html/location_edit.html), zieht erst in der naechsten Stufe
    // nach - hier steht, was App\Helper\LocationView selbst baut.
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

    'standort.bild.zu_viele'            => 'Mehr als {n} Bilder sind an einem Standort nicht möglich.',
    'standort.bild.keine_datei'         => 'Es wurde keine Datei geschickt.',
    'standort.bild.nicht_gespeichert'   => 'Das Bild konnte nicht gespeichert werden.',
    'standort.bild.keine_id'            => 'Kein Bild angegeben.',
    'standort.bild.nicht_gefunden'      => 'Bild nicht gefunden.',
    'standort.bild.keine_reihenfolge'   => 'Keine Reihenfolge angegeben.',
    'standort.bild.reihenfolge_fehler'  => 'Die Reihenfolge konnte nicht gespeichert werden.',
    'standort.bild.titelbild_fehler'    => 'Das Titelbild konnte nicht geändert werden.',

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

    'verwaltung.bewertungen.leer'           => 'Keine Bewertungen.',
    'verwaltung.bewertungen.ohne_text'      => 'ohne Text',
    'verwaltung.bewertungen.entfernt_grund' => 'Entfernt: {grund}',
    'verwaltung.bewertungen.ohne_grund'     => 'ohne Grund',
    'verwaltung.bewertungen.entfernt'       => 'entfernt',
    'verwaltung.bewertungen.entfernen'      => 'Entfernen',
    'verwaltung.bewertungen.konto'          => '{rolle}: {name}',
    'verwaltung.rolle.guide'                => 'Guide',
    'verwaltung.rolle.kunde'                => 'Kunde',

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
];
