<?php

namespace App\Model;

/**
 * Hilfsklasse zum Abrufen von TURN-Credentials vom Metered-Service.
 */
class MeteredTurnService
{
    private $configPath;

    /**
     * Konstruktor: Kann optional einen Pfad zu einer Config erhalten.
     */
    public function __construct($configPath = null)
    {
        $this->configPath = $configPath;
    }

    /**
     * Holt TURN-Credentials von Metered per HTTP-Request.
     *
     * @return string JSON-Response des TURN-Dienstes
     * @throws \Exception bei fehlender Config, fehlenden ENV-Variablen oder HTTP-Fehlern
     */
    public function fetch_turn_credentials()
    {
        try {
            // ENV-Variablen prüfen
            if (empty($_ENV['METERED_APP_NAME']) || empty($_ENV['METERED_API_KEY'])) {
                throw new \Exception("METERED_APP_NAME oder METERED_API_KEY nicht gesetzt in ENV");
            }

            $appname = $_ENV['METERED_APP_NAME'];
            $apikey = $_ENV['METERED_API_KEY'];
            $url = "https://$appname.metered.live/api/v1/turn/credentials?apiKey=$apikey";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode != 200 || !$response) {
                error_log("Fehler beim Abrufen der TURN-Credentials (HTTP $httpcode): $response");
                throw new \Exception("Could not fetch TURN credentials (HTTP $httpcode)");
            }
            return $response;
        } catch (\Exception $e) {
            error_log('Fehler in MeteredTurnService::fetch_turn_credentials: ' . $e->getMessage());
            throw $e; // oder return false; falls du im Aufrufer nicht per try/catch arbeiten willst
        }
    }
}
