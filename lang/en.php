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
    // =================================================================
    // THE PAGES
    //
    // The stage after the catalogues and formats. The order follows the
    // application and not the files: 'standort.*' belongs to the location
    // page, no matter whether App\Helper\LocationView builds it or
    // App\Controller\LocationController sends a message about it.
    // =================================================================

    'zahl.dezimaltrenner'    => '.',
    'zahl.tausendertrenner'  => ',',

    'allgemein.gespeichert'     => 'Saved.',
    'allgemein.interner_fehler' => 'Internal error. Please try again later.',

    // -----------------------------------------------------------------
    // THE HEADER
    // -----------------------------------------------------------------
    'kopf.anmelden'              => 'Sign in',
    'kopf.menue.konto'           => 'My account',
    'kopf.menue.verwaltung'      => 'Administration',
    'kopf.menue.angemeldet_als'  => 'Signed in as {name}',
    'kopf.menue.abmelden'        => 'Sign out',

    'kopf.anfragen.text'   => 'Requests',
    'kopf.anfragen.titel'  => 'Your requests',
    'kopf.anfragen.eingehend' => [
        'one'   => 'One request is waiting for your answer',
        'other' => '{n} requests are waiting for your answer',
    ],
    'kopf.anfragen.ausgehend' => [
        'one'   => 'One of your requests was accepted',
        'other' => '{n} of your requests were accepted',
    ],
    'kopf.anfragen.laufend' => [
        'one'   => 'One tour has not been finished yet',
        'other' => '{n} tours have not been finished yet',
    ],

    'kopf.nachrichten.text'  => 'Messages',
    'kopf.nachrichten.titel' => 'Your messages',
    'kopf.nachrichten.ungelesen' => [
        'one'   => '{n} unread message',
        'other' => '{n} unread messages',
    ],

    'kopf.bereit.an'         => 'Available',
    'kopf.bereit.aus'        => 'Not available',
    'kopf.bereit.titel_an'   => 'You can be called as a guide. Clicking ends your availability.',
    'kopf.bereit.titel_aus'  => 'You cannot be called. Clicking makes you available.',

    // -----------------------------------------------------------------
    // THE LOCATION PAGE
    // -----------------------------------------------------------------
    'standort.titel.fuehrung_in' => 'Tour in {ort}',
    'standort.titel.ohne_ort'    => 'Location',

    'standort.zustand.eigen'             => 'Your location',
    'standort.zustand.eigen_bereit'      => 'You are available – this location can be called right now.',
    'standort.zustand.eigen_nicht_bereit'=> 'You are not available – this location is shown dimmed.',
    'standort.zustand.live'              => 'Available now',
    'standort.zustand.busy'              => 'In a call',
    'standort.zustand.idle'              => 'No guide on site',

    'standort.sperre.wort'  => 'Blocked.',
    'standort.sperre.eigen' => 'Your location is blocked and not visible to other users.',
    'standort.sperre.fremd' => 'This location is blocked and not visible to other users.',
    'standort.sperre.grund' => 'Reason: {grund}',

    'standort.bilder.titel' => 'Pictures of the place',
    'standort.bilder.alt'   => '{titel} – picture {nr}',

    'standort.beschreibung.leer' => 'The guide has not written a detailed description yet.',

    'standort.zeiten.titel'      => 'Usually out and about',
    'standort.zeiten.titel_leer' => 'Usual times',
    'standort.zeiten.leer'       => 'You have not given any yet. Customers cannot tell when a request is worth making – you can add them under {knopf}.',
    'standort.zeiten.bearbeiten' => 'Edit',
    'standort.zeiten.ortszeit'   => 'Local time: {zone}',

    'standort.fakten.dauer'    => 'Duration',
    'standort.fakten.sprachen' => 'Languages',
    'standort.fakten.ort'      => 'Place',

    'standort.aktion.eigen'         => 'You do not send a request to your own location. What is pending here you will find under {liste}; whether you can be called right away is decided by the availability switch in the header.',
    'standort.aktion.anfragen'      => 'Requests',
    'standort.aktion.gesperrt'      => 'This location is blocked. No tour can be requested from here.',
    'standort.aktion.konto_noetig'  => 'You need an account to send a request – the guide has to know who they are agreeing to meet.',
    'standort.aktion.anmelden'      => 'Sign in and request',
    'standort.aktion.konto_anlegen' => 'Create an account',

    'standort.frage.knopf'   => 'Ask the guide',
    'standort.frage.hinweis' => 'Questions about the place or about possible times – the guide will answer you in the chat.',

    'standort.anfrage.hinweis_bereit'  => 'The guide is available right now – “right now” has good prospects.',
    'standort.anfrage.hinweis_offline' => 'Nobody is on site at the moment. That is no obstacle: ask for a later time, and the guide will accept or decline.',
    'standort.anfrage.jetzt'           => 'Right now',
    'standort.anfrage.in_1h'           => 'In 1 hour',
    'standort.anfrage.in_3h'           => 'In 3 hours',
    'standort.anfrage.morgen'          => 'Tomorrow at this time',
    'standort.anfrage.vorgaben'        => 'Presets for the preferred time',
    'standort.anfrage.wunschzeit'      => 'Preferred time',
    'standort.anfrage.absenden'        => 'Request a tour',

    'standort.anfrage.offen_marke'      => 'Request pending',
    'standort.anfrage.offen_text'       => 'Your request for {wann} is with the guide. As soon as they answer you will see it here and on the counter in the header.',
    'standort.anfrage.zurueckziehen'    => 'Withdraw request',
    'standort.anfrage.absagen'          => 'Cancel',
    'standort.anfrage.marke_laeuft'     => 'Running',
    'standort.anfrage.marke_angenommen' => 'Accepted',
    'standort.anfrage.laeuft_abgerissen'=> 'The tour is still running – the connection dropped. You can rejoin; your guide will end the tour when you are done.',
    'standort.anfrage.laeuft'           => 'The tour is still running. Your guide will end it when you are done.',
    'standort.anfrage.startbereit'      => 'The guide has agreed. You can start now – they will be called.',
    'standort.anfrage.zugesagt'         => 'The guide has agreed to {wann}. Shortly before then the tour can be started from here.',
    'standort.anfrage.einsteigen'       => 'Rejoin',
    'standort.anfrage.starten'          => 'Start tour',

    'standort.bearbeiten.raster_spalte'    => 'Select or clear this part of the day on every day',
    'standort.bearbeiten.raster_zeile'     => 'Select or clear this whole day',
    'standort.bearbeiten.raster_feld'      => '{tag} {abschnitt}',
    'standort.bearbeiten.kein_titelbild'   => 'No cover picture chosen yet. Until then the top of the page shows only the title and the place. Pick one of your pictures below – ideally a very wide one with calm areas for the text to sit on.',
    'standort.bearbeiten.titelbild_alt'    => 'Cover picture',
    'standort.bearbeiten.titelbild_zurueck'=> 'Back to the gallery',
    'standort.bearbeiten.keine_bilder'     => 'No example pictures uploaded yet.',
    'standort.bearbeiten.bild_alt'         => 'Picture {nr}',
    'standort.bearbeiten.bild_titelbild'   => 'Use picture {nr} as the cover picture',
    'standort.bearbeiten.bild_vor'         => 'Move picture {nr} forward',
    'standort.bearbeiten.bild_zurueck'     => 'Move picture {nr} back',
    'standort.bearbeiten.bild_loeschen'    => 'Delete picture {nr}',
    'standort.bearbeiten.kurz_titelbild'   => 'As cover picture',
    'standort.bearbeiten.kurz_vor'         => 'Forward',
    'standort.bearbeiten.kurz_zurueck'     => 'Back',
    'standort.bearbeiten.kurz_loeschen'    => 'Delete',

    'standort.fehlseite.titel' => 'Location not found',
    'standort.fehlseite.text'  => 'This location was removed, was blocked or never existed.',
    'standort.fehlseite.karte' => 'To the map',

    'standort.fehler.titel_kurz'     => 'The title must be at least {n} characters long.',
    'standort.fehler.titel_lang'     => 'The title must not be longer than {n} characters.',
    'standort.fehler.kurz_kurz'      => 'The short description must be at least {n} characters long.',
    'standort.fehler.kurz_lang'      => 'The short description must not be longer than {n} characters.',
    'standort.fehler.lang_lang'      => 'The detailed description must not be longer than {n} characters.',
    'standort.fehler.dauer_zahl'     => 'The duration must be a number of minutes.',
    'standort.fehler.dauer_bereich'  => 'The duration must be between {min} and {max} minutes.',
    'standort.fehler.nicht_gespeichert' => 'The change could not be saved.',
    'standort.fehler.keine_id'       => 'No location given.',
    'standort.fehler.nicht_gefunden' => 'Location not found.',
    'standort.fehler.loeschen'       => 'Deleting failed.',
    'standort.fehler.grund_fehlt'    => 'Please give a reason.',

    'standort.bild.zu_viele'            => 'A location cannot hold more than {n} pictures.',
    'standort.bild.keine_datei'         => 'No file was sent.',
    'standort.bild.nicht_gespeichert'   => 'The picture could not be saved.',
    'standort.bild.keine_id'            => 'No picture given.',
    'standort.bild.nicht_gefunden'      => 'Picture not found.',
    'standort.bild.keine_reihenfolge'   => 'No order given.',
    'standort.bild.reihenfolge_fehler'  => 'The order could not be saved.',
    'standort.bild.titelbild_fehler'    => 'The cover picture could not be changed.',

    // -----------------------------------------------------------------
    // THE GUIDE
    // -----------------------------------------------------------------
    'guide.streifen.eigen'      => 'You offer this tour',
    'guide.streifen.fremd'      => 'Your guide',
    'guide.streifen.mehr_eigen' => 'Your profile',
    'guide.streifen.mehr'       => 'View profile',

    'guide.meta.seit'    => 'Guide since {monat}',
    'guide.meta.spricht' => 'Speaks {sprachen}',

    'guide.ueber.titel' => 'About me',
    'guide.ueber.leer'  => 'You have not written anything about yourself yet. A few sentences about who you are and why you show these places appear on every one of your location pages – and they are what a customer reads before sending a request.',

    'guide.angebote.eigen'      => 'Your locations',
    'guide.angebote.fremd'      => 'What {name} shows you',
    'guide.angebote.leer_eigen' => 'You do not offer a location yet. Under “{anbieten}” this profile turns into an offer.',
    'guide.angebote.anbieten'   => 'Offer a location',
    'guide.angebote.leer_fremd' => 'This guide does not offer a location at the moment.',
    'guide.angebot.ohne_titel'  => 'Tour',
    'guide.angebot.gesperrt'    => 'Blocked',

    'guide.werkzeuge.marke'       => 'Your profile',
    'guide.werkzeuge.bearbeiten'  => 'Edit profile',

    'guide.formular.bild'                   => 'Picture',
    'guide.formular.bild_hinweis'           => 'A picture of you, cropped square. Up to {mb} MB. Without a picture your initials appear there – better than an empty circle, but worse than a face.',
    'guide.formular.bild_entfernen'         => 'Remove picture',
    'guide.formular.bild_entfernen_titel'   => 'Remove profile picture?',
    'guide.formular.bild_entfernen_frage'   => 'The picture will be deleted. Your initials will appear again on your location pages and in your profile. You can upload a new picture at any time.',
    'guide.formular.bild_entfernen_ok'      => 'Remove',
    'guide.formular.anzeigename'            => 'Display name',
    'guide.formular.anzeigename_hinweis'    => 'The name customers see you under – on your location pages, in the location list and here. Your user name{name} is not affected by this; you still sign in with it, and a customer never gets to see it. Without a display name, however, it does appear there.',
    'guide.formular.ueber'                  => 'About me',
    'guide.formular.ueber_hinweis'          => 'A few sentences about yourself. THE FIRST SENTENCE appears on every one of your location pages next to your picture – write it so that it says something on its own.',
    'guide.formular.sprachen'               => 'Languages',
    'guide.formular.sprachen_hinweis'       => 'The languages you speak. Which languages apply to a single tour is still set at the location – that is not the same thing.',
    'guide.formular.speichern'              => 'Save profile',

    // -----------------------------------------------------------------
    // THE GUIDE QUESTION
    // -----------------------------------------------------------------
    'guide.rolle.wort_guide'     => 'guide',
    'guide.rolle.wort_zuschauer' => 'viewer',
    'guide.rolle.status_bedingungen' => 'You are a {rolle}. The terms have changed - please confirm the new version. Until you do you cannot create further locations; your existing ones are not affected.',
    'guide.rolle.status_guide'       => 'You are a {rolle} and can offer locations.',
    'guide.rolle.status_zuschauer'   => 'You are a {rolle}. You can book tours, but you cannot offer locations.',
    'guide.rolle.bestaetigen'        => 'Confirm the new terms',
    'guide.rolle.zurueckgeben'       => 'Give up the guide role',
    'guide.rolle.ja'                 => 'Yes, I want to become a guide',
    'guide.rolle.nein'               => 'No, I only want to watch',
    'guide.rolle.spaeter'            => 'Decide later',
    'guide.rolle.locations'          => 'My locations',
    'guide.rolle.hinweis_bedingungen'=> 'The confirmation takes effect immediately. Nothing changes about your existing locations.',
    'guide.rolle.hinweis_guide'      => 'As long as you still offer locations the role cannot be given up - delete them first under “{locations}”.',
    'guide.rolle.hinweis_offen'      => 'You can change this decision at any time in your settings.',
    'guide.rolle.zurueck'            => 'Back to the settings',
    'guide.rolle.fehler.uebernehmen' => 'The guide role could not be taken on. Please try again later.',
    'guide.rolle.fehler.zurueckgeben'=> 'The guide role could not be given up. Please try again later.',
    'guide.rolle.fehler.zustand'     => 'Your account is not in the right state for this answer.',
    'guide.rolle.fehler.antwort'     => 'Your answer could not be saved.',
    'guide.rolle.fehler.standorte'   => 'You still offer locations. Please delete them first in your settings under “{locations}” - after that you can give up the guide role.',

    // -----------------------------------------------------------------
    // THE RATINGS
    // -----------------------------------------------------------------
    'bewertung.block.titel'       => 'Ratings',
    'bewertung.block.titel_eigen' => 'Your ratings',
    'bewertung.marke.keine'       => 'No rating yet',
    'bewertung.marke.wenige'      => 'Only a few ratings so far',

    'bewertung.jung.fuehrungen' => [
        'one'   => 'One tour completed.',
        'other' => '{n} tours completed.',
    ],
    'bewertung.jung.fuehrungen_bewertet' => [
        'one'   => 'One tour completed, {bewertet}.',
        'other' => '{n} tours completed, {bewertet}.',
    ],
    'bewertung.jung.davon_bewertet' => [
        'one'   => 'one of them rated',
        'other' => '{n} of them rated',
    ],
    'bewertung.jung.keine_eigen'   => 'Nobody has rated yet. From {min} ratings on, an average appears here.',
    'bewertung.jung.keine_texte'   => 'Nobody has written about it yet.',
    'bewertung.jung.keine_fuehrung'=> 'No tour has taken place here yet.',
    'bewertung.jung.wenige_eigen'  => 'An average appears from {min} ratings on – {fehlend} to go. Until then no number appears here: a single voice looks like a verdict, and it is not one.',
    'bewertung.jung.wenige_fremd'  => 'There are still too few for an average – it appears from {min} ratings on.',

    'bewertung.anzahl' => [
        'one'   => '{n} rating',
        'other' => '{n} ratings',
    ],
    'bewertung.sterne.label' => '{wert} out of {max} stars',
    'bewertung.eintrag.zu'   => 'On “{titel}”',

    'bewertung.stern.1' => 'Disappointing',
    'bewertung.stern.2' => 'Not so good',
    'bewertung.stern.3' => 'All right',
    'bewertung.stern.4' => 'Good',
    'bewertung.stern.5' => 'Excellent',

    // -----------------------------------------------------------------
    // THE ADMINISTRATION
    // -----------------------------------------------------------------
    'verwaltung.titel'      => 'Administration',
    'verwaltung.untertitel' => 'Accounts, locations and ratings of the platform.',
    'verwaltung.nav'        => 'Administration',

    'verwaltung.reiter.uebersicht'  => 'Overview',
    'verwaltung.reiter.benutzer'    => 'Users',
    'verwaltung.reiter.anfragen'    => 'Requests',
    'verwaltung.reiter.standorte'   => 'Locations',
    'verwaltung.reiter.bewertungen' => 'Ratings',

    'verwaltung.vorrat.haengend' => [
        'one'   => 'Tour is stuck',
        'other' => 'Tours are stuck',
    ],
    'verwaltung.vorrat.haengend_text' => 'Begun and ended by nobody. As long as that is so, the start button stays with the customer and the rating never falls due.',
    'verwaltung.vorrat.unbeantwortet' => [
        'one'   => 'Request without an answer',
        'other' => 'Requests without an answer',
    ],
    'verwaltung.vorrat.unbeantwortet_text' => 'Expired without the guide accepting or declining – within the last {tage} days. The customer waited and got nothing.',
    'verwaltung.vorrat.gesperrt' => [
        'one'   => 'Location blocked',
        'other' => 'Locations blocked',
    ],
    'verwaltung.vorrat.gesperrt_text' => 'A case somebody opened and somebody has to close again – or confirm.',
    'verwaltung.vorrat.unvollstaendig' => [
        'one'   => 'Offer incomplete',
        'other' => 'Offers incomplete',
    ],
    'verwaltung.vorrat.unvollstaendig_text' => 'Without a pin on the map, without a picture, without a title, without a detailed description or without usual times. To the guide it looks finished – after all, they know what they offer.',
    'verwaltung.vorrat.cron' => [
        'one'   => 'Account stuck on “online”',
        'other' => 'Accounts stuck on “online”',
    ],
    'verwaltung.vorrat.cron_text' => 'No sign of life for over a quarter of an hour, still not set offline: cron/check_online_status.php is not running. As long as that is so, every account in the user list shows as reachable.',
    'verwaltung.vorrat.leer' => 'Nothing open: no stuck tour, no unanswered request, no blocked location, no incomplete offer – and the cleanup job is running.',

    'verwaltung.kachel.konten'              => 'Accounts',
    'verwaltung.kachel.konten_neu'          => '{n} new in {tage} days',
    'verwaltung.kachel.konten_keine_neuen'  => 'none new in {tage} days',
    'verwaltung.kachel.standorte'           => 'Locations',
    'verwaltung.kachel.standorte_anbieter'  => [
        'one'   => 'offered by {n} account',
        'other' => 'offered by {n} accounts',
    ],
    'verwaltung.kachel.standorte_gesperrt'  => '{n} of them blocked',
    'verwaltung.kachel.fuehrungen'          => 'Tours',
    'verwaltung.kachel.fuehrungen_zeitraum' => 'completed in {tage} days',
    'verwaltung.kachel.fuehrungen_fuss'     => '{gesamt} in total · {offen} running right now',
    'verwaltung.kachel.bewertungen'         => 'Ratings',
    'verwaltung.kachel.kein_schnitt'        => 'no average yet',
    'verwaltung.kachel.schnitt'             => 'Average {wert}',
    'verwaltung.kachel.entfernt'            => '{n} removed',

    'verwaltung.geloescht_schalter' => 'Deleted accounts',

    'verwaltung.ohne_titel'      => 'Without a title',
    'verwaltung.konto_geloescht' => 'Account deleted',

    'verwaltung.standorte.leer' => 'No locations.',
    'verwaltung.zustand.live'   => 'available',
    'verwaltung.zustand.busy'   => 'in a call',
    'verwaltung.zustand.idle'   => 'not there',
    'verwaltung.mangel.ort'     => 'no pin',
    'verwaltung.mangel.bild'    => 'no picture',
    'verwaltung.mangel.titel'   => 'no title',
    'verwaltung.mangel.text'    => 'no text',
    'verwaltung.mangel.zeiten'  => 'no times',
    'verwaltung.vollstaendig'   => 'complete',
    'verwaltung.gesperrt'       => 'blocked',
    'verwaltung.freigeben'      => 'Unblock',
    'verwaltung.sperren'        => 'Block',

    'verwaltung.anfragen.leer'       => 'Nothing in this view.',
    'verwaltung.anfragen.haengt'     => 'stuck',
    'verwaltung.anfragen.laeuft_seit'=> 'running for {spanne}',
    'verwaltung.anfragen.verfallen'  => 'expired {wann}',
    'verwaltung.anfragen.wunsch'     => 'Wish: {wann}',
    'verwaltung.chat_mit'            => 'Chat with {name}',
    'verwaltung.guide_anschreiben'   => 'Message the guide',

    'verwaltung.bewertungen.leer'           => 'No ratings.',
    'verwaltung.bewertungen.ohne_text'      => 'without text',
    'verwaltung.bewertungen.entfernt_grund' => 'Removed: {grund}',
    'verwaltung.bewertungen.ohne_grund'     => 'without a reason',
    'verwaltung.bewertungen.entfernt'       => 'removed',
    'verwaltung.bewertungen.entfernen'      => 'Remove',
    'verwaltung.bewertungen.konto'          => '{rolle}: {name}',
    'verwaltung.rolle.guide'                => 'Guide',
    'verwaltung.rolle.kunde'                => 'Customer',

    // -----------------------------------------------------------------
    // THE IMAGE STORE
    // -----------------------------------------------------------------
    'bild.fehler.zu_gross'          => 'The file is too large.',
    'bild.fehler.nicht_empfangen'   => 'The file could not be received.',
    'bild.fehler.grenze'            => 'The file is too large – up to {mb} MB is allowed.',
    'bild.fehler.kein_bild'         => 'That is not an image file.',
    'bild.fehler.format'            => 'This image format is not accepted (JPEG, PNG or WebP).',
    'bild.fehler.masse'             => 'The picture is too large – up to {kante} pixels per edge is allowed.',
    'bild.fehler.nicht_lesbar'      => 'The picture could not be read.',
    'bild.fehler.nicht_gespeichert' => 'The picture could not be saved.',

    // -----------------------------------------------------------------
    // THE ACCOUNT PAGE
    // -----------------------------------------------------------------
    'konto.2fa.aktiv'          => 'Enabled',
    'konto.2fa.inaktiv'        => 'Not enabled',
    'konto.2fa.deaktivieren'   => 'Disable 2FA',
    'konto.2fa.einrichten'     => 'Set up 2FA',

    'konto.guide.aktiv'            => 'Active',
    'konto.guide.inaktiv'          => 'Not active',
    'konto.guide.aktiv_offen'      => 'Active {zusatz}',
    'konto.guide.offen_hinweis'    => '– new terms pending',
    'konto.guide.knopf_bestaetigen'=> 'Confirm the new terms',
    'konto.guide.knopf_aendern'    => 'Change the guide role',
    'konto.guide.knopf_werden'     => 'Become a guide',

    'konto.mail.label'  => 'Email confirmed',
    'konto.mail.ja'     => 'Confirmed',
    'konto.mail.nein'   => 'Not confirmed',
    'konto.mail.senden' => 'Send confirmation email',

    'konto.profil.titel'   => 'My guide profile',
    'konto.profil.ansehen' => 'View public profile',
    'konto.profil.hinweis' => 'This is what a customer sees before requesting a tour: on every one of your location pages and on your own page, which you can pass on. A user name and a coloured dot are no basis for handing money to a stranger.',

    'konto.farbprofil.unbekannt' => 'Unknown colour scheme.',
    'konto.farbprofil.fehler'    => 'The colour scheme could not be saved.',

    // -----------------------------------------------------------------
    // THE SIGN-UP
    // -----------------------------------------------------------------
    'registrierung.fehler.benutzername_vergeben'  => 'That user name is already taken.',
    'registrierung.fehler.email_vergeben'         => 'That email address is already taken.',
    'registrierung.fehler.benutzername_geloescht' => 'This user name belongs to a deleted account and cannot be given out again. Please choose a different one.',
    'registrierung.fehler.email_geloescht'        => 'There already was an account for this email address, and it was deleted. The address can therefore not be used again. Please use a different address or get in touch with the operator.',
    'registrierung.fehler.rennen'                 => 'The user name or the email address was taken just now. Please try again.',
    'registrierung.fehler.passwoerter'            => 'The passwords do not match.',
    'registrierung.fehler.benutzername_ungueltig' => 'Invalid user name. Letters, digits and underscore only, 3-20 characters.',
    'registrierung.fehler.email_ungueltig'        => 'Please enter a valid email address.',
    'registrierung.fehler.passwort_kurz'          => 'The password must be at least {n} characters long.',
    'registrierung.fehler.gesperrt'               => 'Too many sign-up attempts. Please wait {warten}.',
    'registrierung.fehler.unbekannt'              => 'An unknown error occurred.',

    // -----------------------------------------------------------------
    // THE REQUEST
    // -----------------------------------------------------------------
    'anfrage.fehler.post'                 => 'POST only.',
    'anfrage.fehler.zu_viele'             => 'Too many requests in a short time. Please wait {warten}.',
    'anfrage.fehler.standort_fehlt'       => 'The location is missing.',
    'anfrage.fehler.zu_weit'              => 'A tour cannot be requested that far in advance.',
    'anfrage.fehler.keine_anfragen'       => 'This location does not accept requests.',
    'anfrage.fehler.eigener_standort'     => 'You do not send a request to your own location.',
    'anfrage.fehler.bereits_offen'        => 'You already have a request pending for this location.',
    'anfrage.fehler.nicht_gestellt'       => 'The request could not be sent.',
    'anfrage.fehler.anfrage_fehlt'        => 'The request is missing.',
    'anfrage.fehler.nicht_beantwortbar'   => 'This request can no longer be answered.',
    'anfrage.fehler.nicht_zuruecknehmbar' => 'This request can no longer be withdrawn.',
    'anfrage.fehler.fuehrung_fehlt'       => 'The tour is missing.',
    'anfrage.fehler.nicht_beendbar'       => 'This tour can no longer be finished.',

    // -----------------------------------------------------------------
    // THE WAITING TIME OF A LIMIT
    // -----------------------------------------------------------------
    'warten.gleich' => 'a moment',
    'warten.sekunden' => [
        'one'   => 'another {n} second',
        'other' => 'another {n} seconds',
    ],
    'warten.minuten' => [
        'one'   => 'another {n} minute',
        'other' => 'another {n} minutes',
    ],
    'warten.stunden' => [
        'one'   => 'another {n} hour',
        'other' => 'another {n} hours',
    ],
    // =================================================================
    // THE TEMPLATES
    //
    // The stage after the texts PHP produces itself. They are fetched with
    // the {{t:key}} marker, resolved when the file is loaded. A sentence
    // with a value or an emphasis inside it cannot be a marker - those are
    // built in PHP with ViewHelper::tHtml(); the template comment names the
    // caller.
    // =================================================================

    'fuss.rechtliches' => 'Legal',
    'fuss.impressum'   => 'Imprint',
    'fuss.datenschutz' => 'Privacy',
    'fuss.kontakt'     => 'Contact',

    'ansicht.label'    => 'View',
    'ansicht.karte'    => 'Map',
    'ansicht.liste'    => 'List',
    'ansicht.anfragen' => 'Requests',

    'tabelle.spalte.status'        => 'Status',
    'tabelle.spalte.guide'         => 'Guide',
    'tabelle.spalte.bewertung'     => 'Rating',
    'tabelle.spalte.land'          => 'Country',
    'tabelle.spalte.stadt'         => 'City',
    'tabelle.spalte.beschreibung'  => 'Description',
    'tabelle.spalte.aktionen'      => 'Actions',

    'allgemein.schliessen' => 'Close',

    // -----------------------------------------------------------------
    // SIGNING IN AND SIGNING UP
    // -----------------------------------------------------------------
    'anmelden.titel'        => 'Sign in',
    'anmelden.untertitel'   => 'On to your tours.',
    'anmelden.benutzername' => 'User name',
    'anmelden.passwort'     => 'Password',
    'anmelden.knopf'        => 'Sign in',
    'anmelden.kein_konto'   => 'No account yet?',
    'anmelden.registrieren' => 'Sign up now',

    'registrierung.titel'        => 'Create an account',
    'registrierung.untertitel'   => 'After that you can book tours – and offer some yourself.',
    'registrierung.benutzername' => 'User name',
    'registrierung.email'        => 'Email address',
    'registrierung.passwort'     => 'Password',
    'registrierung.passwort_wdh' => 'Repeat password',
    'registrierung.knopf'        => 'Create an account',
    'registrierung.schon_dabei'  => 'Already signed up?',
    'registrierung.anmelden'     => 'Sign in',

    // -----------------------------------------------------------------
    // THE PASSWORD
    // -----------------------------------------------------------------
    'passwort.vergessen.titel'   => 'Forgot your password',
    'passwort.vergessen.text'    => 'We will send you a link to the address on file.',
    'passwort.vergessen.email'   => 'Email address',
    'passwort.vergessen.knopf'   => 'Request a link',
    'passwort.vergessen.zurueck' => 'Back to signing in',

    'passwort.neu.titel'    => 'Set a new password',
    'passwort.neu.feld'     => 'New password',
    'passwort.neu.feld_wdh' => 'Repeat new password',
    'passwort.neu.knopf'    => 'Change password',

    'passwort.aendern.titel'      => 'Change password',
    'passwort.aendern.angemeldet' => 'Signed in as {name}',
    'passwort.aendern.alt'        => 'Old password',
    'passwort.aendern.knopf'      => 'Change password',
    'passwort.aendern.abbrechen'  => 'Cancel',

    // -----------------------------------------------------------------
    // THE HOME PAGE
    // -----------------------------------------------------------------
    'startseite.titel'       => 'Where would you like to go today?',
    'startseite.untertitel'  => 'Pick a place on the map. A guide on site takes you along – live, and you say where to.',
    'startseite.erklaerung'  => 'How does this work?',
    'startseite.karte_label' => 'Map of the locations on offer',
    'startseite.laden'       => 'Loading locations …',

    'startseite.legende.live'  => 'Guide available now',
    'startseite.legende.busy'  => 'Guide in a call',
    'startseite.legende.idle'  => 'Location without a guide',
    'startseite.legende.eigen' => 'Your location',

    'startseite.gast.eyebrow'  => 'Guided live instead of scrolled past',
    'startseite.gast.titel'    => 'Somebody sets off for you.',
    'startseite.gast.text'     => 'Like a street view – only real and happening now. A person on site sends their picture, you tell them where to go. Sign in to see who is out and about right now.',
    'startseite.gast.konto'    => 'Create an account',
    'startseite.gast.anmelden' => 'Sign in',
    'startseite.gast.fuss'     => 'Are you somewhere others would like to see? Once signed in you can offer your location and give tours yourself.',

    'startseite.leer.eyebrow' => 'Nothing on the map yet',
    'startseite.leer.titel'   => 'Nobody is out and about here yet.',
    'startseite.leer.text'    => 'No locations have been entered so far. This is how it works as soon as somebody offers one – and you could be the first.',
    'startseite.leer.guide'   => 'Become a guide',
    'startseite.leer.liste'   => 'To the list view',
    'startseite.leer.fuss'    => 'As a guide you enter a place you know your way around. When you are online it appears highlighted on this map – and people call you.',

    'startseite.fehler.eyebrow' => 'Map not loaded',
    'startseite.fehler.titel'   => 'The locations cannot be reached right now.',
    'startseite.fehler.text'    => 'The server did not answer. That is usually down to the connection and is fixed by trying again.',
    'startseite.fehler.knopf'   => 'Try again',

    'startseite.schritt1.titel' => 'Pick a place',
    'startseite.schritt1.text'  => 'On the map you can see where a guide is standing by right now.',
    'startseite.schritt2.titel' => 'Call',
    'startseite.schritt2.text'  => 'One click on the pin starts the call. Sound and picture come straight from the street.',
    'startseite.schritt3.titel' => 'Guide them',
    'startseite.schritt3.text'  => 'The arrow keys set the direction. The guide hears and sees what you want and goes there.',

    // -----------------------------------------------------------------
    // THE LOCATION LIST
    // -----------------------------------------------------------------
    'standortliste.titel'      => 'All locations',
    'standortliste.untertitel' => 'The same locations as on the map, here to search and sort.',

    // -----------------------------------------------------------------
    // OFFERING AND EDITING A LOCATION
    // -----------------------------------------------------------------
    'standort.anbieten.titel'      => 'Offer a location',
    'standort.anbieten.untertitel' => 'The place where you guide. It appears on the map – highlighted as soon as you are online.',

    'standort.formular.land'              => 'Country',
    'standort.formular.land_waehlen'      => 'Choose a country …',
    'standort.formular.stadt'             => 'City',
    'standort.formular.stadt_waehlen'     => 'Choose a city …',
    'standort.formular.titel'             => 'Title',
    'standort.formular.titel_platzhalter' => 'What is this tour about?',
    'standort.formular.kurz'              => 'Short description',
    'standort.formular.kurz_platzhalter'  => 'One line – it appears on the map and in the list',
    'standort.formular.kurz_hinweis'      => 'Others see this line in the map window and in the location list. The detailed text appears on the location page.',
    'standort.formular.lang'              => 'Detailed description',
    'standort.formular.lang_platzhalter'  => 'What do you show? Where do we meet? What should people know?',
    'standort.formular.lang_hinweis'      => 'Can be added later as well – on the location page.',
    'standort.formular.dauer'             => 'Typical duration (minutes)',
    'standort.formular.dauer_platzhalter' => 'e.g. 45',
    'standort.formular.dauer_hinweis'     => 'Leave empty if there is no usual duration.',
    'standort.formular.sprachen'          => 'Languages you guide in',
    'standort.formular.punkt'             => 'Point on the map',
    'standort.formular.aktueller_ort'     => 'Use my current position',
    'standort.formular.breitengrad'       => 'Latitude',
    'standort.formular.laengengrad'       => 'Longitude',
    'standort.formular.osm'               => 'Place according to OpenStreetMap',
    'standort.formular.speichern'         => 'Save location',
    'standort.formular.abbrechen'         => 'Cancel',

    'standort.bearbeiten.titel'          => 'Your location',
    'standort.bearbeiten.umschalten'     => 'Edit',
    'standort.bearbeiten.kurz_hinweis'   => 'Others see this line in the map window and in the location list. The detailed text appears only here.',
    'standort.bearbeiten.zeiten_frage'   => 'When are you usually out and about?',
    'standort.bearbeiten.zeiten_hinweis' => 'A rough orientation for customers – not a firm commitment. Requests for other times remain possible.',
    'standort.bearbeiten.zone'           => 'Time zone of the place',
    'standort.bearbeiten.zone_hinweis'   => 'Your times apply where the tour takes place. Customers in other time zones see both. Preset is the zone that follows from country and coordinates.',
    'standort.bearbeiten.speichern'      => 'Save',
    'standort.bearbeiten.abbrechen'      => 'Cancel',
    'standort.bearbeiten.titelbild'      => 'Cover picture',
    'standort.bearbeiten.galerie'        => 'Pictures of the place',
    'standort.bearbeiten.zahl'           => 'Up to {max} pictures in total, cover picture included – currently {bisher}.',
    'standort.bearbeiten.hinzufuegen'    => 'Add a picture',

    'standort.zurueck'             => 'Back to the overview',
    'standort.treffpunkt'          => 'Meeting point',
    'standort.lightbox.vorheriges'  => 'Previous picture',
    'standort.lightbox.naechstes'   => 'Next picture',

    // -----------------------------------------------------------------
    // THE REQUESTS PAGE
    // -----------------------------------------------------------------
    'anfrage.seite.titel'          => 'Requests',
    'anfrage.seite.untertitel'     => 'What is addressed to your locations – and what you asked for yourself.',
    'anfrage.seite.eingehend'      => 'To my locations',
    'anfrage.seite.eingehend_leer' => 'Nothing is open right now. As soon as somebody requests a tour at one of your locations it appears here – and the counter in the header tells you.',
    'anfrage.seite.ausgehend'      => 'My requests',
    'anfrage.seite.ausgehend_leer' => 'You have not requested a tour yet. Pick a place on the map – on its page you request the tour with your preferred time.',
    'anfrage.seite.fehler'         => 'The requests could not be loaded. That is usually down to the connection.',

    // -----------------------------------------------------------------
    // THE GUIDE QUESTION
    // -----------------------------------------------------------------
    'guide.rolle.frage'                 => 'Would you like to become a guide?',
    'guide.rolle.was_guide.titel'       => 'What a guide does',
    'guide.rolle.was_guide.text'        => 'As a {rolle} you offer locations: places you know your way around and where you can be out and about. When somebody books a tour you are on site with camera and sound - and {regie}. They tell you through a direction pad where to walk and where to look. You always decide for yourself whether to follow an instruction, and you can lock the controls during a call.',
    'guide.rolle.was_guide.regie'       => 'the viewer directs',
    'guide.rolle.was_zuschauer.titel'   => 'What a viewer does',
    'guide.rolle.was_zuschauer.text'    => 'As a {rolle} you pick a location on the map and let a guide on site show you around. You do not have to offer anything, and your own position does not matter.',
    'guide.rolle.kosten.titel'          => 'A note about later costs:',
    'guide.rolle.kosten.text'           => 'Tours are free at the moment. They will become chargeable - viewers pay for a tour, guides are paid for giving one. Before that takes effect we will put the terms that then apply to you for approval again. Without your approval neither costs nor claims arise.',

    // -----------------------------------------------------------------
    // THE ACCOUNT PAGE
    // -----------------------------------------------------------------
    'konto.titel'      => 'My account',
    'konto.untertitel' => 'Sign-in details, security and your own locations.',

    'konto.angaben.titel'                => 'Details',
    'konto.angaben.benutzername'         => 'User name',
    'konto.angaben.benutzername_hinweis' => 'For signing in only. Customers see the display name from your guide profile.',
    'konto.angaben.email'                => 'Email address',
    'konto.angaben.zweifaktor'           => 'Two-factor sign-in',
    'konto.angaben.guide_rolle'          => 'Guide role',
    'konto.passwort_aendern'             => 'Change password',

    'konto.farbprofil.titel'   => 'Colour scheme',
    'konto.farbprofil.hinweis' => 'Applies to this account and will still be set the next time you sign in. The colours of the map pins stay the same in every scheme.',

    'konto.standorte.titel'    => 'My locations',
    'konto.standorte.anbieten' => 'Offer a location',

    'konto.geloescht' => 'Deleted account',

    // -----------------------------------------------------------------
    // THE COLOUR SCHEMES
    // -----------------------------------------------------------------
    'farbprofil.indigo.name'     => 'Indigo',
    'farbprofil.indigo.text'     => 'The default. Cool grey with an indigo accent.',
    'farbprofil.himmelblau.name' => 'Sky blue',
    'farbprofil.himmelblau.text' => 'Light and friendly, on a faintly blue ground.',
    'farbprofil.dunkel.name'     => 'Dark',
    'farbprofil.dunkel.text'     => 'Dark surfaces for evenings and dark rooms.',
    'farbprofil.neutral.name'    => 'Neutral',
    'farbprofil.neutral.text'    => 'Very restrained, without a coloured accent.',

    // -----------------------------------------------------------------
    // THE NOTE ABOUT THE UNCONFIRMED ADDRESS
    // -----------------------------------------------------------------
    'mailhinweis.gesperrt'        => 'Please confirm your email address first. After that this function is available again.',
    'mailhinweis.streifen.titel'  => 'Email address not confirmed yet.',
    'mailhinweis.streifen.text'   => 'Requests, chat and uploading pictures are blocked until then.',
    'mailhinweis.streifen.knopf'  => 'Send confirmation email',

    // -----------------------------------------------------------------
    // THE ADMINISTRATION
    // -----------------------------------------------------------------
    'verwaltung.uebersicht.vorrat'  => 'Needs attention',
    'verwaltung.uebersicht.bestand' => 'Inventory',

    'verwaltung.benutzer.titel'      => 'Users',
    'verwaltung.benutzer.untertitel' => 'You can call and write to whoever is online right now.',
    'verwaltung.benutzer.neu'        => 'New user',
    'verwaltung.benutzer.spalte.status'       => 'Status',
    'verwaltung.benutzer.spalte.anrufen'      => 'Call',
    'verwaltung.benutzer.spalte.benutzername' => 'User name',
    'verwaltung.benutzer.spalte.nachricht'    => 'Message',
    'verwaltung.benutzer.spalte.email'        => 'Email',
    'verwaltung.benutzer.spalte.aktionen'     => 'Actions',

    'verwaltung.benutzer.formular.anlegen'      => 'Create a new user',
    'verwaltung.benutzer.formular.bearbeiten'   => 'Edit user {id} ({name})',
    'verwaltung.benutzer.formular.rolle'        => 'Role',
    'verwaltung.benutzer.formular.benutzername' => 'User name',
    'verwaltung.benutzer.formular.email'        => 'Email address',
    'verwaltung.benutzer.formular.passwort'     => 'Password',
    'verwaltung.benutzer.formular.speichern'    => 'Save',
    'verwaltung.benutzer.formular.abbrechen'    => 'Cancel',

    'verwaltung.standorte.titel'           => 'Locations',
    'verwaltung.standorte.spalte.standort' => 'Location',
    'verwaltung.standorte.spalte.guide'    => 'Guide',
    'verwaltung.standorte.spalte.zustand'  => 'State',
    'verwaltung.standorte.spalte.fehlt'    => 'Missing',
    'verwaltung.standorte.spalte.sperre'   => 'Block',
    'verwaltung.standorte.spalte.aktion'   => 'Action',

    'verwaltung.anfragen.titel'           => 'Requests',
    'verwaltung.anfragen.spalte.zustand'  => 'State',
    'verwaltung.anfragen.spalte.fuehrung' => 'Tour',
    'verwaltung.anfragen.spalte.guide'    => 'Guide',
    'verwaltung.anfragen.spalte.kunde'    => 'Customer',
    'verwaltung.anfragen.spalte.aktion'   => 'Action',

    'verwaltung.bewertungen.titel'            => 'Ratings',
    'verwaltung.bewertungen.spalte.sterne'    => 'Stars',
    'verwaltung.bewertungen.spalte.text'      => 'Text',
    'verwaltung.bewertungen.spalte.fuehrung'  => 'Tour',
    'verwaltung.bewertungen.spalte.abgegeben' => 'Given',
    'verwaltung.bewertungen.spalte.aktion'    => 'Action',

    'verwaltung.filter.label'          => 'Filter',
    'verwaltung.filter.alle'           => 'All',
    'verwaltung.filter.haengend'       => 'Stuck tours',
    'verwaltung.filter.unbeantwortet'  => 'Without an answer',
    'verwaltung.filter.offen'          => 'Open',
    'verwaltung.filter.gesperrt'       => 'Blocked',
    'verwaltung.filter.unvollstaendig' => 'Incomplete',
    'verwaltung.filter.sichtbar'       => 'Visible',
    'verwaltung.filter.schwach'        => '1–2 stars',
    'verwaltung.filter.entfernt'       => 'Removed',

    // -----------------------------------------------------------------
    // THE CHATS
    // -----------------------------------------------------------------
    'chat.titel'           => 'Chats',
    'chat.untertitel'      => 'Conversations from past and running tours.',
    'chat.spalte.status'   => 'Status',
    'chat.spalte.partner'  => 'Partner',
    'chat.spalte.letzte'   => 'Last message',
    'chat.spalte.verlauf'  => 'History',
    'chat.verlauf.titel'   => 'History',
    'chat.verlauf.zurueck' => 'Back to all chats',

    // -----------------------------------------------------------------
    // THE CALL
    // -----------------------------------------------------------------
    'gespraech.kein_video'       => 'No video picture',
    'gespraech.kamera_aus'       => 'Camera off',
    'gespraech.auflegen'         => 'Hang up',
    'gespraech.zweck.titel'      => 'Call from the administration',
    'gespraech.zweck.text'       => 'Not a tour – nothing is being directed.',
    'gespraech.sperre.hinweis'   => 'Controls locked – the guide has paused them.',
    'gespraech.blick'            => 'Look',
    'gespraech.blick_oben'       => 'Look up',
    'gespraech.blick_unten'      => 'Look down',
    'gespraech.vorwaerts'        => 'Forward',
    'gespraech.links'            => 'Turn left',
    'gespraech.rechts'           => 'Turn right',
    'gespraech.rueckwaerts'      => 'Backward',
    'gespraech.sperren'          => 'Lock the controls',
    'gespraech.mikrofon'         => 'Microphone',
    'gespraech.mikrofon_schalter'=> 'Microphone on/off',
    'gespraech.kamera'           => 'Camera',
    'gespraech.kamera_schalter'  => 'Camera on/off',
    'gespraech.geraete'          => 'Devices',
    'gespraech.chat'             => 'Chat',
    'gespraech.ungelesen'        => 'Unread messages',
    'gespraech.chat_schliessen'  => 'Close the chat',
    'gespraech.nachricht'        => 'Message',
    'gespraech.senden'           => 'Send',

    'gespraech.anruf.eingehend'  => 'Incoming call',
    'gespraech.anruf.zweck_text' => 'This is not a tour – nothing is being directed. Sound and picture run both ways.',
    'gespraech.anruf.video'      => 'Send video',
    'gespraech.anruf.ton'        => 'Send sound',
    'gespraech.anruf.annehmen'   => 'Accept',
    'gespraech.anruf.ablehnen'   => 'Decline',

    // -----------------------------------------------------------------
    // THE RESULT PAGES
    // -----------------------------------------------------------------
    'ergebnis.mail_bestaetigt.titel' => 'Email address confirmed',
    'ergebnis.mail_bestaetigt.text'  => 'You can sign in now.',
    'ergebnis.mail_bestaetigt.knopf' => 'To signing in',

    'ergebnis.mail_fehler.titel'        => 'Link invalid or expired',
    'ergebnis.mail_fehler.text'         => 'The confirmation link cannot be redeemed any more. Confirmation links are valid for a limited time.',
    'ergebnis.mail_fehler.registrieren' => 'Sign up again',
    'ergebnis.mail_fehler.anmelden'     => 'To signing in',

    'ergebnis.mail_verschickt.titel' => 'Confirmation email sent',
    'ergebnis.mail_verschickt.text'  => 'Please click the link in the email. The link is valid for 24 hours; do check your spam folder as well.',
    'ergebnis.mail_verschickt.knopf' => 'To the home page',

    'ergebnis.registriert.titel' => 'Sign-up successful',
    'ergebnis.registriert.text'  => 'Your account has been created. We have sent you an email to confirm your address — do check your spam folder as well. You can sign in straight away.',
    'ergebnis.registriert.knopf' => 'To signing in',
];
