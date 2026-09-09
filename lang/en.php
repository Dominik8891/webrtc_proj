<?php
/**
 * Der englische Sprachkatalog - und zugleich der Vorgabekatalog
 * (App\Helper\I18n::DEFAULT).
 *
 * DASS ER DIE VORGABE IST, macht ihn zur wichtigeren der beiden Dateien:
 * Fehlt ein Schluessel in lang/de.php, wird HIER nachgeschlagen. Fehlt er
 * auch hier, steht der Schluessel selbst auf der Seite.
 *
 * DIE REGELN FUER DIESE DATEI stehen in lang/de.php - sie gelten fuer beide
 * gleichermassen, und sie zweimal zu schreiben hiesse, sie irgendwann
 * einmal zu aendern.
 *
 * DIE KOMMENTARE BLEIBEN DEUTSCH. Sie richten sich an den, der diese
 * Anwendung entwickelt, und nicht an den, der sie benutzt - das ist der
 * ganze Unterschied zwischen einem Kommentar und einem Katalogtext.
 */
return [

    // -----------------------------------------------------------------
    // Die Sprachwahl. Reihenfolge und Schluessel wie in lang/de.php.
    // -----------------------------------------------------------------
    'sprache.titel'       => 'Language',
    'sprache.hinweis'     => 'Applies to the interface. The text is rendered by the server, so the page reloads.',
    'sprache.konto'       => 'Applies to this account and will still be set the next time you sign in.',
    'sprache.gast'        => 'Remembered in this browser.',
    'sprache.wechseln_zu' => 'Switch the interface to {sprache}',
    'sprache.aktiv'       => 'Current language: {sprache}',
    'sprache.gespeichert' => 'Language saved.',
    'sprache.unbekannt'   => 'Unknown language.',

    // -----------------------------------------------------------------
    // Dieselben Formen wie im deutschen Katalog. Beide Sprachen kommen mit
    // 'one' und 'other' aus - welche Form gilt, entscheidet
    // I18n::pluralForm() und nicht der Aufrufer.
    // -----------------------------------------------------------------
    'sprache.anzahl' => [
        'one'   => '{n} language',
        'other' => '{n} languages',
    ],
];
