<?php
require_once __DIR__ . '/config/error_handler.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/env.php';

use App\Helper\Request;
use App\Model\PdoConnect;

$routes = require_once __DIR__ . '/config/routes.php';

// HTTPS erzwingen
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $httpsUrl, true, 301);
    exit;
}

// DB-Connection wird durch Controller genutzt, falls nötig
$pdo_instance = new PdoConnect();

$act = Request::g('act');
if (!is_string($act) || !preg_match('/^[a-zA-Z0-9_]+$/', $act)) {
    header("Location: index.php?act=home");
    exit;
}
if (empty($act)) {
    header("Location: index.php?act=home");
    exit;
}

if (isset($routes[$act])) {
    [$class, $method] = $routes[$act];
    $controller = new $class();
    $controller->$method();
} else {
    header("HTTP/1.1 404 Not Found");
    die('Unbekannte Aktion');
}
