<?php
// Lädt die Umgebungsvariablen aus der .env-Datei mithilfe der vlucas/phpdotenv-Bibliothek
// Dadurch stehen alle Konfigurationswerte (z.B. für DB, Mail, etc.) global als $_ENV zur Verfügung

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../'); // Erstellt eine Dotenv-Instanz im Projekt-Hauptverzeichnis
$dotenv->load(); // Lädt die Variablen aus der .env-Datei in $_ENV
