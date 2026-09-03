<?php
namespace App\Controller;

use App\Model\Location;
use App\Model\User;
use App\Model\WebRTCHandler;
use App\Helper\Auth;
use App\Helper\Request;
use App\Helper\Permission;
use App\Helper\Role;

/**
 * WebRTCController – Steuert das Signaling für WebRTC-Verbindungen.
 * Übernimmt die Annahme und Verteilung von Offer/Answer/Candidate zwischen Nutzern.
 */
class WebRTCController
{
    /**
     * usertype.id des Guides.
     *
     * NICHT DAS KRITERIUM FUER EINEN ANRUF. Wer angerufen werden kann,
     * entscheidet das Recht location.offer (siehe offersLocations) - eine
     * Rollennummer wuerde jede kuenftige anbietende Rolle uebersehen.
     *
     * Die Konstante bleibt als Name stehen, weil die Rolle 'guide' im
     * Steuerprotokoll (PROTOKOLL.md) genau dieses Konto meint, und weil
     * tests/server_test.php darueber festhaelt, dass Signaling und
     * App\Helper\Role sich ueber die ID einig sind. Der Wert kommt aus
     * App\Helper\Role, der zentralen Stelle fuer Rollen.
     */
    public const USERTYPE_GUIDE = Role::GUIDE;

    /**
     * Die Rollen, die ein Call kennt - dieselben Zeichenketten wie in
     * assets/js/protocol.js und PROTOKOLL.md.
     *
     * Sie stehen als Konstanten da, damit jede von ihnen im PHP-Teil an genau
     * einer Stelle geschrieben ist: Ein Tippfehler waere sonst eine Rolle, die
     * der Client nicht kennt - und der verwirft eine unbekannte Rolle zu null,
     * also zu "niemand steuert".
     */
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_GUIDE  = 'guide';
    public const ROLE_PEER   = 'peer';

    /**
     * Handhabt Signalisierung für WebRTC.
     * - POST: Neue Nachricht abspeichern (offer, answer, candidate)
     * - GET:  Nachrichten für diesen Empfänger abrufen
     *
     * @return void
     */
    public function getSignal()
    {
        try {
            // POST = Neue Nachricht abspeichern (z.B. offer, answer, candidate)
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);

                if (isset($data['type']) && isset($data['target'])) {
                    $sender    = Auth::userId();
                    $type      = $data['type'];
                    $target    = $data['target'];
                    $sdp       = $data['sdp'] ?? null;
                    $candidate = isset($data['candidate']) ?
                        (is_array($data['candidate']) ? json_encode($data['candidate']) : $data['candidate'])
                        : null;

                    // WOHER KAM DER ANRUF? Die Standortkennung schickt der
                    // Anrufer mit dem Offer; fehlt sie, ist es ein Direktanruf
                    // (Benutzerverwaltung). Sie ist eine BEHAUPTUNG des
                    // Clients und wird als solche behandelt: callRoles()
                    // prueft, ob es den Standort gibt, ob er dem Angerufenen
                    // gehoert und ob er nicht gesperrt ist. Wer eine fremde
                    // Kennung mitschickt, kommt damit nirgends hin.
                    $location  = isset($data['location']) ? (int)$data['location'] : 0;
                    if ($location < 1) $location = null;

                    // DER ANRUF WIRD HIER ZUGELASSEN ODER GAR NICHT.
                    //
                    // Ein Offer erreicht den Angerufenen nur, wenn sich fuer
                    // das Paar ueberhaupt Rollen vergeben lassen (siehe
                    // callRoles): also wenn der Anruf von einem Standort des
                    // Angerufenen ausgeht, wenn der Angerufene Standorte
                    // anbietet oder wenn einer der beiden ein Admin ist.
                    //
                    // Frueher wurde jedes Offer gespeichert und der
                    // Empfaenger dabei zum Guide erklaert - also durfte ein
                    // Anruf ueber eine Rolle entscheiden, der der Betroffene
                    // nie zugestimmt hatte. Abgewiesen wird VOR dem
                    // Speichern: Ein liegengebliebenes Offer wuerde beim
                    // naechsten Poll trotzdem klingeln.
                    //
                    // Nur 'offer' wird geprueft. Answer, Kandidaten, Hangup
                    // und die Restart-Nachrichten gehoeren zu einem Call, der
                    // diese Pruefung bereits bestanden hat.
                    if ($type === 'offer' && !self::callAllowed($sender, $target, $location)) {
                        error_log('WebRTCController: Anruf abgewiesen - Benutzer #'
                            . (int)$target . ' bietet nichts an oder steht nicht auf'
                            . ' bereit, der Anrufer #'
                            . (int)$sender . ' ist kein Admin, und der Anruf kam'
                            . ' von keinem gueltigen Standort ('
                            . ($location === null ? 'ohne Angabe' : '#' . $location) . ').');
                        echo json_encode([
                            'status' => 'error',
                            'msg'    => self::callRejectedMessage($target),
                        ]);
                        exit;
                    }

                    $rtc_handler = new WebRTCHandler();
                    $rtc_handler->setSender($sender);
                    $rtc_handler->setReceiver($target);
                    $rtc_handler->setType($type);
                    $rtc_handler->setSdp($sdp);
                    $rtc_handler->setCandidate($candidate);
                    // Nur am Offer. Alle uebrigen Signaltypen gehoeren zu
                    // einem Call, dessen Rollen laengst feststehen.
                    $rtc_handler->setLocationId($type === 'offer' ? $location : null);
                    $rtc_handler->create();

                    $response = ['status' => 'ok'];
                    // Beim Anruf legt der Server die Rollen fest und gibt dem
                    // Anrufer seine eigene direkt zurueck. Sie haengt damit am
                    // Offer statt an einer zweiten Anfrage: kein Zeitfenster,
                    // in dem ein Client ohne Rolle dasteht, und keine
                    // Gelegenheit, sich selbst eine zuzuweisen.
                    if ($type === 'offer') {
                        $response['role'] = self::roleForCall($sender, $target, $sender, $location);
                    }

                    echo json_encode($response);
                    exit;
                }
            }

            // GET oder sonst: Nachrichten abrufen (alle für diesen Empfänger)
            if (Auth::isLoggedIn()) {
                $receiver = Auth::userId();
                $rtc_handler = new WebRTCHandler();
                $messages = $rtc_handler->getAllSignalsForReceiver($receiver);

                // Genau die IDs merken, die dieser SELECT gelesen hat. Nur die
                // dürfen gelöscht werden - ein Signal, das zwischen SELECT und
                // DELETE eintrifft, muss liegen bleiben und wird beim nächsten
                // Poll ausgeliefert (Befund F-1).
                $delivered_ids = [];
                foreach ($messages as $msg) {
                    if (isset($msg['id'])) {
                        $delivered_ids[] = $msg['id'];
                    }
                }

                $filtered_messages = self::signalMessageFilter($messages);
                // Der Angerufene bekommt seine Rolle am ausgelieferten Offer.
                $filtered_messages = self::stampCallRoles($filtered_messages, $receiver);

                // Gelöscht wird die gesamte gelesene Menge, also auch die vom
                // Filter verworfenen kaputten Kandidaten: Die sind ausgewertet
                // und würden sonst bei jedem Poll erneut geliefert.
                $rtc_handler->deleteSignalsByIds($receiver, $delivered_ids);

                // Zeilen, die das 15-Sekunden-Lesefenster nie erreicht haben,
                // separat aufräumen, damit die Tabelle nicht wächst.
                $rtc_handler->deleteExpiredSignalsForReceiver($receiver);

                echo json_encode($filtered_messages);
                exit;
            }

            // Weder gültiger POST noch angemeldeter Empfänger: Bisher endete die
            // Methode hier ohne jede Ausgabe (HTTP 200, leerer Body) und der
            // Client warf beim Parsen. Jetzt gibt es eine auswertbare Antwort.
            echo json_encode(['status' => 'error', 'msg' => 'Ungültige Signaling-Anfrage.']);
            exit;
        } catch (\Exception $e) {
            // Interne Details nur ins Log, nicht in den Browser.
            error_log('WebRTCController::getSignal: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'msg' => 'Signaling-Fehler.']);
            exit;
        }
    }

    /**
     * Legt die Rollen für einen Call fest.
     *
     * Die Rolle gilt nur für diesen einen Call und wird nicht gespeichert.
     * Grundlage ist ausschließlich, was der Server ohnehin weiß: wer angerufen
     * hat und welchen Kontotyp die beiden haben. Der Client hat darauf keinen
     * Einfluss.
     *
     * ES GIBT DREI AUSGÄNGE
     * ---------------------
     *   null            Der Anruf kommt gar nicht zustande.
     *   viewer/guide    Eine Führung: Der Anrufer schaut zu und steuert, der
     *                   Angerufene ist vor Ort und wird gesteuert.
     *   peer/peer       Ein Gespräch unter Gleichen. Niemand führt, niemand
     *                   steuert, beide senden Ton und Bild.
     *
     * VORGESCHALTET: OHNE BEREITSCHAFT KEINE FÜHRUNG.
     *
     *    Beide Wege in eine Führung - über einen Standort (1) und ohne
     *    Standort an einen Guide (3) - setzen voraus, dass der Angerufene
     *    sich ausdrücklich auf "bereit" gestellt hat und die Frist noch
     *    läuft (siehe readyToGuide). Angemeldet zu sein genügt nicht: Ein
     *    offener Tab ist keine Zusage, jemanden herumzuführen. Genau hier
     *    endete das alte Verhalten, bei dem ein über Nacht offener Browser
     *    einen Guide anrufbar hielt.
     *
     *    Der Direktanruf der Verwaltung (2) bleibt davon unberührt: Für eine
     *    Rückfrage der Moderation muss sich niemand bereit gemeldet haben,
     *    und geführt wird dabei ohnehin nicht.
     *
     * ENTSCHEIDEND IST, WOHER DER ANRUF KAM. Eine Führung beginnt an einem
     * Standort: Der Zuschauer sucht sich auf der Karte oder in der Liste
     * einen Ort aus und ruft den an, der dort ist. Ein Direktanruf aus der
     * Benutzerverwaltung meint etwas anderes - dort steht kein Ort, sondern
     * eine Person.
     *
     * 1. GEHT DER ANRUF VON EINEM STANDORT AUS, FÜHRT DER ANGERUFENE.
     *
     *    Ohne Ausnahme, auch wenn er Admin ist: Wer einen Standort anbietet,
     *    lässt sich dort steuern - dafür steht das Angebot auf der Karte.
     *    Geprüft wird der Standort, nicht die Behauptung des Anrufers, siehe
     *    guidedFromLocation().
     *
     * 2. OHNE STANDORT UND MIT EINEM ADMIN IST ES KEINE FÜHRUNG.
     *
     *    Ein Direktanruf mit der Verwaltung hat einen anderen Zweck:
     *    Rückfragen, Unterstützung, Moderation. Dort gibt es nichts zu
     *    steuern, und beide Seiten sollen einander sehen und hören. Deshalb
     *    bekommen beide die Rolle "peer" - und weil "peer" weder "viewer"
     *    noch "guide" ist, weist die Protokollprüfung Bewegungsbefehle,
     *    Bestätigungen und die Steuerungssperre in einem solchen Call von
     *    selbst ab (siehe assets/js/protocol.js, validate).
     *
     *    Der Admin kann in diesem Fall auch anrufen, ohne dass der
     *    Angerufene Standorte anbieten muss - genau das verspricht der Knopf
     *    "Anrufen" in der Benutzerliste, und genau daran scheiterte er
     *    bisher.
     *
     * 3. SONST MUSS DER ANGERUFENE STANDORTE ANBIETEN DÜRFEN (Recht
     *    location.offer). Dann ist er der Guide und der Anrufer der
     *    Zuschauer. Das ist der Fall "Anruf ohne Standortangabe an einen
     *    Guide" - er bleibt eine Führung, wie er es immer war.
     *
     *    Gefragt wird das Recht und nicht die Rolle - das ist dasselbe
     *    Kriterium, über das ein Standort überhaupt erst auf die Karte kommt.
     *    Wer dort steht, ist anrufbar; wer nicht anbieten darf, ist es nicht.
     *    Ein Rollenvergleich hätte eine künftige anbietende Rolle
     *    stillschweigend übergangen.
     *
     * Vorher fiel die Vergabe auf "im Zweifel ist der Angerufene der Guide"
     * zurück, wenn keiner der beiden ein Guide war. Damit genügte ein Anruf,
     * um ein beliebiges Konto zum Guide zu erklären - ohne dass der
     * Betroffene der Rolle je zugestimmt hätte, und mit einem Steuerkreuz auf
     * der Gegenseite, das ihn herumschickt. Die Guide-Rolle ist eine
     * ausdrückliche Entscheidung (App\Model\GuideRole); ein Anruf ist keine.
     *
     * Wer nichts anbietet, wird deshalb nicht zum Guide erklärt - der Anruf
     * kommt gar nicht erst zustande (siehe getSignal, Zweig 'offer').
     *
     * Beide Seiten rufen diese Funktion mit demselben Paar (Anrufer,
     * Angerufener) auf und bekommen deshalb zwingend zueinander passende
     * Rollen.
     *
     * @param int      $callerId   Wer angerufen hat
     * @param int      $calleeId   Wer angerufen wurde
     * @param int|null $locationId Standort, von dem der Anruf ausging;
     *                             null = Direktanruf ohne Standort
     * @return array|null ['caller' => 'viewer', 'callee' => 'guide'],
     *                    ['caller' => 'peer', 'callee' => 'peer'] oder null,
     *                    wenn der Anruf nicht zustande kommt
     */
    public static function callRoles($callerId, $calleeId, $locationId = null)
    {
        $fuehrung = ['caller' => self::ROLE_VIEWER, 'callee' => self::ROLE_GUIDE];

        // OHNE BEREITSCHAFT KEINE FUEHRUNG - egal auf welchem der beiden Wege
        // sie zustande kaeme. Das ist die eigentliche Sperre: Ein Standort,
        // dessen Guide nicht auf bereit steht, ist ein Angebot ohne Guide,
        // und wer trotzdem darauf anruft (eine veraltete Kartenansicht, ein
        // nachgebauter Aufruf), kommt hier nicht durch.
        $bereit = self::readyToGuide($calleeId);

        if ($bereit && self::guidedFromLocation($locationId, $calleeId)) return $fuehrung;

        if (self::isAdminAccount($callerId) || self::isAdminAccount($calleeId)) {
            return ['caller' => self::ROLE_PEER, 'callee' => self::ROLE_PEER];
        }

        if (!$bereit) return null;

        if (!self::offersLocations($calleeId)) return null;

        return $fuehrung;
    }

    /**
     * Steht dieses Konto gerade auf "bereit"?
     *
     * Die Bereitschaft ist eine ausdrueckliche Entscheidung des Guides und
     * laeuft nach einer Frist ohne Bedienung von selbst ab
     * (App\Model\User, config/presence.php). Sie ist damit etwas anderes als
     * die Anmeldung: Ein offener Tab sagt nichts darueber, ob jemand fuehren
     * will - und genau diese Verwechslung wurde hier aufgeloest.
     *
     * NICHT GEPRUEFT WIRD DER ONLINE-STATUS. Wer ein Offer schickt, hat den
     * Angerufenen auf der Karte gruen gesehen, und dort steht er nur, wenn
     * auch der Heartbeat frisch ist (App\Model\Location::AVAILABILITY_SQL).
     * Zwischen Klick und Offer koennte der Heartbeat einen Takt aussetzen -
     * daran soll ein Anruf nicht scheitern; der Klingelton laeuft ohnehin ins
     * Leere, wenn wirklich niemand da ist.
     *
     * Der Zwischenspeicher lebt nur fuer die Dauer der Anfrage. Ohne ihn
     * waeren es je Offer bis zu drei gleiche SELECTs: aus callAllowed(), aus
     * roleForCall() und aus der Meldung im Ablehnungsfall.
     *
     * @param int $userId
     * @return bool
     */
    private static function readyToGuide($userId)
    {
        static $bekannt = [];

        $id = (int)$userId;
        if ($id < 1) return false;

        if (!array_key_exists($id, $bekannt)) {
            $bekannt[$id] = User::isAvailable($id);
        }
        return $bekannt[$id];
    }

    /**
     * Warum kam dieser Anruf nicht zustande?
     *
     * Nur fuer die Meldung an den Anrufer. Die Entscheidung selbst faellt in
     * callRoles(); hier wird sie lediglich in einen Satz uebersetzt, der dem
     * Anrufer weiterhilft.
     *
     * Der Unterschied ist fuer ihn wesentlich: "bietet nichts an" heisst
     * "such dir jemand anderen", "gerade nicht bereit" heisst "versuch es
     * spaeter noch einmal". Vorher bekam er in beiden Faellen denselben Satz.
     *
     * Verraten wird damit nichts, was nicht ohnehin auf der Karte steht: Dass
     * an einem Standort gerade kein Guide bereit ist, ist genau die Auskunft,
     * die die graue Nadel gibt.
     *
     * @param int $calleeId
     * @return string
     */
    private static function callRejectedMessage($calleeId)
    {
        if (self::offersLocations($calleeId) && !self::readyToGuide($calleeId)) {
            return 'Dieser Guide ist gerade nicht bereit für eine Führung. '
                 . 'Bitte versuchen Sie es später noch einmal.';
        }

        return 'Dieser Benutzer bietet keine Führungen an und kann '
             . 'deshalb nicht angerufen werden.';
    }

    /**
     * Führt der Angerufene an diesem Standort?
     *
     * Die Standortkennung kommt vom Anrufer und ist damit eine BEHAUPTUNG.
     * Sie wird deshalb an drei Bedingungen geprüft, und alle drei müssen
     * halten:
     *
     *   1. Den Standort gibt es.
     *   2. Er gehört dem Angerufenen. Sonst könnte jeder Anrufer eine
     *      beliebige fremde Kennung mitschicken und damit eine Führung
     *      erzwingen - samt Steuerkreuz auf jemanden, der davon nichts weiß.
     *   3. Er ist nicht gesperrt. Ein gesperrter Standort ist aus der
     *      Übersicht genommen; über ihn beginnt keine Führung mehr.
     *
     * Dazu kommt das Recht location.offer: Wer nicht anbieten darf, führt
     * auch nicht - selbst wenn noch ein alter Standort auf seinen Namen läuft.
     *
     * @param int|null $locationId
     * @param int      $calleeId
     * @return bool
     */
    private static function guidedFromLocation($locationId, $calleeId)
    {
        // Dieselbe Frage kommt je Offer zweimal: einmal aus callAllowed(),
        // einmal aus roleForCall(). Ohne diesen Zwischenspeicher waeren das
        // zwei SELECTs auf dieselbe Zeile. Er lebt nur fuer die Dauer der
        // Anfrage - siehe roleIdOf().
        static $bekannt = [];

        $id     = (int)$locationId;
        $callee = (int)$calleeId;
        if ($id < 1 || $callee < 1) return false;

        $schluessel = $id . ':' . $callee;
        if (array_key_exists($schluessel, $bekannt)) return $bekannt[$schluessel];

        $bekannt[$schluessel] = self::checkGuidedFromLocation($id, $callee);
        return $bekannt[$schluessel];
    }

    /**
     * Die eigentliche Pruefung hinter guidedFromLocation().
     *
     * @param int $locationId
     * @param int $calleeId
     * @return bool
     */
    private static function checkGuidedFromLocation($locationId, $calleeId)
    {
        if (!self::offersLocations($calleeId)) return false;

        try {
            $location = new Location($locationId);
        } catch (\Exception $e) {
            // Kein Fehlerfall, sondern eine Antwort: Den Standort gibt es
            // nicht (mehr). Der Anruf faellt auf die uebrigen Regeln zurueck.
            error_log('WebRTCController::checkGuidedFromLocation: ' . $e->getMessage());
            return false;
        }

        return $location->belongsToUser($calleeId) && !$location->isBlocked();
    }

    /**
     * Darf dieser Anruf zustande kommen?
     *
     * Genau dann, wenn sich für das Paar Rollen vergeben lassen - also wenn
     * ein Admin beteiligt ist oder der Angerufene Standorte anbieten darf.
     * Die Frage steht bewusst neben callRoles() und wird nicht daneben
     * nachgebaut: Es gibt eine Bedingung, und sie steht an einer Stelle.
     *
     * @param int      $callerId   Wer angerufen hat
     * @param int      $calleeId   Wer angerufen wurde
     * @param int|null $locationId Standort, von dem der Anruf ausging
     * @return bool
     */
    public static function callAllowed($callerId, $calleeId, $locationId = null)
    {
        return self::callRoles($callerId, $calleeId, $locationId) !== null;
    }

    /**
     * Rolle eines bestimmten Teilnehmers in diesem Call.
     *
     * @param int      $callerId   Wer angerufen hat
     * @param int      $calleeId   Wer angerufen wurde
     * @param int      $userId     Für wen die Rolle gesucht ist
     * @param int|null $locationId Standort, von dem der Anruf ausging
     * @return string|null 'viewer', 'guide', 'peer' oder null - wenn der
     *                     Nutzer nicht beteiligt ist ODER der Anruf gar nicht
     *                     zulässig war
     */
    public static function roleForCall($callerId, $calleeId, $userId, $locationId = null)
    {
        $roles = self::callRoles($callerId, $calleeId, $locationId);
        if ($roles === null) return null;
        if ((int)$userId === (int)$callerId) return $roles['caller'];
        if ((int)$userId === (int)$calleeId) return $roles['callee'];
        return null;
    }

    /**
     * Hängt an jedes ausgelieferte Offer die Rolle des Empfängers.
     *
     * Der Empfänger eines Offers ist immer der Angerufene, der Absender immer
     * der Anrufer - damit steht das Paar fest, ohne dass der Client etwas
     * dazu beitragen müsste.
     *
     * Bleibt das Feld null, hat der Empfänger die Guide-Rolle zwischen dem
     * Anruf und diesem Abruf zurückgegeben. Dann steuert in diesem Call
     * niemand - der Client wertet ein fehlendes "role" als unbekannt aus.
     *
     * DER STANDORT KOMMT AUS DER ZEILE, nicht aus der Anfrage. Der Anrufer
     * hat ihn mit seinem Offer geschickt und der Server ihn an der Zeile
     * abgelegt (Migration 009); der Angerufene holt sein Offer Sekunden
     * später ab und hätte die Angabe sonst nicht mehr. Ohne sie kämen die
     * beiden Seiten zu verschiedenen Rollen - genau das darf nicht passieren.
     *
     * Ausgeliefert wird die Kennung NICHT: Der Client hat mit ihr nichts zu
     * tun, sie ist eine Angelegenheit zwischen Offer und Rollenvergabe.
     *
     * @param array $messages   Bereits gefilterte Signalnachrichten
     * @param int   $receiverId Empfänger, der gerade abfragt
     * @return array Nachrichten mit ergänztem Feld "role" bei Offers
     */
    public static function stampCallRoles($messages, $receiverId)
    {
        foreach ($messages as $index => $msg) {
            unset($messages[$index]['location_id']);
            if (!isset($msg['type']) || $msg['type'] !== 'offer') continue;
            if (!isset($msg['sender_id'])) continue;
            $messages[$index]['role'] = self::roleForCall(
                $msg['sender_id'], $receiverId, $receiverId, $msg['location_id'] ?? null
            );
        }
        return $messages;
    }

    /**
     * Ist dieses Konto ein Admin?
     *
     * GEFRAGT WIRD HIER AUSNAHMSWEISE DIE ROLLE UND NICHT EIN RECHT. Es geht
     * nicht darum, was jemand DARF, sondern darum, WORUM ES IN DIESEM ANRUF
     * GEHT: Ein Gespraech mit der Verwaltung ist keine Fuehrung. Dafuer gibt
     * es kein benanntes Recht, das man fragen koennte - es ist die Rolle
     * selbst.
     *
     * Ein unbekanntes oder nicht ladbares Konto gilt nicht als Admin. Der
     * Anruf faellt dann auf die Fuehrungsregel zurueck und wird dort
     * abgewiesen, statt jemandem stillschweigend einen Admin-Anruf zu
     * gewaehren.
     *
     * @param int $userId
     * @return bool
     */
    private static function isAdminAccount($userId)
    {
        $role = self::roleIdOf($userId);
        return ($role === null) ? false : Role::isAdmin($role);
    }

    /**
     * Die Rollennummer eines Kontos - hoechstens einmal je Anfrage geladen.
     *
     * Eine einzige Rollenvergabe fragt bis zu drei Mal nach einem Konto
     * (Admin? Anrufer, Admin? Angerufener, bietet der Angerufene an?), und
     * stampCallRoles() ruft sie fuer jedes ausgelieferte Offer erneut auf.
     * Ohne diesen Zwischenspeicher waeren das ebenso viele SELECTs auf
     * dieselbe Zeile.
     *
     * Der Speicher lebt nur fuer die Dauer der Anfrage - PHP baut ihn bei
     * jedem Aufruf neu auf. Eine Rollenaenderung wirkt also spaetestens beim
     * naechsten Klick.
     *
     * @param int $userId
     * @return int|null Rollennummer oder null, wenn das Konto nicht ladbar ist
     */
    private static function roleIdOf($userId)
    {
        static $bekannt = [];

        $id = (int)$userId;
        if ($id < 1) return null;
        if (array_key_exists($id, $bekannt)) return $bekannt[$id];

        try {
            $user = new User($id);
            $bekannt[$id] = Role::id($user->getRoleId());
        } catch (\Exception $e) {
            error_log('WebRTCController::roleIdOf: ' . $e->getMessage());
            $bekannt[$id] = null;
        }
        return $bekannt[$id];
    }

    /**
     * Darf dieses Konto Standorte anbieten - und ist damit anrufbar?
     *
     * GEFRAGT WIRD DAS RECHT location.offer, NICHT DIE ROLLE. Anrufbar ist,
     * wer ein Angebot auf der Karte stehen haben darf. Ein Rollenvergleich
     * (Role::isGuide) haette eine kuenftige Rolle, die Standorte anbietet,
     * ohne "Guide" zu heissen, still uebergangen.
     *
     * Damit steht die Bedingung an genau einer Stelle: Wer in
     * App\Helper\Permission location.offer bekommt, ist anrufbar - ohne
     * dass hier etwas nachzuziehen waere. Ob daraus eine FUEHRUNG wird,
     * entscheidet callRoles(): Ist ein Admin beteiligt, nicht.
     *
     * Ein unbekannter oder nicht ladbarer Benutzer ist nicht anrufbar. Der
     * Anruf wird dann abgewiesen, statt jemanden zum Guide zu erklaeren, ueber
     * den nichts bekannt ist.
     *
     * @param int $userId
     * @return bool
     */
    private static function offersLocations($userId)
    {
        $role = self::roleIdOf($userId);
        return ($role === null) ? false : Permission::has($role, Permission::LOCATION_OFFER);
    }

    /**
     * Filtert Nachrichten, damit keine leeren oder defekten ICE-Kandidaten ausgeliefert werden.
     *
     * @param array $messages
     * @return array
     */
    public static function signalMessageFilter($messages)
    {
        $filteredMessages = [];
        foreach ($messages as $msg) {
            if ($msg['type'] === 'iceCandidate') {
                if (empty($msg['candidate'])) continue;
                $msg['candidate'] = json_decode($msg['candidate'], true);
                if (empty($msg['candidate']) || empty($msg['candidate']['candidate'])) continue;
            }
            $filteredMessages[] = $msg;
        }
        return $filteredMessages;
    }
}
