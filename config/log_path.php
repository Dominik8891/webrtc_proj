<?php
/**
 * Ermittelt den Pfad der Logdatei und stellt sicher, dass das Zielverzeichnis existiert.
 *
 * Wird sowohl vom Web-Einstieg (config/error_handler.php) als auch vom
 * Cronjob (cron/check_online_status.php) eingebunden, damit beide in dieselbe
 * Datei schreiben und der Pfad nur an einer Stelle gepflegt wird.
 *
 * Reihenfolge der Pfadermittlung:
 *   1. Umgebungsvariable LOG_PATH (vollstaendiger Pfad zur Logdatei)
 *   2. Fallback: ../logs/php-error.log OBERHALB des Document Root
 *
 * WICHTIG: Diese Datei wird von index.php geladen, BEVOR config/env.php die
 * .env-Datei einliest. LOG_PATH aus der .env ist hier deshalb noch NICHT
 * verfuegbar und muss auf Server-/Systemebene gesetzt werden, z.B.
 *   Apache : SetEnv LOG_PATH /var/log/webrtc/php-error.log
 *   nginx  : fastcgi_param LOG_PATH /var/log/webrtc/php-error.log;
 *   Docker : environment: LOG_PATH=/var/log/webrtc/php-error.log
 * Ist LOG_PATH nicht gesetzt, greift der Fallback und alles funktioniert.
 *
 * HINWEIS ZUR ALTLAST: Frueher wurde nach <Webroot>/php-error.log geschrieben.
 * Diese Datei kann noch existieren und ueber HTTP abrufbar sein. Sie enthaelt
 * unter Umstaenden TOTP-Secrets und Reset-Tokens aus frueheren Versionen.
 * Sie wird hier BEWUSST NICHT automatisch geloescht - bitte manuell entfernen:
 *   rm <Webroot>/php-error.log
 *
 * @return string|null Absoluter Pfad zur Logdatei, oder null wenn kein
 *                     beschreibbares Verzeichnis angelegt werden konnte
 *
 * Die Hilfsfunktionen sind mit function_exists() abgesichert, damit ein
 * mehrfaches require dieser Datei innerhalb eines Requests keinen
 * "Cannot redeclare"-Fehler ausloest.
 */

/**
 * Liest eine Umgebungsvariable aus allen ueblichen Quellen.
 *
 * @param  string $name Name der Variablen
 * @return string|null  Wert oder null, wenn nicht gesetzt
 */
if (!function_exists('webrtc_env')) {
function webrtc_env(string $name): ?string
{
    // $_SERVER wird von Apache SetEnv und nginx fastcgi_param befuellt
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return (string)$_SERVER[$name];
    }
    // $_ENV wird von phpdotenv befuellt (steht hier noch nicht zur Verfuegung)
    if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
        return (string)$_ENV[$name];
    }
    // getenv() greift auf das echte Prozess-Environment zu (Docker, systemd)
    $value = getenv($name);
    return ($value !== false && $value !== '') ? $value : null;
}
}

/**
 * Stellt sicher, dass das Verzeichnis der Logdatei existiert.
 * Legt es beim ersten Schreiben mit den Rechten 0750 an.
 *
 * @param  string $logFile Vollstaendiger Pfad zur Logdatei
 * @return bool            true, wenn das Verzeichnis beschreibbar ist
 */
if (!function_exists('webrtc_ensure_log_dir')) {
function webrtc_ensure_log_dir(string $logFile): bool
{
    $dir = dirname($logFile);

    if (!is_dir($dir)) {
        // Rechte 0750: Eigentuemer voll, Gruppe nur lesen/betreten, Rest nichts.
        // Der dritte Parameter legt fehlende Elternverzeichnisse mit an.
        // @ unterdrueckt die Warnung bei Race-Conditions (paralleler Request
        // hat das Verzeichnis in der Zwischenzeit bereits angelegt).
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        // mkdir() wird von der umask beschnitten - Rechte explizit nachziehen
        @chmod($dir, 0750);
    }

    return is_writable($dir);
}
}

// --- Pfad bestimmen -------------------------------------------------------

$webrtcLogPath = webrtc_env('LOG_PATH');

if ($webrtcLogPath === null) {
    // Fallback: eine Ebene OBERHALB des Document Root.
    // __DIR__ ist <Webroot>/config, also ist __DIR__/../.. der Ordner ueber
    // dem Webroot. Damit liegt das Log ausserhalb des per HTTP erreichbaren
    // Bereichs, auch wenn die .htaccess nicht greift (z.B. unter nginx).
    $webrtcLogPath = __DIR__ . '/../../logs/php-error.log';
}

// Verzeichnis anlegen. Schlaegt das fehl (z.B. keine Schreibrechte oberhalb
// des Webroot), faellt PHP auf das Standard-Fehlerlog des Webservers zurueck -
// das ist immer noch besser, als wieder in den Webroot zu schreiben.
if (!webrtc_ensure_log_dir($webrtcLogPath)) {
    $webrtcLogPath = null;
}

return $webrtcLogPath;
