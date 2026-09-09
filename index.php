<?php
// ---------------------------------------------------------------------------
// DIE REIHENFOLGE DIESER VIER ZEILEN IST EINE AUSSAGE.
//
// Die Sitzung wird ZULETZT gestartet, nicht mehr an zweiter Stelle. Ihr
// Cookie traegt das Merkmal "secure", und ob das gesetzt werden darf, haengt
// an FORCE_HTTPS - einem Wert aus der .env. Solange die Sitzung vor
// config/env.php stand, gab es diesen Wert zum Zeitpunkt der Entscheidung
// noch nicht; das Merkmal war deshalb fest verdrahtet und sperrte jede
// lokale Entwicklung ueber http:// aus (das Cookie wurde nie zurueckgeschickt,
// also war niemand angemeldet).
//
// Die Fehlerbehandlung bleibt an erster Stelle: Was danach schiefgeht, soll
// im Log stehen und nicht im Browser. Sie kommt ohne .env aus - ihr Logpfad
// wird auf Server- oder Systemebene gesetzt (siehe config/log_path.php).
// ---------------------------------------------------------------------------
// Fehlerbehandlung aktivieren
require_once __DIR__ . '/config/error_handler.php';
// Autoloader für Composer-Pakete laden
require_once __DIR__ . '/vendor/autoload.php';
// Umgebungsvariablen laden
require_once __DIR__ . '/config/env.php';
// Startet die Session-Verwaltung - nach der Konfiguration, siehe oben
require_once __DIR__ . '/config/session.php';

use App\Helper\Auth;
use App\Helper\Https;
use App\Helper\I18n;
use App\Helper\MailGate;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\SecurityHeaders;
use App\Model\PdoConnect;
use App\Model\User;

// ---------------------------------------------------------------------------
// HTTPS UND DIE SICHERHEITSKOPFZEILEN. Beides ganz am Anfang, und in dieser
// Reihenfolge.
//
// Die Weiterleitung zuerst: Eine Anfrage, die ohnehin nur umgeleitet wird,
// soll nichts weiter tun - keine Kopfzeilen bauen und, ein paar Zeilen
// weiter unten, keine Datenbankverbindung oeffnen.
//
// Die Kopfzeilen danach, aber VOR JEDER AUSGABE: Was einmal geschrieben ist,
// laesst sich mit header() nicht mehr ergaenzen. Weil index.php der einzige
// Einstieg ist, traegt damit jede Antwort dieselben Kopfzeilen - auch die
// Fehlerseiten aus deny() weiter unten.
//
// Beides haengt an Schaltern in der .env (FORCE_HTTPS, HSTS_MAX_AGE,
// CSP_MODE); die Vorgaben sind so gewaehlt, dass eine bestehende
// Installation nichts merkt. Die Begruendungen stehen in den beiden Klassen.
// ---------------------------------------------------------------------------
Https::erzwingen();
SecurityHeaders::senden();

// Routen-Konfiguration laden
$routes = require __DIR__ . '/config/routes.php';

// ---------------------------------------------------------------------------
// DIE DATENBANKVERBINDUNG. Ab hier darf JEDE Zeile sie benutzen - das ist der
// Sinn dieser Stelle, und sie hat einen Ausfall als Anlass.
//
// Vorher entstand die Verbindung weiter unten, als Nebenwirkung eines
// Konstruktors, dessen Ergebnis niemand benutzt: "$pdo_instance = new
// PdoConnect();". Eine solche Zeile sieht verschiebbar aus. Als darueber eine
// Pruefung dazukam, die die Datenbank braucht (Auth::discardOutdatedSession
// fragt seit dem Filter auf geloeschte Konten nach dem Konto der Sitzung),
// endete jede Seite mit "Call to a member function prepare() on null".
//
// SIE STEHT NACH DER HTTPS-WEITERLEITUNG und nicht davor: Eine Anfrage, die
// nur umgeleitet wird, soll keine Verbindung oeffnen. Alles andere kommt
// danach - auch die Pruefung der Routentabelle, die selbst keine braucht.
// ---------------------------------------------------------------------------
PdoConnect::sicherstellen();

/**
 * Bricht mit einer Meldung ab, ohne interne Details preiszugeben.
 *
 * @param int    $status HTTP-Statuscode
 * @param string $kind   'json' oder 'html' - siehe config/routes.php
 * @param string $msg    Meldung für den Aufrufer
 * @return never
 */
function deny(int $status, string $kind, string $msg)
{
    http_response_code($status);
    if ($kind === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Kein Zugriff</title>'
           . '<p>' . htmlspecialchars($msg) . '</p>'
           . '<p><a href="index.php?act=home">Zur Startseite</a></p>';
    }
    exit;
}

// ---------------------------------------------------------------------------
// Die Routing-Tabelle muss vollständig sein.
//
// Geprüft wird die GESAMTE Tabelle, nicht nur der angeforderte Eintrag: Eine
// Route ohne eingetragenes Recht oder mit einem Rechtenamen, den keine Rolle
// kennt, ist ein Konfigurationsfehler. Sie wird nicht "vorsichtshalber
// gesperrt" und schon gar nicht durchgelassen - die Anwendung antwortet gar
// nicht mehr, bis der Eintrag stimmt. Sonst könnte eine vergessene
// Rechteangabe unbemerkt eine offene Route hinterlassen.
// ---------------------------------------------------------------------------
$route_errors = Permission::routeErrors($routes);
if ($route_errors !== []) {
    foreach ($route_errors as $route_error) {
        error_log('Routing-Konfiguration fehlerhaft: ' . $route_error);
    }
    deny(500, 'html', 'Die Anwendung ist fehlerhaft konfiguriert. Bitte den Betreiber informieren.');
}

// Sitzungen aus einer Version mit anderen Rollennummern gelten nicht mehr -
// und Sitzungen geloeschter Konten auch nicht. Das FRAGT DIE DATENBANK und
// steht deshalb hinter PdoConnect::sicherstellen() weiter oben.
Auth::discardOutdatedSession();

// ---------------------------------------------------------------------------
// DIE SPRACHE DIESER ANTWORT. Genau eine Stelle, und sie liegt hier.
//
// SIE STEHT HINTER discardOutdatedSession(): Erst dort steht fest, ob die
// Sitzung ueberhaupt noch gilt. Eine Sprache aus dem Konto einer verworfenen
// Sitzung waere die Sprache eines Abgemeldeten.
//
// SIE STEHT VOR DEM CONTROLLER, weil der bereits Text baut - und vor jeder
// Ausgabe, weil start() eine Vary-Kopfzeile schickt (siehe App\Helper\I18n).
//
// Die Kontospalte kostet eine Abfrage auf EINE Spalte und nur, wenn jemand
// angemeldet ist; User::lang() laedt bewusst nicht den ganzen Datensatz.
// ---------------------------------------------------------------------------
I18n::start(Auth::isLoggedIn() ? User::lang(Auth::userId()) : null);

// Liest den 'act'-Parameter aus der Request (GET/POST) aus
$act = Request::g('act');

// Validiert den 'act'-Parameter: Muss ein String sein und darf nur Buchstaben, Zahlen und Unterstrich enthalten
if (!is_string($act) || !preg_match('/^[a-zA-Z0-9_]+$/', $act)) {
    header("Location: index.php?act=home");
    exit;
}

// Falls 'act' leer ist, auf Startseite umleiten
if (empty($act)) {
    header("Location: index.php?act=home");
    exit;
}

// Keine Route gefunden: 404 Fehler
if (!isset($routes[$act])) {
    header("HTTP/1.1 404 Not Found");
    die('Unbekannte Aktion');
}

[$class, $method, $right, $kind] = $routes[$act];

// ---------------------------------------------------------------------------
// Rechteprüfung. Sie steht VOR dem Controller und ist die einzige Stelle, an
// der über den Zugang zu einer Route entschieden wird.
//
// Wer nicht angemeldet ist, hat die Rechte der Rolle "Gast" - auch das ist
// eine Rolle mit einer ausgeschriebenen Liste (App\Helper\Permission), kein
// Sonderfall im Ablauf.
// ---------------------------------------------------------------------------
if (!Auth::can($right)) {
    error_log(sprintf(
        'Zugriff abgewiesen: act=%s, benoetigtes Recht=%s, Rolle=%s, UserID=%d',
        $act,
        $right,
        var_export(Auth::roleKey(), true),
        Auth::userId()
    ));

    if (!Auth::isLoggedIn()) {
        // Nicht angemeldet: Seiten führen zum Login, Schnittstellen antworten
        // mit 401. Eine Weiterleitung auf eine AJAX-Anfrage kam vorher als
        // HTML im JSON-Parser des Clients an.
        if ($kind === 'json') {
            deny(401, 'json', 'Nicht angemeldet.');
        }
        header('Location: index.php?act=login_page');
        exit;
    }

    deny(403, $kind, 'Für diese Aktion fehlt Ihnen die Berechtigung.');
}

// ---------------------------------------------------------------------------
// Die Bestaetigungspflicht (MAIL_VERIFY_REQUIRED, siehe App\Helper\MailGate).
//
// SIE STEHT HINTER DER RECHTEPRUEFUNG und nicht davor: Die Rechtefrage lautet
// "darf diese ROLLE das ueberhaupt", diese hier "ist dieses KONTO so weit".
// Wer das Recht gar nicht hat, soll die zweite Antwort nicht bekommen - sonst
// erfuehre ein Aufrufer aus der Fehlermeldung, dass es die Route gibt und was
// ihm zu ihr noch fehlt.
//
// SIE STEHT UEBERHAUPT HIER und nicht in den sechs betroffenen Controllern,
// aus demselben Grund wie die Rechtepruefung darueber: Eine Entscheidung ueber
// den Zugang gehoert an EINE Stelle. Verteilt auf sechs Methoden waere sie
// beim siebten Endpunkt vergessen.
//
// Ist der Schalter aus - die Vorgabe -, kostet das eine Feldabfrage und sonst
// nichts; die Datenbank wird nur auf den Routen der Liste gefragt.
// ---------------------------------------------------------------------------
if (MailGate::sperrt($act)) {
    error_log(sprintf(
        'Zugriff abgewiesen (E-Mail nicht bestaetigt): act=%s, UserID=%d',
        $act,
        Auth::userId()
    ));
    deny(403, $kind, MailGate::hinweis());
}

// Routing: Controller erzeugen und Methode ausführen
$controller = new $class();
$controller->$method();
