<?php

namespace App\Model;

use PDO;
use PDOException;

/**
 * Klasse zur Verwaltung der Datenbankverbindung mittels PDO.
 */
class PdoConnect
{
    // Statische Eigenschaft zur Speicherung der Datenbankverbindung
    public static $connection = null;

    /**
     * Konstruktor zur Initialisierung und Herstellung der Datenbankverbindung.
     */
    public function __construct()
    {       
        if(self::$connection !== null) {
            return;
        }

        $host       = $_ENV['DB_HOST'];
        $port       = $_ENV['DB_PORT'];
        $dbname     = $_ENV['DB_NAME'];
        $username   = $_ENV['DB_USER'];
        $password   = $_ENV['DB_PW'  ];

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

        // Versuch, die Datenbankverbindung über PDO herzustellen
        try {
            // Erstellen der PDO-Instanz mit den geladenen Konfigurationsdaten
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false
            ]);
        } catch(PDOException $e) {
            // Fehler immer nur ins Log!
            error_log('DB_Verbindung fehlgeschlagen: ' . $e->getMessage());
            http_response_code(500);
            die('Interner Serverfehler. Bitte später erneut versuchen.');
        }
    }
}
