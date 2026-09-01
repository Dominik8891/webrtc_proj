<?php
namespace App\Controller;

use App\Model\IceServerConfig;
use App\Model\MeteredTurnService;

/**
 * Controller für Turnserver.
 */
class TurnController
{
    /**
     * Gibt die ICE-Server-Konfiguration als JSON aus.
     *
     * Antwortformat (immer HTTP 200, immer verwertbar):
     *   {
     *     "iceServers":    [ {"urls": "..."} , ... ],   // nie leer
     *     "turnAvailable": true|false,                  // false = nur STUN
     *     "warning":       "..."                        // optional, für den Nutzer
     *   }
     *
     * Bewusste Änderung gegenüber vorher: Bei einem Ausfall des TURN-Dienstes
     * wurde bisher HTTP 500 mit {"error": ...} geliefert. Der Client hat das
     * nicht ausgewertet und das Fehlerobjekt als ICE-Konfiguration an die
     * RTCPeerConnection weitergereicht (Befund F-18). Statt eines harten
     * Fehlers geben wir jetzt die STUN-Fallbacks zurück - damit sind Anrufe in
     * einfachen Netzen weiter möglich - und melden über "turnAvailable" und
     * "warning", dass die Verbindung eingeschränkt ist.
     *
     * Der Server-Proxy für den TURN-Key bleibt unverändert: Der API-Key
     * verlässt den Server nach wie vor nicht.
     *
     * @return void
     */
    public function getTurnCredentials()
    {
        header('Content-Type: application/json');

        $iceServers = [];
        $warning    = null;

        try {
            $service = new MeteredTurnService();
            $raw     = $service->fetch_turn_credentials();
            $iceServers = self::extractIceServers($raw);

            if (empty($iceServers)) {
                // Antwort kam an, war aber nicht verwertbar.
                $warning = 'Der TURN-Dienst hat keine verwertbaren Zugangsdaten geliefert.';
                error_log('TurnController: Antwort des TURN-Dienstes enthielt keine gueltigen ICE-Server.');
            }
        } catch (\Exception $e) {
            // Details nur ins Log - der Client bekommt eine allgemeine Meldung,
            // damit keine internen Informationen im Browser landen.
            error_log('TurnController: TURN-Credentials nicht abrufbar: ' . $e->getMessage());
            $warning = 'Der TURN-Server ist derzeit nicht erreichbar.';
        }

        // STUN-Fallback immer anhängen: Der Ausfall eines einzelnen Servers
        // soll die Verbindung nicht verhindern (BESTANDSAUFNAHME 6.1).
        $iceServers = IceServerConfig::merge($iceServers, IceServerConfig::stunServers());

        $response = [
            'iceServers'    => array_values($iceServers),
            'turnAvailable' => IceServerConfig::hasTurn($iceServers),
        ];
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        echo json_encode($response);
        exit;
    }

    /**
     * Holt die ICE-Server aus der JSON-Antwort des TURN-Dienstes.
     * Metered liefert je nach Endpunkt entweder ein nacktes Array oder ein
     * Objekt mit dem Feld "iceServers" - beides wird unterstützt.
     *
     * @param string|false $raw JSON-Antwort des TURN-Dienstes
     * @return array Liste gültiger ICE-Server-Einträge
     */
    private static function extractIceServers($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = (isset($decoded['iceServers']) && is_array($decoded['iceServers']))
            ? $decoded['iceServers']
            : $decoded;

        $result = [];
        foreach ($list as $entry) {
            // Nur Einträge mit einem nutzbaren urls-Feld übernehmen. Alles
            // andere (z. B. ein Fehlerobjekt) würde die RTCPeerConnection nur
            // durcheinanderbringen.
            if (!empty(IceServerConfig::urlsOf($entry))) {
                $result[] = $entry;
            }
        }
        return $result;
    }
}
