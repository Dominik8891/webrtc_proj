<?php
namespace App\Controller;

use App\Model\MeteredTurnService;

class TurnController
{
    /**
     * Gibt die TURN-Credentials als JSON aus.
     */
    public function getTurnCredentials()
    {
        header('Content-Type: application/json');
        try {
            $service = new MeteredTurnService(__DIR__ . '/../../config/api.php');
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
