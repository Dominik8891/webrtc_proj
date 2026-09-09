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

    // -----------------------------------------------------------------
    // DER KALENDER. Reihenfolge und Schluessel wie in lang/de.php.
    // -----------------------------------------------------------------
    'datum.monat.1'  => 'January',
    'datum.monat.2'  => 'February',
    'datum.monat.3'  => 'March',
    'datum.monat.4'  => 'April',
    'datum.monat.5'  => 'May',
    'datum.monat.6'  => 'June',
    'datum.monat.7'  => 'July',
    'datum.monat.8'  => 'August',
    'datum.monat.9'  => 'September',
    'datum.monat.10' => 'October',
    'datum.monat.11' => 'November',
    'datum.monat.12' => 'December',

    'datum.monat_jahr' => '{monat} {jahr}',

    'datum.heute'   => 'Today',
    'datum.gestern' => 'Yesterday',

    // 'en-GB' UND NICHT 'en': Das nackte Sprachkuerzel ergibt im Browser die
    // amerikanische Reihenfolge (Monat vor Tag). Beide Formate stehen hier
    // in derselben Reihenfolge Tag-Monat-Jahr - sonst zeigte dieselbe Seite
    // ein Datum je nach Herkunft der Angabe verschieden.
    'datum.mit_uhrzeit' => 'd/m/Y H:i',
    'datum.locale'      => 'en-GB',

    // -----------------------------------------------------------------
    // DIE WOCHENTAGE. Der Schluessel behaelt die deutsche Kennung: Sie
    // steht so im gespeicherten Muster und ist ein Bezeichner und keine
    // Beschriftung.
    // -----------------------------------------------------------------
    'zeit.tag.mo.kurz' => 'Mon',
    'zeit.tag.mo.lang' => 'Monday',
    'zeit.tag.di.kurz' => 'Tue',
    'zeit.tag.di.lang' => 'Tuesday',
    'zeit.tag.mi.kurz' => 'Wed',
    'zeit.tag.mi.lang' => 'Wednesday',
    'zeit.tag.do.kurz' => 'Thu',
    'zeit.tag.do.lang' => 'Thursday',
    'zeit.tag.fr.kurz' => 'Fri',
    'zeit.tag.fr.lang' => 'Friday',
    'zeit.tag.sa.kurz' => 'Sat',
    'zeit.tag.sa.lang' => 'Saturday',
    'zeit.tag.so.kurz' => 'Sun',
    'zeit.tag.so.lang' => 'Sunday',

    // -----------------------------------------------------------------
    // DIE TAGESABSCHNITTE. Im Plural, weil sie eine Gewohnheit benennen
    // und keinen einzelnen Abend - "Mon-Fri evenings" sagt dasselbe wie
    // "Mo-Fr abends".
    // -----------------------------------------------------------------
    'zeit.abschnitt.nacht'      => 'nights',
    'zeit.abschnitt.vormittag'  => 'mornings',
    'zeit.abschnitt.nachmittag' => 'afternoons',
    'zeit.abschnitt.abend'      => 'evenings',

    'zeit.tage_abschnitt' => '{tage} {abschnitt}',

    // -----------------------------------------------------------------
    // DAUERN
    // -----------------------------------------------------------------
    'dauer.minuten' => [
        'one'   => '{n} minute',
        'other' => '{n} minutes',
    ],
    'dauer.stunden' => [
        'one'   => '{n} hour',
        'other' => '{n} hours',
    ],
    'dauer.tage' => [
        'one'   => '{n} day',
        'other' => '{n} days',
    ],
    'dauer.stunden_minuten' => '{stunden} {minuten}',

    'dauer.kurz.minuten'         => '{n} min',
    'dauer.kurz.stunden'         => '{n} hr',
    'dauer.kurz.tage'            => '{n} d',
    'dauer.kurz.stunden_minuten' => '{stunden} {minuten}',
    'dauer.kurz.tage_stunden'    => '{tage} {stunden}',

    // -----------------------------------------------------------------
    // RELATIVE ZEITANGABEN. Hier steht der Grund, aus dem die Richtung im
    // Schluessel steckt und nicht im Code: Im Deutschen steht sie vorn
    // ("vor 3 Stunden"), hier hinten.
    // -----------------------------------------------------------------
    'zeit.in.minuten' => [
        'one'   => 'in {n} minute',
        'other' => 'in {n} minutes',
    ],
    'zeit.in.stunden' => [
        'one'   => 'in {n} hour',
        'other' => 'in {n} hours',
    ],
    'zeit.in.tagen' => [
        'one'   => 'in {n} day',
        'other' => 'in {n} days',
    ],
    'zeit.vor.minuten' => [
        'one'   => '{n} minute ago',
        'other' => '{n} minutes ago',
    ],
    'zeit.vor.stunden' => [
        'one'   => '{n} hour ago',
        'other' => '{n} hours ago',
    ],
    'zeit.vor.tagen' => [
        'one'   => '{n} day ago',
        'other' => '{n} days ago',
    ],

    'zeit.jetzt'       => 'now',
    'zeit.gerade_eben' => 'just now',
    'zeit.unbekannt'   => 'unknown',
    'zeit.vereinbart'  => 'the agreed time',

    'zeit.zone.spaeter' => 'it is {dauer} later there than where you are',
    'zeit.zone.frueher' => 'it is {dauer} earlier there than where you are',

    // -----------------------------------------------------------------
    // DIE ZUSTAENDE EINER ANFRAGE. Die Schluessel sind die Werte der
    // Spalte request.status und bleiben deshalb englisch wie dort -
    // uebersetzt wird der Wert.
    // -----------------------------------------------------------------
    'anfrage.status.open'      => 'open',
    'anfrage.status.accepted'  => 'accepted',
    'anfrage.status.declined'  => 'declined',
    'anfrage.status.expired'   => 'expired',
    'anfrage.status.done'      => 'completed',
    'anfrage.status.cancelled' => 'cancelled',

    // -----------------------------------------------------------------
    // DIE BEIDEN E-MAILS. Sie gehen in der Sprache des EMPFAENGERS heraus
    // (user.lang, geholt mit I18n::tIn) und nicht in der dessen, der sie
    // ausloest.
    // -----------------------------------------------------------------
    'mail.bestaetigung.betreff' => 'Confirm your email address',
    'mail.bestaetigung.text'    => "Hello,\n\nPlease confirm your email address by following this link:\n\n{link}\n\nThe link is valid for 24 hours.",
    'mail.passwort.betreff'     => 'Reset your password',
    'mail.passwort.text'        => "Hello,\n\nFollow this link to choose a new password:\n\n{link}\n\nThe link is valid for 1 hour.",
];
