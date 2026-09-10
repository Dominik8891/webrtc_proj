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
    // THE REQUEST LIST IN THE BROWSER (assets/js/requests.js)
    // -----------------------------------------------------------------
    'anfrage.neu'                => 'A new request for one of your tours.',
    'anfrage.angenommen'         => 'Your request has been accepted.',
    'anfrage.zugesagt'           => 'Accepted. The customer starts the tour at the agreed time – you will be called then.',
    'anfrage.abgelehnt'          => 'Declined.',
    'anfrage.zurueckgenommen'    => 'Withdrawn.',
    'anfrage.zurueckziehen'      => 'Withdraw',
    'anfrage.annehmen'           => 'Accept',
    'anfrage.ablehnen'           => 'Decline',
    'anfrage.partner_unbekannt'  => 'Unknown',

    'anfrage.zeile.von'              => 'Requested by {name}',
    'anfrage.zeile.guide'            => 'Your guide: {name}',
    'anfrage.zeile.wunschzeit'       => 'Preferred time: {zeit}',
    'anfrage.zeile.fuehrung'         => 'Tour',
    'anfrage.zeile.nichts_zu_tun'    => 'Nothing left to do.',
    'anfrage.zeile.bewertet'         => 'Rated: {sterne}',
    'anfrage.zeile.bewertung_eigen'  => 'Your rating: {sterne}',

    'anfrage.rest.ohne_frist' => 'Still running. End it when you are done.',
    'anfrage.rest.abgelaufen' => 'Rejoining is no longer possible.',
    'anfrage.rest.offen'      => 'You can rejoin for another {dauer}.',

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

    'allgemein.loeschen'          => 'Delete',
    'allgemein.keine_verbindung'  => 'No connection. Please try again.',
    'allgemein.nicht_geklappt'    => 'That did not work.',
    'allgemein.unbekannter_fehler'=> 'unknown error',
    'allgemein.bearbeiten'        => 'Edit',
    'allgemein.keine_auswahl'     => '— none —',

    // -----------------------------------------------------------------
    // THE BROWSER DIALOGUES (assets/js/notify.js)
    // -----------------------------------------------------------------
    'dialog.hinweis'             => 'Notice',
    'dialog.hinweis_schliessen'  => 'Dismiss notice',
    'dialog.verstanden'          => 'Got it',
    'dialog.sicher'              => 'Are you sure?',
    'dialog.ja'                  => 'Yes',
    'dialog.abbrechen'           => 'Cancel',
    'dialog.eingabe'             => 'Input',
    'dialog.speichern'           => 'Save',
    'dialog.pflicht'             => 'Please enter something.',
    'dialog.loeschen.titel'      => 'Delete this record?',
    'dialog.loeschen.text'       => 'This cannot be undone.',

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

    'kopf.knopf.bedingungen'    => 'Accept the new terms',
    'kopf.knopf.standort_neu'   => 'Add a new location',
    'kopf.knopf.guide_werden'   => 'Become a tour guide!',
    'kopf.knopf.alle_standorte' => 'All locations',

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
    // The same word twice, and the full stop is the difference: on the
    // location page it is a SENTENCE, in the list a TAG inside a cell.
    'standort.sperre.wort_kurz' => 'Blocked',

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

    'standort.anfrage.zeit_fehlt'       => 'Please pick a time – “Right now” counts as one.',
    'standort.anfrage.fehler'           => 'The request could not be sent.',
    'standort.anfrage.gestellt'         => 'Request sent. The guide will answer – you will see it here and on the counter above.',
    'standort.anfrage.zurueck_frage'    => 'Withdraw the request?',
    'standort.anfrage.zurueck_text'     => 'The guide will then see that the tour is not happening.',
    'standort.anfrage.zurueck_knopf'    => 'Withdraw',
    'standort.anfrage.zurueckgezogen'   => 'Request withdrawn.',
    'standort.anfrage.nicht_mehr_offen' => 'Your request is no longer open. What became of it is under “Requests”.',
    'standort.anfrage.ortszeit'         => 'That is {zeit} local time at the meeting point.',
    'standort.anfrage.ausserhalb'       => 'That is outside the usual hours ({zeiten}). You can still ask – the guide decides.',

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
    'standort.fehler.kein_punkt'     => 'Not saved: please pick the location on the map.',
    'standort.fehler.nicht_angelegt' => 'The location could not be created.',

    'standort.bild.zu_viele'            => 'A location cannot hold more than {n} pictures.',
    'standort.bild.keine_datei'         => 'No file was sent.',
    'standort.bild.nicht_gespeichert'   => 'The picture could not be saved.',
    'standort.bild.keine_id'            => 'No picture given.',
    'standort.bild.nicht_gefunden'      => 'Picture not found.',
    'standort.bild.keine_reihenfolge'   => 'No order given.',
    'standort.bild.reihenfolge_fehler'  => 'The order could not be saved.',
    'standort.bild.titelbild_fehler'    => 'The cover picture could not be changed.',

    'standort.bild.titelbild_nicht_gesetzt' => 'The cover picture could not be set.',
    'standort.bild.format'              => 'This picture format is not accepted (JPEG, PNG or WebP).',
    'standort.bild.zu_gross_mb'         => 'The file is too large – {n} MB are allowed.',
    'standort.bild.hinzugefuegt'        => 'Picture added.',
    'standort.bild.loeschen_frage'      => 'Delete this picture?',
    'standort.bild.loeschen_text'       => 'The picture will disappear from the location page. This cannot be undone.',
    'standort.bild.nicht_geloescht'     => 'The picture could not be deleted.',
    'standort.bild.grenze_erreicht'     => 'The limit of {n} pictures is reached (the cover counts).',
    'standort.bild.noch_moeglich' => [
        'one'   => 'One more picture of {grenze} possible, the cover counts.',
        'other' => '{n} more of {grenze} pictures possible, the cover counts.',
    ],

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

    // -----------------------------------------------------------------
    // THE GUIDE PROFILE (App\Controller\GuideProfileController)
    // -----------------------------------------------------------------
    'guide.profil.nicht_gespeichert'      => 'The profile could not be saved.',
    'guide.profil.bild_nicht_gespeichert' => 'The picture could not be saved.',
    'guide.profil.bild_nicht_entfernt'    => 'The picture could not be removed.',
    'guide.profil.bild_nicht_gefunden'    => 'Picture not found.',

    'guide.fehlseite.titel' => 'Guide not found',
    'guide.fehlseite.text'  => 'This profile is gone, or it never existed.',
    'guide.fehlseite.karte' => 'To the map',
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

    // The question after a tour (assets/js/review.js).
    'bewertung.frage.titel'            => 'Rate the tour',
    'bewertung.frage.lead'             => 'How was the tour with {guide}?',
    'bewertung.frage.lead_titel'       => 'How was the tour with {guide}? – {titel}',
    'bewertung.frage.guide_unbekannt'  => 'your guide',
    'bewertung.frage.sterne'           => 'Stars',
    'bewertung.frage.text_label'       => 'A few words, if you like',
    'bewertung.frage.text_platzhalter' => 'What should others know?',
    'bewertung.frage.spaeter'          => 'Later',
    'bewertung.frage.absenden'         => 'Send',
    'bewertung.frage.fuss'             => 'Your name is not shown. The guide can neither change nor delete the rating.',
    'bewertung.frage.sterne_zuerst'    => 'Please pick the stars first.',
    'bewertung.frage.fehler'           => 'The rating could not be saved.',
    'bewertung.frage.danke'            => 'Thank you – your rating has reached the guide.',

    'bewertung.sterne.anzahl' => [
        'one'   => '{n} star',
        'other' => '{n} stars',
    ],

    'bewertung.kurz.fuehrungen' => [
        'one'   => 'New · one tour',
        'other' => 'New · {n} tours',
    ],
    'bewertung.kurz.fuehrungen_bewertet' => [
        'one'   => 'New · one tour, {bewertet}',
        'other' => 'New · {n} tours, {bewertet}',
    ],
    'bewertung.kurz.bewertungen' => [
        'one'   => 'one rating',
        'other' => '{n} ratings',
    ],

    // What the rating endpoint refuses (App\Controller\ReviewController).
    'bewertung.fehler.post'             => 'This is only accepted by POST.',
    'bewertung.fehler.zu_viele'         => 'Too many ratings in a short time. Please wait {warten}.',
    'bewertung.fehler.keine_fuehrung'   => 'The tour is missing.',
    'bewertung.fehler.keine_bewertung'  => 'The rating is missing.',
    'bewertung.fehler.sterne'           => 'Please choose between one and five stars.',
    'bewertung.fehler.nicht_bewertbar'  => 'This tour cannot be rated. Perhaps you have already rated it.',
    'bewertung.fehler.nicht_entfernbar' => 'This rating cannot be removed. Perhaps it already is.',

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

    // The user list (App\Controller\UserController). The state words differ
    // from standort.zustand.*: there it is about an offer, here about an
    // account.
    'verwaltung.benutzer.online'          => 'Online',
    'verwaltung.benutzer.offline'         => 'Offline',
    'verwaltung.benutzer.im_gespraech'    => 'In a call',
    'verwaltung.benutzer.bereit_titel'    => 'Has set themselves available',
    'verwaltung.benutzer.anrufen'         => 'Call',
    'verwaltung.benutzer.anschreiben'     => 'Message',
    'verwaltung.benutzer.bearbeiten_label'=> 'Edit user {name}',
    'verwaltung.benutzer.loeschen_label'  => 'Delete user {name}',

    'verwaltung.bewertungen.leer'           => 'No ratings.',
    'verwaltung.bewertungen.ohne_text'      => 'without text',
    'verwaltung.bewertungen.entfernt_grund' => 'Removed: {grund}',
    'verwaltung.bewertungen.ohne_grund'     => 'without a reason',
    'verwaltung.bewertungen.entfernt'       => 'removed',
    'verwaltung.bewertungen.entfernen'      => 'Remove',
    'verwaltung.bewertungen.konto'          => '{rolle}: {name}',
    'verwaltung.rolle.guide'                => 'Guide',
    'verwaltung.rolle.kunde'                => 'Customer',

    // The three confirmations of the admin area (assets/js/admin.js).
    'verwaltung.sperre.titel'       => 'Block location',
    'verwaltung.sperre.text'        => '“{titel}” disappears from the map and the list. The guide sees this text in their own location list. Nothing is deleted.',
    'verwaltung.sperre.grund'       => 'Reason',
    'verwaltung.sperre.platzhalter' => 'Why is it being blocked?',
    'verwaltung.sperre.pflicht'     => 'Without a reason the guide cannot make sense of the block.',
    'verwaltung.sperre.erledigt'    => 'Blocked.',

    'verwaltung.freigabe.titel'    => 'Lift the block?',
    'verwaltung.freigabe.text'     => '“{titel}” will appear on the map and in the list again.',
    'verwaltung.freigabe.erledigt' => 'Unblocked.',

    'verwaltung.bewertung_weg.titel'    => 'Remove this rating?',
    'verwaltung.bewertung_weg.text'     => 'The rating disappears from the location page and from the guide profile and no longer counts towards the average. It is not deleted – it stays here on the record. The customer cannot rate this tour again afterwards.',
    'verwaltung.bewertung_weg.erledigt' => 'Removed.',

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
    'konto.farbprofil.gespeichert' => 'Colour scheme saved.',
    'konto.farbprofil.fehler_netz' => 'The colour scheme could not be saved. Please try again later.',

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
    'fuss.ueber'       => 'What is this?',

    // The three obligatory pages (assets/html/legal.html). All three are
    // still empty, and they say so. The headings come from fuss.* above -
    // the same words as in the footer.
    'rechtliches.fehlt.titel' => 'This content is still missing.',
    'rechtliches.fehlt.text'  => 'The page exists so that the link in the footer does not lead nowhere. The text will follow.',
    'rechtliches.zurueck'     => 'Back to the start page',

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

    // THE DataTables LANGUAGE BLOCK (assets/js/locations_table.js).
    //
    // _MENU_, _START_, _END_, _TOTAL_ and _MAX_ are NOT placeholders of this
    // application: DataTables fills them in itself, and I18n::einsetzen()
    // leaves them alone. That is why they are not written as {n}.
    'tabelle.suchen'          => 'Search',
    'tabelle.laenge'          => '_MENU_ entries',
    'tabelle.info'            => '_START_–_END_ of _TOTAL_',
    'tabelle.info_leer'       => 'No entries',
    'tabelle.info_gefiltert'  => '(filtered from _MAX_)',
    'tabelle.nichts_gefunden' => 'Nothing found.',
    'tabelle.erste'           => 'First',
    'tabelle.letzte'          => 'Last',
    'tabelle.weiter'          => 'Next',
    'tabelle.zurueck'         => 'Previous',
    'tabelle.sort_auf'        => ': sort ascending',
    'tabelle.sort_ab'         => ': sort descending',

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
    // The side route below the form. The page behind it existed in full
    // (passwort.vergessen.*) - only the way there did not.
    'anmelden.passwort_vergessen' => 'Forgot your password?',
    'anmelden.registrieren' => 'Sign up now',

    // What the sign-in form refuses (App\Controller\LoginController). The
    // lockout message also covers the second factor - same sign-in, same
    // brake.
    'anmelden.fehler.falsch'   => 'Wrong username or password.',
    'anmelden.fehler.gesperrt' => 'Too many failed attempts. Please wait {warten}.',

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

    // What can go wrong when resetting or changing a password
    // (App\Controller\PasswordController).
    'passwort.vergessen.antwort'  => 'If this email address is on file, you will receive a message about resetting your password.',
    'passwort.fehler.alt_falsch'  => 'The old password is not correct.',
    'passwort.fehler.paar'        => 'The passwords do not match or are too short.',
    'passwort.fehler.link'        => 'The link is invalid or has expired.',

    // -----------------------------------------------------------------
    // TWO-FACTOR SIGN-IN (App\Controller\TwoFactorController)
    // -----------------------------------------------------------------
    'zweifaktor.schon_aktiv'          => 'Two-factor sign-in is already switched on.',
    'zweifaktor.aktiviert'            => 'Two-factor sign-in is switched on.',
    'zweifaktor.deaktiviert'          => 'Two-factor sign-in is switched off.',
    'zweifaktor.zurueck'              => 'Back',
    'zweifaktor.zurueck_einstellungen'=> 'Back to the settings',
    'zweifaktor.zur_anmeldung'        => 'To signing in',

    'zweifaktor.einrichten.titel'  => 'Set up two-factor sign-in',
    'zweifaktor.einrichten.text'   => 'Scan the QR code with your authenticator app and enter the six-digit code it shows.',
    'zweifaktor.einrichten.qr_alt' => 'QR code for the authenticator app',
    'zweifaktor.einrichten.code'   => 'Code from the app',
    'zweifaktor.einrichten.knopf'  => 'Switch on',

    'zweifaktor.pruefen.titel' => 'Confirmation code',
    'zweifaktor.pruefen.text'  => 'The six-digit code from your authenticator app.',
    'zweifaktor.pruefen.code'  => 'Code',
    'zweifaktor.pruefen.knopf' => 'Sign in',

    'zweifaktor.fehler.titel'    => 'Sign-in not completed',
    'zweifaktor.fehler.code'     => 'Invalid code. Please try again.',
    'zweifaktor.fehler.qr'       => 'Please scan the QR code again.',
    'zweifaktor.fehler.login'    => 'Signing in with the second factor failed.',
    'zweifaktor.fehler.gesperrt' => 'Too many failed attempts. Please wait {warten} and then sign in again.',

    // -----------------------------------------------------------------
    // CONFIRMING THE EMAIL ADDRESS
    // (App\Controller\EmailVerificationController)
    // -----------------------------------------------------------------
    'mailbestaetigung.keine_mail'     => 'No new mail sent',
    'mailbestaetigung.gebremst'       => 'A confirmation mail has already been sent. Please also look in your spam folder. To send another one, please wait {warten}.',
    'mailbestaetigung.zur_startseite' => 'To the home page',

    'passwort.geaendert' => 'Password changed.',

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

    // The landing page (assets/html/landing.html). One sentence per
    // section - the pictures carry the rest. The alt texts describe what
    // is on the screenshot, because on this page they carry half of it.
    'landing.hero.titel'         => 'Somebody sets off for you.',
    'landing.hero.satz'          => 'You pick a place on the map, a person on site sends their picture – and the arrow keys tell them where to go.',
    'landing.hero.knopf'         => 'Create an account',
    'landing.hero.knopf_karte'   => 'See the map',
    'landing.hero.karte_hinweis' => 'The pins show possible places, not what is on offer right now – that is on the map inside the app.',

    'landing.ablauf.titel'         => 'Pick a place, ask for a tour, come along.',
    'landing.ablauf.alt_standort'  => 'A location page: the description, the guide behind it and the form for the request.',
    'landing.ablauf.alt_steuerung' => 'A running tour as the customer sees it: the guide\'s picture with the control pad on top.',

    'landing.steuerung.titel' => 'You press an arrow key. They walk.',
    'landing.steuerung.alt'   => 'The guide\'s view: a large arrow and the word for the direction over the camera picture.',

    'landing.guide.titel' => 'You know a place? Then show it.',
    'landing.guide.alt'   => 'A guide\'s request list with one open request to accept or decline.',
    'landing.guide.knopf' => 'Start as a guide',

    // The popup on a map pin (assets/js/home_map.js).
    'startseite.karte.eigen_bereit'      => 'You are available – for others this location is highlighted right now.',
    'startseite.karte.eigen_nicht_bereit'=> 'While you are not available this location is shown dimmed.',
    'startseite.karte.eigen_knopf'       => 'View and edit location',
    'startseite.karte.gesperrt'          => 'Blocked. The location is not visible to others.',
    'startseite.karte.gesperrt_grund'    => 'Blocked: {grund}. The location is not visible to others.',
    'startseite.karte.busy'              => 'The guide is on another tour right now.',
    'startseite.karte.idle'              => 'Nobody is on site right now. The location stays bookable as soon as the guide is available.',
    'startseite.karte.knopf'             => 'View location',
    'startseite.karte.knopf_live'        => 'View tour',

    'startseite.karte.guides' => [
        'one'   => '1 guide available',
        'other' => '{n} guides available',
    ],
    'startseite.karte.standorte' => [
        'one'   => '1 location',
        'other' => '{n} locations',
    ],

    // -----------------------------------------------------------------
    // THE LOCATION LIST
    // -----------------------------------------------------------------
    'standortliste.titel'      => 'All locations',
    'standortliste.untertitel' => 'The same locations as on the map, here to search and sort.',

    // The table itself (assets/js/locations_table.js). The state words differ
    // from standort.zustand.* on purpose: the column is narrow and answers a
    // narrower question.
    'standortliste.zustand.live' => 'Available',
    'standortliste.zustand.busy' => 'In a call',
    'standortliste.zustand.idle' => 'Not available',

    'standortliste.leer'                 => 'No locations yet.',
    'standortliste.fehler_laden'         => 'The data could not be loaded.',
    'standortliste.falsch_konfiguriert'  => 'This table is misconfigured.',
    'standortliste.loeschen_label'       => 'Delete location {ort}',
    'standortliste.loeschen_frage'       => 'Delete this location?',
    'standortliste.loeschen_text'        => 'The location disappears from the map and from every list. This cannot be undone.',
    'standortliste.geloescht'            => 'Location deleted.',
    'standortliste.loeschen_fehler'      => 'The location could not be deleted.',
    'standortliste.nicht_zugeordnet'     => 'The location could not be assigned.',
    'standortliste.ansehen'              => 'View',

    // -----------------------------------------------------------------
    // THE FORM WITH THE MAP (assets/js/map.js)
    // -----------------------------------------------------------------
    'standort.karte.select2_fehlt'    => 'The selection fields could not be loaded because a required library (select2) is missing. Please reload the page. If the problem persists, the cause is most likely your internet connection or an ad blocker.',
    'standort.karte.laender_leer'     => 'No countries could be loaded. Without a country there is no city search. Please contact the administrator – the country data is missing from the database.',
    'standort.karte.laender_fehler'   => 'The country list could not be loaded. Please reload the page. If the problem persists, the server is unreachable or the database is unavailable.',
    'standort.karte.punkt_fehlt'      => 'The point on the map is missing. Please click the map, choose a city or use “Use my current location” – only then can the location be saved.',
    'standort.karte.keine_ansicht'    => 'No map view is available for this country.',
    'standort.karte.land_zuerst'      => 'Please choose a country first',
    'standort.karte.keine_ortung'     => 'Your browser does not support location detection.',
    'standort.karte.ortung_fehler'    => 'Your location could not be determined: {grund}',
    'standort.karte.keine_stadt_am_ort' => 'no city at this location',

    // The select2 language block. The number comes from the same constant as
    // minimumInputLength (map.STADT_MIN_ZEICHEN).
    'stadtsuche.zu_kurz'          => 'Please enter at least {n} letters.',
    'stadtsuche.keine_stadt'      => 'No city found.',
    'stadtsuche.land_zuerst'      => 'Please choose a country first.',
    'stadtsuche.nicht_erreichbar' => 'The city search is unavailable right now. Please wait a moment and type again.',

    // -----------------------------------------------------------------
    // THE AVAILABILITY SWITCH (assets/js/availability.js)
    // -----------------------------------------------------------------
    'bereit.jetzt_anrufbar' => 'You can now be called as a guide – {rest}.',
    'bereit.beendet'        => 'Availability ended. Your locations can no longer be called.',
    'bereit.fehler'         => 'Your availability could not be changed. Please try again.',
    'bereit.abgelaufen'     => 'Your availability has expired – you can no longer be called. Set yourself to “Available” again to carry on.',
    'bereit.titel_an_rest'  => 'You can be called as a guide ({rest}). Clicking ends your availability.',

    'bereit.rest.aus'      => 'not available',
    'bereit.rest.sekunden' => '{n} s left',
    'bereit.rest.minuten'  => '{n} min left',
    'bereit.rest.stunden'  => '{std}:{min} h left',

    // -----------------------------------------------------------------
    // THE RUNNING TOUR (assets/js/tour.js, assets/js/requests.js)
    // -----------------------------------------------------------------
    'fuehrung.beenden'  => 'End tour',
    'fuehrung.beendet'  => 'Tour ended.',

    'fuehrung.karte.titel'            => 'Running tour',
    'fuehrung.karte.kunde_unbekannt'  => 'your customer',
    'fuehrung.karte.lead'             => 'Your tour with {kunde} has not been ended yet.',
    'fuehrung.karte.lead_titel'       => 'Your tour with {kunde} – {titel} – has not been ended yet.',
    'fuehrung.karte.hinweis'          => 'Hanging up is not ending: while the tour is open, you and your customer can rejoin.',
    'fuehrung.karte.hinweis_frist'    => 'Hanging up is not ending: while the tour is open, you and your customer can rejoin – {rest}.',
    'fuehrung.karte.spaeter'          => 'Later',
    'fuehrung.karte.fuss'             => 'Only after ending is the tour complete. Your customer can no longer restart it and will be asked for a rating.',

    'fuehrung.beenden_frage.titel' => 'End the tour?',
    'fuehrung.beenden_frage.text'  => 'After that the tour is complete: neither you nor your customer can rejoin, the start button disappears, and your customer will be asked for a rating. This cannot be undone.',
    'fuehrung.beenden_frage.knopf' => 'End',

    'fuehrung.rest.unter_minute' => 'less than a minute left',
    'fuehrung.rest.minuten' => [
        'one'   => 'about a minute left',
        'other' => 'about {n} minutes left',
    ],
    'fuehrung.rest.stunden' => [
        'one'   => 'about an hour left',
        'other' => 'about {n} hours left',
    ],

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

    // The chat windows in the browser (assets/js/chat.js, ui_chat.js,
    // chat_badge.js).
    'chat.neue_nachricht'      => 'New message.',
    'chat.fehler.zu_lang'      => 'Message not sent – it is too long.',
    'chat.fehler.uebertragung' => 'Message not sent – transmission error.',
    'chat.fehler.start'        => 'The chat could not be started.',
    'chat.fehler.weg'          => 'This chat no longer exists.',

    // The state of a row in the chat list.
    'chat.zustand.aktiv'   => 'Active',
    'chat.zustand.beendet' => 'Ended',

    // THE ROW'S ACTION IS TEXT, not a clock icon: it is the only one in the
    // row, and a lone icon does not explain itself.
    'chat.verlauf.oeffnen' => 'Open history',
    'chat.verlauf.von'     => 'Open the history with {name}',

    'chat.partner_unbekannt' => 'Unknown',
    'chat.partner_nummer'    => 'Account {n}',

    // What the chat routes refuse.
    'chat.fehler.ungueltig'         => 'Invalid request.',
    'chat.fehler.nicht_angemeldet'  => 'Not signed in.',
    'chat.fehler.kein_zugriff'      => 'No access.',
    'chat.fehler.nicht_gefunden'    => 'Chat not found.',
    'chat.fehler.nicht_erstellt'    => 'The chat could not be created.',
    'chat.fehler.zum_standort'      => 'No chat is possible for this location.',
    'chat.fehler.eigener_standort'  => 'That is your own location.',
    'chat.fehler.selbst'            => 'Nobody chats with themselves.',
    'chat.fehler.konto_weg'         => 'This account no longer exists.',
    'chat.fehler.konto_weg_verlauf' => 'This account no longer exists. The history stays.',
    'chat.fehler.zu_viele_chats'       => 'Too many chats in a short time. Please wait {warten}.',
    'chat.fehler.zu_viele_nachrichten' => 'Too many messages in a short time. Please wait {warten}.',

    // Files in the in-call chat (assets/js/chat.js). The file name is the
    // browser's suggestion in the save dialogue.
    'chat.datei.gesendet'      => 'File sent: {name}',
    'chat.datei.herunterladen' => 'Download file',
    'chat.datei.name'          => 'received_file',

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
    'gespraech.anruf.medien_noetig' => 'Please select at least audio or video to accept the call.',

    // -----------------------------------------------------------------
    // WHAT COMES UP DURING A CALL (assets/js/rtc.js, control.js, media.js,
    // signaling.js)
    // -----------------------------------------------------------------
    'gespraech.anruf_mit'      => 'Call with {name}',
    'gespraech.kein_anruf_hier'=> 'Calling is not available on this page.',
    'gespraech.tippen'         => 'Please tap the picture once so that audio and video start.',
    'gespraech.freigeben'      => 'Unlock controls',

    'gespraech.fehler.aufbau_grund'      => 'The call could not be set up: {grund}',
    'gespraech.fehler.nicht_zugestellt'  => 'The call could not be delivered. Please try again later.',
    'gespraech.fehler.anruf_weg'         => 'The call is gone.',
    'gespraech.fehler.nicht_angenommen'  => 'The call was not answered.',
    'gespraech.fehler.verbindung'        => 'The connection could not be established.',
    'gespraech.fehler.verbindung_grund'  => 'The connection could not be established: {grund}',
    'gespraech.fehler.gegenseite_beendet'=> 'The other participant ended the connection.',
    'gespraech.fehler.partner_beendet'   => 'The connection to the other participant was ended.',
    'gespraech.fehler.kein_wiederaufbau' => 'The connection to the other participant could not be restored.',
    'gespraech.fehler.start_keine_medien'=> 'The call could not be started: the other side selected neither audio nor video.',
    'gespraech.fehler.start_verbindung'  => 'The call could not be started: the connection could not be established.',

    'gespraech.fehler.mikro_an'         => 'The microphone could not be switched on: {grund}',
    'gespraech.fehler.mikro_aus'        => 'The microphone could not be muted: {grund}',
    'gespraech.fehler.kamera_an'        => 'The camera could not be switched on: {grund}',
    'gespraech.fehler.kamera_aus'       => 'The camera could not be switched off: {grund}',
    'gespraech.fehler.geraet_wechsel'   => 'The device could not be taken over: {grund}',
    'gespraech.fehler.kein_mikrokanal'  => 'No microphone channel has been negotiated.',
    'gespraech.fehler.kein_kamerakanal' => 'No channel for the camera was negotiated when the connection was set up.',
    'gespraech.fehler.kein_geraetekanal'=> 'No channel has been negotiated for this device.',

    'gespraech.hinweis.ohne_eigenen_ton' => 'The call continues without your own audio; the chat stays usable.',

    // The refusals from getUserMedia - ONE WHOLE SENTENCE PER DEVICE.
    'gespraech.medien.abgelehnt_kamera' => 'Access to the camera was denied. Please allow it in your browser settings and try again.',
    'gespraech.medien.abgelehnt_mikro'  => 'Access to the microphone was denied. Please allow it in your browser settings and try again.',
    'gespraech.medien.fehlt_kamera'     => 'No camera was found. Without a camera no picture can be transmitted.',
    'gespraech.medien.fehlt_mikro'      => 'No microphone was found. Without a microphone no conversation is possible.',
    'gespraech.medien.belegt_kamera'    => 'The camera cannot be opened. Another program is probably using it.',
    'gespraech.medien.belegt_mikro'     => 'The microphone cannot be opened. Another program is probably using it.',
    'gespraech.medien.fehler_kamera'    => 'The camera could not be used: {grund}',
    'gespraech.medien.fehler_mikro'     => 'The microphone could not be used: {grund}',
    'gespraech.medien.ohne_bild'        => 'The call continues without video.',
    'gespraech.medien.ohne_ton'         => 'The call continues without audio; the chat stays usable.',

    // The device list in the call dialogue.
    'gespraech.geraet.keine_freigabe'      => 'The device cannot be selected yet. Please allow access to camera and microphone and open the device list again.',
    'gespraech.geraet.kamera_aus_hinweis'  => 'The camera is off. Your choice applies as soon as you switch it on.',
    'gespraech.geraet.mikro_stumm_hinweis' => 'The microphone is muted. Your choice applies as soon as you switch it on.',
    'gespraech.geraet.keine_kamera'        => 'No camera found',
    'gespraech.geraet.kein_mikrofon'       => 'No microphone found',
    'gespraech.geraet.kamera_nr'           => 'Camera {n}',
    'gespraech.geraet.mikrofon_nr'         => 'Microphone {n}',

    'gespraech.mikrofon_stumm'  => 'Mute microphone',
    'gespraech.mikrofon_an'     => 'Unmute microphone',
    'gespraech.kamera_aus_titel'=> 'Switch camera off',
    'gespraech.kamera_an'       => 'Switch camera on',

    // The ICE servers.
    'gespraech.ice.keine_daten'     => 'The connection data could not be loaded.',
    'gespraech.ice.hinweis'         => 'Note: {text}',
    'gespraech.ice.kein_turn'       => 'Note: no TURN server is available. The call only works if both sides are on simple networks.',
    'gespraech.ice.kein_turn_grund' => 'Note: no TURN server is available. The call only works if both sides are on simple networks. ({grund})',

    // The visible connection state.
    'gespraech.zustand.aufbau'           => 'Connecting',
    'gespraech.zustand.verbunden'        => 'Connected',
    'gespraech.zustand.instabil'         => 'Connection unstable',
    'gespraech.zustand.wieder'           => 'Reconnecting …',
    'gespraech.zustand.getrennt'         => 'Disconnected',
    'gespraech.zustand.wiederhergestellt'=> 'Connection restored.',

    // THE DIRECTION INDICATOR shown to the guide. Upper case on purpose: it
    // fills the screen and is read at a glance while walking.
    'gespraech.richtung.forward'   => 'FORWARD',
    'gespraech.richtung.backward'  => 'BACK',
    'gespraech.richtung.left'      => 'LEFT',
    'gespraech.richtung.right'     => 'RIGHT',
    'gespraech.richtung.look_up'   => 'LOOK UP',
    'gespraech.richtung.look_down' => 'LOOK DOWN',

    // The controls.
    'gespraech.steuerung.nicht_stabil'        => 'Control command not sent – the connection is not stable right now.',
    'gespraech.steuerung.uebertragung'        => 'Control command not sent – transmission error.',
    'gespraech.steuerung.verworfen'           => 'Control command discarded – the connection had dropped.',
    'gespraech.steuerung.keine_bestaetigung'  => 'No confirmation received for the control command.',
    'gespraech.steuerung.sperre_fehler'       => 'The lock could not be transmitted.',
    'gespraech.steuerung.gesperrt'            => 'The guide has locked the controls.',
    'gespraech.steuerung.gesperrt_grund'      => 'The guide has locked the controls. ({grund})',
    'gespraech.steuerung.freigegeben'         => 'The guide has unlocked the controls again.',

    // The rejection reasons from the protocol - EACH CARRIES THE WHOLE
    // MESSAGE.
    'gespraech.abgelehnt.unstable'  => 'Control command rejected – the connection was not stable.',
    'gespraech.abgelehnt.locked'    => 'Control command rejected – the guide has locked the controls.',
    'gespraech.abgelehnt.duplicate' => 'Control command rejected – the command was a repeat.',
    'gespraech.abgelehnt.no_role'   => 'Control command rejected – the other side does not know its role.',
    'gespraech.abgelehnt.invalid'   => 'Control command rejected – the command was invalid.',
    'gespraech.abgelehnt.unbekannt' => 'Control command rejected – the reason is unknown.',

    // What the server says about a call (App\Controller\WebRTCController,
    // App\Controller\TurnController).
    'gespraech.fehler.signaling'         => 'Invalid signalling request.',
    'gespraech.fehler.signaling_intern'  => 'The call could not be relayed.',
    'gespraech.fehler.guide_nicht_bereit'=> 'This guide is not available for a tour right now. Request the tour on the location page – with a time that suits you both.',
    'gespraech.fehler.kein_guide'        => 'This user does not offer tours and therefore cannot be called.',

    'gespraech.ice.gebremst'           => 'Too many connection attempts in a short time. The call is being set up without a relay server.',
    'gespraech.ice.keine_zugangsdaten' => 'The TURN service returned no usable credentials.',
    'gespraech.ice.nicht_erreichbar'   => 'The TURN server cannot be reached right now.',

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
