<?php
// Einbindung notwendiger Klassen und Systeme
require_once __DIR__ . '/vendor/autoload.php';
use App\Helper\Request;
use App\Model\PdoConnect;

$routes = require_once __DIR__ . '/config/routes.php';

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $httpsUrl, true, 301);
    exit;
}
// Erstellen einer Instanz der PDO-Verbindung zur Datenbank
$pdo_instance = new PdoConnect();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start der PHP-Session für Benutzerdaten
session_start();
ini_set('display_errors', 0); // Fehleranzeige deaktivieren
ini_set('log_errors', 1); // Fehlerprotokollierung aktivieren
ini_set('error_log', 'php-error.log'); // Log-Datei

$act = Request::g('act');

if (empty($act)) {
    header("Location: index.php?act=home");
    exit;
}

// Überprüfen, ob ein Aktionsparameter (act) in der URL oder Anfrage vorhanden ist
if (isset($routes[$act])) {
    [$class, $method] = $routes[$act];
    $controller = new $class();
    $controller->$method();
} else {
    header("HTTP/1.1 404 Not Found");
    die('Unbekannte Aktion');
}
//var_dump(Request::g('act'));
//file_put_contents('getUsername_debug.txt', date('c').' data:'.Request::g('act').PHP_EOL, FILE_APPEND);