<?php
// Fehlerbehandlung aktivieren
require_once __DIR__ . '/config/error_handler.php';
// Startet die Session-Verwaltung
require_once __DIR__ . '/config/session.php';
// Autoloader für Composer-Pakete laden
require_once __DIR__ . '/vendor/autoload.php';
// Umgebungsvariablen laden
require_once __DIR__ . '/config/env.php';

use App\Helper\Request;
use App\Model\PdoConnect;

// Routen-Konfiguration laden
$routes = require_once __DIR__ . '/config/routes.php';

// HTTPS erzwingen: Weiterleitung auf HTTPS, falls nicht aktiv
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $httpsUrl, true, 301);
    exit;
}

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

// Routing: Prüft, ob für die Aktion ein Controller und eine Methode definiert sind
if (isset($routes[$act])) {
    [$class, $method] = $routes[$act]; // Zerlegt das Array in Controllerklasse und Methode
    $controller = new $class();        // Erstellt eine Instanz der Controllerklasse
    $controller->$method();            // Führt die Methode aus
} else {
    // Keine Route gefunden: 404 Fehler
    header("HTTP/1.1 404 Not Found");
    die('Unbekannte Aktion');
}
