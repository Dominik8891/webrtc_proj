<?php
// Fehlerbehandlung aktivieren
require_once __DIR__ . '/config/error_handler.php';
// Startet die Session-Verwaltung
require_once __DIR__ . '/config/session.php';
// Autoloader für Composer-Pakete laden
require_once __DIR__ . '/vendor/autoload.php';
// Umgebungsvariablen laden
require_once __DIR__ . '/config/env.php';

use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Request;
use App\Model\PdoConnect;

// Routen-Konfiguration laden
$routes = require __DIR__ . '/config/routes.php';

// HTTPS erzwingen: Weiterleitung auf HTTPS, falls nicht aktiv
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $httpsUrl, true, 301);
    exit;
}

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

// Sitzungen aus einer Version mit anderen Rollennummern gelten nicht mehr.
Auth::discardOutdatedSession();

// Erstellt eine neue Instanz für die Datenbankverbindung (wird ggf. von Controllern verwendet)
$pdo_instance = new PdoConnect();

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

// Routing: Controller erzeugen und Methode ausführen
$controller = new $class();
$controller->$method();
