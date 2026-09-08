<?php
namespace App\Helper;

/**
 * HTTPS: die Weiterleitung und die Frage, ob die laufende Anfrage sicher ist.
 *
 * WARUM DAS UEBERHAUPT SEIN MUSS
 * ------------------------------
 * Die Anwendung fragt Kamera und Mikrofon ab. Browser geben beides nur in
 * einem "secure context" frei - ueber http:// bekommt der Guide seine Kamera
 * gar nicht erst. Dazu kommt das Uebliche: Anmeldedaten, Sitzungscookie und
 * Chatinhalte gehen sonst im Klartext ueber die Leitung.
 *
 * WARUM ES JETZT EINEN SCHALTER GIBT
 * ----------------------------------
 * Vorher stand die Weiterleitung fest in index.php - vier Zeilen ohne
 * Ausweg. Auf einem Entwicklungsrechner ohne Zertifikat leitete sie auf eine
 * Adresse, die es nicht gibt; wer lokal arbeiten wollte, musste die Zeilen
 * auskommentieren. Genau das ist der Zustand, den App\Helper\MailGate fuer
 * den Mailversand aufgeloest hat: Auskommentierter Code ist kein
 * ausgeschalteter Code, sondern Code, den niemand mehr pflegt - und der beim
 * naechsten Commit versehentlich mit hochgeht.
 *
 * Jetzt laeuft der Code immer, und FORCE_HTTPS entscheidet, was er tut. Die
 * Vorgabe ist "an" und damit das bisherige Verhalten: Eine bestehende
 * Installation, die ihre .env nicht anfasst, merkt von dem Schalter nichts.
 *
 * DIE DREI SCHALTER UND IHRE VORGABEN
 * -----------------------------------
 *   FORCE_HTTPS      an   Weiterleitung von http:// auf https:// (wie bisher)
 *   TRUST_PROXY      aus  X-Forwarded-Proto auswerten - siehe istSicher()
 *   HSTS_MAX_AGE     0    kein Strict-Transport-Security-Header
 *
 * HSTS IST AUS UND NICHT AN. Ein einmal gesendetes max-age laesst sich nicht
 * zurueckrufen: Der Browser merkt es sich fuer die volle Dauer und weigert
 * sich, die Seite ueber http:// zu laden - auch dann, wenn das Zertifikat
 * abgelaufen ist oder der Betreiber umzieht. Ein Header mit dieser Wirkung
 * darf nicht als Nebenwirkung eines Updates entstehen; er gehoert
 * ausdruecklich hingeschrieben. Der Weg dahin steht in der README.
 */
class Https
{
    /**
     * Laeuft die aktuelle Anfrage ueber HTTPS?
     *
     * DREI QUELLEN, und die dritte ist die heikle:
     *
     *   1. $_SERVER['HTTPS']      - der Webserver selbst terminiert TLS.
     *   2. SERVER_PORT 443        - derselbe Fall bei Servern, die HTTPS
     *                               nicht setzen (aeltere nginx-Konfigurationen).
     *   3. X-Forwarded-Proto      - NUR bei TRUST_PROXY=1.
     *
     * WARUM DER HEADER EINEN EIGENEN SCHALTER BRAUCHT
     * -----------------------------------------------
     * X-Forwarded-Proto kommt vom Aufrufer, solange kein Proxy davorsteht,
     * der ihn ueberschreibt. Wer ihn ungeprueft glaubt, laesst jeden
     * Aufrufer behaupten, seine Klartextverbindung sei sicher - die
     * Weiterleitung unterbleibt dann, und das Sitzungscookie geht ueber
     * http:// hinaus. Deshalb ist TRUST_PROXY aus, bis der Betreiber
     * bestaetigt, dass ein Reverse-Proxy oder Load-Balancer davorsteht, der
     * den Header setzt (und einen mitgeschickten ueberschreibt).
     *
     * Ohne den Schalter gibt es dafuer den umgekehrten Fehler: Hinter einem
     * Load-Balancer, der TLS terminiert, sieht PHP nur http:// - und leitet
     * auf https:// weiter, was der Balancer wieder als http:// weitergibt.
     * Das ist die Endlosschleife, die man in jedem zweiten Deployment sieht.
     *
     * @return bool
     */
    public static function istSicher(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string)$https) !== 'off') return true;

        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;

        if (Env::schalter('TRUST_PROXY', false)) {
            $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            // Eine Kette mehrerer Proxies haengt an: "https, http". Der ERSTE
            // Eintrag ist der des urspruenglichen Aufrufers.
            if ($proto !== '') {
                $erster = trim(explode(',', $proto)[0]);
                if ($erster === 'https') return true;
            }
            $ssl = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
            if ($ssl === 'on') return true;
        }

        return false;
    }

    /**
     * Soll auf HTTPS umgeleitet werden?
     *
     * @return bool
     */
    public static function erzwungen(): bool
    {
        // Vorgabe true: Wer den Schluessel nicht kennt, hat eine Installation,
        // die bisher umgeleitet hat - die soll das weiter tun.
        return Env::schalter('FORCE_HTTPS', true);
    }

    /**
     * Leitet eine Klartextanfrage auf HTTPS um und beendet den Request.
     *
     * Gerufen wird das in index.php GANZ AM ANFANG, vor der
     * Datenbankverbindung: Eine Anfrage, die nur umgeleitet wird, soll keine
     * Verbindung oeffnen.
     *
     * 301 UND NICHT 302: Die Umleitung ist dauerhaft gemeint. Der Browser
     * merkt sie sich und spart beim naechsten Mal den Klartext-Aufruf - der
     * kleine Bruder von HSTS, und der einzige Schutz, solange HSTS aus ist.
     *
     * @return void
     */
    public static function erzwingen(): void
    {
        // Auf der Kommandozeile gibt es nichts umzuleiten (Cronjob, Tests).
        if (PHP_SAPI === 'cli') return;

        if (!self::erzwungen() || self::istSicher()) return;

        $host = self::host();
        if ($host === null) {
            // Ohne verwertbaren Hostnamen gibt es kein Ziel. Das ist kein
            // Normalfall - es heisst, der Aufrufer hat einen kaputten
            // Host-Header geschickt.
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Ungueltiger Host.';
            exit;
        }

        $ziel = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $ziel, true, 301);
        exit;
    }

    /**
     * Der Wert des Strict-Transport-Security-Headers, oder null.
     *
     * NUR UEBER HTTPS. Ueber eine Klartextverbindung muss der Browser den
     * Header laut Spezifikation ignorieren; ihn trotzdem zu senden hiesse,
     * sich darauf zu verlassen, dass er ignoriert wird.
     *
     * @return string|null
     */
    public static function hstsHeader(): ?string
    {
        if (!self::istSicher()) return null;

        $maxAge = Env::zahl('HSTS_MAX_AGE', 0);
        if ($maxAge <= 0) return null;

        $header = 'max-age=' . $maxAge;

        if (Env::schalter('HSTS_INCLUDE_SUBDOMAINS', false)) {
            $header .= '; includeSubDomains';
        }

        // preload gibt es hier bewusst NICHT als Schalter. Die Vorabliste der
        // Browser nimmt eine Domain in Wochen auf und gibt sie in Monaten
        // wieder frei; wer dort hineinwill, traegt seine Domain selbst ein
        // und weiss dann auch, was er tut. Ein Schalter in einer .env, der
        // eine Domain fuer Monate festnagelt, waere zu leicht umgelegt.

        return $header;
    }

    /**
     * Der Hostname aus der Anfrage - geprueft, nicht geglaubt.
     *
     * Der Host-Header kommt vom Aufrufer. Fuer die Weiterleitung ist das
     * hinnehmbar (wer ihn faelscht, leitet nur sich selbst um), aber er geht
     * in einen Location-Header, und dort hat nichts zu suchen, was nicht wie
     * ein Hostname aussieht. Erlaubt sind Buchstaben, Ziffern, Punkt,
     * Bindestrich und ein Doppelpunkt mit Portnummer - mehr hat ein Host
     * nicht.
     *
     * @return string|null
     */
    private static function host(): ?string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '' || strlen($host) > 255) return null;
        if (!preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host)) return null;

        return $host;
    }
}
