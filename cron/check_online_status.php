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

    // ABGELAUFENE BEREITSCHAFT AUFRAEUMEN.
    //
    // Das ist AUFRAEUMEN UND KEINE PRUEFUNG. Ob ein Guide bereit ist,
    // entscheidet nirgends dieser Cronjob, sondern der Vergleich mit NOW()
    // in der Abfrage selbst (App\Model\Location::AVAILABILITY_SQL,
    // App\Model\User::availableSeconds). Eine abgelaufene Bereitschaft wirkt
    // also auch dann nicht mehr, wenn dieser Job gar nicht eingerichtet ist -
    // ein Standort wird von selbst wieder grau.
    //
    // Geraeumt wird trotzdem, aus zwei Gruenden: Die Spalte traegt sonst
    // dauerhaft alte Zeitpunkte herum, und "NULL heisst nicht bereit" ist die
    // Aussage, die auch das Abmelden und der Schalter hinterlassen. Ein
    // einziger Zustand fuer eine Sache ist leichter zu lesen als zwei.
    //
    // Getrennt vom Statement darueber, weil es eine ANDERE Frist ist: Der
    // Status haengt am Heartbeat (Sekunden), die Bereitschaft an der
    // Entscheidung des Guides (Stunden).
    $pdo::$connection->exec(
        "UPDATE user SET available_until = NULL
         WHERE available_until IS NOT NULL
           AND available_until <= NOW()"
    );

    // ABGELAUFENE ANFRAGEN AUFRAEUMEN.
    //
    // Auch das ist AUFRAEUMEN UND KEINE PRUEFUNG - dieselbe Ueberlegung wie
    // bei der Bereitschaft darueber. Ob eine Anfrage noch gilt, entscheidet
    // der Vergleich mit NOW() in jeder einzelnen Abfrage
    // (App\Model\TourRequest::statusSql). Ohne diesen Job laeuft alles
    // genauso; die Spalte traegt dann nur dauerhaft einen Wert, der nicht mehr
    // stimmt, und die Tabelle sammelt Karteileichen.
    //
    // Zwei Faelle: eine offene Anfrage, deren Frist verstrichen ist, und eine
    // angenommene, zu der es nie ein Gespraech gab und deren Zeitfenster
    // vorbei ist. Beide enden als "abgelaufen".
    App\Model\TourRequest::expireDue();

    // Und die Gegenrichtung: eine Fuehrung, die begonnen hat und deren Ende
    // nie angekommen ist (Absturz, Netzausfall). Sie wird abgeschlossen, aber
    // OHNE ein Ende zu erfinden - siehe dort.
    App\Model\TourRequest::closeStale();

    // Optional: Logging für Cronjobs (nur zur Überwachung/Debug)
    // error_log("Cron: $affected Nutzer auf offline gesetzt (" . date('c') . ")");

} catch (Exception $e) {
    // Fehler ins Log schreiben
    error_log("Fehler im Cronjob: " . $e->getMessage());
}

?>
