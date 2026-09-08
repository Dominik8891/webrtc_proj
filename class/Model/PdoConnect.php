<?php

namespace App\Model;

use PDO;
use PDOException;

/**
 * Klasse zur Verwaltung der Datenbankverbindung mittels PDO.
 * Stellt eine Singleton-Verbindung bereit, die von allen Models genutzt werden kann.
 */
class PdoConnect
{
    // Statische Eigenschaft zur Speicherung der Datenbankverbindung
    public static $connection = null;

    /**
     * Stellt sicher, dass die Verbindung steht.
     *
     * WOZU ES DIESE METHODE GIBT - und sie hat einen konkreten Ausfall als
     * Anlass: Die Verbindung entstand bisher als NEBENWIRKUNG eines
     * Konstruktors, dessen Ergebnis niemand benutzt ("$pdo_instance = new
     * PdoConnect();" in index.php). Was dort wirklich passiert, stand nur im
     * Kommentar daneben - und damit war es eine Zeile, die aussieht, als
     * koenne man sie verschieben.
     *
     * Genau das ist schiefgegangen: In index.php stand ein Aufruf, der die
     * Datenbank braucht (App\Helper\Auth::discardOutdatedSession), DREI
     * ZEILEN VOR dieser Nebenwirkung. Jede Seite endete mit "Call to a member
     * function prepare() on null".
     *
     * Der Aufruf heisst jetzt, was er tut, und er ist IDEMPOTENT: Steht die
     * Verbindung schon, passiert nichts. Damit laesst er sich an jeder
     * Stelle nachziehen, an der sich jemand nicht sicher ist - und die
     * Testattrappen, die $connection selbst setzen, ueberschreibt er nicht.
     *
     * @return void
     */
    public static function sicherstellen(): void
    {
        if (self::$connection === null) {
            new self();
        }
    }

    /**
     * Konstruktor zur Initialisierung und Herstellung der Datenbankverbindung.
     * Lädt alle nötigen Einstellungen aus Umgebungsvariablen (.env).
     * Bei erneutem Aufruf wird keine neue Verbindung aufgebaut (Singleton-Prinzip).
     * Im Fehlerfall wird ein Log-Eintrag geschrieben und ein HTTP 500 zurückgegeben.
     */
    public function __construct()
    {       
        if (self::$connection !== null) {
            return;
        }

        $host     = $_ENV['DB_HOST'];
        $port     = $_ENV['DB_PORT'];
        $dbname   = $_ENV['DB_NAME'];
        $username = $_ENV['DB_USER'];
        $password = $_ENV['DB_PW'];

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ]);
        } catch (PDOException $e) {
            error_log('DB_Verbindung fehlgeschlagen: ' . $e->getMessage());
            http_response_code(500);
            die('Interner Serverfehler. Bitte später erneut versuchen.');
        }
    }
}
