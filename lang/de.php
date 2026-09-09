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
 * WAS HIER NOCH NICHT STEHT
 * -------------------------
 * Der Bestand der Anwendung. Diese Stufe baut das Fundament und uebersetzt
 * noch nichts; hier stehen nur die Schluessel, die die Sprachwahl selbst
 * braucht. Der Umzug der uebrigen Texte laeuft ueber den Test, der neue
 * nackte deutsche Literale meldet (tests/i18n_scan.php).
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
];
