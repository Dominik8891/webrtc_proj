<?php
/**
 * Cronjob: loescht Chatnachrichten, die aelter sind als die in
 * config/chat_retention.php eingetragene Aufbewahrungsdauer.
 *
 * Aufbau bewusst wie cron/check_online_status.php - derselbe Einstieg,
 * dasselbe Logziel, dieselbe Fehlerbehandlung. Wer den einen Cronjob
 * eingerichtet hat, richtet den zweiten genauso ein.
 *
 * Der Lauf ist idempotent: Er loescht, was zu alt ist, und findet beim
 * naechsten Mal nichts mehr davon. Er darf beliebig oft laufen und darf
 * ausfallen - beim naechsten Durchlauf wird nachgeholt.
 */

// Lade PDO und Composer-Autoloader
require_once __DIR__ . '/../class/Model/PdoConnect.php';
// Autoloader für Composer-Pakete laden
require __DIR__ . '/../vendor/autoload.php';
// Umgebungsvariablen laden
require_once __DIR__ . '/../config/env.php';

// Fehlerbehandlung: Fehler werden ins Log geschrieben, aber nicht ausgegeben
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Logpfad wie im Web-Einstieg ermitteln, damit der Cronjob nicht in den
// Webroot schreibt (siehe config/log_path.php).
$logFile = require __DIR__ . '/../config/log_path.php';
if ($logFile !== null) {
    ini_set('error_log', $logFile);
}

try {
    // Aufbewahrungsdauer aus der einen zustaendigen Stelle. Dieselbe Datei
    // liest das Frontend fuer den Hinweis im Chatfenster - Hinweis und
    // Loeschlauf koennen deshalb nicht auseinanderlaufen.
    $retention = require __DIR__ . '/../config/chat_retention.php';
    $days      = (int)$retention['retention_days'];

    // Null oder negativ heisst: Aufbewahrung unbegrenzt. Dann wird nichts
    // geloescht - und zwar wortlos, sonst stuende bei abgeschalteter
    // Loeschung jede Minute eine Zeile im Log.
    if ($days <= 0) {
        return;
    }

    // Datenbankverbindung herstellen
    $pdo = new App\Model\PdoConnect();

    // Endgueltig loeschen, nicht als geloescht markieren: Eine Zeile, die
    // stehen bleibt, ist nicht geloescht - sie ist nur ausgeblendet.
    //
    // $days ist oben nach int gecastet und geht deshalb direkt in die
    // Abfrage; INTERVAL nimmt keinen Platzhalter fuer die Einheit, und ein
    // gebundener Parameter waere hier ein String im Ausdruck.
    $stmt = App\Model\PdoConnect::$connection->prepare(
        "DELETE FROM chat_message WHERE sent_at < (NOW() - INTERVAL $days DAY)"
    );
    $stmt->execute();
    $geloescht = $stmt->rowCount();

    // Der Chat selbst bleibt bestehen - er ist die Verbindung zweier Nutzer,
    // nicht der Inhalt. Sein Datum der letzten Nachricht muss aber mitgehen:
    // Sonst stuende in der Chatuebersicht weiterhin ein Zeitpunkt, zu dem es
    // keine Nachricht mehr gibt, und ein Klick auf den Verlauf zeigte eine
    // leere Seite unter einem Datum.
    if ($geloescht > 0) {
        App\Model\PdoConnect::$connection->exec(
            "UPDATE chat SET last_msg_at = NULL
              WHERE last_msg_at IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM chat_message WHERE chat_message.chat_id = chat.id
                )"
        );
    }

    // Der Lauf haelt fest, was er geloescht hat. Anders als beim
    // Online-Status ist das kein Debug-Rauschen: Geloeschte Nachrichten sind
    // nicht wiederherstellbar, und wer eine Auskunft geben muss, will
    // belegen koennen, dass und wann geloescht wurde. Geschrieben wird nur,
    // wenn wirklich etwas weggefallen ist.
    if ($geloescht > 0) {
        error_log("Chat-Aufbewahrung: $geloescht Nachricht(en) aelter als $days Tage geloescht.");
    }

} catch (Exception $e) {
    // Fehler ins Log schreiben
    error_log("Fehler im Cronjob cleanup_chat_messages: " . $e->getMessage());
}
