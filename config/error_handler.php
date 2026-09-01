<?php
// Fehlerausgabe im Browser deaktivieren (nur für Produktion empfohlen)
ini_set('display_errors', 0);
// Fehlerprotokollierung aktivieren
ini_set('log_errors', 1);

// Logpfad ermitteln (LOG_PATH aus der Umgebung, sonst Fallback oberhalb des
// Document Root). Details und der Hinweis auf die alte Logdatei im Webroot
// stehen in config/log_path.php.
$logFile = require __DIR__ . '/log_path.php';

// Nur setzen, wenn ein beschreibbarer Pfad ermittelt werden konnte.
// Sonst bleibt das Standard-Fehlerlog des Webservers aktiv.
if ($logFile !== null) {
    ini_set('error_log', $logFile);
}

// Alle Fehlertypen werden erfasst (E_ALL)
error_reporting(E_ALL);

// Setzt einen benutzerdefinierten Error-Handler für Laufzeit-Fehler
set_error_handler(function ($severity, $message, $file, $line) {
    // Fehler ins Logfile schreiben
    error_log("[$severity] $message in $file on line $line");
    // HTTP-Fehlercode 500 (Internal Server Error) senden
    http_response_code(500);
    // Fehlermeldung für Nutzer (keine Details, damit keine sensiblen Infos nach außen gelangen)
    echo "Interner Serverfehler.";
    exit;
});

// Setzt einen benutzerdefinierten Exception-Handler für unbehandelte Ausnahmen
set_exception_handler(function ($exception) {
    // Ausnahme ins Logfile schreiben
    error_log($exception->getMessage());
    // HTTP-Fehlercode 500 senden
    http_response_code(500);
    // Fehlermeldung für Nutzer
    echo "Interner Serverfehler.";
    exit;
});
