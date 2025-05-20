<?php

/**
 * Klasse zur Verwaltung der Datenbankverbindung mittels PDO.
 */
class PdoConnect
{
    // Private Attribute für Datenbank-Verbindungsinformationen
    private $host;
    private $port;
    private $dbname;
    private $dbusername;
    private $dbpassword;
    
    // Statische Eigenschaft zur Speicherung der Datenbankverbindung
    public static $connection = null;

    /**
     * Konstruktor zur Initialisierung und Herstellung der Datenbankverbindung.
     */
    public function __construct()
    {
        // Laden der Konfigurationsdaten aus der externen Konfigurationsdatei db_config.php
        $config = include 'config/db_config.php';
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->dbname = $config['dbname'];
        $this->dbusername = $config['username'];
        $this->dbpassword = $config['password'];

        // Versuch, die Datenbankverbindung über PDO herzustellen
        try {
            // Erstellen der PDO-Instanz mit den geladenen Konfigurationsdaten
            PdoConnect::$connection = new PDO(
                "mysql:host=$this->host;port=$this->port;dbname=$this->dbname", 
                $this->dbusername, 
                $this->dbpassword
            );
            
            // Setzen des Fehlermodus auf Ausnahme, um bei Fehlern eine Exception auszulösen
            PdoConnect::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        } catch(PDOException $e) {
            // Falls die Verbindung fehlschlägt, wird eine Fehlermeldung ausgegeben und das Skript beendet
            die('Connection failed: ' . $e->getMessage());
        }
    }
}
