<?php
namespace App\Model;

/**
 * Model-Klasse für Chat-Sitzungen zwischen zwei Benutzern.
 *
 * EIN CHAT ENTSTEHT UEBER EINEN STANDORT (Migration 019)
 * -----------------------------------------------------
 * Das ist die Regel, die diese Klasse traegt und die den frueheren Befund
 * N-12 abloest: Vorher nahm die Route chat_start eine beliebige Kontokennung
 * entgegen - wer sie kannte, konnte jedem Konto der Plattform eine Nachricht
 * ins Postfach legen. Jetzt sagt der STANDORT, wer das Gegenueber ist: Ein
 * Kunde schreibt den Guide von dessen Standortseite aus an, und die Kennung
 * des Guides holt der Server sich selbst aus dem Standort.
 *
 * Die Spalte location_id traegt die HERKUNFT und nicht das Thema. Sie
 * beantwortet die Frage, warum diese beiden Konten miteinander reden duerfen.
 * NULL heisst "ohne Standort": ein Direktchat des Admins (Recht
 * chat.start_direct) oder ein Chat aus der Zeit vor Migration 019.
 *
 * EIN CHAT JE PAAR, NICHT JE STANDORT. Bietet derselbe Guide drei Standorte
 * an und fragt derselbe Kunde zu allen dreien, bleibt es EIN Gespraech - sonst
 * haette der Kunde drei Fenster mit demselben Menschen und muesste raten, in
 * welchem er zuletzt geschrieben hat.
 *
 * WAS HIER NICHT MEHR STEHT: DIE EINLADUNG
 * ----------------------------------------
 * setActive(), checkIfActive() und getInvitations() sind mit den Spalten
 * is_active und pending_for entfallen (Migration 019). Ein Chat war vorher
 * erst eine Einladung ("X moechte mit Ihnen chatten") und wurde durch
 * Annehmen zum Gespraech; erst danach gab es ein Eingabefeld.
 *
 * Diese Mechanik stammt aus einer Anwendung, in der jeder jeden anschreiben
 * konnte - dort ist sie der Schutz vor Fremden. Hier gibt es diesen Fremden
 * nicht mehr: Ein Chat entsteht nur zwischen einem Kunden und dem Guide eines
 * Standorts, den dieser Guide selbst oeffentlich angeboten hat. Wer Standorte
 * anbietet, will Rueckfragen bekommen.
 *
 * Und sie blockierte ausgerechnet das, was sie schuetzen sollte: Der Guide
 * entschied ueber einen blossen Namen, ohne zu wissen, worum es geht. Die
 * Begruendung im Ganzen steht in migrations/019_standort_chat.sql.
 */
class Chat
{
    private $id;
    private $user1_id;
    private $user2_id;
    private $location_id;
    private $last_msg_at;
    private $deleted;

    /**
     * Konstruktor: Erstellt ein Chat-Objekt aus einem Daten-Array (DB-Row).
     * @param array $data Assoziatives Array mit Chat-Daten
     */
    public function __construct(array $data)
    {
        $this->id          = $data['id'         ] ?? null;
        $this->user1_id    = $data['user1_id'   ] ?? null;
        $this->user2_id    = $data['user2_id'   ] ?? null;
        $this->location_id = $data['location_id'] ?? null;
        $this->last_msg_at = $data['last_msg_at'] ?? null;
        $this->deleted     = $data['deleted'    ] ?? null;
    }

    /**
     * Sucht den Chat zweier Konten und legt ihn an, wenn es ihn noch nicht
     * gibt.
     *
     * DIESE METHODE ENTSCHEIDET NICHTS. Ob die beiden ueberhaupt miteinander
     * reden duerfen, ist vorher entschieden - im Controller, der die Kennung
     * des Gegenuebers aus dem Standort holt statt aus der Anfrage
     * (App\Controller\ChatController::startChat). Wer hier eine zweite
     * Pruefung erwartet, sucht sie an der falschen Stelle.
     *
     * DIE REIHENFOLGE DER BEIDEN KENNUNGEN wird sortiert abgelegt, damit ein
     * Chat unabhaengig davon gefunden wird, wer ihn gerade oeffnet.
     *
     * EIN BEENDETER CHAT WIRD WIEDERBELEBT statt verdoppelt: Der Verlauf
     * gehoert den beiden Beteiligten und faengt nicht bei null an, nur weil
     * einer von ihnen das Fenster einmal weggeraeumt hat.
     *
     * DIE HERKUNFT WIRD NICHT UEBERSCHRIEBEN. Sie sagt, wie der Kontakt
     * ZUSTANDE GEKOMMEN ist; ein spaeterer Standort aendert daran nichts.
     * Gesetzt wird sie deshalb nur, wenn noch keine da ist - das trifft die
     * alten Chats aus der Zeit vor Migration 019 und die Direktchats des
     * Admins, sobald derselbe Kunde spaeter ueber einen Standort schreibt.
     *
     * @param int      $user1_id
     * @param int      $user2_id
     * @param int|null $location_id Der Standort, ueber den der Kontakt
     *                 entsteht; null beim Direktchat des Admins
     * @return Chat|null Gibt das Chat-Objekt oder null bei Fehler zurück
     */
    public static function findOrCreate(int $user1_id, int $user2_id, ?int $location_id = null): Chat|null
    {
        $ids = [$user1_id, $user2_id];
        sort($ids);

        $standort = ($location_id !== null && $location_id > 0) ? $location_id : null;

        try {
            // 1. Gibt es schon einen Chat, egal ob deleted oder nicht?
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM chat WHERE user1_id = ? AND user2_id = ? LIMIT 1"
            );
            $stmt->execute([$ids[0], $ids[1]]);
            $chat = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($chat) {
                // Beendet? Dann wieder aufnehmen. Und wenn die Herkunft noch
                // fehlt, wird sie jetzt nachgetragen - beides in EINEM UPDATE,
                // damit kein Zwischenzustand entsteht.
                $neueHerkunft = ($chat['location_id'] ?? null) === null ? $standort : null;

                if ($chat['deleted'] || $neueHerkunft !== null) {
                    $stmt2 = PdoConnect::$connection->prepare(
                        "UPDATE chat SET deleted = 0,
                                location_id = COALESCE(location_id, ?)
                          WHERE id = ?"
                    );
                    $stmt2->execute([$neueHerkunft, $chat['id']]);
                    $chat['deleted']     = 0;
                    $chat['location_id'] = $chat['location_id'] ?? $neueHerkunft;
                }
                return new self($chat);
            }

            // Kein Chat vorhanden: Lege neuen an.
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO chat (user1_id, user2_id, location_id, last_msg_at, deleted)
                 VALUES (?, ?, ?, NULL, 0)"
            );
            $stmt->execute([$ids[0], $ids[1], $standort]);
            $chat_id = PdoConnect::$connection->lastInsertId();
            return new self([
                "id"          => $chat_id,
                "user1_id"    => $ids[0],
                "user2_id"    => $ids[1],
                "location_id" => $standort,
                "last_msg_at" => null,
                "deleted"     => 0
            ]);
        } catch (\PDOException $e) {
            error_log('Fehler in Chat::findOrCreate: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Setzt das Chat-Objekt und den DB-Eintrag auf gelöscht (deleted = 1).
     *
     * EIN SOFT-DELETE UND KEINE SPERRE: Der Verlauf bleibt stehen und unter
     * "Alle Chats" lesbar, und die naechste Nachricht belebt das Gespraech
     * wieder (findOrCreate). Es ist das Wegraeumen einer erledigten
     * Unterhaltung, nicht "diese Person nicht mehr".
     *
     * @return bool
     */
    public function delete(): bool
    {
        try {
            $stmt = PdoConnect::$connection->prepare("UPDATE chat SET deleted = 1 WHERE id = ?");
            $stmt->execute([$this->id]);
            $this->deleted = 1;
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler in Chat::delete: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt alle Chats zurück, an denen der User beteiligt ist, sortiert nach letztem Nachrichtenzeitpunkt.
     *
     * @param int $user_id
     * @param bool :optional ($includeDeleted) standard false
     * @return Chat[] Array von Chat-Objekten
     */
    public static function getAllForUser(int $user_id, bool $includeDeleted = false): array
    {
        $where = $includeDeleted ? '' : 'AND deleted = 0';
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM chat WHERE (user1_id = ? OR user2_id = ?) $where ORDER BY last_msg_at DESC"
        );
        $stmt->execute([$user_id, $user_id]);
        $chats = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $chats[] = new self($row);
        }
        return $chats;
    }

    /**
     * Sucht einen Chat anhand der ID.
     *
     * @param int $chatId
     * @return Chat|null Gibt das Chat-Objekt oder null zurück
     */
    public static function findById(int $chatId, bool $includeDeleted = false): ?Chat
    {
        $where = $includeDeleted ? '' : 'AND deleted = 0';
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE id = ? $where");
        $stmt->execute([$chatId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Was im Zaehler der Kopfleiste steht.
     *
     * WOZU UEBERHAUPT
     * ---------------
     * Weil eine Nachricht sonst verlorengeht. Der Guide bekam sie bisher nur
     * dann zu sehen, wenn zufaellig ein Chatfenster offen war - die Fenster
     * baut assets/js/ui_chat.js, und wer die Seite gewechselt hat, fing von
     * vorne an. Genau dieselbe Ueberlegung wie beim Anfragenzaehler
     * (App\Model\TourRequest::counters): Was auf jemanden wartet, muss an
     * einer Stelle wieder auftauchen, die er ohnehin ansteuert.
     *
     * EINE ZAHL, EINE BEDEUTUNG: ungelesene Nachrichten, ueber alle nicht
     * beendeten Chats hinweg. Sie zaehlt Nachrichten und keine Gespraeche -
     * "drei ungelesene" sagt mehr als "in einem Chat wartet etwas".
     *
     * SIE FAEHRT AUF DEM HEARTBEAT MIT (App\Controller\UserController) und
     * bekommt keine eigene Schleife: Der Takt laeuft ohnehin.
     *
     * @param int $in_user_id
     * @return array{unread:int}
     */
    public static function counters($in_user_id): array
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return ['unread' => 0];

        return ['unread' => ChatMessage::countUnseenTotal($user_id)];
    }

    // Getter-Methoden für die wichtigsten Eigenschaften
    public function getId(): int          { return $this->id;               }
    public function getUser1Id(): int     { return $this->user1_id;         }
    public function getUser2Id(): int     { return $this->user2_id;         }
    public function isDeleted(): bool     { return (bool)$this->deleted;    }
    public function getLastMsgAt()        { return $this->last_msg_at;      }
    /** Der Standort, ueber den der Chat entstanden ist - null heisst "ohne". */
    public function getLocationId()       { return $this->location_id === null
                                                    ? null : (int)$this->location_id; }

    /**
     * Ist dieses Konto an dem Chat beteiligt?
     *
     * DIE EINE FASSUNG DIESER FRAGE. Sechs Stellen im ChatController stellen
     * sie (Befund S-1); stuende sie sechsmal ausgeschrieben da, waere die
     * siebte die, die beim naechsten Ergaenzen vergessen wird.
     *
     * @param int $in_user_id
     * @return bool
     */
    public function hatTeilnehmer($in_user_id): bool
    {
        $user_id = (int)$in_user_id;
        return $user_id > 0
            && ((int)$this->user1_id === $user_id || (int)$this->user2_id === $user_id);
    }

    /**
     * Der jeweils andere - aus Sicht des Angemeldeten.
     *
     * @param int $in_user_id
     * @return int
     */
    public function partnerVon($in_user_id): int
    {
        return ((int)$this->user1_id === (int)$in_user_id)
            ? (int)$this->user2_id
            : (int)$this->user1_id;
    }
}
