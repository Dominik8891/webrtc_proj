<?php

namespace App\Model;

/**
 * Liefert die STUN-Server, die unabhaengig vom TURN-Dienst (Metered) immer
 * mit ausgeliefert werden.
 *
 * Hintergrund (BESTANDSAUFNAHME 6.1): Bisher kamen saemtliche ICE-Server zur
 * Laufzeit von Metered. Faellt Metered aus oder ist das Kontingent erschoepft,
 * bekommt der Browser gar keine ICE-Konfiguration und es kommt keine Verbindung
 * zustande - auch dann nicht, wenn beide Seiten in einfachen Netzen sitzen und
 * ein STUN-Server voellig ausreichen wuerde.
 *
 * Deshalb wird hier eine Liste mehrerer STUN-Server gepflegt. Faellt einer aus,
 * probiert der Browser die naechsten. Die Liste ist ueber die Umgebungsvariable
 * STUN_SERVERS austauschbar, damit spaeter ohne Codeaenderung auf einen eigenen
 * Server (z. B. coturn) gewechselt werden kann.
 */
class IceServerConfig
{
    /**
     * Vorgabe, solange STUN_SERVERS nicht gesetzt ist: oeffentliche STUN-Server
     * verschiedener Betreiber. Bewusst verschiedene Anbieter, damit der Ausfall
     * eines Betreibers nicht alle Eintraege gleichzeitig trifft.
     */
    private const DEFAULT_STUN_SERVERS = [
        'stun:stun.l.google.com:19302',
        'stun:stun1.l.google.com:19302',
        'stun:stun.cloudflare.com:3478',
    ];

    /**
     * Gibt die STUN-Server im Format der RTCIceServer-Liste zurueck.
     *
     * @return array Liste von ['urls' => 'stun:host:port']
     */
    public static function stunServers(): array
    {
        $servers = self::parseEnvList($_ENV['STUN_SERVERS'] ?? '');

        if (empty($servers)) {
            $servers = self::DEFAULT_STUN_SERVERS;
        }

        $result = [];
        foreach ($servers as $url) {
            $result[] = ['urls' => $url];
        }
        return $result;
    }

    /**
     * Zerlegt den ENV-Wert (kommagetrennt) und laesst nur gueltige
     * STUN-URLs durch. Alles andere wird verworfen, damit ein Tippfehler in der
     * .env nicht als Muell in der ICE-Konfiguration des Browsers landet.
     *
     * @param mixed $raw Rohwert aus der Umgebung
     * @return array Liste gueltiger STUN-URLs
     */
    private static function parseEnvList($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $servers = [];
        foreach (explode(',', $raw) as $entry) {
            $url = trim($entry);
            if ($url === '') {
                continue;
            }
            // Nur stun:/stuns: zulassen. TURN-Zugaenge gehoeren nicht hierher,
            // die kommen mit kurzlebigen Credentials vom TURN-Dienst.
            if (preg_match('/^stuns?:[A-Za-z0-9\.\-]+(:\d{1,5})?$/', $url) !== 1) {
                error_log('IceServerConfig: ungueltiger Eintrag in STUN_SERVERS wird ignoriert.');
                continue;
            }
            $servers[] = $url;
        }
        return $servers;
    }

    /**
     * Haengt die STUN-Fallbacks an eine bestehende ICE-Server-Liste an, ohne
     * bereits vorhandene URLs zu doppeln.
     *
     * @param array $iceServers Liste vom TURN-Dienst (kann leer sein)
     * @param array $stunServers Liste aus stunServers()
     * @return array Zusammengefuehrte Liste
     */
    public static function merge(array $iceServers, array $stunServers): array
    {
        $known = [];
        foreach ($iceServers as $server) {
            foreach (self::urlsOf($server) as $url) {
                $known[strtolower($url)] = true;
            }
        }

        foreach ($stunServers as $server) {
            $urls = self::urlsOf($server);
            if (empty($urls)) {
                continue;
            }
            $isNew = false;
            foreach ($urls as $url) {
                if (!isset($known[strtolower($url)])) {
                    $isNew = true;
                    $known[strtolower($url)] = true;
                }
            }
            if ($isNew) {
                $iceServers[] = $server;
            }
        }

        return $iceServers;
    }

    /**
     * Normalisiert das urls-Feld eines ICE-Server-Eintrags auf ein Array.
     * Laut Spezifikation darf dort ein String oder ein Array stehen.
     *
     * @param mixed $server Ein ICE-Server-Eintrag
     * @return array Liste der URLs
     */
    public static function urlsOf($server): array
    {
        if (!is_array($server) || !isset($server['urls'])) {
            return [];
        }
        $urls = is_array($server['urls']) ? $server['urls'] : [$server['urls']];
        return array_values(array_filter($urls, 'is_string'));
    }

    /**
     * Prueft, ob in der Liste mindestens ein TURN-Server steckt. Ohne TURN
     * scheitern Verbindungen hinter symmetrischem NAT - der Nutzer soll das
     * als Hinweis sehen statt eine unerklaerliche Fehlermeldung zu bekommen.
     *
     * @param array $iceServers Zusammengefuehrte ICE-Server-Liste
     * @return bool true, wenn ein turn:- oder turns:-Eintrag enthalten ist
     */
    public static function hasTurn(array $iceServers): bool
    {
        foreach ($iceServers as $server) {
            foreach (self::urlsOf($server) as $url) {
                $scheme = strtolower(substr($url, 0, 5));
                if ($scheme === 'turn:' || $scheme === 'turns') {
                    return true;
                }
            }
        }
        return false;
    }
}
