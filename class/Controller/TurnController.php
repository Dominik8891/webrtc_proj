<?php
namespace App\Controller;

use App\Model\MeteredTurnService;

/**
 * Controller für Turnserver.
 */
class TurnController
{
    /**
     * Gibt die TURN-Credentials als JSON aus.
     * Holt die Credentials vom MeteredTurnService und behandelt Fehler sauber.
     * @return void
     */
    public function getTurnCredentials()
    {
        header('Content-Type: application/json');
        try {
            $service = new MeteredTurnService();
            $result = $service->fetch_turn_credentials();
            echo $result;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                "error" => $e->getMessage()
            ]);
        }
        exit;
    }
}
