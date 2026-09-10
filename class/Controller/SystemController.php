<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\I18n;
use App\Helper\Request;
use App\Helper\ViewHelper;
use App\Model\User;

/**
 * SystemController – übernimmt alle ehemals globalen Systemfunktionen außer output.
 */
class SystemController
{
    // HIER STAND showAdmin(). Die Methode gab eine einzige Zeile Text aus -
    // "Willkommen im Admin Panel" - und hing an der Route 'admin', auf die
    // nichts verwies. Die Route gibt es weiter, sie fuehrt jetzt auf die
    // Uebersicht des Verwaltungsbereichs
    // (App\Controller\AdminController::showDashboard).

    /**
     * Generiert ein HTML-Dropdown-Menü (Select-Optionen) basierend auf einem Array.
     * @param array $dataArray Array für Optionen (Key => Value)
     * @param mixed $selectedId Vorzubelegende ID
     * @param bool $addEmpty Optionale Leerauswahl hinzufügen
     * @return string HTML-Optionen
     */
    public static function generateHtmlOptions($dataArray, $selectedId, $addEmpty = false): string
    {
        $outOpt = "";

        if ($addEmpty) {
            $outOpt .= '<option value="0">'
                     . ViewHelper::esc(I18n::t('allgemein.keine_auswahl')) . '</option>';
        }

        foreach ($dataArray as $key => $val) {
            $sel = ((string)$key === (string)$selectedId) ? "selected" : "";
            $outOpt .= '<option value="' . htmlspecialchars($key) . '" ' . $sel . '>' . htmlspecialchars($val) . ' </option>';
        }
        return $outOpt;
    }

    /**
     * Gibt die Startseite aus.
     *
     * Der Einstieg ist die Karte (assets/html/home.html), nicht eine Seite mit
     * Knoepfen: Das Produkt ist eine Fuehrung an einem Ort, also faengt es bei
     * einem Ort an. Gefuellt wird die Karte im Browser von
     * assets/js/home_map.js ueber die Route get_locations - dieselbe Quelle
     * wie die Tabellenansicht.
     *
     * Die Seite ist bewusst auch fuer Gaeste erreichbar (Recht system.home).
     * Die Standortliste bekommen sie nicht - dafuer fehlt ihnen location.list -
     * , sondern die Erklaerflaeche der Vorlage.
     *
     * @return void
     */
    public static function home(): void
    {
        $out = ViewHelper::template('assets/html/home.html');
        ViewHelper::output($out);
    }

    /**
     * Gibt die Landingpage aus.
     *
     * WAS SIE VON DER STARTSEITE UNTERSCHEIDET
     * ----------------------------------------
     * Die Startseite (home() darueber) zeigt den BESTAND: Auf ihrer Karte
     * steht, was gerade wirklich angeboten wird, und wenn dort nichts ist,
     * sagt sie das auch. Diese Seite hier zeigt die MOEGLICHKEIT - ihre
     * Nadeln stehen fuer Orte, an denen es eine Fuehrung geben koennte, und
     * nicht fuer Standorte in der Datenbank.
     *
     * Das ist der Grund, aus dem sie eine eigene Seite ist und nicht ein
     * weiterer Zustand der Startseite: Zwei Karten, von denen die eine den
     * Bestand und die andere ein Versprechen zeigt, duerfen nicht dieselbe
     * Karte sein. Auf der Startseite waere eine erfundene Nadel eine
     * Falschauskunft; hier ist sie das Bild zu einem Satz.
     *
     * SIE FRAGT NICHTS AB. Kein Standort, kein Guide, keine Zahl - die Seite
     * kommt ohne eine einzige Datenbankabfrage aus. Ihre Nadeln stehen in
     * assets/js/landing.js.
     *
     * DAS RECHT IST system.home, und zwar dasselbe wie bei der Startseite:
     * Das Recht heisst "Startseite und oeffentliche Einstiegsseiten" (siehe
     * App\Helper\Permission), und genau das ist diese Seite. Ein eigenes
     * Recht "landing.view" waere eine Unterscheidung ohne Unterschied - es
     * haette dieselben Rollen wie system.home, und zwar fuer immer.
     *
     * @return void
     */
    public static function landing(): void
    {
        $out = ViewHelper::template('assets/html/landing.html');
        ViewHelper::output($out);
    }

    /**
     * Gibt eine der drei Pflichtseiten aus: Impressum, Datenschutz, Kontakt.
     *
     * EINE METHODE FUER DREI ROUTEN. Die Seiten unterscheiden sich heute in
     * einem Wort - der Ueberschrift -, und drei Methoden mit identischem
     * Rumpf waeren drei Stellen, an denen dieselbe Aenderung nachzuziehen
     * waere. Drei ROUTEN sind es trotzdem: Die Adressen stehen in der
     * Fusszeile und werden weitergegeben; sie duerfen nicht davon abhaengen,
     * dass jemand einen Parameter richtig mitschickt.
     *
     * DER SCHLUESSEL KOMMT AUS DER ROUTINGTABELLE UND NICHT AUS DER ANFRAGE.
     * Das ist der Punkt: Wuerde die Methode ihn aus $_GET lesen, koennte
     * jeder Aufrufer einen beliebigen Katalogschluessel als Ueberschrift
     * setzen lassen. So sind es genau die drei Werte, die in
     * config/routes.php stehen.
     *
     * @param string $in_schluessel Katalogschluessel der Ueberschrift
     * @return void
     */
    public static function legalPage(string $in_schluessel): void
    {
        $out = ViewHelper::template('assets/html/legal.html');
        $out = str_replace('###LEGAL_TITLE###',
                           ViewHelper::esc(I18n::t($in_schluessel)), $out);

        ViewHelper::output($out);
    }

    /** Das Impressum. Siehe legalPage(). */
    public static function imprint(): void
    {
        self::legalPage('fuss.impressum');
    }

    /** Die Datenschutzerklaerung. Siehe legalPage(). */
    public static function privacy(): void
    {
        self::legalPage('fuss.datenschutz');
    }

    /** Die Kontaktseite. Siehe legalPage(). */
    public static function contact(): void
    {
        self::legalPage('fuss.kontakt');
    }

    /**
     * Stellt die Sprache der Oberflaeche um.
     *
     * ZWEI SPEICHERORTE, UND BEIDE WERDEN BESCHRIEBEN:
     *
     *   Das Cookie   immer. Es gilt in diesem Browser und ueberlebt das
     *                Abmelden - sonst bekaeme jemand, der seine Sprache im
     *                Konto umstellt und sich abmeldet, das Anmeldeformular
     *                wieder in der alten Sprache und muesste die Wahl ein
     *                zweites Mal treffen, um sich anmelden zu koennen.
     *   Das Konto    nur angemeldet. Es gewinnt beim naechsten Aufruf
     *                (App\Helper\I18n) und folgt dem Nutzer auf jedes Geraet.
     *
     * WARUM EINE WEITERLEITUNG UND KEINE JSON-ANTWORT
     * -----------------------------------------------
     * Anders als beim Farbprofil kann der Browser hier nichts sofort
     * umstellen: Den Text hat der SERVER gesetzt, und er steht bereits
     * fertig in der Seite. Es muss also ohnehin neu geladen werden - dann
     * kann es auch gleich eine gewoehnliche Weiterleitung sein, die ohne
     * JavaScript funktioniert.
     *
     * WOHIN ES ZURUECKGEHT
     * --------------------
     * Auf die Seite, von der der Umschalter kam. Die Adresse dafuer kommt
     * als "back" aus der Anfrage - also von aussen, und damit ist sie
     * Fremdeingabe. Sie wird deshalb nicht uebernommen, sondern zerlegt:
     * uebrig bleiben "act" und eine numerische "id", der Rest faellt weg.
     *
     * Das ist strenger als noetig und mit Absicht so: Eine Weiterleitung,
     * die einen Aufrufer irgendwohin bringt, ist die klassische offene
     * Weiterleitung - und sie faellt niemandem auf, weil sie ja
     * funktioniert. Aus zwei geprueften Werten laesst sich keine fremde
     * Adresse bauen, auch nicht mit Zeilenumbruechen im Header und auch
     * nicht mit "//example.org" als Ziel.
     *
     * @return void
     */
    public function setLanguage(): void
    {
        $sprache = I18n::normalize(Request::g('lang', ''));

        // Beides nur, wenn wirklich eine bekannte Sprache kam: normalize()
        // liefert sonst die Vorgabe, und die duerfte eine bestehende Wahl
        // nicht ueberschreiben, bloss weil jemand "?lang=xx" aufgerufen hat.
        if (I18n::isValid(Request::g('lang', ''))) {
            I18n::cookieSetzen($sprache);

            if (Auth::isLoggedIn()) {
                try {
                    $user = new User(Auth::userId());
                    $user->saveLang($sprache);
                } catch (\Exception $e) {
                    // Das Cookie steht bereits - die Sprache gilt also in
                    // diesem Browser, auch wenn das Konto sie nicht behalten
                    // konnte. Der Grund steht im Log.
                    error_log('Sprache konnte nicht am Konto gespeichert werden: '
                        . $e->getMessage());
                }
            }
        }

        header('Location: ' . self::rueckweg(Request::g('back', '')));
        exit;
    }

    /**
     * Baut aus dem "back" der Anfrage eine Adresse innerhalb der Anwendung.
     *
     * Uebernommen werden genau zwei Werte:
     *
     *   act   der Aktionsname. Geprueft gegen dasselbe Muster wie in
     *         index.php - Buchstaben, Ziffern, Unterstrich.
     *   id    eine Zahl. Sie ist der einzige weitere Wert, ohne den eine
     *         Rueckkehr ins Leere fuehrte: Standortseite, Guide-Profil und
     *         Chat haengen daran.
     *
     * Alles andere faellt weg - auch ein zweites "lang", das den Wechsel
     * sofort wieder rueckgaengig machte.
     *
     * Oeffentlich, damit der Test dieselbe Stelle prueft, die im Betrieb
     * laeuft.
     *
     * @param string $in_back Roher Wert aus der Anfrage (eine Query-Zeichenkette)
     * @return string Immer eine relative Adresse, beginnend mit "index.php?act="
     */
    public static function rueckweg(string $in_back): string
    {
        $ziel = [];
        parse_str($in_back, $ziel);

        $act = isset($ziel['act']) && is_string($ziel['act']) ? $ziel['act'] : '';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $act) || $act === 'set_lang') {
            // Kein brauchbares Ziel - oder der Umschalter selbst, und der
            // wuerde in einer Schleife enden.
            return 'index.php?act=home';
        }

        $out = 'index.php?act=' . $act;

        if (isset($ziel['id']) && is_string($ziel['id']) && ctype_digit($ziel['id'])) {
            $out .= '&id=' . $ziel['id'];
        }

        return $out;
    }

}
