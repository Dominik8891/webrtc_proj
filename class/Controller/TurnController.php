<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Model\IceServerConfig;
use App\Model\MeteredTurnService;
use App\Model\RateLimit;

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
     * DIE BREMSE, UND WARUM SIE NICHT ABWEIST (Befund N-10)
     * -----------------------------------------------------
     * Jeder Aufruf loeste einen ausgehenden HTTPS-Request an Metered aus und
     * verbrauchte ein bezahltes Kontingent. Unbegrenzt ist das nicht nur Last,
     * sondern ein FREMDFINANZIERTER VERSTAERKER - und das Kontingent ist am
     * Ende auch dann leer, wenn der Missbrauch von einem einzigen Konto
     * ausging. Deshalb ist dies neben dem Mailversand der einzige Endpunkt aus
     * N-10, der zusaetzlich je IP zaehlt: Die Kosten fallen draussen an, und
     * dort interessiert nicht, ueber wie viele Konten sie verteilt wurden.
     *
     * ERREICHT DIE GRENZE ZU, GIBT ES TROTZDEM EINE BRAUCHBARE ANTWORT. Kein
     * HTTP 429, kein Fehlerobjekt: Der Endpunkt hat fuer den Ausfall des
     * TURN-Dienstes bereits einen Weg - die STUN-Liste mit
     * turnAvailable=false und einem Hinweis -, und genau den nimmt er auch
     * hier. Ein Anruf in einem einfachen Netz gelingt weiterhin; es
     * unterbleibt nur der teure Weg nach draussen.
     *
     * Das ist derselbe Gedanke wie bei Befund F-18, dem dieser Endpunkt seine
     * heutige Form verdankt: Eine Antwort, die der Client nicht verwerten
     * kann, ist schlimmer als eine eingeschraenkte, die er verwerten kann.
     *
     * DER ABGEWIESENE AUFRUF ZAEHLT NICHT MIT. Er kostet nichts nach draussen
     * - wuerde er zaehlen, hielte ein Client, der stur weiterprobiert, seine
     * eigene Sperre endlos am Leben.
     *
     * @return void
     */
    public function getTurnCredentials()
    {
        header('Content-Type: application/json');

        $iceServers = [];
        $warning    = null;

        $teile = ['konto' => RateLimit::konto(Auth::userId()), 'ip' => RateLimit::ip()];
        $rest  = RateLimit::restsperre('turn_credentials', $teile);

        if ($rest > 0) {
            // Nicht nach draussen gehen - aber auch nicht scheitern. Unten
            // wird die STUN-Liste angehaengt, turnAvailable meldet dann von
            // selbst false.
            error_log('TurnController: TURN-Abruf gebremst (Konto #' . Auth::userId()
                . ', noch ' . $rest . 's)');
            $warning = 'Zu viele Verbindungsversuche in kurzer Zeit. '
                     . 'Der Anruf wird ohne Relay-Server aufgebaut.';
        } else {
            RateLimit::verbuchen('turn_credentials', $teile);

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
