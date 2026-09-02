<?php
// Lade PDO und Composer-Autoloader
require_once __DIR__  . '/../class/Model/PdoConnect.php'; 
// Autoloader für Composer-Pakete laden
require __DIR__ . '/../vendor/autoload.php';
// Umgebungsvariablen laden
require_once __DIR__ . '/../config/env.php';

// Fehlerbehandlung: Fehler werden ins Log geschrieben, aber nicht ausgegeben
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Logpfad wie im Web-Einstieg ermitteln, damit der Cronjob nicht weiterhin
// in den Webroot schreibt (siehe config/log_path.php).
$logFile = require __DIR__ . '/../config/log_path.php';
if ($logFile !== null) {
    ini_set('error_log', $logFile);
}

try {
    // Datenbankverbindung herstellen
    $pdo = new App\Model\PdoConnect();

    // Timeout zentral aus config/presence.php, damit er nicht gegen den
    // Heartbeat-Takt des Browsers auseinanderlaeuft (siehe Kommentar dort).
    $presence = require __DIR__ . '/../config/presence.php';
    $timeout  = (int)$presence['offline_timeout'];

    // Setze Benutzer offline, wenn sie länger als $timeout Sekunden inaktiv
    // waren. Bereits offline gemeldete Zeilen werden ausgelassen - sie muessen
    // bei jedem Durchlauf nicht erneut geschrieben werden.
    $sql_offline = "UPDATE user SET user_status = 'offline'
                    WHERE user_status <> 'offline'
                      AND updated_at < (NOW() - INTERVAL $timeout SECOND)";
    $affected = $pdo::$connection->exec($sql_offline);

    // Optional: Logging für Cronjobs (nur zur Überwachung/Debug)
    // error_log("Cron: $affected Nutzer auf offline gesetzt (" . date('c') . ")");

} catch (Exception $e) {
    // Fehler ins Log schreiben
    error_log("Fehler im Cronjob: " . $e->getMessage());
}

?>
