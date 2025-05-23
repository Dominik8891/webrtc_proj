<?php
// Einbindung notwendiger Klassen und Systeme
include 'class/PdoConnect.php';    // Verbindet die Datenbankklasse
include 'class/User.php';          // Verbindet die Benutzerklasse
include 'class/Message.php';       // Verbindet die Chat-Protokoll-Klasse
include 'class/Connection.php';    // Verbindet die WebRTC-Klasse
include 'controller/system.php';  // Verbindet allgemeine Systemfunktionen
include 'controller/login_system.php'; // Verbindet das Login-System
include 'controller/signup_system.php'; // Verbindet das Anmelde-/Registrierungssystem
include 'controller/user_system.php';   // Verbindet das Benutzersystem
include 'controller/message_system.php'; // Verbindet das Nachrichtensystem
include 'controller/webrtc_system.php';
include 'controller/api_system.php';

// Erstellen einer Instanz der PDO-Verbindung zur Datenbank
$pdo_instance = new PdoConnect();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start der PHP-Session für Benutzerdaten
session_start();
ini_set('display_errors', 0); // Fehleranzeige deaktivieren
ini_set('log_errors', 1); // Fehlerprotokollierung aktivieren
ini_set('error_log', 'php-error.log'); // Log-Datei

// Setzt ein Zeitlimit von 300 Sekunden für das Skript (5 Minuten)
set_time_limit(300);

// Überprüfen, ob ein Aktionsparameter (act) in der URL oder Anfrage vorhanden ist
if (g('act') != null)
{
    // Konstruieren des Funktionsnamens aus dem 'act' Parameter
    $func_name = "act_" . g('act');

    // Aufruf der entsprechenden Funktion basierend auf dem 'act' Parameter
    call_user_func($func_name);
}
else
{
    // Wenn keine Aktion angegeben ist, wird die Startseite aufgerufen
    home();
}
