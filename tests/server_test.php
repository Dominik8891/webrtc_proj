<?php
/**
 * Prüft die Serverlogik rund um das Signaling und die ICE-Konfiguration -
 * ohne Datenbank und ohne Netzwerk. Die PDO-Verbindung wird durch eine
 * Attrappe ersetzt, die die abgesetzten Statements nur mitschreibt.
 *
 * Ausführen:  php tests/server_test.php
 * Siehe tests/README.md.
 */
$ROOT = __DIR__ . '/..';
require_once $ROOT . '/class/Model/PdoConnect.php';
require_once $ROOT . '/class/Model/IceServerConfig.php';
require_once $ROOT . '/class/Model/WebRTCHandler.php';
require_once $ROOT . '/class/Model/TourRequest.php';
// Die Bewertung einer Fuehrung. Nach TourRequest, weil sie sich beim
// Schreiben auf dessen Zeile stuetzt (INSERT ... SELECT).
require_once $ROOT . '/class/Model/TourReview.php';
require_once $ROOT . '/class/Controller/TurnController.php';
require_once $ROOT . '/class/Model/User.php';
require_once $ROOT . '/class/Helper/Role.php';
require_once $ROOT . '/class/Helper/Permission.php';
require_once $ROOT . '/class/Controller/WebRTCController.php';
require_once $ROOT . '/class/Controller/UserController.php';
require_once $ROOT . '/class/Model/Location.php';
require_once $ROOT . '/class/Model/LocationImage.php';
require_once $ROOT . '/class/Helper/ImageStore.php';
require_once $ROOT . '/class/Helper/Languages.php';
require_once $ROOT . '/class/Helper/Availability.php';
require_once $ROOT . '/class/Helper/LocationView.php';
require_once $ROOT . '/class/Model/GuideRole.php';
// Das Guide-Profil: der Mensch hinter dem Angebot. Vor LocationView
// gebraucht - die Standortseite baut ihren Guide-Streifen mit GuideView.
require_once $ROOT . '/class/Model/GuideProfile.php';
require_once $ROOT . '/class/Helper/Avatar.php';
require_once $ROOT . '/class/Helper/GuideView.php';
// Der Bewertungsblock. Nach GuideView, weil er dessen Monatsformatierung
// benutzt ("Maerz 2026").
require_once $ROOT . '/class/Helper/ReviewView.php';
require_once $ROOT . '/class/Controller/ReviewController.php';
require_once $ROOT . '/class/Controller/GuideProfileController.php';
require_once $ROOT . '/class/Helper/Theme.php';
require_once $ROOT . '/class/Helper/ViewHelper.php';
require_once $ROOT . '/class/Helper/Auth.php';
// Der Verwaltungsbereich. Nach ViewHelper und Auth, weil er beide benutzt:
// ViewHelper::esc fuer jede Fremdeingabe in seinen Tabellen und Auth::can
// fuer die Frage, welche Reiter ein Betrachter ueberhaupt bekommt.
require_once $ROOT . '/class/Model/AdminStats.php';
require_once $ROOT . '/class/Helper/AdminView.php';
require_once $ROOT . '/class/Controller/AdminController.php';
require_once $ROOT . '/class/Helper/Request.php';
require_once $ROOT . '/class/Helper/Url.php';
require_once $ROOT . '/class/Model/Chat.php';
require_once $ROOT . '/class/Model/ChatMessage.php';
require_once $ROOT . '/class/Controller/ChatController.php';
// Die Guide-Frage und die Stelle, die sie beim Standortformular stellt.
require_once $ROOT . '/class/Controller/GuideController.php';
require_once $ROOT . '/class/Controller/LocationController.php';
// Die Bremse. Haengt an nichts ausser PdoConnect und config/limits.php.
require_once $ROOT . '/class/Model/RateLimit.php';

use App\Model\IceServerConfig;
use App\Model\PdoConnect;
use App\Model\TourRequest;
use App\Model\TourReview;
use App\Model\RateLimit;
use App\Model\WebRTCHandler;
use App\Controller\TurnController;
use App\Controller\WebRTCController;
use App\Controller\UserController;
use App\Controller\LocationController;
use App\Model\Location;
use App\Model\LocationImage;
use App\Model\User;
use App\Helper\ImageStore;
use App\Helper\Languages;
use App\Helper\Availability;
use App\Helper\LocationView;
use App\Model\GuideRole;
use App\Model\GuideProfile;
use App\Helper\Avatar;
use App\Helper\GuideView;
use App\Helper\ReviewView;
use App\Controller\GuideProfileController;
use App\Helper\Role;
use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Theme;
use App\Helper\ViewHelper;
use App\Helper\Url;
use App\Controller\ChatController;

$passed = 0;
function ok($name) { global $passed; fwrite(STDERR, "  ok  $name\n"); $passed++; }
function check($cond, $msg) { if (!$cond) { fwrite(STDERR, "\nFEHLGESCHLAGEN: $msg\n"); exit(1); } }

// ---------------------------------------------------------------------
fwrite(STDERR, "\n1) STUN-Fallback\n");
unset($_ENV['STUN_SERVERS']);
$default = IceServerConfig::stunServers();
check(count($default) === 3, 'drei Vorgabe-Server');
check($default[0]['urls'] === 'stun:stun.l.google.com:19302', 'erster Vorgabe-Server');
$hosts = array_map(fn($s) => $s['urls'], $default);
check(count(array_unique($hosts)) === 3, 'keine Doppelungen');
ok('Vorgabeliste greift ohne ENV');

$_ENV['STUN_SERVERS'] = 'stun:stun.meine-domain.de:3478, stuns:stun.meine-domain.de:5349';
$own = IceServerConfig::stunServers();
check(count($own) === 2, 'eigene Liste ersetzt die Vorgabe');
check($own[1]['urls'] === 'stuns:stun.meine-domain.de:5349', 'stuns: erlaubt');
ok('eigener Server ist ohne Codeaenderung eintragbar');

$_ENV['STUN_SERVERS'] = 'turn:boese.example:3478,javascript:alert(1),stun:gut.example:3478,';
$mixed = IceServerConfig::stunServers();
check(count($mixed) === 1, 'nur der gueltige Eintrag bleibt, ist aber ' . count($mixed));
check($mixed[0]['urls'] === 'stun:gut.example:3478', 'richtiger Eintrag');
ok('ungueltige Eintraege werden verworfen');
unset($_ENV['STUN_SERVERS']);

// ---------------------------------------------------------------------
fwrite(STDERR, "\n2) Zusammenfuehren der ICE-Server\n");
$turn = [
    ['urls' => 'stun:stun.metered.ca:80'],
    ['urls' => 'turn:turn.metered.ca:80', 'username' => 'u', 'credential' => 'c'],
    ['urls' => 'turns:turn.metered.ca:443', 'username' => 'u', 'credential' => 'c'],
];
$merged = IceServerConfig::merge($turn, IceServerConfig::stunServers());
check(count($merged) === 6, 'drei TURN-Eintraege plus drei STUN, ist aber ' . count($merged));
check(IceServerConfig::hasTurn($merged) === true, 'TURN erkannt');
ok('STUN wird ergaenzt, TURN bleibt erhalten');

// Doppelte URL darf nicht zweimal auftauchen.
$withDuplicate = IceServerConfig::merge(
    [['urls' => 'stun:stun.l.google.com:19302']],
    IceServerConfig::stunServers()
);
$urls = [];
foreach ($withDuplicate as $s) { $urls = array_merge($urls, IceServerConfig::urlsOf($s)); }
check(count($urls) === count(array_unique($urls)), 'keine doppelten URLs');
ok('Doppelungen werden vermieden');

// Ohne TURN muss hasTurn false liefern - davon haengt der Hinweis im Client ab.
check(IceServerConfig::hasTurn(IceServerConfig::stunServers()) === false, 'kein TURN erkannt');
ok('Fehlendes TURN wird als solches gemeldet');

// Auch das Array-Format von urls muss erkannt werden.
check(IceServerConfig::hasTurn([['urls' => ['stun:a:1', 'turns:b:443']]]) === true, 'urls als Array');
ok('urls als Array wird unterstuetzt');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n3) Antwort des TURN-Dienstes auswerten\n");
$m = new ReflectionMethod(TurnController::class, 'extractIceServers');
$m->setAccessible(true);

$fehlerobjekt = json_encode(['error' => 'Could not fetch TURN credentials (HTTP 500)']);
check($m->invoke(null, $fehlerobjekt) === [], 'Fehlerobjekt liefert keine Server');
ok('Fehlerobjekt wird nicht als ICE-Server durchgereicht (F-18)');

check($m->invoke(null, 'kein json') === [], 'Muell liefert keine Server');
check($m->invoke(null, false) === [], 'false liefert keine Server');
ok('unbrauchbare Antworten werden verworfen');

$nacktesArray = json_encode([['urls' => 'turn:a:80'], ['kaputt' => 1]]);
$r = $m->invoke(null, $nacktesArray);
check(count($r) === 1 && $r[0]['urls'] === 'turn:a:80', 'nur gueltige Eintraege');
ok('nacktes Array und Objektform werden beide unterstuetzt');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n4) Loeschen nur der ausgelieferten Signale (F-1)\n");

/** Fängt die abgesetzten Statements ab, statt sie auszuführen. */
class FakeStatement implements IteratorAggregate {
    public $sql; public $params = [];
    /**
     * Ein PDOStatement laesst sich mit foreach durchlaufen - App\Model\User::
     * getAll() tut genau das. Ohne diese Zusage liefe foreach ueber die
     * OEFFENTLICHEN EIGENSCHAFTEN der Attrappe, und $row waere der SQL-Text.
     * Geliefert wird nichts: Was eine Abfrage zurueckgibt, setzen die
     * einzelnen Faelle ueber self::$rows.
     */
    public function getIterator(): Traversable { return new ArrayIterator(self::$rows); }
    /** Zeilen, die das naechste execute() angeblich getroffen hat. */
    public static $affected = 1;
    public function __construct($sql) { $this->sql = $sql; }
    public function bindParam($k, &$v, $type = null) { $this->params[$k] = $v; }
    public function execute() { return true; }
    public function rowCount() { return self::$affected; }
    /**
     * Was das naechste fetch() liefert. Vorgabe false - "keine Zeile", so wie
     * bisher; wer eine Zeile braucht, setzt sie und raeumt danach wieder auf.
     */
    public static $row = false;
    /** Dasselbe fuer fetchAll(). */
    public static $rows = [];
    public function fetch($mode = null) { return self::$row; }
    public function fetchAll($mode = null) { return self::$rows; }
    /** Fuer COUNT-Abfragen (LocationImage::countForLocation). */
    public function fetchColumn($i = 0) { return 0; }
}
class FakeConnection {
    public $statements = [];
    public function prepare($sql) { $s = new FakeStatement($sql); $this->statements[] = $s; return $s; }

    // Transaktionen: App\Model\LocationImage::reorder() setzt alle Updates
    // in eine, damit nicht zwei Bilder auf derselben Position stehen
    // bleiben, wenn es in der Mitte abbricht. Die Attrappe schreibt nur mit,
    // ob sie geoeffnet und geschlossen wurde.
    public $transaktionen = [];
    private $offen = false;
    public function beginTransaction() { $this->offen = true;  $this->transaktionen[] = 'begin';    return true; }
    public function commit()           { $this->offen = false; $this->transaktionen[] = 'commit';   return true; }
    public function rollBack()         { $this->offen = false; $this->transaktionen[] = 'rollback'; return true; }
    public function inTransaction()    { return $this->offen; }

    // exec() setzt eine Anweisung ohne Platzhalter ab - so raeumt der Cronjob
    // auf (App\Model\TourRequest::expireDue). Mitgeschrieben wird sie wie
    // jede andere, damit auch diese Statements pruefbar sind.
    public function exec($sql) { $this->statements[] = new FakeStatement($sql); return 0; }

    // query() setzt eine Abfrage ohne Platzhalter ab - so arbeiten die
    // Listen der Verwaltung, deren Filter feste Textbausteine sind
    // (App\Model\Location::selectAllForAdmin, TourRequest::allForAdmin).
    public function query($sql) { $s = new FakeStatement($sql); $this->statements[] = $s; return $s; }

    // Nach einem INSERT fragt das Modell die neue Kennung ab.
    public function lastInsertId() { return 42; }
}
$fake = new FakeConnection();
PdoConnect::$connection = $fake;

$handler = new WebRTCHandler();
$handler->deleteSignalsByIds(7, [11, '12', 13]);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'IN (11,12,13)') !== false, "IDs in der Bedingung: $sql");
check(strpos($sql, 'receiver_id = :receiver') !== false, 'Empfaenger bleibt in der Bedingung');
check($fake->statements[0]->params[':receiver'] === 7, 'Empfaenger gebunden');
ok('geloescht wird nur die gelesene Menge, gebunden an den Empfaenger');

// Einschleusversuche und Unsinn duerfen nicht in das Statement gelangen.
$fake->statements = [];
$handler->deleteSignalsByIds(7, ['1 OR 1=1', '5; DROP TABLE rtc_signal', -3, 0, null, 9]);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'DROP') === false, "kein DROP im Statement: $sql");
check(strpos($sql, 'OR 1=1') === false, 'kein OR 1=1');
// is_numeric weist '1 OR 1=1' und '5; DROP ...' komplett ab - es bleibt
// nur die einzige echte ID uebrig.
check(strpos($sql, 'IN (9)') !== false, "nur echte IDs uebrig: $sql");
ok('nicht-numerische und ungueltige IDs werden aussortiert');

// Leere Liste darf gar kein Statement absetzen.
$fake->statements = [];
check($handler->deleteSignalsByIds(7, []) === true, 'leere Liste ist erfolgreich');
check(count($fake->statements) === 0, 'kein ueberfluessiges Statement');
ok('leere Liste erzeugt keinen DB-Zugriff');

// Aufraeumgrenze darf nie unter das Lesefenster rutschen.
$fake->statements = [];
$handler->deleteExpiredSignalsForReceiver(7, 3);
check(strpos($fake->statements[0]->sql, 'INTERVAL 15 SECOND') !== false,
    'Untergrenze 15 s: ' . $fake->statements[0]->sql);
$fake->statements = [];
$handler->deleteExpiredSignalsForReceiver(7);
check(strpos($fake->statements[0]->sql, 'INTERVAL 60 SECOND') !== false, 'Vorgabe 60 s');
ok('Aufraeumen loescht nie innerhalb des Lesefensters');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n5) Rollenvergabe fuer den Call (Steuerprotokoll)\n");

/**
 * Attrappe, die Benutzer UND Standorte aus Tabellen im Speicher liefert. Die
 * Rollenvergabe ist die einzige Serverlogik des Steuerprotokolls; geprueft
 * wird sie damit ohne Datenbank.
 *
 * Unterschieden wird an der Abfrage selbst: App\Model\User fragt "FROM user"
 * ueber :user_id, App\Model\Location fragt "FROM location" ueber :id.
 */
class FakeUserStatement {
    public $sql; public $params = []; private $users; private $locations;
    public function __construct($sql, $users, $locations) {
        $this->sql = $sql; $this->users = $users; $this->locations = $locations;
    }
    public function bindParam($k, &$v) { $this->params[$k] = $v; }
    public function execute() { return true; }
    public function fetch($mode = null) {
        // Die Bereitschaft hat eine eigene Abfrage mit eigener Antwortform
        // (App\Model\User::availableSeconds): eine Spalte "rest" statt einer
        // Benutzerzeile, und die Bedingung "available_until IS NOT NULL"
        // steckt im WHERE. Beides bildet die Attrappe nach, sonst pruefte der
        // Test die Bereitschaftssperre gegen eine Antwort, die es so nie gibt.
        if (strpos($this->sql, 'TIMESTAMPDIFF') !== false) {
            $id   = (int)($this->params[':id'] ?? 0);
            $user = $this->users[$id] ?? null;
            if (!$user || empty($user['available_until'])) return false;
            return ['rest' => 3600];
        }
        if (strpos($this->sql, 'FROM location') !== false) {
            $id = (int)($this->params[':id'] ?? 0);
            return $this->locations[$id] ?? false;
        }
        $id = (int)($this->params[':user_id'] ?? 0);
        return $this->users[$id] ?? false;
    }
    public function fetchAll($mode = null) { return []; }
    /**
     * Nur fuer App\Model\User::isDeleted(): "SELECT deleted FROM user".
     *
     * Ein Konto, das die Attrappe nicht kennt, gilt als geloescht - genau
     * wie in der echten Methode, wo eine fehlende Zeile die sichere Seite
     * ist. Ein bekanntes Konto ohne ausdrueckliches 'deleted' gilt als
     * vorhanden; sonst muesste jeder aeltere Testfall das Feld nachtragen.
     */
    public function fetchColumn($i = 0) {
        $id   = (int)($this->params[':id'] ?? 0);
        $user = $this->users[$id] ?? null;
        return $user === null ? 1 : (int)($user['deleted'] ?? 0);
    }
}
class FakeUserConnection {
    public $users = [];
    public $locations = [];
    public function prepare($sql) {
        return new FakeUserStatement($sql, $this->users, $this->locations);
    }
}

/**
 * Baut eine Benutzerzeile, wie sie aus der Tabelle user kaeme.
 *
 * $bereit steht fuer user.available_until: ein Zeitpunkt in der Zukunft
 * heisst "hat sich auf bereit gestellt", null heisst "nicht bereit". Die
 * Vorgabe ist bereit, damit die uebrigen Pruefungen dieses Abschnitts weiter
 * die Rollenvergabe pruefen und nicht die Bereitschaft.
 */
function fakeUser($id, $typeId, $bereit = true) {
    return [
        'id' => $id, 'username' => 'u' . $id, 'email' => 'u' . $id . '@example.org',
        'pwd' => 'x', 'type_id' => $typeId, 'deleted' => 0,
        'available_until' => $bereit ? '2999-01-01 00:00:00' : null
    ];
}

/** Baut eine Standortzeile, wie sie aus der Tabelle location kaeme. */
function fakeLocation($id, $userId, $blocked = 0) {
    return [
        'id' => $id, 'user_id' => $userId, 'city_id' => 1,
        'latitude' => '52.0', 'longitude' => '13.0', 'description' => 'Ort ' . $id,
        'blocked' => $blocked, 'blocked_reason' => $blocked ? 'Grund' : null,
        'country_name' => 'Deutschland', 'city_name' => 'Berlin'
    ];
}

$userDb = new FakeUserConnection();
// usertype laut database.sql: 0=Trial, 1=User, 2=Guide, 10=Admin
$userDb->users = [
    1 => fakeUser(1, 10),  // Admin
    2 => fakeUser(2,  2),  // Guide
    3 => fakeUser(3,  2),  // Guide
    4 => fakeUser(4,  1),  // User
    5 => fakeUser(5,  0),  // Trial
    6 => fakeUser(6,  2, false),  // Guide, NICHT auf bereit gestellt
];
// Standorte: 10 gehoert dem Guide 2, 11 dem Admin 1, 12 ist gesperrt,
// 13 gehoert dem Guide 6, der nicht auf bereit steht.
$userDb->locations = [
    10 => fakeLocation(10, 2),
    11 => fakeLocation(11, 1),
    12 => fakeLocation(12, 2, 1),
    13 => fakeLocation(13, 6),
];
PdoConnect::$connection = $userDb;

// Regelfall und einziger zulaessiger Fall: Der Zuschauer sucht einen Standort
// und ruft den Guide an.
$r = WebRTCController::callRoles(5, 2);
check($r !== null, 'Anruf beim Guide kommt zustande');
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'Zuschauer ruft Guide an');
check(WebRTCController::callAllowed(5, 2) === true, 'Anruf beim Guide ist erlaubt');
ok('Anrufer wird Zuschauer, angerufener Guide wird Guide');

// Der Angerufene darf keine Standorte anbieten: Der Anruf kommt nicht
// zustande. Vorher wurde er hier stillschweigend zum Guide erklaert - eine
// Rolle, der er nie zugestimmt hatte, samt Steuerkreuz auf der Gegenseite.
foreach ([[2, 5, 'Guide ruft Trial an'],
          [4, 5, 'Zuschauer ruft Trial an'],
          [5, 4, 'Trial ruft Zuschauer an'],
          [5, 99, 'Anruf bei einem unbekannten Konto']] as [$caller, $callee, $was]) {
    check(WebRTCController::callRoles($caller, $callee) === null, $was . ': keine Rollen');
    check(WebRTCController::callAllowed($caller, $callee) === false, $was . ': nicht erlaubt');
    check(WebRTCController::roleForCall($caller, $callee, $caller) === null, $was . ': keine Anruferrolle');
    check(WebRTCController::roleForCall($caller, $callee, $callee) === null, $was . ': keine Rolle fuer den Angerufenen');
}
ok('wer keine Standorte anbieten darf, wird durch einen Anruf auch kein Guide');

// EIN DIREKTANRUF MIT EINEM ADMIN IST KEINE FUEHRUNG. Er geht nicht von einem
// Standort aus, sondern von einer Person in der Benutzerverwaltung, und hat
// einen anderen Zweck - Rueckfrage, Unterstuetzung, Moderation. Dort gibt es
// nichts zu steuern, beide sollen einander sehen und hoeren: zweimal 'peer'.
foreach ([[4, 1, 'Nutzer ruft Admin an'],
          [1, 4, 'Admin ruft Nutzer an'],
          [2, 1, 'Guide ruft Admin an'],
          [1, 2, 'Admin ruft Guide an'],
          [1, 5, 'Admin ruft Trial an']] as [$caller, $callee, $was]) {
    $r = WebRTCController::callRoles($caller, $callee);
    check($r !== null, $was . ': kommt zustande');
    check($r['caller'] === 'peer' && $r['callee'] === 'peer', $was . ': beide sind peer');
    check(WebRTCController::callAllowed($caller, $callee) === true, $was . ': erlaubt');
}
ok('ein Direktanruf mit einem Admin ist ein Gespraech unter Gleichen');

// Der Admin darf jeden anrufen - auch jemanden, der keine Standorte anbietet.
// Genau das verspricht der Knopf "Anrufen" in der Benutzerliste, und genau
// daran scheiterte er bisher: Der Server wies das Offer ab.
check(WebRTCController::callAllowed(1, 4) === true, 'Admin ruft einen Nutzer an');
check(WebRTCController::callAllowed(4, 4) === false, 'Nutzer ruft Nutzer an - weiterhin nicht');
ok('der Admin erreicht jeden, alle anderen nur, wer Standorte anbietet');

// GEHT DER ANRUF VON EINEM STANDORT AUS, FUEHRT DER ANGERUFENE - ohne
// Ausnahme. Wer einen Standort anbietet, laesst sich dort steuern; dafuer
// steht das Angebot auf der Karte. Das gilt auch fuer den Admin: Standort 11
// gehoert ihm.
$r = WebRTCController::callRoles(4, 2, 10);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'Anruf am Standort des Guides');
$r = WebRTCController::callRoles(4, 1, 11);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide',
    'am eigenen Standort fuehrt auch der Admin');
check(WebRTCController::roleForCall(4, 1, 1, 11) === 'guide',
    'der angerufene Admin bekommt am Standort die Guide-Rolle');
check(WebRTCController::roleForCall(4, 1, 4, 11) === 'viewer',
    'der Anrufer bekommt dort die Zuschauerrolle');
ok('von einem Standort aus fuehrt der Angerufene, auch als Admin');

// Und genau deshalb wird die Kennung geprueft statt geglaubt. Sie kommt vom
// Anrufer; wer eine fremde oder gesperrte mitschickt, erzwingt damit keine
// Fuehrung.
foreach ([[10, 1, 'fremder Standort (gehoert dem Guide, angerufen ist der Admin)'],
          [12, 2, 'gesperrter Standort'],
          [99, 2, 'Standort, den es nicht gibt']] as [$ort, $callee, $was]) {
    $r = WebRTCController::callRoles(1, $callee, $ort);
    check($r['callee'] !== 'guide', $was . ': fuehrt trotzdem');
}
// Der gesperrte Standort des Guides faellt auf die Regel "Angerufener bietet
// an" zurueck - eine Fuehrung bleibt es, nur nicht wegen dieses Ortes.
$r = WebRTCController::callRoles(4, 2, 12);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide',
    'ein Guide bleibt auch mit gesperrtem Standort ein Guide');
ok('eine fremde, gesperrte oder unbekannte Standortkennung erzwingt keine Fuehrung');

// Fuer alle ohne Admin gilt weiter: Anrufbar ist, wer location.offer hat -
// dasselbe Kriterium, ueber das ein Standort auf die Karte kommt. Die
// Bedingung steht damit an einer Stelle, in der Rechtetabelle.
foreach ([[Role::GUIDE, 2, true], [Role::USER, 4, false], [Role::TRIAL, 5, false]] as [$rolle, $konto, $erwartet]) {
    check(Permission::has($rolle, Permission::LOCATION_OFFER) === $erwartet,
        'location.offer fuer Rolle ' . var_export($rolle, true));
    check(WebRTCController::callAllowed(5, $konto) === $erwartet,
        'anrufbar genau dann, wenn das Recht da ist (Konto ' . $konto . ')');
}
ok('Anrufbarkeit und location.offer sind dieselbe Aussage');

// OHNE BEREITSCHAFT KEINE FUEHRUNG. Das ist der Kern der Aenderung: Konto 6
// ist Guide, hat das Recht location.offer und einen eigenen Standort (13) -
// aber es hat sich nicht auf bereit gestellt. Vorher genuegte ein offener Tab,
// um angerufen zu werden; jetzt ist die Bereitschaft eine eigene Aussage.
check(WebRTCController::callRoles(5, 6) === null,
    'ein nicht bereiter Guide kommt ueber den Weg ohne Standort zustande');
check(WebRTCController::callRoles(4, 6, 13) === null,
    'ein nicht bereiter Guide fuehrt auch am eigenen Standort nicht');
check(WebRTCController::callAllowed(5, 6) === false,
    'der Anruf bei einem nicht bereiten Guide ist nicht erlaubt');
check(WebRTCController::callAllowed(4, 6, 13) === false,
    'auch vom Standort aus nicht');
check(WebRTCController::roleForCall(4, 6, 6, 13) === null,
    'der nicht bereite Guide bekommt keine Guide-Rolle');
ok('wer sich nicht auf bereit gestellt hat, ist nicht anrufbar');

// DAS RECHT ALLEIN GENUEGT NICHT MEHR. Beide Konten sind Guide und haben
// dasselbe Recht - der Unterschied liegt allein in der Bereitschaft. Damit ist
// festgehalten, dass die Sperre nicht versehentlich an der Rolle haengt.
check(Permission::has(Role::GUIDE, Permission::LOCATION_OFFER) === true,
    'der nicht bereite Guide hat location.offer weiterhin');
check(WebRTCController::callAllowed(5, 2) === true,  'der bereite Guide ist anrufbar');
check(WebRTCController::callAllowed(5, 6) === false, 'der nicht bereite nicht');
ok('angemeldet und bereit sind zwei verschiedene Aussagen');

// EIN DIREKTANRUF DER VERWALTUNG BLEIBT MOEGLICH. Fuer eine Rueckfrage der
// Moderation muss sich niemand bereit gemeldet haben, und gefuehrt wird dabei
// ohnehin nicht - beide bekommen 'peer'. Waere die Bereitschaft hier Pflicht,
// koennte der Admin einen Guide nicht mehr erreichen, um genau darueber zu
// sprechen.
$r = WebRTCController::callRoles(1, 6);
check($r !== null, 'der Admin erreicht auch einen nicht bereiten Guide');
check($r['caller'] === 'peer' && $r['callee'] === 'peer',
    'und zwar als Gespraech unter Gleichen, nicht als Fuehrung');
$r = WebRTCController::callRoles(1, 6, 13);
check($r['callee'] === 'peer',
    'auch mit Standortkennung wird daraus keine Fuehrung, solange er nicht bereit ist');
ok('die Bereitschaft sperrt Fuehrungen, nicht die Moderation');

// DIE BEREITSCHAFT WIRD MIT GEZIELTEN UPDATES GESCHRIEBEN, nicht beilaeufig
// beim Speichern eines Benutzers. Sonst wuerde der Heartbeat, der ohnehin
// jeden Takt ein save() ausloest, die Bereitschaft mitschreiben - und damit
// waere genau die Kopplung wieder da, die aufgeloest werden sollte.
$userQuellcode = file_get_contents(__DIR__ . '/../class/Model/User.php');
$von = strpos($userQuellcode, 'private function update()');
$bis = strpos($userQuellcode, 'public function save()');
check($von !== false && $bis !== false && $bis > $von,
    'User::update() und save() stehen nicht mehr in dieser Reihenfolge - die Pruefung greift ins Leere');
$updateBlock = substr($userQuellcode, $von, $bis - $von);
check(strpos($updateBlock, 'available_until') === false,
    'User::update() schreibt available_until mit - dann verlaengert jedes save() die Bereitschaft');
ok('kein beilaeufiges Speichern der Bereitschaft');

// Zwei Guides: Der Angerufene ist der Guide. Wer anruft, schaut zu - auch wenn
// er selbst Standorte anbietet.
$r = WebRTCController::callRoles(2, 3);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'zwei Guides');
ok('bei zwei Guides ist der Angerufene der Guide');

// Beide Seiten fragen unabhaengig - und muessen zusammenpassen.
foreach ([[5, 2], [4, 2], [2, 3]] as [$caller, $callee]) {
    $a = WebRTCController::roleForCall($caller, $callee, $caller);
    $b = WebRTCController::roleForCall($caller, $callee, $callee);
    check($a === 'viewer' && $b === 'guide', "Fuehrung: viewer ruft guide ($caller -> $callee)");
}
// Im Anruf ohne Fuehrung sind beide gleich - das ist der Sinn der Rolle.
foreach ([[1, 2], [2, 1], [1, 4]] as [$caller, $callee]) {
    check(WebRTCController::roleForCall($caller, $callee, $caller) === 'peer', 'Anrufer ist peer');
    check(WebRTCController::roleForCall($caller, $callee, $callee) === 'peer', 'Angerufener ist peer');
}
check(WebRTCController::roleForCall(5, 2, 4) === null, 'Unbeteiligter bekommt keine Rolle');
ok('beide Seiten bekommen zueinander passende Rollen, Dritte gar keine');

// Die Rollennamen sind eine Zeichenkette, die zwei Sprachen teilen. Weicht
// eine davon ab, verwirft der Client die Rolle als unbekannt - und dann
// steuert in diesem Call niemand, ohne dass irgendwo ein Fehler stuende.
$protokollJs = file_get_contents(__DIR__ . '/../assets/js/protocol.js');
foreach ([WebRTCController::ROLE_VIEWER, WebRTCController::ROLE_GUIDE,
          WebRTCController::ROLE_PEER] as $rolle) {
    check(strpos($protokollJs, "'" . $rolle . "'") !== false,
        'die Rolle "' . $rolle . '" steht auch in assets/js/protocol.js');
}
ok('Server und Client meinen dieselben Rollennamen');

// Gestempelt wird ausschliesslich am Offer.
$messages = [
    ['type' => 'offer',        'sender_id' => 5, 'receiver_id' => 2],
    ['type' => 'iceCandidate', 'sender_id' => 5, 'receiver_id' => 2],
    ['type' => 'restart_offer','sender_id' => 5, 'receiver_id' => 2],
];
$stamped = WebRTCController::stampCallRoles($messages, 2);
check($stamped[0]['role'] === 'guide', 'Angerufener ist der Guide');
check(!isset($stamped[1]['role']), 'ICE-Kandidat bekommt keine Rolle');
check(!isset($stamped[2]['role']), 'restart_offer bekommt keine Rolle - die Rolle steht seit dem Anruf fest');
ok('nur das Offer traegt die Rolle');

// BEIDE SEITEN MUESSEN ZUR SELBEN ANTWORT KOMMEN. Der Angerufene holt sein
// Offer Sekunden spaeter ueber das Polling ab - den Standort hat er dann nur
// noch, weil er an der Zeile steht (Migration 009). Ohne ihn wuerde aus
// derselben Verbindung beim Anrufer eine Fuehrung und beim Angerufenen ein
// Gespraech unter Gleichen.
$mitOrt  = [['type' => 'offer', 'sender_id' => 4, 'receiver_id' => 1, 'location_id' => 11]];
$ohneOrt = [['type' => 'offer', 'sender_id' => 4, 'receiver_id' => 1, 'location_id' => null]];
$a = WebRTCController::stampCallRoles($mitOrt, 1);
$b = WebRTCController::stampCallRoles($ohneOrt, 1);
check($a[0]['role'] === 'guide', 'mit Standort an der Zeile fuehrt der angerufene Admin');
check($b[0]['role'] === 'peer',  'ohne Standort ist es ein Direktanruf');
check($a[0]['role'] === WebRTCController::roleForCall(4, 1, 1, 11),
    'die gestempelte Rolle weicht von der berechneten ab');
check(!array_key_exists('location_id', $a[0]),
    'die Standortkennung wird an den Client ausgeliefert');
ok('der Standort an der Zeile haelt beide Seiten zusammen und bleibt intern');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n6) Rollen-Normalisierung (Befunde F-5/F-6)\n");

// Der Kern der beiden Befunde: usertype.name ist gross geschrieben, verglichen
// wurde gegen kleingeschriebene Literale. Der Helfer macht die Schreibweise
// egal.
check(Role::id('Guide')   === Role::GUIDE, "'Guide' ist die Guide-Rolle");
check(Role::id('guide')   === Role::GUIDE, "'guide' ebenso");
check(Role::id('GUIDE')   === Role::GUIDE, "'GUIDE' ebenso");
check(Role::id(' Guide ') === Role::GUIDE, 'Leerzeichen stoeren nicht');
check(Role::id('Admin')   === Role::ADMIN, "'Admin' wird erkannt");
check(Role::id('Trial')   === Role::TRIAL, "'Trial' wird erkannt");
ok('Rollennamen werden unabhaengig von der Schreibweise erkannt');

// Die Nummernvergabe selbst. Sie steht hier, damit ein versehentliches
// Verschieben auffaellt: An den Nummern haengen die Daten in usertype.
check(Role::TRIAL ===  0, 'Trial ist 0');
check(Role::USER  ===  1, 'User ist 1');
check(Role::GUIDE ===  2, 'Guide ist 2');
check(Role::ADMIN === 10, 'Admin ist 10');
check(Role::id(0) === Role::TRIAL, '0 ist nicht falsy mit "keine Rolle" zu verwechseln');
check(count(Role::all()) === 4, 'genau vier Rollen');
ok('die Rollennummern sind die aus database.sql');

// PDO liefert je nach Treibereinstellung '1' statt 1. Ein === 1 scheitert
// daran still - der Helfer nicht.
check(Role::id(2)    === Role::GUIDE, 'int 2');
check(Role::id('2')  === Role::GUIDE, "Zahlenstring '2'");
check(Role::id('0')  === Role::TRIAL, "Zahlenstring '0'");
check(Role::id('10') === Role::ADMIN, "Zahlenstring '10'");
ok('Zahl und Zahlenstring bedeuten dasselbe');

// Alles Unbekannte ist null und darf nirgends als Berechtigung durchgehen.
foreach ([null, '', '   ', 'tourist', 'Tourist', 'viewer', 7, 3, -1, '2.5', [], true] as $bad) {
    check(Role::id($bad) === null, 'unbekannte Rolle: ' . var_export($bad, true));
}
check(Role::name('tourist') === null, "'tourist' gibt es in usertype nicht");
ok('unbekannte Rollen ergeben null, nicht versehentlich eine gueltige');

check(Role::name(Role::GUIDE) === 'Guide', 'kanonische Schreibweise Guide');
check(Role::name('user')      === 'User',  'kanonische Schreibweise User');
ok('name() liefert die Schreibweise aus usertype.name');

// Wer den Button "Neue Lokation hinzufuegen" sieht (Befund F-5).
check(Role::mayOfferLocation('Admin') === true,  'Admin darf Standorte anbieten');
check(Role::mayOfferLocation('Guide') === true,  'Guide darf Standorte anbieten');
check(Role::mayOfferLocation('User')  === false, 'User noch nicht');
check(Role::mayOfferLocation('Trial') === false, 'Trial noch nicht');
check(Role::mayOfferLocation(null)    === false, 'unbekannt heisst nein');
check(Role::mayOfferLocation('tourist') === false, 'erfundene Rolle heisst nein');
ok('mayOfferLocation trifft genau Admin und Guide');

// Wer durch das Anlegen eines Standorts zum Guide aufsteigt (Befund F-6).
check(Role::mayBecomeGuide('User')  === true,  'User steigt auf');
check(Role::mayBecomeGuide('Trial') === true,  'Trial steigt auf');
check(Role::mayBecomeGuide('Guide') === false, 'ein Guide ist schon Guide');
check(Role::mayBecomeGuide('Admin') === false, 'ein Admin bleibt Admin');
check(Role::mayBecomeGuide(null)    === false, 'unbekannt steigt nicht auf');
ok('mayBecomeGuide trifft genau User und Trial');

// Kein Konto ist gleichzeitig beides - sonst waere die Beschriftung des
// Buttons in ui.js nicht eindeutig.
foreach (['Admin', 'Guide', 'User', 'Trial'] as $role) {
    check(!(Role::mayOfferLocation($role) && Role::mayBecomeGuide($role)),
        "$role ist nicht beides zugleich");
}
ok('die beiden Rechte schliessen einander aus');

// Das Signaling benutzt denselben Wert.
check(WebRTCController::USERTYPE_GUIDE === Role::GUIDE, 'Signaling teilt die Guide-ID');
check(Role::isGuide('Guide') === true && Role::isGuide('Admin') === false, 'isGuide');
check(Role::isAdmin(Role::ADMIN) === true && Role::isAdmin(Role::GUIDE) === false, 'isAdmin');
ok('Signaling und Rollenhelfer sind sich ueber die Guide-ID einig');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n7) Berechtigungstabelle und Routen\n");

// Jede Route braucht ein Recht. Das ist keine Empfehlung, sondern die
// Bedingung, unter der index.php ueberhaupt etwas ausliefert.
$routes = require $ROOT . '/config/routes.php';
$fehler = Permission::routeErrors($routes);
check($fehler === [], "Routing-Tabelle fehlerhaft: " . implode(' | ', $fehler));
check(count($routes) > 40, 'die Tabelle ist vollstaendig geladen');
ok('jede Route in config/routes.php hat ein bekanntes Recht und eine Antwortart');

// Die Pruefung muss auch anschlagen. Sonst waere sie nur Zierde.
check(Permission::routeErrors([]) !== [], 'leere Tabelle ist ein Fehler');
check(Permission::routeErrors(['x' => [UserController::class, 'listUser']]) !== [],
    'Route ohne Recht ist ein Fehler');
check(Permission::routeErrors(['x' => [UserController::class, 'listUser', '', 'html']]) !== [],
    'leeres Recht ist ein Fehler');
check(Permission::routeErrors(['x' => [UserController::class, 'listUser', 'gibt.es.nicht', 'html']]) !== [],
    'erfundenes Recht ist ein Fehler');
check(Permission::routeErrors(['x' => [UserController::class, 'listUser', Permission::USER_LIST, 'xml']]) !== [],
    'unbekannte Antwortart ist ein Fehler');
check(Permission::routeErrors(['x' => [UserController::class, 'listUser', Permission::USER_LIST, 'html']]) === [],
    'vollstaendiger Eintrag ist in Ordnung');
ok('eine Route ohne definiertes Recht wird als Konfigurationsfehler erkannt');

// Die drei Endpunkte ohne jede Pruefung (Befund: nicht einmal ein Login).
check($routes['delete_user'][2]        === Permission::USER_DELETE      , 'delete_user braucht user.delete');
check($routes['delete_location'][2]    === Permission::LOCATION_DELETE_OWN, 'delete_location braucht location.delete_own');
check($routes['chat_get_messages'][2]  === Permission::CHAT_READ        , 'chat_get_messages braucht chat.read');
check($routes['delete_user'][3]        === 'html', 'delete_user antwortet als Seite');
check($routes['delete_location'][3]    === 'json', 'delete_location antwortet als JSON');
check($routes['chat_get_messages'][3]  === 'json', 'chat_get_messages antwortet als JSON');
ok('die drei ungeschuetzten Endpunkte haengen jetzt an einem Recht');

// Wer nicht angemeldet ist, kommt nur an die oeffentlichen Routen.
//
// location.map_public steht bewusst in dieser Liste: Die Startseite ist eine
// Karte, und ein Gast soll das Angebot sehen koennen, bevor er sich
// entscheidet. Die Route gibt dafuer nur Ort, Beschreibung und einen von drei
// Verfuegbarkeitswerten heraus - siehe Abschnitt 13.
//
// location.view ebenso, und aus demselben Grund: Die Seite eines Standorts
// ist die Adresse, die ein Guide weitergibt. Ein geteilter Link, der beim
// Empfaenger auf dem Anmeldeformular endet, wird nicht weitergegeben. Auch
// diese Seite gibt einem Gast keine user_id heraus - er kann von dort aus
// also niemanden anrufen, sondern landet bei der Anmeldung.
//
// guide.view als drittes, und wieder aus demselben Grund: Die Profilseite
// eines Guides ist eine Adresse, die er weitergibt. Sie zeigt Anzeigename,
// Bild, Selbstbeschreibung, Sprachen und die angebotenen Standorte - keinen
// Benutzernamen, keine E-Mail-Adresse und nichts, womit sich jemand anmelden
// koennte.
$oeffentlich = [Permission::SYSTEM_HOME, Permission::AUTH_LOGIN, Permission::AUTH_SIGNUP,
                Permission::AUTH_PASSWORD_RESET, Permission::AUTH_EMAIL_VERIFY,
                Permission::AUTH_TWOFACTOR_VERIFY, Permission::LOCATION_MAP_PUBLIC,
                Permission::LOCATION_VIEW, Permission::GUIDE_VIEW];
sort($oeffentlich);
$gast = Permission::rightsOf(Permission::GUEST);
sort($gast);
check($gast === $oeffentlich, 'Gastrechte: ' . implode(',', $gast));
foreach ([Permission::USER_LIST, Permission::USER_DELETE, Permission::RTC_SIGNAL,
          Permission::LOCATION_CREATE, Permission::CHAT_READ, Permission::AUTH_LOGOUT] as $recht) {
    check(Permission::has(Permission::GUEST, $recht) === false, "Gast darf $recht nicht");
}
ok('ohne Anmeldung gibt es nur die oeffentlichen Rechte');

// Benutzerverwaltung und Moderation sind Adminsache - und zwar genau eine
// Rolle, nicht "alles ab einer bestimmten Nummer".
foreach ([Permission::USER_MANAGE, Permission::USER_DELETE, Permission::LOCATION_BLOCK,
          Permission::SYSTEM_ADMIN] as $recht) {
    check(Permission::has(Role::ADMIN, $recht) === true, "Admin darf $recht");
    foreach ([Role::GUIDE, Role::USER, Role::TRIAL, Permission::GUEST] as $andere) {
        check(Permission::has($andere, $recht) === false,
            'Rolle ' . var_export($andere, true) . " darf $recht nicht");
    }
}
ok('user.manage, user.delete, location.block und system.admin hat nur der Admin');

// Der Admin loescht keine fremden Standorte - er sperrt sie.
check(Permission::has(Role::ADMIN, Permission::LOCATION_BLOCK) === true, 'Admin sperrt');
check(Permission::has(Role::GUIDE, Permission::LOCATION_BLOCK) === false, 'Guide sperrt nicht');
ok('Moderation heisst sperren, nicht loeschen');

// Standorte anbieten und anlegen darf, wer die Guide-Rolle angenommen hat.
// Frueher durfte jeder Angemeldete anlegen, und genau dieser Schritt machte
// aus einem Zuschauer stillschweigend einen Guide. Die Rolle ist jetzt eine
// bewusste Entscheidung (App\Model\GuideRole) - wer sie nicht getroffen hat,
// kommt gar nicht erst an das Standortformular.
check(Role::mayOfferLocation(Role::GUIDE) === true , 'Guide bietet an');
check(Role::mayOfferLocation(Role::ADMIN) === true , 'Admin bietet an');
check(Role::mayOfferLocation(Role::USER)  === false, 'User noch nicht');
check(Role::mayOfferLocation(Role::TRIAL) === false, 'Trial noch nicht');
foreach ([Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::LOCATION_CREATE) === true,
        'wer Standorte anbietet, darf auch welche anlegen');
}
foreach ([Role::TRIAL, Role::USER, Permission::GUEST] as $rolle) {
    check(Permission::has($rolle, Permission::LOCATION_CREATE) === false,
        'ohne Guide-Rolle kein Standortformular');
}
ok('Standorte anlegen setzt die angenommene Guide-Rolle voraus');

// ---------------------------------------------------------------------
// Die Guide-Frage und die entfallene GPS-Abfrage.
// ---------------------------------------------------------------------

// Die GPS-Abfrage ist weg, und zwar vollstaendig: Route, Controllermethode,
// Modellmethode, Dialog und Recht. Sie schrieb nach user.latitude/longitude -
// Spalten ohne eine einzige Lesestelle - und begruendete das mit einer
// Umkreissuche, die es nie gab.
check(!isset($routes['save_location']), 'die Route save_location gibt es nicht mehr');
check(!in_array('user.position', Permission::allRights(), true),
    'das Recht user.position ist entfallen');
check(!method_exists('App\\Controller\\UserController', 'saveLocation'),
    'UserController::saveLocation ist entfallen');
check(!method_exists('App\\Model\\User', 'saveLocation'),
    'User::saveLocation ist entfallen');
check(!file_exists($ROOT . '/assets/html/location_prompt.html'), 'der Dialog ist geloescht');
check(!file_exists($ROOT . '/assets/js/location_prompt.js'),     'sein Skript ist geloescht');
check(strpos(file_get_contents($ROOT . '/assets/html/index.html'), 'location_prompt.js') === false,
    'das Layout laedt kein Skript mehr, das es nicht gibt');
// Die Spalten selbst bleiben stehen - aber im Schema als ungenutzt vermerkt,
// damit niemand sie fuer gepflegte Daten haelt.
check(strpos(file_get_contents($ROOT . '/database.sql'), 'UNGENUTZT') !== false,
    'die Spalten sind in database.sql als ungenutzt gekennzeichnet');
ok('die GPS-Abfrage ist samt Route und Recht entfallen, die Spalten bleiben vermerkt stehen');

// Die Guide-Frage wird nach dem Login nicht mehr gestellt. Sie steht in den
// Einstellungen und auf dem Knopf der Kopfleiste - erreichbar, aber nicht
// mehr als Sperre vor der ersten Benutzung.
$loginQuelle = file_get_contents($ROOT . '/class/Controller/LoginController.php');
check(strpos($loginQuelle, 'showGuideRolePage') === false,
    'der Login zeigt die Guide-Frage nicht mehr');
check(strpos($loginQuelle, 'location_prompt') === false,
    'der Login zeigt die Standortabfrage nicht mehr');
check(strpos(file_get_contents($ROOT . '/class/Controller/SettingsController.php'),
    'act=guide_role_page') !== false, 'die Einstellungen fuehren zur Guide-Frage');
check(strpos(file_get_contents($ROOT . '/assets/js/ui.js'),
    'act=guide_role_page') !== false, 'der Knopf der Kopfleiste fuehrt zur Guide-Frage');
ok('die Guide-Frage liegt in den Einstellungen, nicht hinter der Anmeldung');

// Ueber die eigene Guide-Rolle entscheiden duerfen alle angemeldeten Rollen
// ausser dem Admin: Er wuerde beim Annehmen der Guide-Rolle seine
// Adminrechte verlieren.
foreach ([Role::TRIAL, Role::USER, Role::GUIDE] as $rolle) {
    check(Permission::has($rolle, Permission::USER_GUIDE_ROLE) === true,
        'darf ueber die eigene Guide-Rolle entscheiden');
}
check(Permission::has(Role::ADMIN, Permission::USER_GUIDE_ROLE) === false,
    'der Admin entmachtet sich nicht per Klick');
check(Permission::has(Permission::GUEST, Permission::USER_GUIDE_ROLE) === false,
    'ohne Anmeldung gibt es keine Rolle zu entscheiden');
check($routes['guide_role_page'][2] === Permission::USER_GUIDE_ROLE, 'die Dialogseite haengt am Recht');
check($routes['guide_role'][2]      === Permission::USER_GUIDE_ROLE, 'die Antwort haengt am Recht');
ok('die Guide-Frage stellt sich jedem ausser dem Admin');

// Trial heisst "Frage noch offen", User heisst "hat sich entschieden".
// Beide haben dieselben Rechte - der Unterschied ist die Bedeutung, nicht das
// Duerfen.
check(Role::isUndecided(Role::TRIAL) === true , 'Trial ist unentschieden');
check(Role::isUndecided(Role::USER)  === false, 'User hat sich entschieden');
check(Role::isUndecided(Role::GUIDE) === false, 'Guide hat sich entschieden');
check(Role::isUndecided(Role::ADMIN) === false, 'der Admin steht ausserhalb');
check(Role::isUndecided(null)        === false, 'unbekannt ist nicht unentschieden');
check(Permission::rightsOf(Role::TRIAL) === Permission::rightsOf(Role::USER),
    'Trial und User haben dieselben Rechte - der Unterschied ist die offene Frage');
ok('Trial bedeutet "Guide-Frage noch offen"');

// Unbekannte Rollen bekommen nichts - auch nicht die Gastrechte.
foreach (Permission::allRights() as $recht) {
    check(Permission::has(null, $recht)      === false, "null darf $recht nicht");
    check(Permission::has('tourist', $recht) === false, "'tourist' darf $recht nicht");
    check(Permission::has(3, $recht)         === false, "unbelegte Nummer darf $recht nicht");
}
check(Permission::rightsOf('tourist') === [], 'unbekannte Rolle hat keine Rechte');
check(Permission::has(Role::ADMIN, 'gibt.es.nicht') === false, 'unbekanntes Recht gilt nie');
check(Permission::has(Role::ADMIN, '') === false, 'leeres Recht gilt nie');
ok('unbekannte Rolle und unbekanntes Recht heissen nein');

// Keine Vererbung: Jede Rolle fuehrt ihre Rechte selbst. Der Nachweis ist,
// dass keine Rolle die Rechte einer anderen vollstaendig mitbringt, ohne dass
// sie dort auch stehen - anders gesagt: Die Listen sind unabhaengig
// voneinander lesbar. Geprueft wird die sichtbare Folge: Es gibt Rechte, die
// eine "hoehere" Rolle NICHT hat.
check(Permission::has(Role::ADMIN, Permission::AUTH_LOGIN) === false,
    'auch der Admin darf sich nicht doppelt anmelden');
check(Permission::has(Role::ADMIN, Permission::AUTH_SIGNUP) === false,
    'auch der Admin registriert sich nicht neu');
ok('es gibt keine Rolle, die einfach alles darf');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n8) Vergleichsoperatoren auf Rollenwerten sind verboten\n");

/**
 * Entfernt Kommentare aus PHP-Quelltext, behaelt aber die Zeilennummern bei.
 *
 * Kommentare muessen raus, weil in ihnen der falsche Vergleich als
 * abschreckendes Beispiel stehen darf - so wie in UserController::manageUser().
 *
 * Zeichenketten bleiben ausdruecklich STEHEN. Der haeufigste Rollenausdruck
 * ueberhaupt steht naemlich in einer: $_SESSION['user']['role_id']. Wer die
 * Zeichenketten mit entfernt, uebersieht genau den Vergleich, um den es hier
 * geht. Der Preis ist, dass auch ein SQL-Text mit einem Vergleich auf type_id
 * anschlaegt - was richtig ist: Auch dort waere die Rangfolge falsch.
 *
 * @param string $code
 * @return string
 */
function stripPhpNoise($code) {
    $out = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                // Nur die Zeilenumbrueche behalten, damit die Zeilennummern
                // stimmen.
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

/**
 * Entfernt ganze Kommentarzeilen aus JavaScript. Eine vollstaendige Analyse
 * waere hier unangemessen - es geht darum, dass die erlaeuternden Kommentare
 * ueber die frueheren Rollenvergleiche nicht selbst anschlagen.
 *
 * @param string $code
 * @return string
 */
function stripJsCommentLines($code) {
    $zeilen = explode("\n", $code);
    foreach ($zeilen as $i => $zeile) {
        $t = ltrim($zeile);
        if ($t === '' || strpos($t, '//') === 0 || strpos($t, '*') === 0 || strpos($t, '/*') === 0) {
            $zeilen[$i] = '';
        }
    }
    return implode("\n", $zeilen);
}

// Ausdruecke, die einen Rollenwert bezeichnen.
$rollen_ausdruck = '(?:getRoleId\s*\(\s*\)|getUsertype\s*\(\s*\)'
                 . '|(?:Role|self)::(?:ADMIN|GUIDE|USER|TRIAL)'
                 . '|\brole_id\b|\broleId\b|\btype_id\b|\btypeId\b'
                 . '|\buser_role_id\b|\buserRoleId\b|\buserRole\b)';

// Vergleichsoperatoren. Die Ausschluesse verhindern Treffer auf "=>" (Pfeil
// im Array) und "->" (Objektzugriff).
// Der Nachlauf (?!=) verhindert, dass "==" als Teiltreffer von "===" gilt:
// sonst wuerde der Ausschluss fuer Vergleiche gegen null nie greifen.
$vergleich = '(?<![=<>!+*\/.\-])(?:===|!==|==|!=|<=|>=|<|>)(?!=)';

// Zwischen dem Rollenwert und dem Operator duerfen schliessende Anfuehrungs-
// und Klammerzeichen stehen: $_SESSION['user']['role_id'] > 1.
$nachlauf = '[\s\'"\]\)]*';
// Zwischen dem Operator und dem Rollenwert steht der Anfang eines Ausdrucks,
// etwa "$_SESSION['user'][". Die Laenge ist begrenzt, und Zeichen wie ? & ; ,
// beenden die Suche - sonst wuerde das Muster ueber eine ganze Zeile hinweg
// zwei unabhaengige Ausdruecke zusammenziehen.
$vorlauf = '[\s\$\w\'"\[\(:>\-]{0,40}?';

// Verboten: Rollenwert VOR einem Vergleich - ausser gegen null. "Rolle
// unbekannt" muss abfragbar bleiben.
// Der Ausschluss steht direkt hinter dem Operator und nicht hinter einem
// \s* - sonst wuerde die Suche das Leerzeichen einfach nicht mitnehmen und
// der Ausschluss liefe ins Leere.
$muster_links  = '/' . $rollen_ausdruck . $nachlauf . $vergleich . '(?!\s*null\b)/i';
// Verboten: Rollenwert NACH einem Vergleich, ausnahmslos.
$muster_rechts = '/' . $vergleich . $vorlauf . $rollen_ausdruck . '/i';

// Die Regel muss zuschlagen. Wenn dieser Selbsttest nicht anschlaegt, ist das
// Muster kaputt und die ganze Pruefung wertlos.
$boese = [
    '$x = $_SESSION["user"]["role_id"] > 1;',
    'if ($user->getRoleId() === 1) {}',
    'if ($role_id <= 1) {}',
    'if (Role::GUIDE == $x) {}',
    'if (window.userRoleId >= 2) {}',
    'if ($tmp->getUsertype() != "Admin") {}',
];
foreach ($boese as $zeile) {
    check(preg_match($muster_links, $zeile) || preg_match($muster_rechts, $zeile),
        'Muster erkennt den verbotenen Vergleich nicht: ' . $zeile);
}
$erlaubt = [
    '$user_role_id === null ? "null" : (int)$user_role_id',
    'if (Auth::can(Permission::USER_MANAGE)) {}',
    '$user->setRoleId(Role::GUIDE);',
    "'right' => Permission::USER_DELETE,",
    '$this->type_id = $id;',
    'if (Role::mayBecomeGuide($user->getRoleId())) {}',
];
foreach ($erlaubt as $zeile) {
    check(!preg_match($muster_links, $zeile) && !preg_match($muster_rechts, $zeile),
        'Muster schlaegt faelschlich an: ' . $zeile);
}
ok('die Regel erkennt verbotene Vergleiche und laesst erlaubten Code in Ruhe');

/**
 * Sammelt alle zu pruefenden Quelldateien.
 *
 * @param string $verzeichnis
 * @param string $endung
 * @return string[]
 */
function quellDateien($verzeichnis, $endung) {
    if (!is_dir($verzeichnis)) return [];
    $gefunden = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verzeichnis));
    foreach ($iterator as $datei) {
        if ($datei->isFile() && strtolower($datei->getExtension()) === $endung) {
            $gefunden[] = $datei->getPathname();
        }
    }
    sort($gefunden);
    return $gefunden;
}

// Ausgenommen sind genau die beiden Dateien, die das Rollenmodell selbst
// bilden: Role normalisiert, Permission ordnet zu. Irgendwo MUSS eine Rolle
// mit einer Rolle verglichen werden - aber nur dort.
$ausnahmen = ['class/Helper/Role.php', 'class/Helper/Permission.php'];

$dateien = array_merge(
    quellDateien($ROOT . '/class', 'php'),
    quellDateien($ROOT . '/config', 'php'),
    quellDateien($ROOT . '/cron', 'php'),
    [$ROOT . '/index.php'],
    quellDateien($ROOT . '/assets/js', 'js')
);

$treffer = [];
$geprueft = 0;
foreach ($dateien as $datei) {
    $relativ = ltrim(str_replace(realpath($ROOT), '', realpath($datei)), '/\\');
    $relativ = str_replace('\\', '/', $relativ);
    if (in_array($relativ, $ausnahmen, true)) continue;

    $inhalt = file_get_contents($datei);
    $inhalt = substr($datei, -3) === '.js' ? stripJsCommentLines($inhalt) : stripPhpNoise($inhalt);
    $geprueft++;

    foreach (explode("\n", $inhalt) as $nr => $zeile) {
        if (preg_match($muster_links, $zeile) || preg_match($muster_rechts, $zeile)) {
            $treffer[] = $relativ . ':' . ($nr + 1) . '  ' . trim($zeile);
        }
    }
}
check($geprueft > 25, "es wurden nur $geprueft Dateien geprueft - stimmt der Pfad?");
check($treffer === [],
    "Vergleichsoperator auf einem Rollenwert gefunden. Statt dessen ein "
    . "benanntes Recht (Permission::has / Auth::can) benutzen:\n    "
    . implode("\n    ", $treffer));
ok("$geprueft Dateien enthalten keinen Vergleich auf einem Rollenwert");

// ---------------------------------------------------------------------
fwrite(STDERR, "\n9) Eigentum steht in der WHERE-Klausel\n");

$fake = new FakeConnection();
PdoConnect::$connection = $fake;
FakeStatement::$affected = 1;

// Aendern: Der Eigentuemer gehoert ins Statement, nicht nur in den Controller.
$loc = new Location();
$refl = new ReflectionObject($loc);
$feld = $refl->getProperty('id');
$feld->setAccessible(true);
$feld->setValue($loc, 42);

$loc->setDescription('neue Beschreibung');
check($loc->updateLocation(7) === true, 'Aenderung des eigenen Standorts');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'UPDATE location') !== false, 'es ist ein UPDATE');
check(preg_match('/WHERE\s+id\s*=\s*:id\s+AND\s+user_id\s*=\s*:user_id/i', $sql) === 1,
    "Eigentuemer fehlt in der Bedingung: $sql");
check($fake->statements[0]->params[':user_id'] === 7, 'Eigentuemer gebunden');
check($fake->statements[0]->params[':id'] === 42, 'Standort gebunden');
ok('updateLocation traegt user_id in der WHERE-Klausel');

// Loeschen: dasselbe, und der Rueckgabewert sagt die Wahrheit.
$fake->statements = [];
$loc2 = new Location();
check($loc2->deleteLocation(42, 7) === true, 'Loeschen des eigenen Standorts');
$sql = $fake->statements[0]->sql;
check(preg_match('/DELETE\s+FROM\s+location\s+WHERE\s+id\s*=\s*:id\s+AND\s+user_id\s*=\s*:user_id/i', $sql) === 1,
    "Eigentuemer fehlt in der Bedingung: $sql");
check($fake->statements[0]->params[':user_id'] === 7, 'Eigentuemer gebunden');
ok('deleteLocation traegt user_id in der WHERE-Klausel');

// Trifft die Bedingung nichts, ist es kein Erfolg. Vorher meldete die
// Methode auch dann "erledigt", wenn gar nichts geloescht wurde.
FakeStatement::$affected = 0;
$fake->statements = [];
check((new Location())->deleteLocation(42, 999) === false, 'fremder Standort wird nicht als geloescht gemeldet');
ok('kein Treffer heisst kein Erfolg');
FakeStatement::$affected = 1;

// Ohne Benutzer wird gar kein Statement abgesetzt.
$fake->statements = [];
check((new Location())->deleteLocation(42, 0) === false, 'ohne Benutzer kein Loeschen');
check((new Location())->deleteLocation(0, 7)  === false, 'ohne Standort kein Loeschen');
$loc3 = new Location();
$feld->setValue($loc3, 42);
check($loc3->updateLocation(0) === false, 'ohne Benutzer keine Aenderung');
check(count($fake->statements) === 0, 'kein Statement ohne vollstaendige Angaben');
ok('unvollstaendige Angaben erreichen die Datenbank nicht');

// Die Sperre ist bewusst nicht an das Eigentum gebunden: Gesperrt werden
// gerade fremde Standorte. Wer das darf, entscheidet das Recht location.block.
$fake->statements = [];
check((new Location())->block(42, 1, 'Spam') === true, 'Sperren');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'blocked        = 1') !== false, 'Sperrkennzeichen wird gesetzt');
check(strpos($sql, 'user_id') === false, 'die Sperre fragt bewusst nicht nach dem Eigentuemer');
check(strpos($sql, 'DELETE') === false, 'gesperrt wird, nicht geloescht');
check($fake->statements[0]->params[':reason'] === 'Spam', 'Grund wird gespeichert');
ok('block() sperrt fremde Standorte, ohne sie zu loeschen');

$fake->statements = [];
check((new Location())->unblock(42) === true, 'Freigeben');
check(strpos($fake->statements[0]->sql, 'blocked        = 0') !== false, 'Sperre wird aufgehoben');
ok('unblock() gibt wieder frei');

// Die Uebersicht zeigt gesperrte Standorte nicht - ausser der Moderation.
$fake->statements = [];
(new Location())->selectAllLocations(7);
check(strpos($fake->statements[0]->sql, 'location.blocked = 0') !== false,
    'gesperrte Standorte fehlen in der Uebersicht');
$fake->statements = [];
(new Location())->selectAllLocations(7, true);
check(strpos($fake->statements[0]->sql, 'location.blocked = 0') === false,
    'die Moderation sieht auch gesperrte Standorte');
ok('die Sperre wirkt in der Abfrage, nicht erst in der Anzeige');

// Der Guide sieht seinen gesperrten Standort samt Grund.
$fake->statements = [];
(new Location())->selectAllLocationsOfOneUser(7);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'location.blocked') !== false && strpos($sql, 'blocked_reason') !== false,
    'eigene Liste enthaelt Sperre und Grund');
check(strpos($sql, 'location.blocked = 0') === false, 'die eigene Liste verbirgt nichts');
ok('der betroffene Guide bekommt Sperre und Grund geliefert');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n10) Standort-Tabellen: id und Spaltenzahl\n");

/**
 * Liest die Spaltenzahl (<th> im ersten <thead>) je Tabellen-id aus einem
 * HTML-Baustein.
 *
 * Bewusst mit einem Muster statt mit einem HTML-Parser: Die Templates sind
 * Fragmente, kein vollstaendiges Dokument, und geprueft wird genau eine
 * einfache Zusage - so viele <th>, wie das JavaScript Zellen liefert.
 *
 * @param string $datei
 * @return array<string,int> id => Anzahl der Kopfspalten
 */
function tabellenSpalten($datei) {
    $html = file_get_contents($datei);
    $ergebnis = [];
    if (!preg_match_all('/<table\b[^>]*\bid=["\']([^"\']+)["\'][^>]*>(.*?)<\/table>/is', $html, $treffer, PREG_SET_ORDER)) {
        return $ergebnis;
    }
    foreach ($treffer as $tabelle) {
        if (preg_match('/<thead\b.*?<tr\b(.*?)<\/tr>/is', $tabelle[2], $kopf)) {
            $ergebnis[$tabelle[1]] = preg_match_all('/<th\b/i', $kopf[1]);
        }
    }
    return $ergebnis;
}

$uebersicht = tabellenSpalten($ROOT . '/assets/html/locations_table.html');
$eigene     = tabellenSpalten($ROOT . '/assets/html/settings.html');

// Beide Tabellen brauchen verschiedene ids. Hiessen sie gleich, entschied
// nur die Reihenfolge im Initialisierungsblock, welche Tabelle mit welchen
// Zeilen befuellt wird - die Uebersicht hat eine Spalte mehr, DataTables
// brach dann mit "Incorrect column count" ab.
check(isset($uebersicht['locationsTable']), 'Uebersicht heisst locationsTable');
check(isset($eigene['myLocationsTable']),   'eigene Standorte heissen myLocationsTable');
check(array_intersect(array_keys($uebersicht), array_keys($eigene)) === [],
    'keine Tabellen-id kommt in beiden Templates vor');
ok('jede Standort-Tabelle hat ihre eigene id');

// Die Spaltenzahl im Template muss zu der Liste passen, aus der das
// JavaScript die Zellen baut (locationsTable.columnKeys).
$js = file_get_contents($ROOT . '/assets/js/locations_table.js');
check(preg_match('/columnKeys\s*\(options\)\s*\{\s*return options\.onlyOwn\s*\?\s*\[(.*?)\]\s*:\s*\[(.*?)\]/s', $js, $spalten) === 1,
    'columnKeys() ist in locations_table.js zu finden');
$spaltenEigene     = preg_match_all("/'[a-z]+'/", $spalten[1]);
$spaltenUebersicht = preg_match_all("/'[a-z]+'/", $spalten[2]);

check($uebersicht['locationsTable'] === $spaltenUebersicht,
    "Uebersicht: {$uebersicht['locationsTable']} Spalten im Template, $spaltenUebersicht im JavaScript");
check($eigene['myLocationsTable'] === $spaltenEigene,
    "eigene Standorte: {$eigene['myLocationsTable']} Spalten im Template, $spaltenEigene im JavaScript");
check($spaltenUebersicht === $spaltenEigene + 1,
    'die Uebersicht hat genau eine Spalte mehr (User)');
ok('Kopfzeile und Zeilenaufbau haben ueberall dieselbe Spaltenzahl');

// Die Tabellenkonfiguration steht an genau einer Stelle. Vorher war sie
// dreimal ausgeschrieben (Initialisierung, Loeschen-Handler,
// Beschreibung-aendern-Formular) und musste von Hand gleichgehalten werden.
check(preg_match_all('/tableSelector\s*:\s*[\'"]#/', $js) === 0,
    'kein fest verdrahteter tableSelector ausserhalb von TABLES');
check(preg_match_all('/[\'"]#(?:my)?locationsTable[\'"]/i', $js) === 2,
    'die beiden Selektoren stehen nur in TABLES');
ok('jede Tabelle wird aus einer einzigen Konfiguration heraus geladen');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n11) Die Guide-Rolle wird angenommen, nicht vergeben\n");

/**
 * Attrappe fuer die drei Tabellen, die App\Model\GuideRole anfasst: `user`,
 * `guide_profile` und die Anzahl der Standorte. Antwortet je nach Statement
 * und schreibt alle abgesetzten mit.
 */
class FakeGuideStatement {
    public $sql; public $params = []; private $db;
    public function __construct($sql, $db) { $this->sql = $sql; $this->db = $db; }
    public function bindParam($k, &$v, $type = null) { $this->params[$k] = $v; }
    public function execute() { $this->db->ausgefuehrt[] = $this; return true; }
    public function rowCount() { return 1; }
    public function fetchColumn($i = 0) { return $this->db->standorte; }
    public function fetchAll($mode = null) { return []; }
    public function fetch($mode = null) {
        if (strpos($this->sql, 'guide_profile') !== false) return $this->db->profil;
        if (strpos($this->sql, 'FROM user')     !== false) return $this->db->benutzer;
        return false;
    }
}
class FakeGuideConnection {
    public $ausgefuehrt = [];
    public $benutzer;          // Zeile aus `user`
    public $profil    = false; // Zeile aus `guide_profile` oder false
    public $standorte = 0;     // COUNT(*) aus `location`
    public function prepare($sql) { return new FakeGuideStatement($sql, $this); }
    /** Alle abgesetzten Statements, die diesen Text enthalten. */
    public function mit($teil) {
        $treffer = [];
        foreach ($this->ausgefuehrt as $stmt) {
            if (strpos($stmt->sql, $teil) !== false) $treffer[] = $stmt;
        }
        return $treffer;
    }
}

$gdb = new FakeGuideConnection();
PdoConnect::$connection = $gdb;

// --- Wem wird die Frage gestellt ---------------------------------------
// Trial heisst "noch nicht entschieden" - ohne jeden Datenbankzugriff.
$gdb->ausgefuehrt = [];
check(GuideRole::needsDecision(5, Role::TRIAL) === true, 'Trial wird gefragt');
check(count($gdb->ausgefuehrt) === 0, 'fuer Trial braucht es keine Abfrage');

// User hat sich entschieden und wird nicht wieder gefragt.
check(GuideRole::needsDecision(5, Role::USER)  === false, 'User wird nicht wieder gefragt');
check(GuideRole::needsDecision(5, Role::ADMIN) === false, 'der Admin steht ausserhalb');
check(GuideRole::needsDecision(0, Role::TRIAL) === false, 'ohne Benutzer keine Frage');
ok('gefragt wird, wessen Entscheidung noch aussteht');

// Ein Guide mit gueltiger Zustimmung wird in Ruhe gelassen.
$gdb->profil = ['user_id' => 5, 'guide_since' => '2026-01-01 00:00:00',
                'terms_version' => GuideRole::TERMS_VERSION,
                'terms_accepted_at' => '2026-01-01 00:00:00', 'resigned_at' => null];
check(GuideRole::needsDecision(5, Role::GUIDE) === false, 'zugestimmt ist zugestimmt');

// Der Hebel fuer die spaetere Abrechnung: Wer einer aelteren Fassung
// zugestimmt hat, bekommt den Dialog erneut. Genau darueber laeuft spaeter
// die Zustimmung zu kostenpflichtigen Fuehrungen.
$gdb->profil['terms_version'] = GuideRole::TERMS_VERSION - 1;
check(GuideRole::needsDecision(5, Role::GUIDE) === true, 'alte Fassung wird erneut vorgelegt');

// Ein Guide ohne Profil hat nie zugestimmt (Rolle von Hand gesetzt).
$gdb->profil = false;
check(GuideRole::needsDecision(5, Role::GUIDE) === true, 'ohne Zustimmung wird gefragt');
ok('eine neue Fassung der Bedingungen legt die Frage erneut vor');

// --- Annehmen -----------------------------------------------------------
$gdb->benutzer   = fakeUser(5, Role::USER);
$gdb->profil     = false;
$gdb->ausgefuehrt = [];
check(GuideRole::accept(5, Role::USER) === true, 'ein Zuschauer nimmt die Rolle an');

$zustimmung = $gdb->mit('INSERT INTO guide_profile');
check(count($zustimmung) === 1, 'die Zustimmung wird genau einmal festgehalten');
check((int)$zustimmung[0]->params[':version'] === GuideRole::TERMS_VERSION,
    'festgehalten wird die Fassung, die im Dialog stand');
check(strpos($zustimmung[0]->sql, 'terms_accepted_at') !== false, 'mit Zeitpunkt');
check(strpos($zustimmung[0]->sql, 'guide_since')       !== false, 'mit Beginn');
check(strpos($zustimmung[0]->sql, 'resigned_at       = NULL') !== false,
    'ein Wiedereinstieg loescht den Widerruf');

$rolle = $gdb->mit('UPDATE user SET');
check(count($rolle) === 1, 'die Rolle wird genau einmal geschrieben');
check((int)$rolle[0]->params[':type_id'] === Role::GUIDE, 'und zwar auf Guide');
ok('annehmen heisst: Zustimmung festhalten UND Rolle setzen');

// Der Admin kommt hier nicht durch - er wuerde seine Adminrechte verlieren.
$gdb->ausgefuehrt = [];
check(GuideRole::accept(5, Role::ADMIN) === false, 'der Admin wird nicht zum Guide');
check($gdb->mit('UPDATE user SET') === [], 'und seine Rolle bleibt unangetastet');
ok('ein Klick entmachtet keinen Admin');

// --- Zurueckgeben -------------------------------------------------------
// Mit Standorten geht es nicht: Ein Standort ohne Guide waere ein Angebot,
// das niemand einloesen kann.
$gdb->benutzer    = fakeUser(5, Role::GUIDE);
$gdb->standorte   = 2;
$gdb->ausgefuehrt = [];
check(GuideRole::hasLocations(5) === true, 'die Standorte werden gezaehlt');
check(GuideRole::resign(5, Role::GUIDE) === false, 'mit Standorten kein Widerruf');
check($gdb->mit('UPDATE user SET') === [], 'die Rolle bleibt stehen');
check($gdb->mit('DELETE') === [], 'und geloescht wird nichts');
ok('wer noch Standorte anbietet, bleibt Guide');

// Ohne Standorte klappt es, und das Profil bleibt als Beleg stehen.
$gdb->standorte   = 0;
$gdb->ausgefuehrt = [];
check(GuideRole::resign(5, Role::GUIDE) === true, 'ohne Standorte geht der Widerruf');
$rolle = $gdb->mit('UPDATE user SET');
check(count($rolle) === 1 && (int)$rolle[0]->params[':type_id'] === Role::USER,
    'aus dem Guide wird wieder ein Zuschauer');
check(count($gdb->mit('resigned_at = CURRENT_TIMESTAMP')) === 1, 'mit Zeitpunkt vermerkt');
check($gdb->mit('DELETE FROM guide_profile') === [],
    'die Zustimmung von damals wird nicht geloescht');
ok('der Widerruf wird vermerkt, nicht weggeraeumt');

// Wer gar kein Guide ist, kann auch nichts zurueckgeben.
$gdb->ausgefuehrt = [];
check(GuideRole::resign(5, Role::USER) === false, 'ein Zuschauer gibt nichts zurueck');
check($gdb->mit('UPDATE user SET') === [], 'ohne Schreibzugriff');
ok('zurueckgeben kann nur, wer die Rolle hat');

// --- Die Zaehlung selbst ------------------------------------------------
$gdb->ausgefuehrt = [];
$gdb->standorte   = 3;
check((new Location())->countLocationsOfUser(5) === 3, 'COUNT wird durchgereicht');
$zaehlung = $gdb->mit('SELECT COUNT(*) FROM location');
check(count($zaehlung) === 1, 'gezaehlt wird in der Datenbank, nicht im PHP');
check(strpos($zaehlung[0]->sql, 'user_id = :user_id') !== false, 'auf den Eigentuemer begrenzt');
$gdb->ausgefuehrt = [];
check((new Location())->countLocationsOfUser(0) === 0, 'ohne Benutzer null');
check(count($gdb->ausgefuehrt) === 0, 'und ohne Abfrage');
ok('die Standortzahl kommt aus einem COUNT auf den Eigentuemer');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n12) Jeder Platzhalter im Template wird auch gefuellt\n");

/**
 * Sammelt die ###PLATZHALTER### einer Vorlage.
 *
 * @param string $datei
 * @return string[]
 */
/**
 * Wie tief stehen Formulare in diesem HTML ineinander?
 *
 * HTML KENNT KEINE VERSCHACHTELTEN FORMULARE, und der Parser meldet das
 * nicht: Er verwirft das innere <form> ersatzlos und schliesst mit dessen
 * </form> das AEUSSERE. Alles, was im Quelltext danach kommt, gehoert dann zu
 * keinem Formular mehr - es steht sichtbar auf der Seite, wird beim Absenden
 * aber nicht mitgeschickt. Ein Fehler, den man an der Seite nicht sieht,
 * sondern erst an den Daten danach.
 *
 * Gezaehlt wird auf Textebene und nicht mit einem Parser: Genau der wuerde
 * das Problem ja wegraeumen, statt es zu zeigen. Kommentare fallen vorher
 * heraus - in den Vorlagen dieser Anwendung stehen die Platzhalter samt
 * Beispielmarkup im einleitenden Kommentarblock.
 *
 * @param string $html
 * @return int 1 ist in Ordnung, alles darueber ist der Fehler
 */
function formularTiefe(string $html): int {
    $roh = preg_replace('/<!--.*?-->/s', '', $html);

    $tiefe = 0;
    $max   = 0;
    preg_match_all('#</?form\b#i', (string)$roh, $treffer);
    foreach ($treffer[0] as $marke) {
        if (strpos($marke, '/') !== false) {
            $tiefe = max(0, $tiefe - 1);
        } else {
            $tiefe++;
            $max = max($max, $tiefe);
        }
    }
    return $max;
}

function platzhalter($datei) {
    preg_match_all('/###[A-Z_]+###/', file_get_contents($datei), $treffer);
    return array_values(array_unique($treffer[0]));
}

// Ein Platzhalter, den niemand ersetzt, steht als ###GUIDEBTN### auf der
// Seite - sichtbar fuer jeden Benutzer. Geprueft werden die beiden Vorlagen
// dieses Umbaus gegen ihren Controller.
$vorlagen = [
    'assets/html/guide_role.html' => 'class/Controller/GuideController.php',
    'assets/html/settings.html'   => 'class/Controller/SettingsController.php',
    // Die fuenf Platzhalter, ueber die eine abgelehnte Eingabe zurueck ins
    // Formular kommt. Bliebe einer unbesetzt, stuende ###DESCRIPTION### als
    // Text im Beschreibungsfeld.
    'assets/html/set_location.html' => 'class/Controller/LocationController.php',
    // Das Hauptlayout. Es traegt die Platzhalter, die auf JEDER Seite stehen -
    // darunter den Anfragenzaehler der Kopfleiste. Bliebe einer unbesetzt,
    // stuende er in der Kopfleiste jeder einzelnen Seite.
    'assets/html/index.html'        => 'class/Helper/ViewHelper.php',
];
foreach ($vorlagen as $vorlage => $controller) {
    $code = file_get_contents($ROOT . '/' . $controller);
    foreach (platzhalter($ROOT . '/' . $vorlage) as $marke) {
        check(strpos($code, $marke) !== false,
            "$marke aus $vorlage wird in $controller nicht ersetzt");
    }
}
ok('guide_role.html, settings.html, set_location.html und das Hauptlayout haben keinen unbesetzten Platzhalter');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n12b) Veraltete Guide-Bedingungen greifen dort, wo die Rolle benutzt wird\n");

// GuideRole::needsDecision() stand eine Weile ohne Aufrufer da - der Login
// stellte die Frage nicht mehr, und eine erhoehte TERMS_VERSION wirkte
// dadurch ueberhaupt nicht. Die Pruefung sitzt jetzt dort, wo ein Guide seine
// Rolle tatsaechlich benutzt: beim Anlegen eines Standorts.
$guideCode = file_get_contents($ROOT . '/class/Controller/GuideController.php');
$locCode   = file_get_contents($ROOT . '/class/Controller/LocationController.php');

check(method_exists('App\\Controller\\GuideController', 'requireCurrentTerms'),
    'GuideController::requireCurrentTerms fehlt');
check(strpos(methodenRumpf($guideCode, 'requireCurrentTerms'), 'needsDecision') !== false,
    'requireCurrentTerms fragt gar nicht nach der Zustimmung');

// BEIDE Methoden, nicht nur die Seite: Ein POST erreicht setLocation() auch
// ohne den Umweg ueber das Formular. Eine Pruefung, die sich durch
// Ueberspringen der Seite umgehen laesst, ist keine.
foreach (['setLocationPage', 'setLocation'] as $methode) {
    check(strpos(methodenRumpf($locCode, $methode), 'requireCurrentTerms') !== false,
        "LocationController::$methode() prueft die Zustimmung nicht");
}
ok('das Standortformular haelt an, solange die Zustimmung offen ist');

// Der Admin darf Standorte anlegen (location.create), hat aber kein
// user.guide_role. Wuerde ihn requireCurrentTerms zur Dialogseite schicken,
// endete das in einer Absage von index.php - deshalb muss needsDecision()
// fuer ihn falsch bleiben. Ohne Datenbankzugriff, denn seine Rolle genuegt.
$adminDb = new FakeConnection();
PdoConnect::$connection = $adminDb;
check(GuideRole::needsDecision(1, Role::ADMIN) === false, 'der Admin wird nicht gefragt');
check(count($adminDb->statements) === 0, 'und dafuer wird nichts abgefragt');
check(Permission::has(Role::ADMIN, Permission::LOCATION_CREATE) === true, 'er legt aber an');
check(Permission::has(Role::ADMIN, Permission::USER_GUIDE_ROLE) === false,
    'und kaeme auf der Dialogseite nicht durch');
ok('der Admin laeuft nicht in eine Weiterleitung, die er nicht aufrufen darf');

// Die Weiterleitung darf keine Sackgasse sein: Ein Guide mit veralteter
// Zustimmung braucht auf der Dialogseite einen Knopf zum Zustimmen. Vorher
// sah er dort ausschliesslich "Guide-Rolle zurueckgeben".
$dialog = methodenRumpf($guideCode, 'showGuideRolePage');
check(strpos($dialog, 'needsDecision') !== false,
    'die Dialogseite unterscheidet den Fall gar nicht');
check(preg_match("/button\('accept'/", $dialog) === 1,
    'die Dialogseite bietet dem Guide kein Zustimmen an');
ok('wer hergeschickt wird, kann dort auch zustimmen');

// Sichtbar, nicht nur sperrend: Einstellungsseite und Kopfleiste sagen es,
// bevor jemand am gesperrten Formular ankommt.
check(strpos(methodenRumpf(file_get_contents($ROOT . '/class/Controller/SettingsController.php'),
    'showSettingsPage'), 'needsDecision') !== false,
    'die Einstellungen zeigen den offenen Punkt nicht');
$viewCode = file_get_contents($ROOT . '/class/Helper/ViewHelper.php');
check(strpos($viewCode, "'termsOutdated'") !== false,
    'window.userCan meldet den offenen Punkt nicht an den Client');
check(strpos(file_get_contents($ROOT . '/assets/js/ui.js'), 'termsOutdated') !== false,
    'der Knopf der Kopfleiste wertet ihn nicht aus');
ok('Einstellungen und Kopfleiste melden offene Bedingungen');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n13) Die oeffentliche Karte gibt keine Personendaten heraus\n");

// Diese Abfrage beantwortet auch Anfragen ohne Anmeldung. Was sie
// zurueckgibt, ist damit oeffentlich - deshalb wird hier nicht die Absicht
// geprueft, sondern das Statement selbst.
$mapDb = new FakeConnection();
PdoConnect::$connection = $mapDb;
(new Location())->selectPublicMapLocations();
check(count($mapDb->statements) === 1, 'genau eine Abfrage');
$sql = $mapDb->statements[0]->sql;

// Geprueft werden die SPALTEN DER ANTWORT, nicht Textstellen im Statement:
// Was der Server herausgibt, sind die Ausgabenamen der Auswahlliste. Die
// JOIN-Bedingung darf user.id nennen, sonst gaebe es keine Verknuepfung, und
// der Anwesenheitsstatus darf in einem CASE vorkommen - dort wird er ja
// gerade uebersetzt, damit er die Antwort NICHT erreicht.
check(preg_match('/SELECT(.*?)\bFROM\b/is', $sql, $t) === 1, "kein SELECT gefunden:\n$sql");

// VORHER AUFRAEUMEN, sonst zaehlt der Parser Woerter mit, die keine Spalten
// sind: Kommentare erklaeren die Auswahlliste (und enthalten Kommas), und
// Klammerausdruecke - CASE mit TIMESTAMPDIFF, COALESCE, die gruppierten
// Teilabfragen der Bewertung - tragen ihre eigenen. Uebrig bleibt die
// Auswahlliste selbst, und nur ihre Ausgabenamen erreichen den Browser.
$auswahl = preg_replace('/--[^\n]*/', '', $t[1]);
// Klammern von innen nach aussen entfernen, bis keine mehr da sind.
do {
    $vorher  = $auswahl;
    $auswahl = preg_replace('/\([^()]*\)/', ' ', $auswahl);
} while ($auswahl !== $vorher);

$spalten = [];
foreach (explode(',', $auswahl) as $stueck) {
    // Ausgabename ist der letzte Bezeichner des Ausdrucks - mit AS oder ohne.
    if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*$/s', trim($stueck), $n)) {
        $spalten[] = strtolower($n[1]);
    }
}
$spalten = array_values(array_unique($spalten));
sort($spalten);
// title steht seit migrations/011 dabei: Das Kartenfenster zeigt die
// Ueberschrift des Angebots statt nur des Ortsnamens. Er ist Inhalt des
// Angebots wie die Beschreibung auch - keine Personenangabe.
//
// Die drei review_*-Spalten seit migrations/016: Sie sagen, wie ein STANDORT
// bewertet wurde - Anzahl, Durchschnitt (nur oberhalb der Schwelle, sonst
// NULL) und die Zahl der durchgefuehrten Fuehrungen. Ueber einzelne Konten
// steht darin nichts, und dieselben Zahlen stehen auf der Standortseite, die
// ein Gast ebenfalls aufrufen darf.
$erlaubt = ['availability', 'city_name', 'country_name', 'description', 'id',
            'latitude', 'longitude', 'title',
            'review_count', 'review_average', 'review_tours'];
sort($erlaubt);
check($spalten === $erlaubt,
    "die oeffentliche Karte liefert andere Spalten als erlaubt:\n  ist:      "
    . implode(',', $spalten) . "\n  erlaubt:  " . implode(',', $erlaubt));

// Und nirgends im Statement ein Benutzername.
check(stripos($sql, 'username') === false, "Benutzername im Statement:\n$sql");

// Der Anwesenheitsstatus wird uebersetzt und nicht durchgereicht: Aus der
// Antwort geht hervor, dass an einem Ort jemand erreichbar ist, nicht wer.
check(stripos($sql, 'AS availability') !== false, "kein uebersetzter Zustand:\n$sql");
foreach (['live', 'busy', 'idle'] as $wert) {
    check(strpos($sql, "'$wert'") !== false, "Verfuegbarkeitswert '$wert' fehlt");
}

// Gesperrte Standorte sind fuer niemanden sichtbar, der sie nicht moderiert.
check(preg_match('/WHERE\s+location\.blocked\s*=\s*0/i', $sql) === 1,
    "gesperrte Standorte werden nicht ausgeschlossen:\n$sql");
ok('selectPublicMapLocations liefert Ort, Beschreibung und Verfuegbarkeit - sonst nichts');

// Die Route dazu haengt am oeffentlichen Recht, die vollstaendige Liste
// weiterhin am angemeldeten. Ein Vertauschen der beiden waere der Fehler,
// der hier auffallen soll.
check($routes['get_map_locations'][2] === Permission::LOCATION_MAP_PUBLIC,
    'get_map_locations haengt am oeffentlichen Recht');
check($routes['get_map_locations'][3] === 'json', 'get_map_locations antwortet als JSON');
check($routes['get_locations'][2] === Permission::LOCATION_LIST,
    'die vollstaendige Liste bleibt am angemeldeten Recht');
check(Permission::has(Permission::GUEST, Permission::LOCATION_LIST) === false,
    'ein Gast kommt nicht an die vollstaendige Liste');
check(Permission::has(Permission::GUEST, Permission::LOCATION_MAP_PUBLIC) === true,
    'ein Gast kommt an die Karte');
ok('die beiden Standortrouten haengen an verschiedenen Rechten');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n14) Kein Platzhalter erreicht den Browser im Kommentar\n");

// DER FEHLER, DEN DIESER TEST FESTHAELT
// -------------------------------------
// Die Vorlagen tragen am Anfang einen Kommentar, der ihre Platzhalter
// erklaert - dort steht ###USER_ROWS### also auch als Beschreibung.
// str_replace() kennt keine Kommentare und ersetzte beide Vorkommen. Bei der
// Benutzerliste brachten die eingesetzten Zeilen den Kommentarkopf von
// list_user_row.html mit; dessen "-->" schloss den aeusseren Kommentar
// vorzeitig. Danach stand die halbe Tabelle ein zweites Mal ueber der
// Ueberschrift, mit dem Kommentartext als nacktem Text daneben.
//
// Geprueft wird deshalb nicht die Absicht, sondern das Ergebnis: Was
// ViewHelper::template() liefert, darf keinen Platzhalter mehr in einem
// Kommentar haben.
$vorlagenDateien = glob($ROOT . '/assets/html/*.html');
check(count($vorlagenDateien) > 0, 'keine Vorlagen gefunden');

$mitKommentarPlatzhalter = [];
foreach ($vorlagenDateien as $datei) {
    $sauber = ViewHelper::template($datei);
    if (preg_match_all('/<!--.*?-->/s', $sauber, $bloecke)) {
        foreach ($bloecke[0] as $block) {
            if (preg_match('/###[A-Z0-9_]+###/', $block)) {
                $mitKommentarPlatzhalter[] = basename($datei);
            }
        }
    }
}
check($mitKommentarPlatzhalter === [],
    'Platzhalter in Kommentaren: ' . implode(', ', array_unique($mitKommentarPlatzhalter)));
ok('kein Platzhalter steht nach dem Laden noch in einem Kommentar');

// Die Dokumentation soll dabei in der DATEI bleiben - entfernt wird sie erst
// beim Laden. Sonst waere die Loesung, die Kommentare zu loeschen.
$rohListe = file_get_contents($ROOT . '/assets/html/list_user.html');
check(strpos($rohListe, '###USER_ROWS###  Die Zeilen') !== false,
    'die Beschreibung in list_user.html ist verschwunden');
ok('die Beschreibung bleibt in der Vorlage stehen');

// Ein Kommentar MITTEN im Markup ist eine Anmerkung an Ort und Stelle und
// darf nicht mitgeloescht werden.
check(strpos(ViewHelper::template($ROOT . '/assets/html/list_user.html'), 'Keine ID-Spalte') !== false,
    'ein Kommentar im Markup wurde mitentfernt');

// Und die Platzhalter selbst muessen die Behandlung ueberleben.
$zeile = ViewHelper::template($ROOT . '/assets/html/list_user_row.html');
foreach (['###STATUS###', '###CALL###', '###USERNAME###', '###EMAIL###', '###ACTION###'] as $marke) {
    check(strpos($zeile, $marke) !== false, "$marke ging beim Laden verloren");
}
ok('Markup-Kommentare und Platzhalter bleiben erhalten');

// Kein Controller laedt eine Vorlage noch an der Hilfsmethode vorbei - sonst
// gaebe es wieder einen Weg, auf dem der Kommentar in den Browser kommt.
$roheLader = [];
foreach (glob($ROOT . '/class/Controller/*.php') as $datei) {
    if (preg_match('/file_get_contents\(\s*[\'"]assets\/html\//', file_get_contents($datei))) {
        $roheLader[] = basename($datei);
    }
}
check($roheLader === [],
    'laedt eine Vorlage ohne ViewHelper::template(): ' . implode(', ', $roheLader));
ok('alle Vorlagen laufen ueber ViewHelper::template()');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n15) Symbolknoepfe in den PHP-Tabellen tragen Label und Tooltip\n");

// Dieselbe Bedingung wie auf der JavaScript-Seite: Ein Knopf ohne Text ist
// nur benutzbar, wenn er aria-label (Vorleseprogramm) und title (Tooltip)
// hat. Geprueft wird die Quelle, weil die Zellenbauer privat sind und ohne
// Datenbank nicht aufgerufen werden koennen.
foreach ([
    'class/Controller/UserController.php' => 'Benutzerliste',
    'class/Controller/ChatController.php' => 'Chatliste',
] as $datei => $was) {
    $code = file_get_contents($ROOT . '/' . $datei);

    // Jedes Vorkommen von app-iconbtn ist ein Symbolknopf. Zu jedem muss im
    // selben Ausdruck ein aria-label und ein title gehoeren.
    $anzahl = preg_match_all('/app-iconbtn app-iconbtn--/', $code);
    check($anzahl > 0, "$was hat keinen Symbolknopf");
    check(preg_match_all('/aria-label="/', $code) >= $anzahl,
        "$was: nicht jeder Symbolknopf hat ein aria-label");
    check(preg_match_all('/title="/', $code) >= $anzahl,
        "$was: nicht jeder Symbolknopf hat einen Tooltip");
}
ok('Benutzerliste und Chatliste beschriften ihre Symbolknoepfe');

// Die Hauptaktion behaelt Text und Flaeche - sie sagt, worum es in der Liste
// geht. Wuerde sie auch zum Symbol, waere die Zeile eine Reihe gleich lauter
// Zeichen ohne Schwerpunkt.
$uc = file_get_contents($ROOT . '/class/Controller/UserController.php');
check(strpos($uc, '>Anrufen</button>') !== false,
    'der Anruf-Knopf der Benutzerliste hat seine Beschriftung verloren');
check(strpos($uc, 'btn btn-sm start-call-btn') !== false,
    'der Anruf-Knopf ist kein Flaechenknopf mehr');
ok('die Hauptaktion behaelt Text und Flaeche');

// Und die Symbole selbst stehen an EINER Stelle - in der CSS-Datei als
// Maske, nicht als SVG in PHP und noch einmal in JavaScript.
$css = file_get_contents($ROOT . '/assets/css/theme.css');
foreach (['chat', 'edit', 'trash', 'lock', 'unlock', 'history'] as $symbol) {
    check(strpos($css, '--icon-' . $symbol . ':') !== false,
        "das Symbol $symbol fehlt in theme.css");
}
foreach (['class/Controller/UserController.php',
          'class/Controller/ChatController.php',
          'assets/js/locations_table.js'] as $datei) {
    check(stripos(file_get_contents($ROOT . '/' . $datei), '<svg') === false,
        "$datei zeichnet ein eigenes SVG statt die Klasse zu setzen");
}
ok('die Symbole stehen einmal in theme.css, nicht in jedem Tabellenbauer');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n16) Farbprofile lassen die Nadelfarben in Ruhe\n");

// DIE REGEL
// Gruen heisst auf der Karte "Guide jetzt verfuegbar", Gelb "im Gespraech",
// Grau "Standort ohne Guide". Wuerde ein Farbprofil einen dieser Werte
// verstellen, hiesse dieselbe Farbe je nach Einstellung etwas anderes - und
// die Legende neben der Karte waere falsch. Geprueft wird deshalb die
// CSS-Datei selbst und nicht die Absicht.
$themeCss = file_get_contents($ROOT . '/assets/css/theme.css');

/**
 * Schneidet einen Regelblock aus der CSS-Datei und liest seine Angaben.
 *
 * Gelesen wird BEIDES - eigene Variablen (--app-...) und gewoehnliche
 * Eigenschaften (color, background-color). Die Variablen braucht die Pruefung
 * der Farbprofile, die Eigenschaften die Pruefung der Bauteile, bei denen es
 * gar keine Variable gibt, auf die man zeigen koennte (der Knopf im
 * Dateifeld weiter unten).
 *
 * @param string $css
 * @param string $selektor
 * @return array<string,string>
 */
function cssBlock(string $css, string $selektor): array {
    $i = strpos($css, $selektor);
    if ($i === false) return [];
    $a = strpos($css, '{', $i);
    $b = strpos($css, "\n}", $a);
    // Kommentare heraus, BEVOR gelesen wird. In dieser Datei stehen ganze
    // Absaetze in den Regelbloecken, und darin kommt ein Doppelpunkt oefter
    // vor als in den Angaben selbst ("wir gar nicht selbst zeichnen:
    // Bildlaufleisten, ..."). Ohne dieses Entfernen liest der Ausdruck einen
    // solchen Satz als Angabe und verschluckt dabei alles bis zum naechsten
    // Semikolon - samt der Angaben, die dazwischen stehen.
    $rumpf = preg_replace('#/\*.*?\*/#s', '', substr($css, $a, $b - $a));

    // Eine Angabe beginnt am Zeilenanfang. Das haelt den Ausdruck davon ab,
    // in einem Wert nach einem zweiten Doppelpunkt zu suchen (data:image/...).
    $werte = [];
    preg_match_all('/^\s*(-{0,2}[a-z][a-z0-9-]*)\s*:\s*([^;]+);/m', $rumpf, $m, PREG_SET_ORDER);
    foreach ($m as $t) $werte[$t[1]] = trim($t[2]);
    return $werte;
}

$NADELN = ['--app-live', '--app-warn-solid', '--app-idle'];

$root = cssBlock($themeCss, ':root {');
check($root !== [], 'der :root-Block wurde nicht gefunden');
foreach ($NADELN as $v) {
    check(isset($root[$v]), "$v steht nicht in :root");
}
ok('die drei Nadelfarben stehen in :root');

// Jedes Profil aus Theme::PROFILE braucht einen CSS-Block - ausser der
// Vorgabe, die IST :root.
foreach (array_keys(Theme::PROFILE) as $schluessel) {
    if ($schluessel === Theme::DEFAULT) continue;
    $sel   = '[data-theme="' . $schluessel . '"]';
    $block = cssBlock($themeCss, $sel);
    check($block !== [], "zum Profil $schluessel fehlt der Block $sel in theme.css");

    // Der Kern dieser Pruefung: kein Profil fasst eine Nadelfarbe an.
    foreach ($NADELN as $v) {
        check(!isset($block[$v]),
            "das Profil $schluessel veraendert $v - diese Farbe gehoert der Karte");
    }
}
ok('kein Profil schreibt eine Nadelfarbe neu');

// Umgekehrt: Ein Profil, das gar nichts aendert, waere ein leerer Eintrag in
// der Auswahl. Jedes Profil muss Grund, Flaeche und Akzent setzen.
foreach (array_keys(Theme::PROFILE) as $schluessel) {
    $block = ($schluessel === Theme::DEFAULT)
        ? $root
        : cssBlock($themeCss, '[data-theme="' . $schluessel . '"]');
    foreach (['--app-bg', '--app-surface', '--app-accent'] as $v) {
        check(isset($block[$v]), "dem Profil $schluessel fehlt $v");
    }
}
ok('jedes Profil setzt Grundflaeche, Flaeche und Akzent');

// Die Farbmuster auf der Kontoseite sind Kopien aus der CSS-Datei. Kopien
// laufen auseinander - deshalb hier der Abgleich.
foreach (Theme::PROFILE as $schluessel => $profil) {
    $block = ($schluessel === Theme::DEFAULT)
        ? $root
        : cssBlock($themeCss, '[data-theme="' . $schluessel . '"]');
    $erwartet = [$block['--app-bg'], $block['--app-surface'], $block['--app-accent']];
    check($profil['muster'] === $erwartet,
        "das Muster von $schluessel zeigt " . implode(' ', $profil['muster'])
        . ', in theme.css steht aber ' . implode(' ', $erwartet));
}
ok('die Farbmuster der Auswahl stimmen mit theme.css ueberein');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n17) Das Farbprofil kommt nur aus der bekannten Liste\n");

check(Theme::isValid('indigo')  === true,  'indigo sollte gueltig sein');
check(Theme::isValid('dunkel')  === true,  'dunkel sollte gueltig sein');
check(Theme::isValid('gibtsnicht') === false, 'ein unbekanntes Profil wurde durchgelassen');
check(Theme::isValid(null)   === false, 'null wurde durchgelassen');
check(Theme::isValid('')     === false, 'der Leerstring wurde durchgelassen');
// Der Wert landet in einem HTML-Attribut. Was hier durchkaeme, stuende in
// data-theme - deshalb die Pruefung gegen eine Liste und nicht gegen ein Muster.
check(Theme::isValid('indigo" onload="x') === false, 'ein Wert mit Anfuehrungszeichen kam durch');
ok('isValid laesst nur bekannte Profile durch');

check(Theme::normalize(null)         === Theme::DEFAULT, 'null ergibt nicht die Vorgabe');
check(Theme::normalize('')           === Theme::DEFAULT, 'Leerstring ergibt nicht die Vorgabe');
check(Theme::normalize('entfallen')  === Theme::DEFAULT, 'ein entfallenes Profil ergibt nicht die Vorgabe');
check(Theme::normalize('dunkel')     === 'dunkel',       'ein gueltiges Profil wurde veraendert');
check(isset(Theme::PROFILE[Theme::DEFAULT]), 'die Vorgabe steht nicht in der Profilliste');
ok('normalize faengt nie gewaehlt, leer und entfallen ab');

// Die Route haengt am Recht der Kontoseite - nicht an einem eigenen, das
// jemand vergessen koennte einzutragen.
check(isset($routes['set_theme']), 'die Route set_theme fehlt');
check($routes['set_theme'][2] === Permission::USER_SETTINGS,
    'set_theme haengt nicht am Recht der Kontoseite');
check($routes['set_theme'][3] === 'json', 'set_theme antwortet nicht als JSON');
check(Permission::has(Permission::GUEST, Permission::USER_SETTINGS) === false,
    'ein Gast kaeme an die Farbwahl');
ok('set_theme haengt am Recht user.settings');

// Gespeichert wird fuer den Angemeldeten. Stuende hier eine Benutzer-ID aus
// der Anfrage, koennte jemand fremde Konten umfaerben.
$sc = file_get_contents($ROOT . '/class/Controller/SettingsController.php');
check(preg_match('/new User\(Auth::userId\(\)\)/', $sc) === 1,
    'setTheme benutzt nicht die angemeldete Benutzer-ID');
check(strpos($sc, "Request::g('user_id'") === false,
    'setTheme liest eine Benutzer-ID aus der Anfrage');
ok('das Profil wird nur am eigenen Konto gespeichert');

// Und das Attribut steht im ausgelieferten HTML, nicht in einem Skript:
// sonst blitzt bei jedem Seitenwechsel das helle Profil auf.
$layout = file_get_contents($ROOT . '/assets/html/index.html');
check(strpos($layout, 'data-theme="###THEME###"') !== false,
    'das <html>-Element traegt keinen Platzhalter fuer das Farbprofil');
$vh = file_get_contents($ROOT . '/class/Helper/ViewHelper.php');
check(strpos($vh, '"###THEME###"') !== false, 'ViewHelper fuellt ###THEME### nicht');
ok('das Profil steht vor dem ersten Zeichnen im HTML');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n18) Der Kachelfilter beruehrt die Nadeln nicht\n");

// Die hellen Kacheln von OpenStreetMap werden im Dunkelprofil umgekehrt.
// Entscheidend ist, WO der Filter liegt: nur auf der Kachelebene.
// Stuende er am Kartenelement (.leaflet-container) oder an der Nadelebene,
// wuerde aus dem Gruen der verfuegbaren Guides ein Rot - und die Legende
// daneben waere falsch. Das ist im Browser nachgemessen; hier steht die
// Absicherung, damit der Selektor nicht spaeter verrutscht.
check(preg_match('/\[data-theme="dunkel"\]\s+\.leaflet-tile-pane\s*\{[^}]*filter:/', $themeCss) === 1,
    'der Kachelfilter haengt nicht an [data-theme="dunkel"] .leaflet-tile-pane');

// Und an keiner Ebene, in der Nadeln, Kartenfenster oder Bedienelemente
// liegen. Auch nicht am Kartenelement selbst - das enthaelt sie alle.
foreach (['.leaflet-container', '.leaflet-marker-pane', '.leaflet-popup-pane',
          '.leaflet-overlay-pane', '.leaflet-control-container'] as $ebene) {
    $muster = '/\[data-theme="[a-z]+"\]\s+' . preg_quote($ebene, '/') . '\s*\{[^}]*filter:/';
    check(preg_match($muster, $themeCss) === 0,
        "ein Profil filtert $ebene - dort liegen die Nadeln");
}
ok('der Filter liegt nur auf der Kachelebene');

// Umgekehrt darf kein HELLES Profil die Kacheln anfassen: Dort ist die
// Karte richtig, wie sie kommt.
foreach (array_keys(Theme::PROFILE) as $schluessel) {
    if ($schluessel === 'dunkel') continue;
    $muster = '/\[data-theme="' . $schluessel . '"\]\s+\.leaflet-tile-pane\s*\{[^}]*filter:/';
    check(preg_match($muster, $themeCss) === 0,
        "das helle Profil $schluessel filtert die Kacheln");
}
ok('die hellen Profile lassen die Kacheln, wie sie sind');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n19) Das Farbprofil gilt auch vor der Anmeldung\n");

// Das Boot-Skript steht im <head> und laeuft vor dem ersten Zeichnen. Was
// es in data-theme schreibt, darf nur aus der bekannten Liste kommen: Der
// Wert stammt sonst aus dem Browserspeicher, den jeder verstellen kann.
$bootGast  = Theme::bootScript(null);
$bootKonto = Theme::bootScript('dunkel');

check(strpos($bootGast, '<script>') !== false, 'bootScript liefert kein Skript');
check(strpos($bootGast, 'data-theme') !== false, 'bootScript setzt data-theme nicht');

// Die Liste der erlaubten Profile wird eingesetzt, nicht im JavaScript
// wiederholt. Sonst gaebe es sie zweimal und sie liefen auseinander.
foreach (array_keys(Theme::PROFILE) as $schluessel) {
    check(strpos($bootGast, '"' . $schluessel . '"') !== false,
        "das Profil $schluessel fehlt in der Liste des Boot-Skripts");
}
check(strpos($bootGast, 'indexOf(') !== false,
    'das Boot-Skript prueft den gespeicherten Wert nicht gegen die Liste');
ok('das Boot-Skript kennt genau die Profile aus Theme::PROFILE');

// Gast: kein Kontowert. Angemeldet: der Kontowert steht drin und wird in
// den Browserspeicher geschrieben - das Konto ueberschreibt die lokale Wahl.
check(strpos($bootGast, 'var konto   = null;') !== false,
    'fuer einen Gast steht ein Kontowert im Boot-Skript');
check(strpos($bootKonto, '"dunkel"') !== false, 'der Kontowert fehlt im Boot-Skript');
check(strpos($bootKonto, 'localStorage.setItem') !== false,
    'das Konto schreibt den lokalen Wert nicht um');
ok('das Konto gewinnt und zieht den lokalen Wert nach');

// Nie gewaehlt: die Vorgabe des Betriebssystems.
check(strpos($bootGast, 'prefers-color-scheme: dark') !== false,
    'ohne Wahl wird das Betriebssystem nicht gefragt');
check(strpos($bootGast, '"' . Theme::OS_DARK . '"') !== false,
    'das Dunkelprofil fehlt als Antwort auf die Systemvorgabe');
check(Theme::isValid(Theme::OS_DARK), 'OS_DARK ist kein gueltiges Profil');
ok('ohne Wahl entscheidet prefers-color-scheme');

// localStorage kann fehlen oder gesperrt sein. Ein Fehler dort darf die
// Seite nicht aufhalten - das Skript steht im <head>, vor allem anderen.
check(preg_match('/try\s*\{/', $bootGast) === 1, 'der Zugriff auf localStorage ist nicht abgesichert');
check(preg_match('/catch\s*\(/', $bootGast) === 1, 'kein catch um den Speicherzugriff');
ok('ein gesperrter Browserspeicher haelt die Seite nicht auf');

// PHP und JavaScript benutzen denselben Schluessel. Waeren es zwei, merkte
// sich die Anwendung die Wahl und faende sie beim naechsten Aufruf nicht.
$switchJs = file_get_contents($ROOT . '/assets/js/theme_switch.js');
check(strpos($switchJs, "'" . Theme::STORAGE_KEY . "'") !== false,
    'theme_switch.js benutzt einen anderen Schluessel als Theme::STORAGE_KEY');
check(strpos($bootGast, '"' . Theme::STORAGE_KEY . '"') !== false,
    'das Boot-Skript benutzt einen anderen Schluessel');
ok('Boot-Skript und Umschalter benutzen denselben Schluessel');

// Und das Skript steht im Kopf der Seite - nicht am Ende, wo es zu spaet waere.
$layoutKopf = substr($layout, 0, strpos($layout, '</head>'));
check(strpos($layoutKopf, '###THEME_BOOT###') !== false,
    'der Platzhalter fuer das Boot-Skript steht nicht im <head>');
check(strpos($layoutKopf, '###THEME_BOOT###') < strpos($layoutKopf, 'theme.css'),
    'das Boot-Skript steht hinter den Stilvorlagen - dann blitzt die helle Seite auf');
ok('das Boot-Skript steht vor den Stilvorlagen');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n20) select2 haengt an den Farbvariablen\n");

// Die Bibliothek bringt weisse Liste, #333 Schrift und ein eigenes Blau
// (#5897fb) mit. Im Dunkelprofil blieb die aufgeklappte Liste dadurch weiss.
foreach ([
    '.select2-dropdown'                                          => 'die aufgeklappte Liste',
    '.select2-container--default .select2-results__option'        => 'die Eintraege',
    '.select2-container--default .select2-search--dropdown .select2-search__field' => 'das Suchfeld',
    '.select2-container--default .select2-selection--single .select2-selection__placeholder' => 'der Platzhalter',
] as $sel => $was) {
    check(strpos($themeCss, $sel) !== false, "select2: $was wird nicht gestaltet ($sel)");
}
check(strpos($themeCss, '.select2-results__option--highlighted') !== false,
    'select2: der markierte Eintrag wird nicht gestaltet');
ok('Liste, Eintraege, Suchfeld, Platzhalter und Markierung sind gestaltet');

// In unserem Block darf keine feste Farbe stehen - sonst folgt genau diese
// Stelle dem Profil wieder nicht.
$i = strpos($themeCss, 'select2 (Laender- und Staedteauswahl)');
check($i !== false, 'der select2-Block fehlt in theme.css');
// Am ANFANG des Kommentars ansetzen, nicht mitten darin: Sonst fehlt dem
// Entfernen der Kommentare weiter unten das oeffnende /*, und der erklaerende
// Text zaehlt als Regel mit.
$i = strrpos(substr($themeCss, 0, $i), '/*');
$block = substr($themeCss, $i);
// Nur bis zum naechsten grossen Abschnitt schauen.
$ende = strpos($block, "\n/* ---", 200);
if ($ende !== false) $block = substr($block, 0, $ende);

// Kommentare heraus: Dort werden die Eigenfarben der Bibliothek ja gerade
// GENANNT, um zu erklaeren, was ersetzt wurde. Geprueft werden die Regeln.
$regeln = preg_replace('#/\*.*?\*/#s', '', $block);

check(preg_match('/:\s*#[0-9a-fA-F]{3,8}\b/', $regeln) === 0,
    'im select2-Block steht eine feste Farbe statt einer Variablen');
foreach (['#5897fb', '#3875d7', '#aaa', '#333'] as $eigenfarbe) {
    check(strpos($regeln, $eigenfarbe) === false,
        "die select2-Eigenfarbe $eigenfarbe steht noch in den Regeln");
}
ok('der select2-Block benutzt ausschliesslich Profilvariablen');

// color-scheme: Ohne diese Angabe bleiben Bildlaufleisten und die
// eingebauten Bedienelemente des Browsers im Dunkelprofil hell.
check(isset($root['color-scheme']) || strpos($themeCss, 'color-scheme: light') !== false,
    'color-scheme fehlt im Grundprofil');
check(preg_match('/\[data-theme="dunkel"\]\s*\{[^}]*color-scheme:\s*dark/', $themeCss) === 1,
    'das Dunkelprofil setzt color-scheme nicht auf dark');

// accent-color: WELCHE Farbe der Browser fuer die Bedienelemente nimmt, die
// er selbst zeichnet - Kontrollkaestchen und Radioknoepfe (die Sprachauswahl
// im Standort- und im Guide-Formular sind echte <input type="checkbox">).
// Ohne die Angabe steht dort das Blau des Browsers, direkt neben Etiketten
// in der Akzentfarbe.
//
// EINE ZEILE FUER ALLE VIER PROFILE, und genau das wird hier festgehalten:
// var() wird an jedem Element neu aufgeloest. Eine feste Farbe an dieser
// Stelle waere in drei von vier Profilen falsch, und ein zweiter Eintrag im
// Dunkelprofil waere ein Wert, den niemand mit dem ersten zusammen pflegt.
check(($root['accent-color'] ?? '') === 'var(--app-accent)',
    'accent-color steht nicht auf der Akzentfarbe des Profils: '
    . var_export($root['accent-color'] ?? null, true));
$dunkelRoh = cssBlock($themeCss, '[data-theme="dunkel"] {');
check(!isset($dunkelRoh['accent-color']),
    'das Dunkelprofil setzt accent-color noch einmal - eine Angabe reicht, '
    . 'var(--app-accent) aendert sich mit dem Profil');
ok('der Browser weiss, welche Grundstimmung gilt und welche Akzentfarbe');

// Markierter Text. Auch den zeichnet der Browser, und ohne Angabe in der
// Farbe des Betriebssystems - in allen vier Profilen derselben.
//
// BEIDE ANGABEN werden geprueft, nicht nur der Grund: Ohne color bliebe
// markierter Text in seiner eigenen Farbe stehen, und ein Verweis in der
// Akzentfarbe waere auf der Akzentflaeche unsichtbar. Die beiden Variablen
// gehoeren zusammen - --app-text-on-accent ist in jedem Profil der Ton, der
// auf der vollen Akzentflaeche steht.
$auswahl = cssBlock($themeCss, '::selection {');
check(($auswahl['background'] ?? '') === 'var(--app-accent)',
    'der Grund der Markierung kommt nicht aus der Palette');
check(($auswahl['color'] ?? '') === 'var(--app-text-on-accent)',
    'die Schrift der Markierung kommt nicht aus der Palette - ein Verweis '
    . 'waere auf der Akzentflaeche unsichtbar');

// Jedes Profil muss das Paar vollstaendig haben. --app-text-on-accent darf
// dabei aus :root geerbt sein (Weiss steht auf jedem der drei hellen
// Akzente); NICHT geerbt werden darf es im Dunkelprofil, dessen Akzent
// heller ist als der Text darauf.
check(isset($root['--app-text-on-accent']), '--app-text-on-accent fehlt in :root');
check(isset($dunkelRoh['--app-text-on-accent']),
    'das Dunkelprofil erbt --app-text-on-accent - dort steht Weiss auf '
    . 'hellem Violett');
ok('markierter Text traegt die Akzentfarbe, in jedem Profil lesbar');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n21) Was sich nicht von selbst mitfaerbt\n");

// 1) DataTables Responsive. ACHTUNG: Eingebunden ist 2.4.1, und diese
// Fassung kennt keine --dtr-Variablen - sie schreibt ihre Farben direkt in
// die Regeln. Das Aufklappzeichen ist dort ein gruener Kreis mit "+" und im
// aufgeklappten Zustand ein roter mit "-". Gruen heisst in dieser Anwendung
// "Guide jetzt verfuegbar"; ein gruener Kreis in jeder Tabellenzeile
// entwertet das.
check(strpos($layout, 'responsive/2.4.1/css') !== false,
    'die Version der Responsive-Erweiterung hat sich geaendert - den Block in '
    . 'theme.css gegen die neue Fassung pruefen');
check(strpos($themeCss, 'td.dtr-control:before') !== false,
    'das Aufklappzeichen der Responsive-Erweiterung wird nicht gestaltet');
check(strpos($themeCss, 'tr.child > td') !== false,
    'die aufgeklappte Unterzeile wird nicht gestaltet');

// Die Regeln der Bibliothek laden NACH theme.css. Bei gleicher Spezifitaet
// gewinnt die spaetere Datei - deshalb beginnt jede unserer dtr-Regeln mit
// "html". Faellt das weg, sind Gruen und Rot wieder da, ohne dass es
// auffaellt.
// Geprueft wird JEDE Selektorzeile, nicht nur die letzte: Eine Liste von
// Selektoren steht ueber mehrere Zeilen, und nur die letzte endet auf "{".
// Ein fehlender Praefix in der ersten Zeile faellt sonst nicht auf.
$dtrZeilen = [];
foreach (explode("\n", $themeCss) as $zeile) {
    $t = trim($zeile);
    if ($t === '' || $t[0] === '*' || strpos($t, '/*') === 0) continue;   // Kommentare
    if (strpos($t, 'dtr-') === false) continue;
    if (substr($t, -1) !== ',' && substr($t, -1) !== '{') continue;       // Selektorzeilen
    $dtrZeilen[] = $t;
}
check(count($dtrZeilen) >= 8, 'zu wenige dtr-Selektorzeilen gefunden - Block verschoben?');
foreach ($dtrZeilen as $t) {
    check(strpos($t, 'html ') === 0,
        "diese dtr-Selektorzeile hat keinen html-Praefix und kaeme gegen die "
        . "Bibliothek nicht an: $t");
}
ok('die Regeln der Responsive-Erweiterung sind spezifisch genug');

// 2) Das Schliesskreuz. Bootstrap zeichnet es als schwarze Grafik; auf
// dunklem Grund war es unsichtbar.
check(preg_match('/\[data-theme="dunkel"\][^{]*\.btn-close[^{]*\{[^}]*--bs-btn-close-filter/', $themeCss) === 1,
    'das Schliesskreuz wird im Dunkelprofil nicht umgekehrt');
ok('das Schliesskreuz ist im Dunkelprofil sichtbar');

// 3) Der Dialog. --bs-modal-bg zeigt bei Bootstrap auf --bs-body-bg, also
// auf unsere GRUNDflaeche. Ein Dialog liegt ueber allem und gehoert auf die
// oberste Ebene.
check(preg_match('/\.modal\s*\{[^}]*--bs-modal-bg:\s*var\(--app-surface-raised\)/', $themeCss) === 1,
    'der Dialog liegt nicht auf der obersten Ebene');
ok('der Dialog liegt auf --app-surface-raised');

// 4) Der Pfeil im Auswahlfeld. Er steht als Grafik zweimal da - eine
// Hintergrundgrafik kennt kein currentColor. Genau deshalb diese Pruefung:
// Die beiden Farben MUESSEN dem --app-text-muted ihres Profils entsprechen,
// sonst laufen sie beim naechsten Feilen an der Palette auseinander.
$dunkelBlock = cssBlock($themeCss, '[data-theme="dunkel"]');
$erwartet = [
    'indigo' => strtolower(ltrim(trim($root['--app-text-muted']), '#')),
    'dunkel' => strtolower(ltrim(trim($dunkelBlock['--app-text-muted']), '#')),
];
preg_match('/:root\s*\{\s*--app-select-chevron:\s*url\("([^"]+)"\)/', $themeCss, $mHell);
preg_match('/\[data-theme="dunkel"\]\s*\{\s*--app-select-chevron:\s*url\("([^"]+)"\)/', $themeCss, $mDunkel);
check(!empty($mHell[1]),   'der Pfeil fehlt im Grundprofil');
check(!empty($mDunkel[1]), 'der Pfeil fehlt im Dunkelprofil');
check($mHell[1] !== $mDunkel[1], 'beide Profile benutzen dieselbe Pfeilgrafik');
check(stripos($mHell[1],   $erwartet['indigo']) !== false,
    'der helle Pfeil hat nicht die Farbe von --app-text-muted (' . $erwartet['indigo'] . ')');
check(stripos($mDunkel[1], $erwartet['dunkel']) !== false,
    'der dunkle Pfeil hat nicht die Farbe von --app-text-muted (' . $erwartet['dunkel'] . ')');
check(preg_match('/\.form-select\s*\{[^}]*--bs-form-select-bg-img:\s*var\(--app-select-chevron\)/', $themeCss) === 1,
    'das Auswahlfeld benutzt die eigene Pfeilgrafik nicht');
ok('der Pfeil traegt in beiden Profilen die Farbe von --app-text-muted');

// 5) Der Knopf im Dateifeld ("Durchsuchen"). Ein <input type="file"> traegt
// einen Knopf, den der Browser zeichnet.
//
// DER BEFUND
// Bootstrap gestaltet ihn ueber ::file-selector-button, faerbt ihn aber nur
// zur Haelfte mit: Die Schrift kommt aus --bs-body-color (hier auf --app-text
// gelegt), der Grund aus einer eigenen Grundfarbe, die nie nachgezogen wurde.
// Im Dunkelprofil ergab das #e4eaf2 auf #f8f9fa - rund 1,05:1, also nichts.
//
// Geprueft wird deshalb, dass BEIDE Farben aus der Palette kommen. Eine halb
// nachgezogene Farbe ist genau der Fehler, der hier behoben wurde.
$dateiKnopf = cssBlock($themeCss, 'input[type="file"]::file-selector-button');
check($dateiKnopf !== [], 'der Knopf im Dateifeld wird gar nicht gestaltet');
check(($dateiKnopf['color'] ?? '') === 'var(--app-text)',
    'die Schrift des Knopfes kommt nicht aus der Palette: '
    . var_export($dateiKnopf['color'] ?? null, true));
check(($dateiKnopf['background-color'] ?? '') === 'var(--app-surface-sunken)',
    'der Grund des Knopfes kommt nicht aus der Palette: '
    . var_export($dateiKnopf['background-color'] ?? null, true));
check(($dateiKnopf['border-color'] ?? '') === 'var(--app-border-strong)',
    'die Kante des Knopfes kommt nicht aus der Palette');

// DER SELEKTOR HAENGT AM ELEMENTTYP und nicht an .form-control: Ein Dateifeld
// ohne die Bootstrap-Klasse waere sonst genau wieder der Fall, der hier
// behoben wurde. Es gibt in dieser Anwendung heute nur ein sichtbares
// Dateifeld - das naechste soll nicht davon abhaengen, dass jemand an die
// Klasse denkt.
check(strpos($themeCss, '.form-control::file-selector-button {') === false,
    'die Regel haengt an der Bootstrap-Klasse statt am Elementtyp');

// DER ZEIGERZUSTAND braucht die volle Selektorlaenge von Bootstrap
// (".form-control:hover:not(:disabled):not([readonly])"). Ein kuerzerer
// Selektor kaeme nicht dagegen an, und der Knopf spraenge beim Ueberfahren
// auf die Bootstrap-Farbe zurueck - ein Fehler, den man nur sieht, wenn man
// mit der Maus daraufsteht.
check(strpos($themeCss,
    '.form-control:hover:not(:disabled):not([readonly])::file-selector-button') !== false,
    'der Zeigerzustand kaeme gegen Bootstrap nicht an');
ok('der Knopf im Dateifeld nimmt beide Farben aus der Palette');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n22) Die Standortlisten brechen rechtzeitig um\n");

$tabJs = file_get_contents($ROOT . '/assets/js/locations_table.js');

// Kommentare heraus: Dort wird "autoWidth: false" ja gerade ERKLAERT. Ohne
// dieses Entfernen bestuende die Pruefung auch dann, wenn die Einstellung
// selbst wieder auf true stuende - der Erklaertext allein wuerde sie
// erfuellen. (Genau das ist beim Gegenpruefen aufgefallen.)
$tabCode = preg_replace('#/\*.*?\*/#s', '', $tabJs);
$tabCode = preg_replace('#^\s*//.*$#m', '', $tabCode);

// DER KERN DES FEHLERS
// Mit autoWidth (Vorgabe: an) misst DataTables die Tabelle EINMAL beim
// Aufbau und schreibt das Ergebnis als feste Breite ins style-Attribut -
// gemessen waren das 1202px. Diese Zahl blieb stehen, auch bei 400px
// Fensterbreite: Die Tabelle ragte um 850px aus ihrem Bereich heraus,
// waehrend die Responsive-Erweiterung nichts einklappte.
check(preg_match('/autoWidth:\s*false/', $tabCode) === 1,
    'autoWidth ist nicht abgeschaltet - DataTables schreibt dann wieder eine '
    . 'feste Tabellenbreite, die beim Verkleinern stehen bleibt');
ok('DataTables schreibt keine feste Tabellenbreite mehr');

// DIE MINDESTBREITEN GEHOEREN AN DIE KOERPERZELLE
// Die Erweiterung baut die Tabelle zum Messen in einem 1px breiten
// Behaelter nach und setzt dabei auf den geklonten KOPFZELLEN ausdruecklich
// min-width auf 0 (dataTables.responsive.js, _resizeAuto:
// .css('min-width', 0)). Eine Angabe am <th> ist deshalb wirkungslos.
// Diese Pruefung haelt fest, worauf das beim naechsten Mal hinauslaeuft.
check(preg_match('/table\.dataTable\s+td\.col-description\s*\{[^}]*min-width/', $themeCss) === 1,
    'die Mindestbreite der Beschreibung steht nicht an der Koerperzelle');
check(preg_match('/table\.dataTable\s+td\.col-actions\s*\{[^}]*min-width/', $themeCss) === 1,
    'die Mindestbreite der Aktionsspalte steht nicht an der Koerperzelle');
check(preg_match('/table\.dataTable\s+th\.col-\w+\s*\{[^}]*min-width/', $themeCss) === 0,
    'eine Mindestbreite steht an der KOPFzelle - die Erweiterung setzt sie dort auf 0');
ok('die Mindestbreiten stehen an der Koerperzelle, wo sie wirken');

// Die Klassen dafuer kommen aus columnKeys, damit beide Tabellen (alle
// Standorte und eigene Standorte) dieselbe Quelle haben.
check(strpos($tabCode, "'col-' + key") !== false,
    'die Spaltenklassen werden nicht aus den Spaltenkennungen gebildet');
ok('die Spaltenklassen stammen aus columnKeys');

// DIE REIHENFOLGE DES EINKLAPPENS
// Ohne Angabe raeumt die Erweiterung von rechts nach links ab - und rechts
// steht die Aktionsspalte. Bei 800px verschwand als Erstes der Knopf
// "Anrufen", also genau das, wofuer die Liste da ist.
check(preg_match('/COLUMN_PRIORITY:\s*\{(.*?)\}/s', $tabCode, $mPrio) === 1,
    'es gibt keine Reihenfolge fuers Einklappen');
preg_match_all('/(\w+):\s*(\d+)/', $mPrio[1], $mPaare, PREG_SET_ORDER);
$prio = [];
foreach ($mPaare as $paar) $prio[$paar[1]] = (int)$paar[2];

check(isset($prio['actions']) && isset($prio['description']),
    'Aktionen oder Beschreibung fehlen in der Reihenfolge');
// Kleinere Zahl heisst: bleibt laenger stehen.
check($prio['actions'] === min($prio),
    'die Aktionsspalte ist nicht die wichtigste - der Anruf wuerde zuerst verschwinden');
check($prio['description'] === max($prio),
    'die Beschreibung weicht nicht als Erstes');
check($prio['status'] < $prio['description'],
    'der Zustand weicht vor der Beschreibung');
ok('der Anruf bleibt am laengsten, die Beschreibung weicht zuerst');

// Auf sehr schmalen Schirmen faellt nur die BESCHRIFTUNG des Zustands weg,
// nicht die Spalte. Sie bleibt fuer Vorleseprogramme im Dokument - ein
// display:none haette den Zustand fuer sie ersatzlos entfernt.
check(preg_match('/@media[^{]*max-width:\s*560px[^{]*\{\s*\.app-state__text\s*\{([^}]*)\}/', $themeCss, $mText) === 1,
    'die Beschriftung des Zustands wird auf schmalen Schirmen nicht ausgeblendet');
check(strpos($mText[1], 'display: none') === false && strpos($mText[1], 'display:none') === false,
    'die Beschriftung wird mit display:none entfernt - dann fehlt sie auch dem Vorleseprogramm');
check(strpos($mText[1], 'clip-path') !== false || strpos($mText[1], 'position: absolute') !== false,
    'die Beschriftung wird nicht nur optisch ausgeblendet');
// Und beide Stellen, die eine Zustandsanzeige bauen, muessen sie kapseln.
foreach (['class/Controller/UserController.php', 'assets/js/locations_table.js'] as $datei) {
    check(strpos(file_get_contents($ROOT . '/' . $datei), 'app-state__text') !== false,
        "$datei kapselt die Beschriftung des Zustands nicht");
}
ok('die Beschriftung weicht nur fuer das Auge, nicht fuer Vorleseprogramme');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n23) Kopf und Inhalt der Standortlisten stehen buendig\n");

// DER FEHLER
// Die Responsive-Erweiterung fuegt fuer das Aufklappzeichen KEINE eigene
// Zelle ein - Kopf- und Datenzeilen haben immer gleich viele Zellen. Sie
// zeichnet das Zeichen als :before in die erste sichtbare DATENZELLE und
// schafft ihm Platz ueber padding-left: 30px auf genau dieser Zelle. Die
// zugehoerige Kopfzelle bekommt das nicht und behielt ihre 10px. Gemessener
// Versatz: 20px, und nur solange die Tabelle eingeklappt ist.

// Die Kopfzelle bekommt denselben Abstand.
check(preg_match('/thead\s*>\s*tr\s*>\s*th\.dtr-control\s*\{[^}]*padding-left:\s*30px/', $themeCss) === 1,
    'die Kopfzelle der Aufklappspalte bekommt nicht denselben Abstand wie die Datenzelle');
// Die Erweiterung kennt eine kompakte Fassung mit 27px - die auch.
check(preg_match('/compact\s*>\s*thead\s*>\s*tr\s*>\s*th\.dtr-control\s*\{[^}]*padding-left:\s*27px/', $themeCss) === 1,
    'die kompakte Fassung fehlt - dort setzt die Erweiterung 27px');
ok('die Kopfzelle traegt denselben Abstand wie die Datenzelle');

// Die Marke muss auf die Kopfzelle uebertragen werden: Die Erweiterung
// markiert nur die Datenzelle.
check(strpos($tabCode, 'syncControlHeader') !== false,
    'die Marke der Aufklappspalte wird nicht auf die Kopfzelle uebertragen');
check(preg_match('/draw\.dt[^\']*responsive-resize\.dt/', $tabCode) === 1,
    'die Angleichung haengt nicht an draw UND responsive-resize');
ok('die Marke wandert mit der Aufklappspalte mit');

// UND SIE MUSS AUF DEN NAECHSTEN BILDAUFBAU WARTEN.
// Die Ereignisse laufen teils schon, waehrend die Erweiterung die Spalten
// umstellt. Ohne Verzoegerung las der Handler den alten Stand und markierte
// die falsche Kopfzelle - sichtbar als Versatz, der genau bei einer
// einzigen Fensterbreite (560px) stehen blieb.
check(strpos($tabCode, 'requestAnimationFrame') !== false,
    'die Angleichung laeuft sofort statt im naechsten Bild - dann trifft sie '
    . 'beim Umschalten der Spalten die falsche Kopfzelle');
ok('die Angleichung wartet, bis die Erweiterung fertig ist');

// Ein th:first-child waere der naheliegende, aber falsche Weg: Welche
// Spalte die erste SICHTBARE ist, aendert sich mit der Fensterbreite.
check(preg_match('/thead[^{]*th:first-child\s*\{[^}]*padding-left/', $themeCss) === 0,
    'der Abstand haengt an th:first-child - das trifft oft eine ausgeblendete Zelle');
ok('der Abstand haengt an der Marke, nicht an der Stellung der Spalte');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n24) Chat: nur ueber einen Standort, und nur unter Beteiligten\n");

/**
 * Attrappe fuer die Chat-Pruefungen.
 *
 * Liefert auf die Abfrage der Tabelle `chat` und auf die des Standorts je
 * eine vorbereitete Zeile und schreibt alles mit, was sonst abgesetzt wird.
 * Damit laesst sich pruefen, was der Controller bei einem unerlaubten Zugriff
 * NICHT tut - und genau darauf kommt es hier an: Eine Fehlermeldung nuetzt
 * nichts, wenn die Nachricht trotzdem in der Datenbank landet.
 */
class ChatAttrappeStatement {
    public $sql; public $params = []; private $zeile; private $geloeschte;
    public function __construct($sql, $zeile, array $geloeschte = []) {
        $this->sql = $sql; $this->zeile = $zeile; $this->geloeschte = $geloeschte;
    }
    public function bindParam($k, &$v, $type = null) { $this->params[$k] = $v; }
    public function execute($params = null) { if ($params !== null) $this->params = $params; return true; }
    public function fetch($mode = null) { return $this->zeile; }
    public function fetchAll($mode = null) { return []; }
    public function rowCount() { return 1; }
    /** Nur fuer App\Model\User::isDeleted(): "SELECT deleted FROM user". */
    public function fetchColumn($i = 0) {
        return in_array((int)($this->params[':id'] ?? 0), $this->geloeschte, true) ? 1 : 0;
    }
}
class ChatAttrappe {
    public $statements = [];
    /** Zeile, die eine Abfrage auf `chat` liefert; false = gibt es nicht. */
    public $chat = false;
    /** Zeile, die Location::guideIdOf() liefert; false = Standort gibt es nicht. */
    public $standort = false;
    /** Kontokennungen, die als geloescht gelten (App\Model\User::isDeleted). */
    public $geloeschte = [];
    public function prepare($sql) {
        $zeile = false;
        if (preg_match('/^\s*SELECT\s.*\sFROM\s+chat\s/i', $sql))          $zeile = $this->chat;
        // guideIdOf() verbindet seit dem Filter auf geloeschte Konten mit
        // `user` und qualifiziert deshalb seine Spalten.
        if (preg_match('/^\s*SELECT\s+location\.user_id,\s*location\.blocked\s+FROM\s+location/is', $sql)) {
            $zeile = $this->standort;
        }
        $s = new ChatAttrappeStatement($sql, $zeile, $this->geloeschte);
        $this->statements[] = $s;
        return $s;
    }
    public function lastInsertId() { return 42; }
    /**
     * Alle abgesetzten Statements, die etwas veraendern - AUSSER dem
     * Versuchszaehler.
     *
     * Die Ausnahme ist Absicht und kein Aufweichen der Pruefung. Gefragt ist
     * hier "wurde eine Nachricht geschrieben, obwohl der Absender nicht
     * beteiligt ist" - und die Antwort darauf soll nein bleiben. Der Zaehler
     * in `rate_limit` ist das Gegenteil davon: Er MUSS auch beim
     * abgewiesenen Aufruf steigen, denn der Aufruf in einen fremden Chat ist
     * kein Versehen, sondern das Abklopfen fremder Kennungen (Befund N-10,
     * ChatController::sendMessage). Zaehlte er hier mit, wuerde die Pruefung
     * genau das verbieten, was sie meint zu schuetzen.
     */
    public function schreibend(): array {
        $treffer = [];
        foreach ($this->statements as $s) {
            if (strpos($s->sql, 'rate_limit') !== false) continue;
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\s/i', $s->sql)) $treffer[] = $s;
        }
        return $treffer;
    }
    public function vergessen() { $this->statements = []; }
}

$chatDb = new ChatAttrappe();
PdoConnect::$connection = $chatDb;

// Ein Chat zwischen 2 und 3. Die 9 hat mit ihm nichts zu tun.
$chatZeile = ['id' => 5, 'user1_id' => 2, 'user2_id' => 3, 'location_id' => 7,
              'last_msg_at' => null, 'deleted' => 0];

/** Ruft eine Controller-Methode als Benutzer $wer auf und liest die JSON-Antwort. */
$alsBenutzer = function ($wer, array $anfrage, string $methode) use ($chatDb) {
    $_SESSION = $wer === null ? [] : ['user' => ['user_id' => $wer, 'role_id' => Role::USER]];
    $_REQUEST = $anfrage;
    $chatDb->vergessen();
    ob_start();
    (new ChatController())->$methode();
    return json_decode(ob_get_clean(), true);
};

// --- startChat: das Gegenueber kommt aus dem STANDORT (Befund N-12) -------
//
// Vorher nahm die Route eine beliebige Kontokennung entgegen. Wer sie kannte,
// konnte jedem Konto der Plattform eine Nachricht ins Postfach legen; die
// Kennungen sind fortlaufend, ein Durchzaehlen genuegte. Jetzt gibt es diesen
// Parameter nicht mehr.
$chatDb->chat     = false;                                  // noch kein Chat
$chatDb->standort = ['user_id' => 3, 'blocked' => 0];       // Standort 7 gehoert der 3
$antwort = $alsBenutzer(2, ['location_id' => 7], 'startChat');
check($antwort['success'] === true, 'ein Kunde darf den Guide eines Standorts anschreiben');
$geschrieben = $chatDb->schreibend();
check(count($geschrieben) === 1 && preg_match('/INSERT INTO chat\s/i', $geschrieben[0]->sql) === 1,
    'der Chat wird nicht angelegt');
check($geschrieben[0]->params === [2, 3, 7],
    'die Kennungen des Chats stimmen nicht: ' . var_export($geschrieben[0]->params, true));
ok('das Gegenueber kommt aus dem Standort, nicht aus der Anfrage');

// Die alte Form der Anfrage darf nichts mehr bewirken. Das ist der Kern des
// Befundes: Ein Skript, das target_id schickt, laeuft ins Leere.
$antwort = $alsBenutzer(2, ['target_id' => 9], 'startChat');
check($antwort['success'] === false, 'eine freie Kontokennung startet weiterhin einen Chat');
check($chatDb->schreibend() === [], 'trotz Fehlermeldung wurde ein Chat angelegt');
ok('die freie Wahl des Gegenuebers gibt es nicht mehr');

// Ein Standort, den es nicht gibt - dieselbe Antwort wie ein gesperrter,
// damit sich ueber diese Route keine Standortkennungen abklopfen lassen.
$chatDb->standort = false;
$ohneStandort = $alsBenutzer(2, ['location_id' => 999], 'startChat');
check($ohneStandort['success'] === false, 'ein Standort, den es nicht gibt, oeffnet einen Chat');
check($chatDb->schreibend() === [], 'trotz Fehlermeldung wurde ein Chat angelegt');

$chatDb->standort = ['user_id' => 3, 'blocked' => 1];       // gesperrt
$gesperrt = $alsBenutzer(2, ['location_id' => 7], 'startChat');
check($gesperrt['success'] === false, 'von einem gesperrten Standort aus laesst sich schreiben');
check($gesperrt === $ohneStandort,
    '"gesperrt" und "gibt es nicht" sind unterscheidbar - damit liessen sich '
    . 'die fortlaufenden Standortkennungen abklopfen');
ok('ein gesperrter Standort gibt kein Gegenueber her - und verraet sich nicht');

// Sich selbst schreibt niemand an.
$chatDb->standort = ['user_id' => 2, 'blocked' => 0];
$antwort = $alsBenutzer(2, ['location_id' => 7], 'startChat');
check($antwort['success'] === false, 'der Guide schreibt seinen eigenen Standort an');
check($chatDb->schreibend() === [], 'trotz Fehlermeldung wurde ein Chat angelegt');
ok('der Guide schreibt sich nicht selbst an');

// --- startDirectChat: der Direktzugang der Verwaltung ---------------------
//
// Er ist eine EIGENE Route mit einem EIGENEN Recht (chat.start_direct), und
// nur der Admin hat es. Ueber den Zugang entscheidet index.php; hier wird
// geprueft, dass es die Route ueberhaupt gibt und dass sie das richtige Recht
// traegt - ein Direktzugang mit chat.start waere die Luecke von vorher.
$routen = require $ROOT . '/config/routes.php';
check(isset($routen['chat_start_direct']), 'die Route chat_start_direct fehlt');
check($routen['chat_start_direct'][2] === Permission::CHAT_START_DIRECT,
    'der Direktzugang haengt am falschen Recht');
check($routen['chat_start'][2] === Permission::CHAT_START,
    'der Weg ueber den Standort haengt am falschen Recht');
check(!isset($routen['chat_accept']) && !isset($routen['chat_decline']),
    'die Routen der Einladung stehen noch in der Tabelle');
ok('zwei Einstiege, zwei Rechte - und die Einladung ist weg');

// Und das Recht hat wirklich nur der Admin. Ein Guide, der es haette, koennte
// von sich aus fremde Konten anschreiben - genau das sollte mit N-12 weg.
check(Permission::has(Role::ADMIN, Permission::CHAT_START_DIRECT),
    'der Admin hat den Direktzugang nicht');
foreach ([Role::TRIAL, Role::USER, Role::GUIDE, Permission::GUEST] as $rolle) {
    check(!Permission::has($rolle, Permission::CHAT_START_DIRECT),
        "Rolle $rolle hat den Direktzugang - damit ist die freie Wahl zurueck");
}
check(!in_array('chat.answer', Permission::allRights(), true),
    'das Recht der Einladung steht noch in der Rechtetabelle');
ok('den Direktzugang hat nur die Verwaltung');

$chatDb->chat     = false;
$chatDb->standort = false;
$antwort = $alsBenutzer(1, ['target_id' => 9], 'startDirectChat');
check($antwort['success'] === true, 'der Direktzugang legt keinen Chat an');
$geschrieben = $chatDb->schreibend();
check(count($geschrieben) === 1 && $geschrieben[0]->params === [1, 9, null],
    'ein Direktchat traegt eine erfundene Herkunft: '
    . var_export($geschrieben[0]->params ?? null, true));
ok('ein Direktchat gehoert zu keinem Standort und behauptet es auch nicht');

// --- sendMessage: der Kern des Befundes S-1 -------------------------------
// Vorher wurde nur geprueft, DASS jemand angemeldet ist. Die chat_id ging
// ungeprueft ins INSERT: Jeder Angemeldete konnte in jeden fremden Chat
// schreiben.
$chatDb->chat = $chatZeile;
$antwort = $alsBenutzer(9, ['chat_id' => 5, 'msg' => 'Hallo'], 'sendMessage');
check($antwort['success'] === false, 'Unbeteiligter darf nicht schreiben');
check($chatDb->schreibend() === [], 'trotz Fehlermeldung wurde geschrieben: '
    . implode(' | ', array_map(fn($s) => $s->sql, $chatDb->schreibend())));
ok('ein Unbeteiligter schreibt nicht in einen fremden Chat');

$fremdeAntwort = $antwort;
$chatDb->chat = false; // Chat gibt es gar nicht
$antwort = $alsBenutzer(9, ['chat_id' => 5, 'msg' => 'Hallo'], 'sendMessage');
check($antwort === $fremdeAntwort,
    '"gibt es nicht" und "geht dich nichts an" sind unterscheidbar - damit '
    . 'liessen sich die fortlaufenden Chat-IDs abklopfen');
ok('die Ablehnung verraet nicht, ob es den Chat gibt');

$chatDb->chat = $chatZeile;
$antwort = $alsBenutzer(2, ['chat_id' => 5, 'msg' => 'Hallo'], 'sendMessage');
check($antwort['success'] === true, 'ein Teilnehmer darf schreiben');
$geschrieben = $chatDb->schreibend();
check(count($geschrieben) > 0 && preg_match('/INSERT INTO chat_message/i', $geschrieben[0]->sql) === 1,
    'die Nachricht wird nicht gespeichert');
check($geschrieben[0]->params[1] === 2, 'der Absender kommt nicht aus der Sitzung');
ok('ein Teilnehmer schreibt weiterhin, als er selbst');

// DIE ERSTE NACHRICHT BRAUCHT KEINE ZUSTIMMUNG MEHR. Das ist die Aenderung
// aus Migration 019: Frueher haette der Empfaenger erst annehmen muessen -
// und dabei ueber einen blossen Namen entschieden, ohne den Inhalt zu kennen.
$ohneKommentar = function (string $pfad): string {
    $code = file_get_contents($pfad);
    $code = preg_replace('#/\*.*?\*/#s', '', $code);
    return preg_replace('#//[^\n]*#', '', $code);
};
check(strpos($ohneKommentar($ROOT . '/class/Controller/ChatController.php'), 'is_active') === false,
    'der Controller wertet noch einen Einladungszustand aus');
check(strpos($ohneKommentar($ROOT . '/class/Model/Chat.php'), 'pending_for') === false,
    'das Model kennt noch den Gefragten einer Einladung');
check(strpos($ohneKommentar($ROOT . '/assets/js/ui_chat.js'), 'accept-chat-btn') === false,
    'im Chatfenster steht noch ein Annehmen-Knopf');
ok('geschrieben wird ohne vorherige Zustimmung - der Inhalt steht beim Guide');

// --- setMessagesSeen: der Leser steht in der Sitzung ----------------------
// Vorher kam sender_id aus dem Formular, und es gab keine Pruefung: In einem
// fremden Chat liess sich der Ungelesen-Zaehler des anderen zuruecksetzen.
$antwort = $alsBenutzer(9, ['chat_id' => 5, 'sender_id' => 9], 'setMessagesSeen');
check($antwort['success'] === false, 'ein Unbeteiligter markiert als gelesen');
check($chatDb->schreibend() === [], 'in einem fremden Chat wurde etwas markiert');
ok('ein Unbeteiligter setzt keine fremden Nachrichten auf gelesen');

// Auch mit einer fremden Kennung im Formular gilt die Sitzung.
$antwort = $alsBenutzer(2, ['chat_id' => 5, 'sender_id' => 3], 'setMessagesSeen');
check($antwort['success'] === true, 'ein Teilnehmer darf markieren');
$geschrieben = $chatDb->schreibend();
check(count($geschrieben) === 1 && preg_match('/UPDATE chat_message SET seen/i', $geschrieben[0]->sql) === 1,
    'nichts wurde markiert');
check($geschrieben[0]->params === [5, 2],
    'die Kennung kommt aus dem Formular statt aus der Sitzung: '
    . var_export($geschrieben[0]->params, true));
ok('markiert wird aus Sicht des Angemeldeten, nicht aus Sicht der Anfrage');

// --- getMessages: die Pruefung, an der sich die anderen orientieren -------
$antwort = $alsBenutzer(9, ['chat_id' => 5], 'getMessages');
check($antwort['success'] === false, 'ein Unbeteiligter liest mit');
$antwort = $alsBenutzer(3, ['chat_id' => 5], 'getMessages');
check($antwort['success'] === true, 'ein Teilnehmer liest');
ok('Lesen bleibt auf die Teilnehmer beschraenkt');

// --- Und die Regel fuer alles, was noch dazukommt -------------------------
// Wer eine chat_id aus der Anfrage entgegennimmt, muss die Sitzung
// hinzuziehen. Ohne diese Pruefung faellt eine spaeter ergaenzte Methode
// still in dieselbe Luecke zurueck.
$chatCode = file_get_contents($ROOT . '/class/Controller/ChatController.php');
$chatCode = preg_replace('#/\*.*?\*/#s', '', $chatCode);   // Kommentare weg
$chatCode = preg_replace('#//[^\n]*#', '', $chatCode);
preg_match_all('/public function (\w+)\(\).*?(?=\n    public function |\n    private function |\z)/s',
    $chatCode, $mMethoden, PREG_SET_ORDER);
check(count($mMethoden) >= 7, 'die Methoden des ChatControllers wurden nicht gefunden');
$geprueft = 0;
foreach ($mMethoden as $methode) {
    if (strpos($methode[0], "Request::g('chat_id')") === false) continue;
    $geprueft++;
    check(strpos($methode[0], 'Auth::userId()') !== false,
        "ChatController::{$methode[1]}() nimmt eine chat_id entgegen, fragt aber "
        . 'nicht, wer angemeldet ist');
    check(strpos($methode[0], 'hatTeilnehmer(') !== false,
        "ChatController::{$methode[1]}() prueft die Beteiligung nicht");
}
check($geprueft >= 4, "nur $geprueft Methoden mit chat_id gefunden - erwartet werden mindestens 4");
ok("alle $geprueft Methoden mit chat_id aus der Anfrage pruefen die Beteiligung");

// --- Der Zaehler in der Kopfleiste ----------------------------------------
//
// Ein Guide sah eine Rueckfrage bisher nur dann, wenn zufaellig ein
// Chatfenster offen war. Der Zaehler steht auf jeder Seite - dieselbe
// Ueberlegung wie beim Anfragenzaehler daneben.
$viewSrc = file_get_contents($ROOT . '/class/Helper/ViewHelper.php');
check(strpos($viewSrc, 'chatBadge') !== false, 'es gibt keinen Nachrichtenzaehler');
check(strpos($viewSrc, 'Permission::CHAT_LIST') !== false,
    'der Zaehler haengt nicht am Recht chat.list - er gilt fuer beide Seiten');
check(strpos(file_get_contents($ROOT . '/assets/html/index.html'), '###CHATS###') !== false,
    'der Zaehler hat keinen Platz in der Kopfleiste');

$heartbeat = file_get_contents($ROOT . '/class/Controller/UserController.php');
check(preg_match("/'chat'\s*=>\s*Chat::counters/", $heartbeat) === 1,
    'die Zahl faehrt nicht auf dem Heartbeat mit - dann braeuchte sie eine eigene Schleife');
check(strpos(file_get_contents($ROOT . '/assets/js/signaling.js'), 'chatBadge') !== false,
    'der Browser wertet die Zahl aus dem Heartbeat nicht aus');
ok('eine ungelesene Nachricht ist auf jeder Seite zu sehen');

// Der Weg dorthin: der Knopf auf der Standortseite. Er traegt die
// STANDORTKENNUNG - das ist derselbe Befund noch einmal, diesmal in der
// Ansicht.
$knopf = LocationView::frageHtml(
    ['id' => 7, 'blocked' => 0, 'user_id' => 3, 'username' => 'guide',
     'display_name' => 'Mara', 'about' => ''],
    false, true, 3
);
check(strpos($knopf, 'start-location-chat-btn') !== false, 'der Knopf fehlt auf der Standortseite');
check(strpos($knopf, 'data-locationid="7"') !== false, 'der Knopf traegt die Standortkennung nicht');
check(strpos($knopf, 'data-userid') === false,
    'der Knopf traegt eine Kontokennung - damit waere die freie Wahl zurueck');

// Wer ihn NICHT bekommt.
check(LocationView::frageHtml(['id' => 7, 'blocked' => 0], true,  true, 3)  === '',
    'der Eigentuemer bekommt einen Knopf, um sich selbst anzuschreiben');
check(LocationView::frageHtml(['id' => 7, 'blocked' => 1], false, true, 3)  === '',
    'ein gesperrter Standort bietet ein Gespraech an');
check(LocationView::frageHtml(['id' => 7, 'blocked' => 0], false, false, null) === '',
    'ein Gast bekommt einen Knopf, der nichts tun kann');
ok('der Knopf steht dort, wo ein Kunde einen Guide fragen kann - und sonst nirgends');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n25) Die Adresse in E-Mail-Links kommt aus der Konfiguration\n");

// Passwort-Reset und E-Mail-Bestaetigung verschickten Links auf
// "https://localhost/rctprojnew/" - die Adresse eines Entwicklungsrechners.
// Auf jedem echten Server verwies der Link ins Leere.
foreach (['class/Controller/PasswordController.php',
          'class/Controller/EmailVerificationController.php'] as $datei) {
    $code = file_get_contents($ROOT . '/' . $datei);
    check(stripos($code, 'localhost') === false, "$datei enthaelt weiterhin eine feste localhost-Adresse");
    check(preg_match('#["\']https?://#', $code) === 0, "$datei baut weiterhin eine Adresse im Code zusammen");
    // Und ausdruecklich nicht aus dem Request: Der Host-Header kommt vom
    // Aufrufer. Wer den Reset anstoesst, koennte den Link sonst auf einen
    // eigenen Server umbiegen - die Mail ginge an den richtigen Empfaenger,
    // der Klick an den Angreifer.
    check(strpos($code, 'HTTP_HOST') === false, "$datei liest den Host aus der Anfrage");
    check(strpos($code, 'Url::to(') !== false, "$datei baut den Link nicht ueber App\\Helper\\Url");
}
ok('die Basisadresse steht nicht mehr im Code und kommt nicht aus der Anfrage');

$_ENV['APP_BASE_URL'] = 'https://example.org/rctproj';
check(Url::base() === 'https://example.org/rctproj', 'Basisadresse: ' . var_export(Url::base(), true));
check(Url::to('index.php?act=verify_email&token=abc') === 'https://example.org/rctproj/index.php?act=verify_email&token=abc',
    'Link: ' . var_export(Url::to('index.php?act=verify_email&token=abc'), true));
$_ENV['APP_BASE_URL'] = 'https://example.org/';
check(Url::base() === 'https://example.org', 'Schraegstrich am Ende faellt weg');
check(Url::to('/index.php') === 'https://example.org/index.php', 'kein doppelter Schraegstrich');
ok('aus Basisadresse und Ziel wird genau eine Adresse');

// Unbrauchbares darf keinen Link ergeben - ein falscher Link ist schlimmer
// als keine Mail.
foreach (['', '   ', 'example.org', 'javascript:alert(1)', 'https://',
          'https://example.org/?x=1', "https://example.org/a\nb", 'https://example.org/a#b'] as $mist) {
    $_ENV['APP_BASE_URL'] = $mist;
    check(Url::base() === null, 'unbrauchbare Adresse durchgelassen: ' . var_export($mist, true));
    check(Url::to('index.php') === null, 'trotzdem ein Link gebaut fuer: ' . var_export($mist, true));
}
unset($_ENV['APP_BASE_URL']);
check(Url::base() === null, 'ohne Konfiguration gibt es keine Adresse');
ok('fehlt oder taugt die Adresse nichts, entsteht kein Link');

// Der Schluessel ist in .env.example erklaert - sonst faellt er beim
// Einrichten unter den Tisch und die Mails bleiben stumm aus.
$envBeispiel = file_get_contents($ROOT . '/.env.example');
check(preg_match('/^APP_BASE_URL=\S+/m', $envBeispiel) === 1, 'APP_BASE_URL fehlt in .env.example');
check(substr_count($envBeispiel, 'APP_BASE_URL') >= 3, 'APP_BASE_URL ist in .env.example nicht erklaert');
ok('der Schluessel steht mit Erklaerung in .env.example');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n26) Passwortwechsel: das Konto kommt aus der Sitzung\n");

/** Der Rumpf einer Methode, ohne Kommentare. */
function methodenRumpf(string $code, string $name): string {
    $pos = strpos($code, "function $name(");
    check($pos !== false, "Methode $name nicht gefunden");
    $rest = substr($code, $pos);
    // "static" gehoert mit in das Muster: Ohne es endete der Rumpf einer
    // Methode erst an der naechsten NICHT-statischen - in Klassen, die fast
    // nur statische Methoden haben (App\Model\TourReview), also praktisch
    // nie. Eine Pruefung "in diesem Rumpf steht X nicht" las dann den halben
    // Rest der Klasse mit und schlug an, sobald irgendwo dahinter etwas
    // Aehnliches dazukam.
    $ende = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $rest, $m, PREG_OFFSET_CAPTURE, 10)
          ? $m[0][1] : strlen($rest);
    $rumpf = substr($rest, 0, $ende);
    $rumpf = preg_replace('#/\*.*?\*/#s', '', $rumpf);
    return preg_replace('#//[^\n]*#', '', $rumpf);
}

// DER BEFUND
// handleChangePassword() nahm den Benutzernamen aus dem Formular. Die Route
// verlangt zwar eine Anmeldung, aber WELCHES Konto geaendert werden sollte,
// bestimmte damit die Anfrage. Wer angemeldet war, konnte einen fremden
// Namen eintragen und Passwoerter durchprobieren; die Meldung "Das alte
// Passwort ist nicht korrekt!" ist die Auskunft, ob geraten wurde.
//
// Der Lockout aus LoginController::handleLogin() greift dort nicht: Er
// steht in $_SESSION und zaehlt nur Anmeldeversuche. Ueber diese Route
// liess sich also unbegrenzt und ohne Sperre raten.
$pwCode = file_get_contents($ROOT . '/class/Controller/PasswordController.php');
$aendern = methodenRumpf($pwCode, 'handleChangePassword');
check(strpos($aendern, "Request::g('username')") === false,
    'der Benutzername kommt weiterhin aus der Anfrage');
check(strpos($aendern, 'Auth::userId()') !== false,
    'das Konto kommt nicht aus der Sitzung');
check(preg_match('/FROM user WHERE id = :id/', $aendern) === 1,
    'gesucht wird weiterhin ueber den Benutzernamen statt ueber die Kennung');
check(preg_match('/WHERE username/i', $aendern) === 0,
    'der Benutzername steht weiterhin in der Bedingung');
ok('geaendert wird das Konto aus der Sitzung, nicht das aus dem Formular');

// Das Formular zeigt den Namen nur noch an. Ein verstecktes Feld waere die
// Ruecktuer zum selben Befund.
$formular = file_get_contents($ROOT . '/assets/html/change_pw.html');
check(preg_match('/name=["\']username["\']/', $formular) === 0,
    'change_pw.html schickt weiterhin einen Benutzernamen mit');
check(preg_match('/type=["\']hidden["\']/', $formular) === 0,
    'change_pw.html hat wieder ein verstecktes Feld');
$einstellungen = file_get_contents($ROOT . '/assets/html/settings.html');
check(preg_match('/change_pw_page[^"\']*username=/', $einstellungen) === 0,
    'settings.html haengt den Benutzernamen wieder an den Link');
ok('das Formular schickt keine Kennung mehr mit');

// Auch die Anzeige des Formulars nimmt den Namen nicht mehr aus der Adresse.
$anzeigen = methodenRumpf($pwCode, 'showChangePwForm');
check(strpos($anzeigen, "Request::g('username')") === false,
    'der angezeigte Name kommt weiterhin aus der Adresse');
check(strpos($anzeigen, 'Auth::username()') !== false,
    'der angezeigte Name kommt nicht ueber den zentralen Helfer aus der Sitzung');
check(strpos($anzeigen, 'htmlspecialchars') !== false,
    'der angezeigte Name wird nicht maskiert');
$_SESSION = ['user' => ['user_id' => 7, 'username' => 'anna', 'role_id' => Role::USER]];
check(Auth::username() === 'anna', 'Auth::username() liefert den Namen aus der Sitzung');
$_SESSION = [];
check(Auth::username() === '', 'ohne Anmeldung gibt es keinen Namen');
ok('auch die Anzeige nimmt den Namen aus der Sitzung');

// DIE REGEL DAHINTER, projektweit: Wer etwas am EIGENEN Konto tut, nimmt die
// Kennung aus der Sitzung. Eine Kennung aus der Anfrage ist nur dort in
// Ordnung, wo bewusst ein FREMDER Datensatz gemeint ist (Benutzerverwaltung,
// Standortsperre, Chatpartner) - und dort steht eine eigene Pruefung daneben.
$eigenesKonto = [
    'class/Controller/PasswordController.php'          => ['handleChangePassword', 'showChangePwForm'],
    'class/Controller/SettingsController.php'          => ['showSettingsPage', 'setTheme'],
    'class/Controller/TwoFactorController.php'         => ['handle2FAActivate', 'disable2FA'],
    'class/Controller/EmailVerificationController.php' => ['sendVerification'],
    'class/Controller/UserController.php'              => ['heartbeat', 'setAvailability'],
    'class/Controller/GuideController.php'             => ['handleGuideRole'],
];
foreach ($eigenesKonto as $datei => $methoden) {
    $code = file_get_contents($ROOT . '/' . $datei);
    foreach ($methoden as $name) {
        $rumpf = methodenRumpf($code, $name);
        check(preg_match("/Request::g\('(user_?id|username|id)'/", $rumpf) === 0,
            "$datei::$name() nimmt eine Kennung aus der Anfrage");
        check(preg_match('/\$_(REQUEST|GET|POST)\[/', $rumpf) === 0,
            "$datei::$name() liest direkt aus der Anfrage");
    }
}
ok('keine Methode am eigenen Konto nimmt die Kennung aus der Anfrage');

// Die Bestaetigungsmail ist der Grenzfall: Die Route ruft ohne Argument auf,
// der Registrierungsablauf mit der frisch angelegten ID. Vorher hatte der
// Parameter keinen Vorgabewert - der Aufruf ueber die Route endete zwingend
// mit einem ArgumentCountError, also HTTP 500.
$mailCode = file_get_contents($ROOT . '/class/Controller/EmailVerificationController.php');
check(preg_match('/function sendVerification\(\$user_id = null\)/', $mailCode) === 1,
    'sendVerification() laesst sich ueber die Route nicht ohne Argument aufrufen');
check(strpos(methodenRumpf($mailCode, 'sendVerification'), 'Auth::userId()') !== false,
    'sendVerification() faellt ohne Argument nicht auf das angemeldete Konto zurueck');
$routen = require $ROOT . '/config/routes.php';
check($routen['send_email_verify'][1] === 'sendVerification', 'die Route zeigt woanders hin');
ok('die Bestaetigungsmail geht an das angemeldete Konto, nicht an eine mitgeschickte ID');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n27) Eine abgelehnte Eingabe geht nicht verloren\n");

// DER BEFUND
// setLocation() antwortet auf eine Ablehnung mit einer Weiterleitung zurueck
// aufs Formular. Der POST-Rumpf geht dabei verloren - der Nutzer stand vor
// einem leeren Feld und musste die Beschreibung noch einmal tippen, obwohl
// nur die Koordinaten gefehlt hatten. Land und Stadt traf es genauso: Beide
// Listen baut erst map.js auf, eine Auswahl ueberlebte den Ruecksprung nicht.
//
// Die Werte reisen jetzt ueber die Sitzung mit. Nicht ueber die URL: Eine
// Beschreibung gehoert nicht in die Adresszeile, ins Server-Log und in den
// Verlauf.
$_SESSION = [];
$merke   = new ReflectionMethod(LocationController::class, 'merkeEingaben');
$hole    = new ReflectionMethod(LocationController::class, 'holeEingaben');
$vergiss = new ReflectionMethod(LocationController::class, 'vergissEingaben');
$merke->setAccessible(true);
$hole->setAccessible(true);
$vergiss->setAccessible(true);

$eingabe = [
    'country' => '7', 'city' => 'Berlin', 'latitude' => '', 'longitude' => '',
    'title'            => 'Altstadt zu Fuss',
    'description'      => 'Fuehrung durch die Altstadt',
    'description_long' => 'Zwei Stunden durch die Gassen.',
    'duration'         => '90',
    'languages'        => 'de,en',
];
$merke->invoke(null, $eingabe);
check($hole->invoke(null) === $eingabe, 'die gemerkten Eingaben kommen nicht zurueck');

// Das Loeschen gehoert zum Holen: Sonst haenge die alte Beschreibung beim
// naechsten, voellig unabhaengigen Aufruf des Formulars wieder darin.
check($hole->invoke(null) === [], 'die Eingaben bleiben nach dem Holen liegen');
ok('die Eingaben ueberleben genau einen Ruecksprung');

$merke->invoke(null, $eingabe);
$vergiss->invoke(null);
check($hole->invoke(null) === [], 'vergissEingaben() raeumt nicht weg');
ok('der Erfolgsweg raeumt die gemerkten Eingaben weg');

// Gemerkt wird VOR den Pruefungen, damit keine Ablehnung den Rueckweg
// vergessen kann - und weggeraeumt auf dem Erfolgsweg, sonst stuende der eben
// gespeicherte Standort beim naechsten Aufruf wieder im Formular.
$rumpf = methodenRumpf($locCode, 'setLocation');
check(strpos($rumpf, 'merkeEingaben') !== false, 'setLocation() merkt die Eingaben nicht');
check(strpos($rumpf, 'merkeEingaben') < strpos($rumpf, 'header('),
    'gemerkt wird erst nach der ersten Ablehnung');
check(strpos($rumpf, 'vergissEingaben') > strpos($rumpf, 'setNewLocation'),
    'der Erfolgsweg raeumt die Eingaben nicht weg');
check(strpos(methodenRumpf($locCode, 'setLocationPage'), 'fuelleFormular') !== false,
    'setLocationPage() setzt die Eingaben nicht ins Formular ein');
ok('beide Wege durch setLocation() sind bedacht');

// Und das Einsetzen selbst, gegen die echte Vorlage.
$vorlage = ViewHelper::template($ROOT . '/assets/html/set_location.html');
$gefuellt = LocationController::fuelleFormular($vorlage, $eingabe);

check(strpos($gefuellt, 'value="Fuehrung durch die Altstadt"') !== false,
    'die Kurzbeschreibung steht nicht wieder im Feld');
check(strpos($gefuellt, 'value="Altstadt zu Fuss"') !== false, 'der Titel fehlt');
check(strpos($gefuellt, 'Zwei Stunden durch die Gassen.') !== false,
    'die ausfuehrliche Beschreibung fehlt');
check(strpos($gefuellt, 'value="90"') !== false, 'die Dauer fehlt');
check(strpos($gefuellt, 'data-vorher-land="7"') !== false, 'das Land fehlt');
check(strpos($gefuellt, 'data-vorher-stadt="Berlin"') !== false, 'die Stadt fehlt');

// SECHS Platzhalter bleiben - alle sechs sind keine EINGABEN, sondern
// Angaben des Servers, und fuelleFormular() setzt nur Eingaben ein:
//
//   die Sprachauswahl (eine Reihe von Kaestchen aus App\Helper\Languages)
//   und die fuenf Grenzen der Felder, die aus den Konstanten des Controllers
//   kommen - denselben, gegen die pruefeInhalt() prueft.
//
// Alle sechs setzt setLocationPage() ein.
$erwarteteReste = ['###LANGUAGES###', '###TITLE_MAX###', '###SHORT_MAX###',
                   '###LONG_MAX###', '###DURATION_MIN###', '###DURATION_MAX###'];
preg_match_all('/###[A-Z_]+###/', $gefuellt, $rest);
sort($rest[0]);
$erwartetSortiert = $erwarteteReste;
sort($erwartetSortiert);
check($rest[0] === $erwartetSortiert,
    "andere Platzhalter als erwartet:\n  ist:      " . implode(',', $rest[0])
    . "\n  erwartet: " . implode(',', $erwartetSortiert));

$seitenCode = methodenRumpf($locCode, 'setLocationPage');
foreach ($erwarteteReste as $marke) {
    check(strpos($seitenCode, $marke) !== false,
        "setLocationPage() setzt $marke nicht ein");
}
ok('das Anlegeformular bekommt Sprachen und Grenzen vom Server, nicht als eigene Zahlen');

// Und die Zahlen stehen NICHT in der Vorlage und nicht im JavaScript. Zwei
// Fassungen derselben Regel liefen auseinander, und der Nutzer bekaeme eine
// Absage fuer eine Eingabe, die das Feld ausdruecklich erlaubt hat.
$rohForm = file_get_contents($ROOT . '/assets/html/set_location.html');
foreach (['maxlength="120"', 'maxlength="200"', 'maxlength="5000"', 'max="480"'] as $zahl) {
    check(strpos($rohForm, $zahl) === false,
        "set_location.html traegt die Grenze $zahl als eigene Zahl");
}
$mainJs = file_get_contents($ROOT . '/assets/js/main.js');
foreach (['200 Zeichen', '480 Minuten'] as $zahl) {
    check(strpos($mainJs, $zahl) === false,
        "assets/js/main.js nennt die Grenze '$zahl' als eigene Zahl");
}
ok('Beschreibung, Land und Stadt stehen wieder im Formular');

// Die Beschreibung ist freier Text des Nutzers und landet in einem
// value=""-Attribut. Ohne Maskierung beendete ein Anfuehrungszeichen dort das
// Attribut - der naechste Aufruf des Formulars fuehrte den eigenen Text als
// Markup aus. Geprueft wird das Ergebnis, nicht die Absicht.
$boese = LocationController::fuelleFormular($vorlage, [
    'description' => '"><script>alert(1)</script>',
    'city'        => "Bad ' Ischl",
]);
check(strpos($boese, '<script>alert(1)</script>') === false,
    'die Beschreibung kommt unmaskiert ins Dokument');
check(strpos($boese, '&quot;&gt;&lt;script&gt;') !== false,
    'die Beschreibung ist nicht maskiert');
check(strpos($boese, "Bad &#039; Ischl") !== false,
    'das einfache Anfuehrungszeichen ist nicht maskiert');
ok('eingesetzte Werte koennen kein Markup oeffnen');

// Ohne gemerkte Eingaben - der Normalfall - bleibt das Formular leer.
// Uebrig bleiben wieder genau die sechs Angaben des Servers (siehe oben).
$leer = LocationController::fuelleFormular($vorlage, []);
preg_match_all('/###[A-Z_]+###/', $leer, $restLeer);
sort($restLeer[0]);
check($restLeer[0] === $erwartetSortiert,
    'im leeren Formular stehen andere Platzhalter: ' . implode(',', $restLeer[0]));
check(strpos($leer, 'value=""') !== false, 'kein einziges Feld ist leer vorbelegt');

// Ein Feld ist NICHT leer: die Dauer. Sie traegt ihre Vorgabe.
check(preg_match('/id="duration"[\s\S]{0,200}value="5"/', $leer) === 1,
    'im leeren Anlegeformular fehlt die Vorgabe fuer die Dauer');

// Aber nur, wenn ueberhaupt nichts gemerkt ist. Hat der Nutzer das Feld beim
// abgelehnten Versuch ausdruecklich geleert, bleibt es leer - sonst schriebe
// der Ruecksprung ihm eine Angabe zurueck, die er gerade weggenommen hat.
$geleert = LocationController::fuelleFormular($vorlage, ['duration' => '']);
check(preg_match('/id="duration"[\s\S]{0,200}value=""/', $geleert) === 1,
    'ein ausdruecklich geleertes Dauerfeld wird wieder gefuellt');
check(strpos($leer, 'data-vorher-land=""') !== false, 'das Land ist nicht leer');
check(strpos($leer, 'id="description"') !== false && strpos($leer, 'value=""') !== false,
    'das Beschreibungsfeld ist nicht leer');
ok('ohne gemerkte Eingaben bleibt das Formular leer');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n28) Die Koordinatenfelder tragen kein wirkungsloses required\n");

// An #latitude und #longitude stand ein required. Ein <input type="hidden">
// ist von der Pruefung des Browsers ausgenommen ("barred from constraint
// validation") - das Formular ging also ohne Koordinaten raus, und erst der
// Server wies es ab. Geprueft wird jetzt beim Abschicken in map.js; das
// wirkungslose Attribut darf nicht zurueckkommen und den Eindruck erwecken,
// es sei abgesichert.
$rohVorlage = file_get_contents($ROOT . '/assets/html/set_location.html');
foreach (['latitude', 'longitude'] as $feld) {
    check(preg_match('/<input[^>]*id="' . $feld . '"[^>]*>/', $rohVorlage, $m) === 1,
        "das Feld $feld fehlt in der Vorlage");
    check(strpos($m[0], 'type="hidden"') !== false, "$feld ist nicht mehr versteckt");
    check(strpos($m[0], 'required') === false,
        "an $feld steht wieder ein wirkungsloses required");
}
ok('an den versteckten Koordinatenfeldern steht kein required mehr');

// Die sichtbaren Pflichtfelder behalten ihres - dort greift es.
foreach (['description', 'countrySelect', 'citySelect'] as $feld) {
    check(preg_match('/<(?:input|select)[^>]*id="' . $feld . '"[^>]*>/', $rohVorlage, $m) === 1,
        "das Feld $feld fehlt in der Vorlage");
    check(strpos($m[0], 'required') !== false, "$feld hat sein required verloren");
}
ok('die sichtbaren Pflichtfelder behalten ihres');

// Die verbindliche Pruefung bleibt der Server: Wer ohne JavaScript
// abschickt, kommt an der Pruefung des Browsers ohnehin vorbei.
check(strpos(methodenRumpf($locCode, 'setLocation'), 'is_numeric($latitude)') !== false,
    'der Server prueft die Koordinaten nicht mehr selbst');
ok('der Server prueft die Koordinaten weiterhin selbst');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n29) Ein Standort hat Inhalt - und die Seite dazu\n");

// DER BEFUND, DEN DIESER ABSCHNITT FESTHAELT
// -----------------------------------------
// Ein Standort bestand aus Land, Stadt, zwei Koordinaten und EINER Zeile
// Freitext. Auf dieser Grundlage sollte ein Kunde entscheiden, ob er einen
// Fremden losschickt. Dazugekommen sind Titel, ausfuehrliche Beschreibung,
// Dauer, Sprachen und Bilder - und eine eigene Seite, die das zeigt.

// --- Der Sprachkatalog steht an einer Stelle -------------------------------
check(Languages::normalize(['de', 'en']) === 'de,en', 'zwei bekannte Sprachen');
check(Languages::normalize('en,de') === 'de,en',
    'die Reihenfolge ist die des Katalogs und nicht die der Eingabe');
check(Languages::normalize(['de', 'de']) === 'de', 'Doppelungen fallen weg');
check(Languages::normalize(['de', 'xx', 'klingon']) === 'de',
    'unbekannte Kuerzel fallen weg, der Rest bleibt stehen');
check(Languages::normalize([]) === '', 'keine Auswahl ist ein leerer Wert');
check(Languages::normalize(null) === '', 'auch aus null wird nichts Gueltiges');
check(Languages::normalize(['<script>']) === '',
    'ein Markup-Versuch ueberlebt die Normalisierung nicht');
check(Languages::names('de,en') === ['Deutsch', 'English'], 'die Namen kommen aus dem Katalog');
ok('Languages::normalize laesst nur bekannte Kuerzel in fester Reihenfolge durch');

// Der Katalog steht NUR in App\Helper\Languages. Eine zweite Liste im
// Template oder im JavaScript liefe beim naechsten Eintrag auseinander.
foreach (['assets/html/location_edit.html', 'assets/html/set_location.html',
          'assets/js/location_page.js'] as $datei) {
    $inhalt = file_get_contents($ROOT . '/' . $datei);
    check(strpos($inhalt, 'Nederlands') === false && strpos($inhalt, 'Portugues') === false,
        "$datei fuehrt eine eigene Sprachliste");
}
ok('die Sprachen stehen einmal im Katalog, nicht in jeder Vorlage');

// --- Die Abfrage der Standortseite -----------------------------------------
$seiteDb = new FakeConnection();
PdoConnect::$connection = $seiteDb;
(new Location())->selectOneForPage(5);
check(count($seiteDb->statements) === 1, 'genau eine Abfrage fuer die Seite');
$sql = $seiteDb->statements[0]->sql;

foreach (['location.title', 'location.description_long', 'location.duration_minutes',
          'location.languages', 'user.username'] as $spalte) {
    check(strpos($sql, $spalte) !== false, "der Seite fehlt $spalte");
}
// DIESELBE Auswertung wie Karte und Liste. Eine Standortseite, die
// "verfuegbar" anders beantwortet als die Nadel, von der aus man auf sie
// geklickt hat, waere schlimmer als gar keine Angabe.
check(strpos($sql, 'AS availability') !== false, 'die Seite wertet die Verfuegbarkeit nicht aus');
foreach (['live', 'busy', 'idle'] as $wert) {
    check(strpos($sql, "'$wert'") !== false, "Verfuegbarkeitswert '$wert' fehlt");
}
// KEIN Filter auf die Sperre: Der Eigentuemer und die Moderation sollen den
// gesperrten Standort sehen - wer sonst noch darf, entscheidet der
// Controller, und er braucht dafuer blocked und blocked_reason.
check(strpos($sql, 'blocked = 0') === false,
    'die Abfrage filtert die Sperre selbst - dann kaeme der Guide nicht mehr an seinen Standort');
check(strpos($sql, 'location.blocked') !== false, 'die Sperre wird gar nicht mitgeliefert');
ok('selectOneForPage liefert alles fuer die Seite, entscheidet aber nichts');

// --- Die Sperre schlaegt jede Bereitschaft ---------------------------------
class FakeZustandStatement {
    public $sql; public static $zeile = [];
    public function __construct($sql) { $this->sql = $sql; }
    public function bindParam($k, &$v, $t = null) { return true; }
    public function execute() { return true; }
    public function fetch($m = null) { return self::$zeile; }
    public function fetchAll($m = null) { return []; }
}
class FakeZustandConnection {
    public function prepare($sql) { return new FakeZustandStatement($sql); }
}
PdoConnect::$connection = new FakeZustandConnection();

FakeZustandStatement::$zeile = ['availability' => 'live', 'blocked' => 0];
check((new Location())->availabilityOf(5) === 'live', 'ein freier Standort meldet live');

FakeZustandStatement::$zeile = ['availability' => 'live', 'blocked' => 1];
check((new Location())->availabilityOf(5) === 'idle',
    'ein gesperrter Standort meldet weiterhin live - dann waere die Sperre wirkungslos');

FakeZustandStatement::$zeile = false;
check((new Location())->availabilityOf(5) === null, 'ein unbekannter Standort meldet nichts');
check((new Location())->availabilityOf(0) === null, 'ohne Kennung wird gar nicht gefragt');
ok('die Sperre schlaegt die Bereitschaft, auch in der Taktabfrage');

// --- Die Routen der Seite ---------------------------------------------------
check($routes['location'][2]           === Permission::LOCATION_VIEW, 'die Seite haengt am Ansichtsrecht');
check($routes['location'][3]           === 'html', 'die Seite ist eine Seite');
check($routes['location_image'][2]     === Permission::LOCATION_VIEW, 'die Bilder haengen am Ansichtsrecht');
check($routes['get_location_state'][2] === Permission::LOCATION_VIEW, 'die Taktabfrage haengt am Ansichtsrecht');
foreach (['update_location', 'upload_location_image', 'delete_location_image',
          'sort_location_images'] as $route) {
    check($routes[$route][2] === Permission::LOCATION_EDIT_OWN,
        "$route haengt nicht am Bearbeitungsrecht");
}
check(!isset($routes['edit_location_desc']),
    'die Route edit_location_desc steht noch in der Tabelle');
check(strpos($locCode, 'editLocationDesc') === false,
    'die Methode editLocationDesc steht noch im Controller');
ok('die neuen Routen haengen am richtigen Recht, die alte ist weg');

// --- Fremdeingabe kann keine Ersetzung des Servers ausloesen ----------------
//
// DIESE ANWENDUNG BAUT IHRE SEITEN MIT PLATZHALTERN, und
// App\Helper\ViewHelper::output() laeuft NACH dem Controller ueber das ganze
// Dokument. Eine Beschreibung, in der jemand einen Platzhalternamen schreibt,
// bekaeme sonst an dieser Stelle den Inhalt des Servers eingesetzt.
// htmlspecialchars sieht das nicht - an einer Raute ist nichts gefaehrlich,
// gefaehrlich ist sie nur in DIESEM Bauverfahren.
check(preg_match('/###[A-Z_]+###/', LocationView::esc('###USER###')) === 0,
    'ein Platzhaltername aus Fremdeingabe ueberlebt die Maskierung');
check(strpos(LocationView::esc('<script>x</script>'), '<script>') === false, 'Markup ist nicht maskiert');
check(strpos(LocationView::esc('a "b" c'), '&quot;') !== false, 'Anfuehrungszeichen sind nicht maskiert');
check(LocationView::esc('harmlos') === 'harmlos', 'gewoehnlicher Text wird veraendert');
ok('esc() maskiert Markup UND entschaerft Platzhalternamen');

// --- Die Seite wird wirklich gebaut ----------------------------------------
//
// App\Helper\LocationView ist eine reine Funktion: Werte rein, HTML raus.
// Deshalb laesst sich hier die GANZE Seite bauen und ansehen, ohne eine
// Anmeldung nachzustellen und ohne eine Datenbank.
$standort = [
    'id' => 7, 'user_id' => 3, 'latitude' => '38.7', 'longitude' => '-9.13',
    'title'            => 'Alfama bei Nacht',
    'description'      => 'Die alten Gassen nach Sonnenuntergang.',
    'description_long' => "Wir starten am Miradouro.\n\nDann durch die Gassen.",
    'duration_minutes' => 90, 'languages' => 'de,en',
    'blocked' => 0, 'blocked_reason' => null,
    'country_name' => 'Portugal', 'city_name' => 'Lissabon',
    'username' => 'guide1', 'availability' => 'live',
];
// Die Bilder kommen aus der Datenbank in EINER Liste und werden getrennt -
// welches das Titelbild ist, steht in der Zeile (role) und nicht in der
// Reihenfolge.
$bilderRoh = [
    ['id' => 11, 'file_name' => str_repeat('a', 32), 'role' => LocationImage::ROLE_COVER,   'sort_order' => 0],
    ['id' => 12, 'file_name' => str_repeat('b', 32), 'role' => LocationImage::ROLE_GALLERY, 'sort_order' => 1],
    ['id' => 13, 'file_name' => str_repeat('c', 32), 'role' => LocationImage::ROLE_GALLERY, 'sort_order' => 2],
];
$bilder = LocationImage::teile($bilderRoh);

// 1. Der angemeldete Zuschauer: Er bekommt das ANFRAGEFORMULAR. Der
//    Anrufknopf, der frueher hier stand, kommt erst nach der Zusage - siehe
//    weiter unten und Abschnitt 31.
$seiteGast    = LocationView::page($standort, $bilder,
    ['eigen' => false, 'angemeldet' => false, 'viewer_id' => null]);
$seiteKunde   = LocationView::page($standort, $bilder,
    ['eigen' => false, 'angemeldet' => true,  'viewer_id' => 3]);
// Dieselbe Seite, aber der Guide hat zugesagt und das Fenster laeuft: Jetzt
// steht dort der Knopf, und er traegt BEIDE Kennungen. Ginge die
// Standortkennung verloren, waere jede Fuehrung ueber einen Admin-Standort
// ein Gespraech ohne Fuehrung (WebRTCController::callRoles).
$seiteZusage  = LocationView::page($standort, $bilder,
    ['eigen' => false, 'angemeldet' => true,  'viewer_id' => 3,
     'anfrage' => ['id' => 5, 'status' => TourRequest::STATUS_ACCEPTED,
                   'callable' => 1, 'wish_in' => 120]]);
$seiteEigner  = LocationView::page($standort, $bilder,
    ['eigen' => true,  'angemeldet' => true,  'viewer_id' => null,
     'grenzen' => ['max_images' => 5, 'max_bytes' => 100, 'max_source_edge' => 6000,
                   'accept' => 'image/jpeg', 'titel_max' => 120, 'kurz_max' => 200,
                   'lang_max' => 5000, 'dauer_min' => 5, 'dauer_max' => 480,
                   'dauer_vorgabe' => 5]]);

check(preg_match('/loc-call-btn[^>]*data-userid="3"[^>]*data-locationid="7"/', $seiteZusage) === 1,
    'am Anrufknopf der Standortseite fehlt eine der beiden Kennungen');
check(strpos($seiteZusage, 'Führung starten') !== false, 'der Anrufknopf fehlt');
// Ohne Anfrage KEIN Anrufknopf: Der Weg in die Fuehrung fuehrt ueber die
// Anfrage, und der Knopf traegt dann auch keine Kennungen, mit denen sich
// ein Anruf nachbauen liesse.
check(strpos($seiteKunde, 'loc-call-btn') === false,
    'ohne Anfrage steht auf der Standortseite ein Anrufknopf');
check(strpos($seiteKunde, 'loc-req-submit') !== false, 'das Anfrageformular fehlt');
check(preg_match('/loc-req__preset[^>]*data-seconds="0"/', $seiteKunde) === 1,
    '"Jetzt sofort" fehlt als Wunschzeitpunkt');
ok('ohne Zusage steht das Anfrageformular da, mit Zusage der Knopf samt beider Kennungen');

// 2. DER GAST BEKOMMT KEINE user_id. Ohne sie laesst sich von hier aus
//    niemand anrufen - genau wie auf der oeffentlichen Karte. Statt eines
//    Knopfes, der nichts tut, steht dort der Weg zur Anmeldung.
check(strpos($seiteGast, 'data-userid') === false,
    'die Standortseite gibt einem Gast die Benutzerkennung heraus');
check(strpos($seiteGast, 'act=login_page') !== false, 'der Gast findet den Weg zur Anmeldung nicht');
check(strpos($seiteGast, '"userId":null') !== false,
    'die Seitendaten tragen fuer einen Gast eine Benutzerkennung');
ok('ein Gast bekommt die Seite, aber keine Kennung zum Anrufen');

// 3. Der Eigentuemer bekommt das Formular - und ruft sich nicht selbst an.
check(strpos($seiteEigner, 'id="loc-edit-form"') !== false,
    'der Eigentuemer bekommt kein Bearbeitungsformular');
check(strpos($seiteEigner, 'loc-call-btn') === false,
    'der Eigentuemer kann sich selbst anrufen');
check(strpos($seiteEigner, 'loc-req-submit') === false,
    'der Eigentuemer bekommt ein Anfrageformular fuer den eigenen Standort');
check(strpos($seiteKunde, 'id="loc-edit-form"') === false,
    'das Bearbeitungsformular wird auch fremden Aufrufern geliefert');
check(strpos($seiteGast, 'id="loc-edit-form"') === false,
    'ein Gast bekommt das Bearbeitungsformular');
ok('das Bearbeitungsformular erreicht nur den Eigentuemer');

// DIE DAUER HAT EINE VORGABE. Ein Standort ohne Dauer bekommt sie im
// Formular trotzdem eingetragen - ein leeres Feld waere dort eine stille
// Aufforderung, es leer zu lassen. Die Zahl kommt aus den Grenzen und steht
// weder in der Vorlage noch in der Ansicht.
$grenzenBsp = ['max_images' => 5, 'max_bytes' => 100, 'max_source_edge' => 6000,
               'accept' => 'image/jpeg', 'titel_max' => 120, 'kurz_max' => 200,
               'lang_max' => 5000, 'dauer_min' => 5, 'dauer_max' => 480,
               'dauer_vorgabe' => 5];
$ohneDauer = LocationView::bearbeitenHtml(
    array_merge($standort, ['duration_minutes' => null]), null, [], $grenzenBsp);
check(preg_match('/id="edit-duration"[^>]*value="5"/s', $ohneDauer) === 1
      || preg_match('/id="edit-duration"[\s\S]{0,200}value="5"/', $ohneDauer) === 1,
    'ohne eigene Dauer steht die Vorgabe nicht im Feld');

$mitDauer = LocationView::bearbeitenHtml($standort, null, [], $grenzenBsp);
check(preg_match('/id="edit-duration"[\s\S]{0,200}value="90"/', $mitDauer) === 1,
    'eine vorhandene Dauer wird von der Vorgabe ueberschrieben');

// Die Vorgabe ist eine EIGENE Konstante und nicht die Untergrenze: Das sind
// zwei Aussagen, und wer die eine aendert, meint selten die andere mit.
$refLoc = new ReflectionClass(LocationController::class);
check($refLoc->getConstant('DAUER_VORGABE') === 5, 'die Vorgabe ist nicht 5 Minuten');
check(strpos(file_get_contents($ROOT . '/class/Helper/LocationView.php'), 'dauer_vorgabe') !== false,
    'die Ansicht liest die Vorgabe nicht aus den Grenzen');
check(strpos(methodenRumpf($locCode, 'grenzen'), 'DAUER_VORGABE') !== false,
    'die Vorgabe wird nicht an die Ansicht weitergereicht');
ok('die Dauer ist mit 5 Minuten vorbelegt, eine vorhandene bleibt stehen');

// 3b. DIE RANGFOLGE DER SEITE. Vorher war sie eine Reihe gleichrangiger
//     Kaesten; die Beschreibung stand ganz unten unter der Karte, und der
//     Knopf klemmte zwischen zwei Datenzeilen. Geprueft wird die Reihenfolge
//     im Dokument, denn genau die entscheidet auf einem Telefon, was zuerst
//     kommt - und auf breiten Bildschirmen ordnet das Raster daraus zwei
//     Spalten (assets/css/location.css).
$reihenfolge = [
    'Bild'          => 'loc-hero',
    'Beschreibung'  => 'loc__main',
    'Knopf'         => 'loc-cta__action',
    'Karte'         => 'loc__meeting',
];
$vorher = -1;
$vorname = '';
foreach ($reihenfolge as $was => $marke) {
    $pos = strpos($seiteKunde, $marke);
    check($pos !== false, "$was fehlt auf der Seite ($marke)");
    check($pos > $vorher, "$was steht vor '$vorname' - die Rangfolge stimmt nicht");
    $vorher = $pos;
    $vorname = $was;
}
ok('Bild, Beschreibung, Knopf und Karte stehen in dieser Reihenfolge');

// Titel, Ort und Zustand liegen AUF dem Bild und nicht in einer eigenen
// Zeile darueber: Sie stehen zwischen <header class="loc-hero"> und dessen
// Ende.
$heroAnfang = strpos($seiteKunde, 'class="loc-hero"');
$heroEnde   = strpos($seiteKunde, '</header>', $heroAnfang);
$hero       = substr($seiteKunde, $heroAnfang, $heroEnde - $heroAnfang);
foreach (['loc-hero__title' => 'Der Titel',
          'loc-hero__place' => 'Der Ort',
          'loc-state'       => 'Die Zustandsmarke'] as $marke => $was) {
    check(strpos($hero, $marke) !== false, "$was liegt nicht auf dem Bild");
}
check(strpos($hero, 'Alfama bei Nacht') !== false, 'der Titeltext steht nicht im Bildbereich');
ok('Titel, Ort und Zustand liegen auf dem Bild');

// Der Knopf steht VOR den Datenzeilen im Kasten - nicht dazwischen und nicht
// darunter. Das war der Befund: Er sah aus wie die Fussnote der Angaben.
// Gesucht wird ab dem Beginn des Kastens im vollen Text und nicht in einem
// Ausschnitt fester Laenge: Zwischen Knopf und Datenzeilen steht inzwischen
// der Kasten mit den ueblichen Zeiten, und ein zu kurzes Fenster liesse die
// Datenzeilen herausfallen - die Pruefung schluege dann fehl, ohne dass sich
// an der Rangfolge etwas geaendert haette.
$ctaAnfang = strpos($seiteKunde, 'class="loc-cta"');
$posKnopf  = strpos($seiteKunde, 'loc-cta__action', $ctaAnfang);
$posDaten  = strpos($seiteKunde, 'loc-facts',       $ctaAnfang);
check($posKnopf !== false && $posDaten !== false, 'Knopf oder Datenzeilen fehlen im Kasten');
check($posKnopf < $posDaten, 'die Datenzeilen stehen vor dem Knopf');
ok('der Knopf steht oben im Kasten, die Nebendaten darunter');

// 3c. ZWEI ARTEN VON BILDERN, und sie stehen an zwei Stellen.
//
//     DER BEFUND: Ein Bild musste beides sein - Hintergrund der Kopfzeile und
//     Beispielbild des Ortes. Ein Titelbild braucht ein sehr breites Format
//     und ruhige Flaechen fuer die Schrift, ein Beispielbild soll zeigen, was
//     man dort sieht. Das erste hochgeladene Bild wurde stillschweigend zum
//     Titelbild, ob es dafuer taugte oder nicht.
check(substr_count($seiteKunde, 'loc-hero__cover') === 1,
    'im Kopf steht nicht genau ein Titelbild');
check(strpos($hero, 'id=11') !== false,
    'im Kopf steht ein anderes Bild als das mit der Rolle "cover"');
check(strpos($hero, 'loc-shots') === false,
    'die Beispielbilder stehen noch im Kopf');

// Die Beispielbilder stehen im Inhaltsbereich - und zwar ALLE ausser dem
// Titelbild.
check(substr_count($seiteKunde, 'loc-shots__item') === 2,
    'nicht jedes Beispielbild steht in der Galerie');
check(strpos($seiteKunde, 'loc-shots__item') > strpos($seiteKunde, 'loc__main'),
    'die Galerie steht vor der Beschreibung');

// Jede Kachel ist ein VERWEIS auf das Bild - ohne JavaScript oeffnet ein
// Klick es, statt ins Leere zu greifen.
check(preg_match('#loc-shots__item[^>]*href="index\.php\?act=location_image#', $seiteKunde) === 1,
    'die Kacheln sind keine Verweise auf das Bild');
ok('Titelbild im Kopf, Beispielbilder in der Galerie darunter');

// OHNE TITELBILD, aber mit Beispielbildern: ein ruhiger Streifen im Kopf, die
// Bilder trotzdem in der Galerie. Kein Rueckfall auf das erste Beispielbild -
// genau diese Kopplung sollte weg.
$ohneTitel = LocationView::page($standort,
    LocationImage::teile([
        ['id' => 21, 'file_name' => str_repeat('d', 32),
         'role' => LocationImage::ROLE_GALLERY, 'sort_order' => 0],
    ]),
    ['eigen' => false, 'angemeldet' => true, 'viewer_id' => 3]);
check(strpos($ohneTitel, 'loc-hero__frame--empty') !== false,
    'ohne Titelbild fehlt der ruhige Streifen');
check(strpos($ohneTitel, 'loc-hero__cover') === false,
    'ohne Titelbild rueckt ein Beispielbild in den Kopf nach');
check(substr_count($ohneTitel, 'loc-shots__item') === 1,
    'das Beispielbild fehlt in der Galerie');
ok('ohne Titelbild rueckt kein Beispielbild nach');

// GAR KEINE BILDER: der Streifen mit Titel, und die Galerie faellt ganz weg -
// kein leerer Rahmen, keine Ueberschrift ohne Inhalt.
$ohneBild = LocationView::page($standort, ['cover' => null, 'gallery' => []],
    ['eigen' => false, 'angemeldet' => true, 'viewer_id' => 3]);
check(strpos($ohneBild, 'loc-hero__frame--empty') !== false, 'der leere Streifen fehlt');
check(strpos($ohneBild, 'loc-hero__cover') === false, 'ohne Bilder steht ein Bild da');
check(strpos($ohneBild, 'loc-shots') === false, 'ohne Bilder steht eine leere Galerie da');
check(strpos($ohneBild, 'Bilder vom Ort') === false,
    'ohne Bilder steht eine Ueberschrift ohne Inhalt da');
check(strpos($ohneBild, 'keine Bilder') === false,
    'die Seite meldet dem Besucher, dass Bilder fehlen');
check(strpos($ohneBild, 'Alfama bei Nacht') !== false, 'der Titel fehlt auf dem leeren Streifen');
ok('ohne Bilder bleibt ein Streifen mit Titel, ohne Meldung und ohne Galerie');

// DIE AUFTEILUNG SELBST. Sie ist eine reine Funktion und laesst sich deshalb
// einzeln pruefen - auch der Fall, den setCover() verhindert, den aber ein von
// Hand veraenderter Datenbestand hergeben koennte.
$zweiCover = LocationImage::teile([
    ['id' => 1, 'role' => LocationImage::ROLE_COVER],
    ['id' => 2, 'role' => LocationImage::ROLE_COVER],
    ['id' => 3, 'role' => LocationImage::ROLE_GALLERY],
]);
check((int)$zweiCover['cover']['id'] === 1, 'bei zwei Titelbildern gilt nicht das erste');
check(count($zweiCover['gallery']) === 2,
    'das zweite Titelbild geht verloren, statt in die Galerie zu fallen');

$keins = LocationImage::teile([]);
check($keins['cover'] === null && $keins['gallery'] === [],
    'aus keinem Bild wird nicht nichts');
ok('die Aufteilung verliert kein Bild, auch nicht bei kaputten Daten');
ok('ohne Bilder bleibt ein Streifen mit Titel, ohne Meldung');

// 3d. DER WEG ZURUECK. Hier stand ein Umschalter "Karte | Liste". Der gehoert
//     auf die Startseite und auf die Standortliste: Dort schaltet er zwischen
//     zwei Ansichten DERSELBEN Menge um, und einer der beiden Eintraege ist
//     der, auf dem man gerade steht. Auf dieser Seite stimmte beides nicht -
//     man ist weder auf der Karte noch in der Liste, sondern bei EINEM
//     Standort.
check(strpos($seiteKunde, 'app-switch') === false,
    'der Umschalter "Karte | Liste" steht noch auf der Standortseite');
check(preg_match('/loc-hero__back[^>]*href="index\.php\?act=home"/', $seiteKunde) === 1,
    'es fehlt der Weg zurueck zur Uebersicht');
check(strpos($seiteKunde, 'Zurück zur Übersicht') !== false,
    'der Rueckweg ist nicht beschriftet');

// Er liegt AUF dem Bild, wie Titel und Zustand auch - ueber dem Bild steht
// nichts.
check(strpos($hero, 'loc-hero__back') !== false, 'der Rueckweg liegt nicht auf dem Bild');
ok('statt eines Umschalters steht dort ein Weg zurueck zur Uebersicht');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n29b) Die Seite auf breiten Bildschirmen\n");

// DER BEFUND: Auf 2500 Punkten lief das Bild nur ueber die Inhaltsspalte -
// links und rechts standen Balken -, und unter der schmalen Spalte blieb eine
// grosse leere Flaeche. Beides ist Anordnung und steht deshalb in
// assets/css/location.css; geprueft wird hier, dass die drei Zusagen darin
// stehen, die es dafuer braucht.
$locCss = file_get_contents($ROOT . '/assets/css/location.css');

// 1. DAS BILD UEBER DIE VOLLE FENSTERBREITE. Dafuer muss die Seite die
//    1200-Punkte-Grenze des Inhaltsbereichs fuer sich aufheben - und zwar NUR
//    fuer sich: :has(> .loc-page) trifft ausschliesslich den Rahmen, in dem
//    eine Standortseite steht.
check(preg_match('/\.app-page:has\(>\s*\.loc-page\)\s*\{[^}]*max-width:\s*none/s', $locCss) === 1,
    'die Standortseite hebt die Breitengrenze des Inhaltsbereichs nicht auf');

// 2. UND SIE TUT ES NICHT MIT vw IN DER WAAGERECHTEN. "width: 100vw" bzw.
//    "margin-inline: calc(50% - 50vw)" ist der uebliche Trick fuer volle
//    Breite, und er zaehlt den senkrechten Rollbalken mit - auf einer Seite,
//    die scrollt, waere das Bild rund 15 Punkte zu breit und zoege einen
//    waagerechten Rollbalken nach sich. Der Weg ueber den Innenabstand
//    rechnet mit dem, was wirklich da ist.
//
//    In der SENKRECHTEN ist vw dagegen erlaubt und wird auch benutzt: Die
//    Hoehe des Bildes waechst mit der Fensterbreite, und eine Hoehe zieht
//    keinen waagerechten Rollbalken nach sich. Geprueft werden deshalb genau
//    die Eigenschaften, an denen es schiefgehen kann - und der Kommentar, in
//    dem "100vw" als Gegenbeispiel steht, wird vorher entfernt.
$cssOhneKommentar = preg_replace('#/\*.*?\*/#s', '', $locCss);
preg_match_all('/(width|min-width|max-width|margin(?:-inline|-left|-right)?)\s*:\s*([^;]*vw[^;]*);/i',
    $cssOhneKommentar, $waagerecht, PREG_SET_ORDER);
check($waagerecht === [],
    'eine waagerechte Angabe rechnet mit vw und zaehlt damit den Rollbalken mit: '
    . implode(', ', array_map(fn($t) => trim($t[0]), $waagerecht)));
ok('das Bild laeuft ueber die volle Fensterbreite, ohne waagerecht mit vw zu rechnen');

// 3. KEINE LEERE FLAECHE UNTER DER SCHMALEN SPALTE. Sie spannte sich ueber
//    beide Rasterzeilen, und weil der Kasten darin nur knapp 200 Punkte hoch
//    ist, klaffte darunter ein Loch von mehreren hundert Punkten. Die Karte
//    laeuft jetzt ueber beide Spalten.
check(preg_match('/grid-template-areas:\s*"main\s+side"\s*"shots\s+shots"\s*"meeting\s+meeting"/s', $locCss) === 1,
    'Galerie und Karte laufen nicht ueber beide Spalten - unter der schmalen Spalte bleibt ein Loch');
check(preg_match('/grid-template-areas:\s*"main"\s*"shots"\s*"side"\s*"meeting"/s', $locCss) === 1,
    'auf schmalen Geraeten stehen die Bereiche nicht in der Reihenfolge '
    . 'Text, Bilder, Knopf, Karte');
ok('Galerie und Karte laufen ueber beide Spalten, unter der schmalen Spalte bleibt nichts leer');

// 4. DIE BREITEN SIND AUFEINANDER ABGESTIMMT. Die Textspalte ist auf 75
//    Zeichen begrenzt; ist die Spalte daneben zu schmal, bleibt zwischen Text
//    und Kasten ein Loch. Auf breiten Bildschirmen waren das 234 Punkte.
//    Geprueft wird die Rechnung, nicht das Aussehen: Spaltenbreite minus
//    Kasten minus Luecke muss ungefaehr die Textbreite ergeben.
check(preg_match('/--loc-column:\s*(\d+)px/', $locCss, $spalte) === 1, 'keine Spaltenbreite gesetzt');
check(preg_match('/grid-template-columns:\s*minmax\(0,\s*1fr\)\s*(\d+)px/', $locCss, $kasten) === 1,
    'keine Breite fuer die schmale Spalte gesetzt');
$rest = (int)$spalte[1] - (int)$kasten[1] - 32;   // 32 = --app-space-6, die Luecke
check($rest >= 600 && $rest <= 700,
    "Text- und Kastenbreite passen nicht zusammen: fuer den Text bleiben $rest Punkte, "
    . 'gebraucht werden rund 626 (75 Zeichen)');
ok('Spaltenbreite, Kasten und Luecke sind aufeinander ausgerechnet');

// 5. DER LEERE STREIFEN BEHAELT SEINE HOEHE. Die Hoehe des Bildrahmens wird in
//    zwei Medienabfragen neu gesetzt (schmale und sehr breite Bildschirme).
//    Eine Medienabfrage erhoeht die Spezifitaet nicht - mit nur einer Klasse
//    im Selektor bekam der leere Streifen dort die Hoehe eines Fotos: 570
//    Punkte graue Flaeche mit einem Titel darin. Zwei Klassen gewinnen.
check(preg_match('/\.loc-hero__frame\.loc-hero__frame--empty\s*\{[^}]*height:/s', $cssOhneKommentar) === 1,
    'die Hoehe des leeren Streifens steht nicht mit zwei Klassen im Selektor - '
    . 'eine Medienabfrage macht daraus wieder eine Fotoflaeche');
ok('der leere Streifen behaelt seine Hoehe auf jedem Bildschirm');

// 6. DIE HOEHE DES KOPFES IST GEDECKELT. Auf einem niedrigen Fenster fuellte
//    er fast alles: 520 Punkte Bild plus Kopfleiste sind auf 700 Punkten
//    Fensterhoehe ueber 80 Prozent - der Besucher sah ein Foto und musste
//    scrollen, um ueberhaupt zu erfahren, worum es geht.
//
//    Der Deckel steht als max-height an EINER Stelle und in vh, also relativ
//    zum Fenster. Die Medienabfragen setzen nur die Wunschhoehe (height) und
//    duerfen ihn nicht ueberschreiben - sonst waere er wieder weg.
check(preg_match('/\.loc-hero__frame\s*\{[^}]*max-height:\s*(\d+)vh/s', $cssOhneKommentar, $deckel) === 1,
    'die Hoehe des Kopfes ist nicht an der Fensterhoehe gedeckelt');
check((int)$deckel[1] >= 40 && (int)$deckel[1] <= 70,
    'der Deckel liegt bei ' . $deckel[1] . 'vh - darunter bliebe zu wenig, darueber zu viel');
// Nur EIN Deckel an der Fensterhoehe. Ein zweiter in einer Medienabfrage
// wuerde diesen ueberschreiben, und dann waere er auf der Bildschirmgroesse,
// auf der es darauf ankommt, wieder weg. (max-height mit anderen Einheiten
// gibt es woanders - die Grossansicht begrenzt ihr Bild auf 100% - und die
// sind hier nicht gemeint.)
check(preg_match_all('/max-height:\s*\d+vh/', $cssOhneKommentar) === 1,
    'es gibt mehr als einen Deckel an der Fensterhoehe - '
    . 'dann ueberschreibt eine Medienabfrage den anderen');
ok('der Kopf ist an der Fensterhoehe gedeckelt, und zwar an einer Stelle');

// 7. DIE LESBARKEIT DES TITELS HAENGT NICHT AM BILD.
//
//    Vorher lag die weisse Schrift auf einem Verlauf, der ueber einen festen
//    Anteil der BILDHOEHE lief. Auf einem hellen Foto verschluckte das Bild
//    den Titel, und bei einem dreizeiligen Titel reichte der Verlauf ohnehin
//    nicht bis nach oben. Ein Verlauf, der am Bild haengt, kann keine Zusage
//    ueber den Text machen.
//
//    Jetzt traegt das BAND den Grund. Es ist so hoch wie sein Inhalt, waechst
//    also mit dem Titel mit. Geprueft wird die Zusage, die es gibt: An seiner
//    hellsten Stelle - dort, wo der Text beginnt - muss es dunkel genug sein,
//    dass weisse Schrift auch auf einer weissen Flaeche darunter noch lesbar
//    ist. 55 Prozent Schwarz ueber Weiss ergeben rund 5:1 und liegen damit
//    ueber der Anforderung von 4.5:1.
// Am Zeilenanfang verankert: Weiter unten steht eine Regel
// ".loc-hero__frame--empty ~ .loc-hero__band" fuer den Fall ohne Titelbild -
// die soll hier nicht getroffen werden.
check(preg_match('/^\.loc-hero__band\s*\{(.*?)\n\}/ms', $cssOhneKommentar, $band) === 1,
    'es gibt kein Band hinter dem Titel');
check(preg_match_all('/rgba\(0,\s*0,\s*0,\s*\.(\d+)\)/', $band[1], $stufen) >= 3,
    'das Band hat keinen Verlauf mit mehreren Stufen');

// Die schwaechste Stufe ausser der durchsichtigen (die ist der Auslauf nach
// oben, dort steht kein Text).
$werte = array_map(fn($z) => (float)('0.' . $z), $stufen[1]);
sort($werte);
check(min($werte) >= 0.5,
    'die hellste Stelle des Bandes liegt bei ' . min($werte)
    . ' - unter 0.55 ist weisse Schrift auf einem weissen Foto nicht mehr lesbar');
check(max($werte) >= 0.85, 'das Band wird nach unten nicht dunkel genug');

// Und der Verlauf ueber dem Bild ist NICHT mehr fuer den Text zustaendig: Er
// reicht nur noch in den oberen Bereich, wo der Rueckweg liegt.
check(preg_match('/^\.loc-hero__scrim\s*\{(.*?)\n\}/ms', $cssOhneKommentar, $scrim) === 1,
    'kein Verlauf am oberen Rand');
check(strpos($scrim[1], 'to bottom') !== false,
    'der Verlauf laeuft nicht von oben nach unten - dann liegt er wieder unter dem Text');
ok('das Band hinter dem Titel ist dunkel genug, unabhaengig vom Bild');

// 4. Kein Platzhalter ueberlebt - in keiner der drei Ansichten.
foreach (['Gast' => $seiteGast, 'Kunde' => $seiteKunde, 'Eigentuemer' => $seiteEigner] as $wer => $html) {
    check(preg_match('/###[A-Z_]+###/', $html, $rest) === 0,
        "in der Ansicht fuer den $wer steht ein Platzhalter: " . ($rest[0] ?? ''));
}
// Inhalte, die dastehen muessen.
check(strpos($seiteKunde, 'Alfama bei Nacht') !== false, 'der Titel fehlt');
check(strpos($seiteKunde, 'Lissabon, Portugal') !== false, 'der Ort fehlt');
check(strpos($seiteKunde, '1 Stunde 30 Minuten') !== false, 'die Dauer fehlt oder ist unlesbar');
check(strpos($seiteKunde, 'Deutsch, English') !== false, 'die Sprachen fehlen');
check(substr_count($seiteKunde, 'act=location_image') >= 4,
    'die Bilder werden nicht ueber den Controller ausgeliefert');
ok('die Seite traegt Titel, Ort, Dauer, Sprachen und Bilder');

// 5. Fremdeingabe kann keine Ersetzung des Servers ausloesen - jetzt am
//    fertigen Dokument geprueft und nicht nur an esc().
$boeserStandort = array_merge($standort, [
    'title'            => 'Ort ###USER### hier',
    'description'      => '<script>alert(1)</script>',
    'description_long' => 'Text ###CONTENT### und "Anfuehrung"',
    'city_name'        => 'Bad ###LOGOUT### Ischl',
]);
$boeseSeite = LocationView::page($boeserStandort, [],
    ['eigen' => false, 'angemeldet' => true, 'viewer_id' => 3]);
check(preg_match('/###[A-Z_]+###/', $boeseSeite) === 0,
    'ein Platzhaltername aus Fremdeingabe steht im fertigen Dokument');
check(strpos($boeseSeite, '<script>alert(1)</script>') === false,
    'Markup aus einer Beschreibung kommt unmaskiert ins Dokument');
ok('Fremdeingabe loest keine Ersetzung des Servers aus und oeffnet kein Markup');

// 6. Ein gesperrter Standort sagt es und bietet keine Fuehrung an.
$gesperrt = LocationView::page(
    array_merge($standort, ['blocked' => 1, 'blocked_reason' => 'Spam']), [],
    ['eigen' => true, 'angemeldet' => true, 'viewer_id' => null, 'grenzen' => []]);
check(strpos($gesperrt, 'Gesperrt') !== false, 'die Sperre wird nicht angezeigt');
check(strpos($gesperrt, 'Spam') !== false, 'der Grund fehlt');
check(strpos($gesperrt, 'app-tag--live') === false,
    'ein gesperrter Standort steht auf verfuegbar');
ok('ein gesperrter Standort zeigt Sperre und Grund und ist nie verfuegbar');

// --- Jeder Platzhalter der neuen Vorlagen wird gefuellt ---------------------
$viewCodeLoc = file_get_contents($ROOT . '/class/Helper/LocationView.php');
foreach (['assets/html/location_page.html', 'assets/html/location_edit.html'] as $vorlageDatei) {
    foreach (platzhalter($ROOT . '/' . $vorlageDatei) as $marke) {
        check(strpos($viewCodeLoc, $marke) !== false,
            "$marke aus $vorlageDatei wird in LocationView nicht ersetzt");
    }
}
ok('location_page.html und location_edit.html haben keinen unbesetzten Platzhalter');

// --- Die Ansicht entscheidet nichts ----------------------------------------
//
// Sie ist eine reine Funktion. Greift sie auf Sitzung, Anfrage oder
// Konfiguration zu, entscheidet sie mit - und dann steht dieselbe Frage an
// zwei Stellen.
// Geprueft wird der CODE und nicht die Kommentare: In der Klassenbeschreibung
// steht ausdruecklich, worauf sie nicht zugreift - das ist keine Verletzung
// der Regel, sondern ihre Erklaerung.
$viewOhneKommentar = stripPhpNoise($viewCodeLoc);
foreach (['Auth::', 'Request::', '$_SESSION', '$_REQUEST', '$_GET', '$_POST',
          'PdoConnect', 'ImageStore::'] as $verboten) {
    check(strpos($viewOhneKommentar, $verboten) === false,
        "LocationView greift auf $verboten zu - dann ist sie keine reine Funktion mehr");
}
ok('LocationView baut nur HTML und entscheidet nichts');

// --- Sichtbarkeit ist keine Berechtigung ----------------------------------
$eigenRumpf = methodenRumpf($locCode, 'eigenerStandortAusAnfrage');
check(strpos($eigenRumpf, 'belongsToUser') !== false,
    'die Bildrouten pruefen das Eigentum nicht');
check(strpos(methodenRumpf($locCode, 'updateLocation'), 'belongsToUser') !== false,
    'das Bearbeiten prueft das Eigentum nicht');
// Und der Controller gibt die Benutzerkennung an genau einer Stelle heraus.
$seitenRumpf = methodenRumpf($locCode, 'showLocationPage');
check(strpos($seitenRumpf, "Auth::isLoggedIn() && !\$ist_eigen") !== false,
    'die Bedingung fuer die Herausgabe der Benutzerkennung hat sich geaendert');
ok('das Formular sieht nur der Eigentuemer, und geprueft wird es trotzdem');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n30) Bilder: ausserhalb des Webroots, geprueft, und ohne Reste\n");

// --- Der Name kommt aus dem Programm, nicht aus der Anfrage ----------------
//
// Zwischen einer Datenbankzeile und dem Dateisystem soll keine Annahme
// stehen, sondern eine Pruefung. 32 Hexzeichen koennen kein "..", keinen
// Schraegstrich und kein Nullbyte enthalten.
check(ImageStore::isValidName(str_repeat('a', 32)) === true, 'ein gueltiger Name wird abgelehnt');
foreach (['../../etc/passwd', 'a/b', str_repeat('a', 31), str_repeat('a', 33),
          'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', str_repeat('a', 32) . "\0", '', null, 42] as $boese) {
    check(ImageStore::isValidName($boese) === false,
        'unbrauchbarer Name wird angenommen: ' . var_export($boese, true));
}
ok('nur selbst vergebene Namen kommen ins Dateisystem');

// pathFor() gibt bei einem unbrauchbaren Namen NICHTS zurueck - kein Pfad,
// den ein Aufrufer versehentlich benutzt.
ImageStore::setConfig([
    'base_path' => '/tmp/webrtc-pruefung', 'max_images_per_location' => 5,
    'max_file_bytes' => 1024, 'max_source_edge' => 100, 'full_edge' => 100,
    'thumb_width' => 10, 'thumb_height' => 10, 'jpeg_quality' => 80,
    'accepted_mime' => ['image/jpeg'],
]);
check(ImageStore::pathFor(7, '../../etc/passwd') === null, 'ein Ausbruchsversuch ergibt einen Pfad');
check(ImageStore::pathFor(0, str_repeat('a', 32)) === null, 'ohne Standort ergibt sich ein Pfad');
$voll  = ImageStore::pathFor(7, str_repeat('a', 32), 'full');
$klein = ImageStore::pathFor(7, str_repeat('a', 32), 'thumb');
check(substr($voll, -4) === '.jpg' && substr($klein, -6) === '_t.jpg',
    'Vollansicht und Vorschau tragen nicht verschiedene Namen');
check(strpos($voll, '/locations/7/') !== false, 'die Datei liegt nicht im Ordner ihres Standorts');
// Ein unbekannter Groessenname liefert die Vollansicht und nicht etwa einen
// Pfad aus dem Parameter - der kommt aus der Anfrage.
check(ImageStore::pathFor(7, str_repeat('a', 32), '../x') === $voll,
    'die Groessenangabe aus der Anfrage landet im Pfad');
ok('pathFor baut nur Pfade, die diese Klasse selbst vergeben haben kann');

// --- Die Obergrenze hat genau eine Lesestelle ------------------------------
//
// Sie soll sich spaeter je Konto unterscheiden koennen. Vorbereitet ist das
// ueber ImageStore::maxImages($user_id) - kommt die Staffelung, bekommt genau
// diese Methode ihre Abfrage. Wer die Zahl stattdessen direkt aus dem Array
// liest, macht das kaputt.
check(ImageStore::maxImages(1) === 5, 'die Vorgabe ist nicht mehr fuenf');
foreach (quellDateien($ROOT . '/class', 'php') as $datei) {
    if (basename($datei) === 'ImageStore.php') continue;
    check(strpos(file_get_contents($datei), 'max_images_per_location') === false,
        basename($datei) . ' liest die Obergrenze an ImageStore vorbei');
}
check(strpos(file_get_contents($ROOT . '/assets/js/location_page.js'), '= 5') === false,
    'im JavaScript steht eine eigene Obergrenze');
ok('die Obergrenze wird nur ueber ImageStore::maxImages gelesen');

// --- Die Dateien liegen ausserhalb des Webroots ----------------------------
ImageStore::setConfig(null);
$uploadConfig = require $ROOT . '/config/uploads.php';
check(is_string($uploadConfig['base_path']) && $uploadConfig['base_path'] !== '',
    'kein Ablagepfad konfiguriert');
// Der Vorgabepfad zeigt eine Ebene OBERHALB des Webroots - dieselbe Ebene wie
// das Fehlerlog. Was unter dem Document Root liegt, ist ueber HTTP abrufbar,
// und eine hochgeladene Datei ist Fremdeingabe.
check(strpos($uploadConfig['base_path'], '/../../uploads') !== false,
    'der Vorgabepfad liegt nicht oberhalb des Webroots: ' . $uploadConfig['base_path']);
check(in_array('image/jpeg', $uploadConfig['accepted_mime'], true), 'JPEG wird nicht angenommen');
check(!in_array('image/svg+xml', $uploadConfig['accepted_mime'], true),
    'SVG wird angenommen - das ist ein Dokument mit Skript, kein Bild');
ok('die Bilder liegen ausserhalb des Document Root, SVG ist nicht dabei');

// --- Eigentum steht auch bei den Bildern in der WHERE-Klausel --------------
$bildDb = new FakeConnection();
PdoConnect::$connection = $bildDb;
FakeStatement::$affected = 1;

LocationImage::reorder(7, 3, [12, 9]);
// EIN vorbereitetes Statement fuer alle Bilder, mehrfach ausgefuehrt - nicht
// eines je Bild. Das ist der Sinn von prepare().
check(count($bildDb->statements) === 1,
    'das Statement wird je Bild neu vorbereitet: ' . count($bildDb->statements));
// Alles in EINER Transaktion: Bricht es in der Mitte ab, stuenden sonst zwei
// Bilder auf derselben Position und die Reihenfolge waere Zufall.
check($bildDb->transaktionen === ['begin', 'commit'],
    'das Sortieren laeuft nicht in einer Transaktion: ' . implode(',', $bildDb->transaktionen));
$sql = $bildDb->statements[0]->sql;
check(strpos($sql, 'location.user_id           = :user_id') !== false,
    "der Eigentuemer fehlt beim Sortieren: $sql");
check(strpos($sql, 'location_image.location_id = :location_id') !== false,
    "der Standort fehlt beim Sortieren: $sql");
check(LocationImage::reorder(7, 0, [12]) === false, 'ohne Benutzer wird sortiert');
check(LocationImage::reorder(0, 3, [12]) === false, 'ohne Standort wird sortiert');
check(LocationImage::reorder(7, 3, [])   === false, 'eine leere Reihenfolge wird gespeichert');
ok('das Sortieren traegt Standort und Eigentuemer im Statement');

// --- Das Titelbild ist eine AUSWAHL, keine Reihenfolge ---------------------
//
// DER BEFUND: Das erste hochgeladene Bild wurde stillschweigend zum
// Titelbild, und jede Umsortierung der Galerie veraenderte damit den Kopf der
// Seite mit. Ein Titelbild braucht aber ein sehr breites Format und ruhige
// Flaechen fuer die Schrift, ein Beispielbild soll den Ort zeigen - das ist
// keine Frage der Position, sondern eine Entscheidung.

/**
 * Attrappe fuer die Statements rund um das Titelbild. Sie liefert eine feste
 * Zeile fuer findWithLocation() und schreibt alles mit, was abgesetzt wird.
 */
class FakeCoverStatement {
    public $sql; public $params = []; private $zeile;
    public function __construct($sql, $zeile) { $this->sql = $sql; $this->zeile = $zeile; }
    public function bindParam($k, &$v, $t = null) { $this->params[$k] = $v; return true; }
    public function execute() { return true; }
    public function rowCount() { return 1; }
    public function fetch($m = null) { return $this->zeile; }
    public function fetchColumn($i = 0) { return 1; }
    public function fetchAll($m = null) { return []; }
}
class FakeCoverConnection {
    public $statements = [];
    public $transaktionen = [];
    private $offen = false;
    /** Zeile, die findWithLocation() zurueckbekommt. */
    public $zeile = ['id' => 11, 'file_name' => 'x', 'location_id' => 7,
                     'role' => 'gallery', 'user_id' => 3, 'blocked' => 0];
    public function prepare($sql) {
        $s = new FakeCoverStatement($sql, $this->zeile);
        $this->statements[] = $s;
        return $s;
    }
    public function beginTransaction() { $this->offen = true;  $this->transaktionen[] = 'begin';    return true; }
    public function commit()           { $this->offen = false; $this->transaktionen[] = 'commit';   return true; }
    public function rollBack()         { $this->offen = false; $this->transaktionen[] = 'rollback'; return true; }
    public function inTransaction()    { return $this->offen; }
    /** Die schreibenden Statements, ohne das SELECT von findWithLocation(). */
    public function schreibend(): array {
        return array_values(array_filter($this->statements,
            fn($s) => stripos(trim($s->sql), 'SELECT') !== 0));
    }
}

$coverDb = new FakeCoverConnection();
PdoConnect::$connection = $coverDb;

check(LocationImage::setCover(11, 3) === true, 'das Titelbild laesst sich nicht setzen');
$schreibend = $coverDb->schreibend();
check(count($schreibend) === 2,
    'es sind nicht genau zwei Schritte: das alte zuruecknehmen, das neue setzen');

// ZWEI SCHRITTE IN EINER TRANSAKTION. Dazwischen darf es keinen Zustand
// geben, in dem ein Standort zwei Titelbilder hat oder gar keines - und genau
// das kann die Datenbank hier nicht selbst durchsetzen: Einen Teilindex ueber
// "role = 'cover'" gibt es in MariaDB nicht.
check($coverDb->transaktionen === ['begin', 'commit'],
    'das Setzen laeuft nicht in einer Transaktion: ' . implode(',', $coverDb->transaktionen));

// Schritt 1 nimmt das BISHERIGE Titelbild zurueck - und loescht es nicht.
check(stripos($schreibend[0]->sql, 'DELETE') === false,
    'das bisherige Titelbild wird geloescht statt zurueckgestuft');
check(strpos($schreibend[0]->sql, 'location.user_id') !== false,
    'der Eigentuemer fehlt beim Zuruecknehmen');
check($schreibend[0]->params[':gallery'] === LocationImage::ROLE_GALLERY,
    'das alte Titelbild landet nicht in der Galerie');

// Schritt 2 setzt das gewaehlte Bild - mit Eigentuemer in der Bedingung.
check(strpos($schreibend[1]->sql, 'location.user_id') !== false,
    'der Eigentuemer fehlt beim Setzen');
check($schreibend[1]->params[':cover'] === LocationImage::ROLE_COVER,
    'die gesetzte Rolle ist nicht das Titelbild');
ok('setCover nimmt das alte zurueck und setzt das neue - in einer Transaktion');

// Ein fremdes Bild kommt nicht durch, und dafuer wird gar nichts geschrieben.
$coverDb->statements = [];
$coverDb->transaktionen = [];
check(LocationImage::setCover(11, 999) === false, 'ein fremdes Bild wird zum Titelbild');
check($coverDb->schreibend() === [], 'fuer ein fremdes Bild wird geschrieben');
check(LocationImage::setCover(0, 3) === false, 'ohne Bild wird gesetzt');
check(LocationImage::setCover(11, 0) === false, 'ohne Benutzer wird gesetzt');
ok('ein fremdes Bild wird nicht zum Titelbild, und es wird nichts geschrieben');

// Zuruecknehmen ist ein UPDATE und kein DELETE: Wer sein Titelbild absetzt,
// will fast immer ein anderes waehlen und nicht dieses Bild verlieren.
$coverDb->statements = [];
check(LocationImage::clearCover(7, 3) === true, 'das Titelbild laesst sich nicht zuruecknehmen');
$sql = $coverDb->statements[0]->sql;
check(stripos($sql, 'DELETE') === false, 'das Zuruecknehmen loescht');
check(strpos($sql, 'location.user_id') !== false, 'der Eigentuemer fehlt beim Zuruecknehmen');
check(LocationImage::clearCover(0, 3) === false, 'ohne Standort wird zurueckgenommen');
ok('clearCover stuft zurueck, statt zu loeschen');

// --- Die Obergrenze gilt fuer die SUMME beider Arten ----------------------
//
// Eine getrennte Grenze je Art waere ueber den Umweg "als Titelbild
// markieren" zu umgehen - und gezaehlt wird ohnehin, was auf der Platte
// liegt.
$summeDb = new FakeConnection();
PdoConnect::$connection = $summeDb;
LocationImage::countForLocation(7);
check(strpos($summeDb->statements[0]->sql, 'role') === false,
    'gezaehlt wird nur eine Bildart - dann laesst sich die Obergrenze umgehen');
ok('gezaehlt werden alle Bilder eines Standorts, unabhaengig von ihrer Rolle');

// Und das Sortieren fasst NUR die Galerie an: Das Titelbild steht nicht darin
// und hat keine Position, die man verschieben koennte.
$sortDb = new FakeConnection();
PdoConnect::$connection = $sortDb;
FakeStatement::$affected = 1;
LocationImage::reorder(7, 3, [12, 13]);
check(strpos($sortDb->statements[0]->sql, "location_image.`role`      = :gallery") !== false,
    'das Sortieren koennte auch das Titelbild treffen');
ok('sortiert wird die Galerie, nicht das Titelbild');

// --- Das erste Bild eines Standorts wird sein Titelbild -------------------
//
// Aber nur, solange gar keines gewaehlt ist. Ohne das stuende ein frischer
// Standort mit fuenf Bildern unter einem leeren Kopf, und der Guide muesste
// erst merken, dass da noch eine Entscheidung offen ist.
$upRumpfCover = methodenRumpf($locCode, 'uploadImage');
check(strpos($upRumpfCover, 'LocationImage::hasCover') !== false,
    'beim Hochladen wird nicht geprueft, ob es schon ein Titelbild gibt');
check(strpos($upRumpfCover, 'LocationImage::hasCover') < strpos($upRumpfCover, 'LocationImage::add'),
    'die Rolle steht erst nach dem Eintragen fest');
check(strpos($upRumpfCover, 'ROLE_GALLERY') !== false && strpos($upRumpfCover, 'ROLE_COVER') !== false,
    'beim Hochladen wird keine Rolle vergeben');
ok('das erste Bild wird Titelbild, jedes weitere ein Beispielbild');

// --- Die Routen fuer die Auswahl ------------------------------------------
foreach (['set_location_cover', 'unset_location_cover'] as $route) {
    check(isset($routes[$route]), "die Route $route fehlt");
    check($routes[$route][2] === Permission::LOCATION_EDIT_OWN,
        "$route haengt nicht am Bearbeitungsrecht");
    check($routes[$route][3] === 'json', "$route antwortet nicht als JSON");
}
ok('die Auswahl des Titelbildes haengt am Bearbeitungsrecht');

// --- Reihenfolge der Schritte beim Hochladen und Loeschen ------------------
//
// HOCHLADEN: erst die Datei, dann die Zeile. Scheitert die Zeile, wird die
// Datei wieder weggeraeumt - andersherum bliebe eine Zeile ohne Bild zurueck,
// und die zeigt die Seite als kaputtes Bild an.
$upRumpf = methodenRumpf($locCode, 'uploadImage');
check(strpos($upRumpf, 'ImageStore::store') < strpos($upRumpf, 'LocationImage::add'),
    'die Zeile entsteht vor der Datei');
check(strpos($upRumpf, 'ImageStore::delete') > strpos($upRumpf, 'LocationImage::add'),
    'eine Datei ohne Zeile wird nicht weggeraeumt');
check(strpos($upRumpf, 'maxImages') < strpos($upRumpf, 'ImageStore::store'),
    'die Obergrenze wird erst nach dem Annehmen geprueft');
ok('beim Hochladen bleibt weder eine Zeile ohne Bild noch eine Datei ohne Zeile');

// LOESCHEN: erst die Zeile, dann die Datei - genau andersherum, aus dem
// gleichen Grund.
$delRumpf = methodenRumpf($locCode, 'deleteImage');
check(strpos($delRumpf, 'deleteOwned') < strpos($delRumpf, 'ImageStore::delete'),
    'die Datei verschwindet vor der Zeile');
ok('beim Loeschen verschwindet zuerst die Zeile');

// --- Ein geloeschter Standort laesst keine Dateien zurueck -----------------
//
// Die Zeilen in location_image nimmt der Fremdschluessel (ON DELETE CASCADE),
// die Dateien nicht - die Datenbank kennt das Dateisystem nicht.
$loeschRumpf = methodenRumpf($locCode, 'deleteLocation');
check(strpos($loeschRumpf, 'ImageStore::deleteLocationDir') !== false,
    'die Bilddateien bleiben nach dem Loeschen des Standorts liegen');
check(strpos($loeschRumpf, 'deleteLocation($location_id, $user_id)')
      < strpos($loeschRumpf, 'ImageStore::deleteLocationDir'),
    'die Dateien werden geloescht, bevor feststeht, dass der Standort dem Aufrufer gehoert');
ok('mit dem Standort verschwinden auch seine Bilddateien');

// --- Ein gesperrter Standort zeigt seine Bilder nicht ----------------------
//
// Sonst waere die Sperre wirkungslos, sobald jemand die Bild-ID kennt.
$serveRumpf = methodenRumpf($locCode, 'serveImage');
check(strpos($serveRumpf, "\$bild['blocked']") !== false,
    'die Auslieferung prueft die Sperre nicht');
check(strpos($serveRumpf, 'Permission::LOCATION_BLOCK') !== false,
    'die Moderation kommt nicht mehr an gesperrte Bilder');
check(strpos($serveRumpf, 'isValidName') !== false || strpos($serveRumpf, 'pathFor') !== false,
    'der Dateiname geht ungeprueft ins Dateisystem');
check(strpos($serveRumpf, 'nosniff') !== false,
    'der Browser darf den Typ des ausgelieferten Bildes selbst erraten');
check(strpos($serveRumpf, 'private') !== false,
    'die Bilder duerfen in einem gemeinsamen Zwischenspeicher landen');
ok('die Auslieferung prueft Sperre, Name und Typ');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n31) Die Anfrage: der neue Anfang einer Fuehrung\n");

// WORUM ES GEHT: Vorher rief ein Kunde den Guide unmittelbar an - beide
// mussten zufaellig im selben Moment koennen. Jetzt steht am Anfang eine
// Anfrage mit einem Wunschzeitpunkt, die der Guide annimmt oder ablehnt.
// "Jetzt sofort" ist dabei ein Zeitpunkt unter anderen und kein Sonderfall.

$fake = new FakeConnection();
PdoConnect::$connection = $fake;
FakeStatement::$affected = 1;

$reqConfig = require $ROOT . '/config/requests.php';

// --- Die Zustaende sind vollstaendig und heissen ueberall gleich -----------
check(TourRequest::statuses() === ['open', 'accepted', 'declined', 'expired', 'done', 'cancelled'],
    'die sechs Zustaende der Anfrage stimmen nicht');
$namen = TourRequest::statusNames();
foreach (TourRequest::statuses() as $z) {
    check(isset($namen[$z]) && $namen[$z] !== '', "der Zustand '$z' hat keinen deutschen Namen");
}
// Die Namen stehen im Modell und nicht in drei Ansichten: Liste,
// Standortseite und Kopfleiste benennen denselben Zustand.
check(strpos(file_get_contents($ROOT . '/class/Model/TourRequest.php'), 'durchgeführt') !== false,
    'die deutschen Namen stehen nicht im Modell');
ok('sechs Zustaende, benannt an einer Stelle');

// --- "Jetzt sofort" ist ein Abstand und kein Sonderfall --------------------
//
// Der Wunschzeitpunkt geht als ABSTAND in Sekunden an die Datenbank, die
// daraus an IHRER Uhr einen Zeitpunkt rechnet. Ein von PHP formatiertes Datum
// wuerde gegen NOW() der Datenbank verglichen - zwei Uhren in womoeglich zwei
// Zeitzonen.
$fake->statements = [];
check(TourRequest::create(7, 3, 4, 0) === 42, 'die Anfrage wird nicht angelegt');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'INSERT INTO tour_request') !== false, "es ist kein INSERT: $sql");
check(strpos($sql, 'DATE_ADD(NOW(), INTERVAL :wunsch SECOND)') !== false,
    "der Wunschzeitpunkt wird nicht aus einem Abstand gerechnet: $sql");
check($fake->statements[0]->params[':wunsch'] === 0, '"jetzt sofort" ist nicht die Null');
check($fake->statements[0]->params[':guide'] === 3, 'der Guide wird nicht gebunden');
check($fake->statements[0]->params[':customer'] === 4, 'der Kunde wird nicht gebunden');
check(strpos($sql, "'open'") !== false, 'eine neue Anfrage ist nicht offen');

// DER ABLAUF WIRD BEIM ANLEGEN GERECHNET, und der fruehere der beiden Gruende
// gewinnt: die Antwortfrist und der um die Karenz verlaengerte
// Wunschzeitpunkt.
check($fake->statements[0]->params[':ablauf']
      === min((int)$reqConfig['response_timeout'], 0 + (int)$reqConfig['wish_grace']),
    'bei "jetzt sofort" gewinnt nicht der verstrichene Wunschzeitpunkt');

$fake->statements = [];
TourRequest::create(7, 3, 4, 86400);
check($fake->statements[0]->params[':ablauf'] === (int)$reqConfig['response_timeout'],
    'bei einem fernen Wunschzeitpunkt gewinnt nicht die Antwortfrist');

// Unvollstaendige Angaben erreichen die Datenbank nicht - und sich selbst
// fragt niemand an.
$fake->statements = [];
check(TourRequest::create(0, 3, 4, 0) === null, 'ohne Standort wird angelegt');
check(TourRequest::create(7, 0, 4, 0) === null, 'ohne Guide wird angelegt');
check(TourRequest::create(7, 3, 3, 0) === null, 'der eigene Standort laesst sich anfragen');
check(count($fake->statements) === 0, 'unvollstaendige Angaben erzeugen ein Statement');
ok('der Wunschzeitpunkt ist ein Abstand, und der Ablauf steht in der Zeile');

// --- Die Zustaendigkeit steht in der WHERE-Klausel -------------------------
//
// Dieselbe Regel wie beim Standort: Eine Rechtetabelle kann nicht wissen, an
// wen eine Anfrage gerichtet ist.
$fake->statements = [];
check(TourRequest::accept(5, 3) === true, 'annehmen scheitert');
$sql = $fake->statements[0]->sql;
check(preg_match('/guide_user_id\s*=\s*:guide/i', $sql) === 1,
    "der Guide fehlt in der Bedingung: $sql");
check(strpos($sql, "status = 'open'") !== false, 'eine beantwortete Anfrage laesst sich erneut beantworten');
check(strpos($sql, 'expires_at > NOW()') !== false,
    'eine abgelaufene Anfrage laesst sich noch annehmen');
check($fake->statements[0]->params[':status'] === 'accepted', 'der Zustand stimmt nicht');
check(strpos($sql, 'decided_at = NOW()') !== false, 'der Zeitpunkt der Antwort wird nicht festgehalten');

$fake->statements = [];
TourRequest::decline(5, 3);
check($fake->statements[0]->params[':status'] === 'declined', 'ablehnen setzt den falschen Zustand');

// Trifft die Bedingung nichts, ist es kein Erfolg.
FakeStatement::$affected = 0;
check(TourRequest::accept(5, 999) === false, 'eine fremde Anfrage gilt als angenommen');
FakeStatement::$affected = 1;

// Zuruecknehmen: beide Seiten duerfen, aber nur bis zum Beginn der Fuehrung.
$fake->statements = [];
check(TourRequest::cancel(5, 4) === true, 'zuruecknehmen scheitert');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'customer_user_id = :user') !== false
      && strpos($sql, 'guide_user_id = :user2') !== false,
    "die Beteiligung fehlt in der Bedingung: $sql");
check(strpos($sql, 'started_at IS NULL') !== false,
    'eine begonnene Fuehrung laesst sich nachtraeglich abbrechen');
ok('annehmen, ablehnen und zuruecknehmen tragen die Beteiligung im Statement');

// --- Der Zustand wird gerechnet, nicht geglaubt ----------------------------
//
// "abgelaufen" steht in keiner Spalte: Es ergibt sich aus den Zeitpunkten und
// wird bei jeder Abfrage ausgewertet. Damit wirkt ein Ablauf auch dann, wenn
// der Cronjob gar nicht eingerichtet ist - dieselbe Bauart wie bei der
// Bereitschaft (Location::AVAILABILITY_SQL).
$statusSql = TourRequest::statusSql('r');
check(strpos($statusSql, 'expires_at <= NOW()') !== false,
    'eine offene Anfrage laeuft nie ab');
check(strpos($statusSql, 'started_at IS NULL') !== false,
    'eine begonnene Fuehrung laeuft ab');
check(preg_match('/DATE_ADD\(r\.wish_at, INTERVAL \d+ SECOND\)/', $statusSql) === 1,
    "das Zeitfenster steht nicht als Zahl in der Abfrage: $statusSql");
check(substr_count($statusSql, "'expired'") === 2, 'es gibt nicht zwei Wege in den Ablauf');

// Der Tabellenalias geht als Textbaustein in die Abfrage und wird geprueft -
// auch wenn er nur aus diesem Projekt kommt.
$praepariert = TourRequest::statusSql('r; DROP TABLE user');
check(strpos($praepariert, ';') === false && strpos($praepariert, 'DROP TABLE') === false,
    "ein praeparierter Alias landet in der Abfrage: $praepariert");

// Anrufbar ist eine Zusage im vereinbarten Fenster - und danach nur noch,
// solange die Fuehrung LAEUFT (begonnen und nicht beendet). Frueher stand
// hier 'done' neben 'accepted': Eine abgeschlossene Fuehrung blieb anrufbar,
// solange das Zeitfenster lief, und der Kunde konnte sie beliebig oft neu
// starten. Genau das ist der behobene Fehler (migrations/017).
$callSql = TourRequest::callableSql('r');
check(strpos($callSql, "'accepted'") !== false, 'eine Zusage ist nicht anrufbar');
// 'done' kommt im Ausdruck noch vor - aber NEGIERT, als Teil der Frage "ist
// sie zu?". Geprueft wird deshalb, dass es die alte Aufzaehlung nicht mehr
// gibt und dass der Abschluss ausgeschlossen wird.
check(preg_match("/status\s+IN\s*\(\s*'accepted'\s*,\s*'done'/", $callSql) !== 1,
    'eine abgeschlossene Fuehrung laesst sich weiterhin neu starten');
check(preg_match('/NOT\s*\(/', $callSql) === 1,
    'der Wiedereinstieg schliesst eine beendete Fuehrung nicht aus');
check(strpos($callSql, 'closed_at') !== false,
    'der Wiedereinstieg fragt nicht danach, ob beendet wurde');
check(strpos($callSql, "'open'") === false, 'eine offene Anfrage ist anrufbar');
check(strpos($callSql, "'declined'") === false && strpos($callSql, "'cancelled'") === false,
    'eine abgelehnte Anfrage ist anrufbar');
check(strpos($callSql, 'DATE_SUB') !== false && strpos($callSql, 'DATE_ADD') !== false,
    'das Zeitfenster hat keine zwei Seiten');
ok('Ablauf und Anrufbarkeit stehen als Ausdruck in jeder Abfrage');

// --- Beginn und Ende kommen aus dem Signaling ------------------------------
$fake->statements = [];
TourRequest::markStarted(4, 3, 7);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'started_at = NOW()') !== false, 'der Beginn wird nicht festgehalten');
check(strpos($sql, 'r.started_at IS NULL') !== false,
    'ein Rueckruf verschiebt den Beginn der Fuehrung');
check(strpos($sql, 'customer_user_id = :customer') !== false
      && strpos($sql, 'guide_user_id    = :guide') !== false
      && strpos($sql, 'location_id      = :location') !== false,
    "der Beginn haengt nicht am Tripel Kunde/Guide/Standort: $sql");

// AUFLEGEN IST NICHT BEENDEN. Es kann heissen "wir sind fertig" - oder "das
// Netz ist weg". Festgehalten wird deshalb nur der Zeitpunkt; der Zustand
// bleibt 'accepted', und ab hier laeuft die Frist fuer den Wiedereinstieg.
$fake->statements = [];
TourRequest::markEnded(4, 3);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'r.ended_at = NOW()') !== false, 'das Auflegen wird nicht festgehalten');
check(strpos($sql, "status   = 'done'") === false && strpos($sql, "= 'done'") === false,
    "das Auflegen schliesst die Fuehrung ab: $sql");
check(strpos($sql, 'r.started_at IS NOT NULL') !== false,
    'ein Anruf, der nie zustande kam, hinterlaesst ein Ende');
check(strpos($sql, 'r.closed_at IS NULL') !== false,
    'eine beendete Fuehrung bekommt ein neues Ende angehaengt');
// UEBERSCHRIEBEN WIRD BEI JEDEM AUFLEGEN: Die Frist zaehlt ab dem LETZTEN,
// nicht ab dem ersten.
check(strpos($sql, 'ended_at IS NULL') === false,
    'nach einem zweiten Auflegen laeuft die Frist weiter ab dem ersten');
// WELCHE SEITE AUFLEGT, IST OFFEN - deshalb das Paar in beide Richtungen.
check(substr_count($sql, 'customer_user_id') === 2 && substr_count($sql, 'guide_user_id') === 2,
    "das Paar wird nur in einer Richtung geprueft: $sql");
check(TourRequest::markEnded(4, 4) === false, 'ein Selbstgespraech schliesst eine Fuehrung ab');
ok('der Beginn kommt aus dem Signaling, das Auflegen ist noch kein Ende');

// --- Der Cronjob raeumt nur auf --------------------------------------------
$fake->statements = [];
TourRequest::expireDue();
check(count($fake->statements) === 2, 'es sind nicht die zwei Ablaufgruende');
$alle = $fake->statements[0]->sql . ' ' . $fake->statements[1]->sql;
check(substr_count($alle, "SET status = 'expired'") === 2, 'es wird nicht auf abgelaufen gesetzt');
check(strpos($alle, "status = 'open'") !== false, 'die offenen Anfragen bleiben liegen');
check(strpos($alle, "status = 'accepted'") !== false, 'die ungenutzten Zusagen bleiben liegen');

$fake->statements = [];
TourRequest::closeStale();
check(count($fake->statements) === 2, 'es sind nicht die zwei Gruende, aus denen eine Fuehrung zugeht');
$alle = $fake->statements[0]->sql . ' ' . $fake->statements[1]->sql;
check(substr_count($alle, "SET status = 'done'") === 2,
    'haengende Fuehrungen werden nicht abgeschlossen');
check(strpos($alle, 'ended_at = NOW()') === false && strpos($alle, 'closed_at = NOW()') === false,
    'der Cronjob erfindet ein Ende oder einen Abschluss');
check(substr_count($alle, 'closed_at IS NULL') === 2,
    'der Cronjob fasst beendete Fuehrungen noch einmal an');

// Der Cronjob ruft beides auf - sonst waere es Code ohne Aufrufer.
$cron = file_get_contents($ROOT . '/cron/check_online_status.php');
check(strpos($cron, 'TourRequest::expireDue') !== false, 'der Cronjob raeumt keine Anfragen auf');
check(strpos($cron, 'TourRequest::closeStale') !== false, 'haengende Fuehrungen bleiben stehen');
ok('der Cronjob raeumt auf, ohne ein Ende zu erfinden');

// --- Die Fristen stehen an genau einer Stelle ------------------------------
foreach (['response_timeout', 'wish_grace', 'lead_time_max',
          'call_window_before', 'call_window_after', 'rejoin_window',
          'stale_call'] as $schluessel) {
    check(isset($reqConfig[$schluessel]) && is_int($reqConfig[$schluessel]),
        "die Frist '$schluessel' fehlt in config/requests.php");
}
foreach ([['class', 'Model', 'TourRequest.php'],
          ['class', 'Controller', 'RequestController.php'],
          ['assets', 'js', 'requests.js']] as $teile) {
    $inhalt = file_get_contents($ROOT . '/' . implode('/', $teile));
    foreach ([(string)$reqConfig['response_timeout'], (string)$reqConfig['wish_grace'],
              (string)$reqConfig['call_window_after']] as $zahl) {
        check(strpos($inhalt, $zahl) === false,
            implode('/', $teile) . ": die Frist $zahl steht als Zahl im Code");
    }
}
ok('die Fristen der Anfrage stehen nur in config/requests.php');

// --- Die Wahl im Formular muss man SEHEN ------------------------------------
//
// Die Vorgabeknoepfe wirkten, aber ihre Wirkung war unsichtbar: eine leise
// Toenung und ein Rahmen. Wer einen drueckte, hielt sie fuer kaputt. Der
// gewaehlte Knopf ist deshalb AUSGEFUELLT und traegt einen Haken - Farbe,
// Flaeche und Form, damit es auch dort ankommt, wo Farbe nicht ankommt.
$locCss = file_get_contents($ROOT . '/assets/css/location.css');
$anfang = strpos($locCss, '.loc-req__preset--on');
check($anfang !== false, 'die gewaehlte Vorgabe hat keine eigene Gestaltung');
$aktiv = substr($locCss, $anfang, 700);

check(preg_match('/background:\s*var\(--app-accent\)/', $aktiv) === 1,
    'die gewaehlte Vorgabe ist nicht ausgefuellt, sondern nur getoent');
check(strpos($aktiv, 'var(--app-text-on-accent)') !== false,
    'die Schrift auf dem Akzent kommt nicht aus der Variablen - im dunklen Profil waere sie unlesbar');
check(strpos($aktiv, '::before') !== false && strpos($aktiv, "content: '✓'") !== false,
    'der gewaehlte Knopf traegt kein Zeichen - Farbe allein ist zu wenig');
check(preg_match('/\.loc-req__preset--on[^{]*\{[^}]*#[0-9a-fA-F]{3,6}/', $aktiv) !== 1,
    'in der Gestaltung steht eine feste Farbe statt einer Variablen');
ok('die gewaehlte Vorgabe ist ausgefuellt, beschriftet und profilfest');

// Und das Skript traegt den Zeitpunkt wirklich ein - sonst stuende die
// Markierung ohne Angabe da, was sie bedeutet.
$seiteJs = file_get_contents($ROOT . '/assets/js/location_page.js');
check(strpos($seiteJs, 'zeigeWunschzeit()') !== false, 'die Wahl wird nirgends ins Feld geschrieben');
check(substr_count($seiteJs, 'this.zeigeWunschzeit();') >= 3,
    'die Wahl wird nicht an allen drei Stellen nachgezogen (Klick, Aufbau, Neuzeichnen)');
// Gesucht wird der AUFRUF (mit Punkt davor), nicht das Wort: Im Kommentar
// steht ausdruecklich, warum toISOString hier falsch waere - das soll dort
// stehen bleiben duerfen.
check(strpos($seiteJs, '.toISOString(') === false,
    'der Zeitpunkt wird ueber UTC gerechnet - das Feld traegt Ortszeit');
ok('der gewaehlte Zeitpunkt steht sichtbar im Feld, in Ortszeit');

// --- Die Rechte und die Routen ---------------------------------------------
$routes = require $ROOT . '/config/routes.php';
check(Permission::routeErrors($routes) === [], 'die Routentabelle ist fehlerhaft');

$erwartet = [
    'request_create'  => Permission::REQUEST_CREATE,
    'request_accept'  => Permission::REQUEST_ANSWER,
    'request_decline' => Permission::REQUEST_ANSWER,
    'request_cancel'  => Permission::REQUEST_CANCEL,
    'get_requests'    => Permission::REQUEST_LIST,
    'requests_page'   => Permission::REQUEST_LIST,
];
foreach ($erwartet as $act => $recht) {
    check(isset($routes[$act]), "die Route '$act' fehlt");
    check($routes[$act][2] === $recht, "die Route '$act' haengt am falschen Recht");
}

// BEANTWORTEN darf nur, wer selbst Standorte anbietet - dieselben Rollen wie
// bei location.offer. Wer keine anbietet, bekommt auch keine Anfragen.
foreach ([Role::TRIAL, Role::USER, Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::REQUEST_ANSWER)
          === Permission::has($rolle, Permission::LOCATION_OFFER),
        "Rolle $rolle: request.answer und location.offer stehen nicht beieinander");
    // Anfragen und die eigene Liste sehen darf jedes angemeldete Konto: Ein
    // Guide ist anderswo Kunde.
    check(Permission::has($rolle, Permission::REQUEST_CREATE), "Rolle $rolle darf nicht anfragen");
    check(Permission::has($rolle, Permission::REQUEST_LIST), "Rolle $rolle sieht seine Anfragen nicht");
}
// Der Gast hat keines der vier: Eine Anfrage gehoert zu einem Konto, sonst
// gaebe es niemanden, dem der Guide zusagen koennte.
foreach ([Permission::REQUEST_CREATE, Permission::REQUEST_ANSWER,
          Permission::REQUEST_LIST, Permission::REQUEST_CANCEL] as $recht) {
    check(!Permission::has(Permission::GUEST, $recht), "der Gast hat $recht");
}
ok('die Anfragerouten haengen an vier eigenen Rechten');

// --- Der Weg in die Fuehrung: die Zusage ersetzt die Bereitschaft ----------
//
// Eine angenommene Anfrage ist die staerkere Aussage: Sie gilt fuer genau
// diesen Kunden, diesen Standort und dieses Zeitfenster. Der
// Bereitschaftsschalter sagt "ich kann jetzt sofort" und gilt fuer jeden.

/**
 * Attrappe wie FakeUserConnection, die zusaetzlich eine angenommene Anfrage
 * kennt. Erkannt wird sie an der Tabelle in der Abfrage.
 */
class FakeRequestStatement extends FakeUserStatement {
    public static $zusage = false;
    /**
     * Eine LAUFENDE Fuehrung zwischen den beiden - begonnen und nicht
     * beendet. Sie entscheidet seit migrations/017 ueber die Rollen, damit
     * beim Wiedereinstieg nach einem Abbruch auch der Guide waehlen darf.
     * false heisst: keine.
     */
    public static $laufend = false;
    public function fetch($mode = null) {
        if (strpos($this->sql, 'FROM tour_request') !== false) {
            // runningBetween() holt den Guide in der AUSWAHLLISTE mit - daran
            // ist sie von der Zusagenpruefung zu unterscheiden, die nur die
            // Kennung holt (beide nennen den Guide in der Bedingung).
            if (strpos($this->sql, 'r.id, r.guide_user_id') !== false) {
                return self::$laufend ?: false;
            }
            return self::$zusage ? ['id' => 5] : false;
        }
        return parent::fetch($mode);
    }
}
class FakeRequestConnection extends FakeUserConnection {
    public function prepare($sql) {
        return new FakeRequestStatement($sql, $this->users, $this->locations);
    }
}

$reqDb = new FakeRequestConnection();
// Guide 6 bietet Standort 13 an, steht aber NICHT auf bereit.
$reqDb->users = [
    4 => fakeUser(4, 1),          // Kunde (Rolle User)
    6 => fakeUser(6, 2, false),   // Guide, nicht bereit
];
// Zwei Standorte desselben Guides: Die Rollenvergabe merkt sich ihre
// Antworten fuer die Dauer EINER Anfrage (Zwischenspeicher in
// WebRTCController) - zwei Faelle brauchen deshalb zwei Standorte, sonst
// pruefte der zweite den gemerkten Wert des ersten.
$reqDb->locations = [13 => fakeLocation(13, 6), 14 => fakeLocation(14, 6)];
PdoConnect::$connection = $reqDb;

// Ohne Zusage und ohne Bereitschaft kommt der Anruf nicht zustande - das war
// schon vorher so und bleibt so.
FakeRequestStatement::$zusage  = false;
FakeRequestStatement::$laufend = false;
check(WebRTCController::callRoles(4, 6, 13) === null,
    'ein Anruf ohne Bereitschaft und ohne Zusage kommt durch');

// Mit Zusage wird es eine Fuehrung, obwohl der Schalter aus ist.
FakeRequestStatement::$zusage = true;
$rollen = WebRTCController::callRoles(4, 6, 14);
check($rollen === ['caller' => 'viewer', 'callee' => 'guide'],
    'eine angenommene Anfrage oeffnet den Anruf nicht');

// ABER NUR VON EINEM STANDORT DES ANGERUFENEN. Die Standortkennung ist eine
// Behauptung des Anrufers und wird weiterhin geprueft - eine Zusage haengt
// immer an einem Standort.
check(WebRTCController::callRoles(4, 6, null) === null,
    'eine Zusage oeffnet auch den Anruf ohne Standort');
check(WebRTCController::callRoles(4, 6, 99) === null,
    'eine Zusage oeffnet den Anruf ueber einen fremden Standort');
ok('die Zusage ersetzt die Bereitschaft - aber nur an ihrem Standort');

// Das Signaling haelt Beginn und Ende fest, und zwar an den beiden
// Nachrichten, die ohnehin durchlaufen.
$rtcCode = file_get_contents($ROOT . '/class/Controller/WebRTCController.php');
check(strpos($rtcCode, 'TourRequest::markStarted') !== false,
    'der Beginn der Fuehrung wird nirgends festgehalten');
check(strpos($rtcCode, 'TourRequest::markEnded') !== false,
    'das Ende der Fuehrung wird nirgends festgehalten');
check(strpos($rtcCode, 'TourRequest::acceptedForCall') !== false
      || strpos($rtcCode, 'acceptedRequest') !== false,
    'die Zusage wird bei der Rollenvergabe nicht gelesen');
ok('Beginn und Ende haengen an Offer und Hangup');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n32) Uebliche Zeiten: das Raster, die Ortszeit und der Hinweis\n");

// DER ANLASS
// Ein Kunde sah nur, ob ein Guide GERADE bereit ist. War er es nicht, blieb
// offen, ob sich eine Anfrage fuer spaeter lohnt. Die ueblichen Zeiten sind
// die Antwort - eine ORIENTIERUNG, kein Kalender.

// --- 32a. Das Raster: 7 x 4, und die Stelle steht fest ---------------------
//
// Die Reihenfolge im Muster ist der Vertrag zwischen Formular, Datenbank,
// Server und Browser. Wer sie verschiebt, verschiebt alle Angaben aller
// Standorte - deshalb steht sie hier als Zahl und nicht als Beschreibung.
check(Availability::LAENGE === 28, 'das Raster hat nicht mehr 7 x 4 Felder');
check(count(Availability::tage()) === 7, 'es sind nicht sieben Wochentage');
check(count(Availability::abschnitte()) === 4, 'es sind nicht vier Tagesabschnitte');
check(array_keys(Availability::tage())[0] === 'mo', 'die Woche beginnt nicht am Montag');
check(Availability::stelle(3, 3) === 15, 'Donnerstag abends liegt nicht auf Stelle 15');
ok('sieben Tage, vier Abschnitte, und die Stelle im Muster ist festgelegt');

// Die Abschnitte decken den Tag LUECKENLOS ab. Faende eine Stunde keinen
// Abschnitt, faende auch kein Wunschzeitpunkt sein Feld - und die Zuordnung
// fiele stillschweigend auf den ersten zurueck.
$gefunden = [];
for ($stunde = 0; $stunde < 24; $stunde++) {
    $gefunden[Availability::abschnittFuerStunde($stunde)] = true;
}
check(count($gefunden) === 4, 'nicht jeder Abschnitt kommt im Tag vor');
check(Availability::abschnittFuerStunde(23) === 'nacht', '23 Uhr ist nicht nachts');
check(Availability::abschnittFuerStunde(3)  === 'nacht', '3 Uhr ist nicht nachts');
check(Availability::abschnittFuerStunde(6)  === 'vormittag', '6 Uhr ist nicht vormittags');
check(Availability::abschnittFuerStunde(21) === 'abend', '21 Uhr ist nicht abends');
ok('die vier Abschnitte decken den Tag lueckenlos, die Nacht ueber Mitternacht hinweg');

// --- 32b. Was aus dem Formular kommt, und was daraus wird ------------------
//
// Wie bei den Sprachen (Languages::normalize): Unbekanntes ist kein
// Ablehnungsgrund, es ist einfach keine Angabe.
$muster = Availability::normalize(['do-abend', 'sa-vormittag', 'quatsch',
                                   'mo-mittagsschlaf', 'do-abend', 42, 'so-vormittag']);
check(strlen($muster) === 28, 'normalize liefert kein Muster der festen Laenge');
check($muster[15] === '1', 'Donnerstag abends fehlt im Muster');
check($muster[Availability::stelle(5, 1)] === '1', 'Samstag vormittags fehlt im Muster');
check($muster[Availability::stelle(6, 1)] === '1', 'Sonntag vormittags fehlt im Muster');
check(substr_count($muster, '1') === 3, 'normalize hat Unbekanntes durchgelassen');
check(Availability::normalize('do-abend') === Availability::leer(),
    'normalize nimmt auch etwas an, das keine Liste ist');
ok('normalize laesst nur bekannte Felder durch und zaehlt sie einmal');

// Was aus der Datenbank kommt, ist nicht immer, was hineingeschrieben wurde:
// Bestandsdaten haben NULL, und ein verstellter Wert kann jede Laenge haben.
// Die Lesestellen sollen sich trotzdem auf 28 Zeichen verlassen koennen.
check(strlen(Availability::muster(null)) === 28, 'NULL ergibt kein Muster');
check(strlen(Availability::muster('11')) === 28, 'ein zu kurzer Wert wird nicht aufgefuellt');
check(strlen(Availability::muster(str_repeat('1', 99))) === 28, 'ein zu langer Wert wird nicht gekuerzt');
check(Availability::muster('1x1') === '101' . str_repeat('0', 25), 'Unsinn wird nicht zu Null');
check(Availability::istLeer(null) === true, 'NULL gilt als Angabe');
check(Availability::istLeer($muster) === false, 'ein gefuelltes Muster gilt als leer');
check(Availability::hat($muster, 'do', 'abend') === true, 'hat() findet Donnerstag abends nicht');
check(Availability::hat($muster, 'do', 'nacht') === false, 'hat() findet ein leeres Feld');
check(Availability::hat($muster, 'xx', 'abend') === false, 'hat() nimmt einen unbekannten Tag an');
ok('ein gespeicherter Wert wird immer zu 28 Zeichen, was auch darin stand');

// --- 32c. Der Satz fasst zusammen, statt aufzuzaehlen ----------------------
//
// "Mo-Fr abends" liest sich auf einen Blick, fuenf Zeilen nicht. Genau darum
// geht es bei einer Orientierung.
$werktags = Availability::normalize(['mo-abend', 'di-abend', 'mi-abend', 'do-abend', 'fr-abend']);
check(Availability::text($werktags) === 'Mo-Fr abends', 'aus fuenf Werktagen wird keine Strecke: ' . Availability::text($werktags));
$wochenende = Availability::normalize(['sa-vormittag', 'so-vormittag']);
check(Availability::text($wochenende) === 'Sa+So vormittags', 'zwei Tage werden nicht mit + verbunden');
check(Availability::text(Availability::normalize(['do-abend'])) === 'Do abends', 'ein einzelner Tag steht nicht allein da');
$gemischt = Availability::normalize(['mo-abend', 'mi-abend', 'sa-vormittag']);
check(Availability::text($gemischt) === 'Sa vormittags, Mo, Mi abends',
    'getrennte Tage werden falsch zusammengefasst: ' . Availability::text($gemischt));
check(Availability::text(null) === '', 'ohne Angabe entsteht trotzdem ein Satz');
ok('der Satz fasst aufeinanderfolgende Tage zusammen und schweigt ohne Angabe');

// --- 32d. Gerechnet wird in der Zeitzone des STANDORTS ---------------------
//
// DAS IST DER KERN DER SACHE: "Donnerstags abends" gilt am Ort der Fuehrung.
// Derselbe Moment ist in Lissabon Donnerstagabend und in Tokio schon
// Freitagmorgen - wer in der falschen Zone rechnet, meldet dem Kunden das
// Gegenteil dessen, was gilt.
$moment = new DateTimeImmutable('2026-09-03 20:30:00', new DateTimeZone('Europe/Lisbon'));
check(Availability::passt(Availability::normalize(['do-abend']), $moment, 'Europe/Lisbon') === true,
    'Donnerstag 20:30 Ortszeit faellt nicht in "donnerstags abends"');
check(Availability::passt(Availability::normalize(['do-abend']), $moment, 'Asia/Tokyo') === false,
    'derselbe Moment in Tokio gerechnet gilt weiterhin als Donnerstagabend');
// In Tokio ist derselbe Moment Freitag halb fuenf morgens - also nachts,
// und die Nacht gehoert dem Kalendertag, auf den die Uhrzeit faellt.
check(Availability::passt(Availability::normalize(['fr-nacht']), $moment, 'Asia/Tokyo') === true,
    'in Tokio faellt dieser Moment nicht in die Nacht des Freitags');
// Der Moment traegt seine eigene Zone mit - er darf in jeder ankommen.
$selberMoment = $moment->setTimezone(new DateTimeZone('UTC'));
check(Availability::passt(Availability::normalize(['do-abend']), $selberMoment, 'Europe/Lisbon') === true,
    'die Zone des uebergebenen Zeitpunkts faelscht das Ergebnis');
// Die Nacht laeuft ueber Mitternacht und gehoert dem Kalendertag.
$nachts = new DateTimeImmutable('2026-09-04 02:00:00', new DateTimeZone('Europe/Lisbon'));
check(Availability::passt(Availability::normalize(['fr-nacht']), $nachts, 'Europe/Lisbon') === true,
    'zwei Uhr nachts faellt nicht in die Nacht ihres Kalendertages');
// OHNE ANGABEN IST NICHTS AUSSERHALB: aus fehlender Auskunft folgt kein
// Hinweis (siehe zeitenHtml und location_page.js).
check(Availability::passt(null, $moment, 'Europe/Lisbon') === false,
    'ohne Angaben passt jeder Zeitpunkt - dann waere die Pruefung sinnlos');
ok('der Wunschzeitpunkt wird in der Zeitzone des Standorts gelesen, nicht in der des Kunden');

// --- 32e. Woher die Zeitzone kommt -----------------------------------------
//
// Drei Stufen, alle mit PHP-Bordmitteln und ohne Netzaufruf: ein Land mit
// einer Zone, ein Land mit mehreren gleichen, sonst die naechstgelegene.
check(Availability::zoneFor('JP', 35.68, 139.76) === 'Asia/Tokyo', 'Japan hat nur eine Zone');
check(Availability::zoneFor('DE', 48.14, 11.58) === 'Europe/Berlin',
    'Deutschland bekommt nicht die gelaeufige Zone: ' . var_export(Availability::zoneFor('DE', 48.14, 11.58), true));
check(Availability::zoneFor('US', 39.74, -104.99) === 'America/Denver',
    'Denver wird nicht getroffen: ' . var_export(Availability::zoneFor('US', 39.74, -104.99), true));
check(Availability::zoneFor('US', 40.71, -74.01) === 'America/New_York',
    'New York wird nicht getroffen: ' . var_export(Availability::zoneFor('US', 40.71, -74.01), true));
check(Availability::zoneFor('AU', -31.95, 115.86) === 'Australia/Perth',
    'Perth wird nicht getroffen: ' . var_export(Availability::zoneFor('AU', -31.95, 115.86), true));
check(Availability::zoneFor('PT', 38.72, -9.14) === 'Europe/Lisbon',
    'Lissabon wird nicht getroffen: ' . var_export(Availability::zoneFor('PT', 38.72, -9.14), true));
// Was PHP nicht kennt, wird nicht geraten.
check(Availability::zoneFor('XX', 0, 0) === null, 'ein unbekanntes Land bekommt eine erfundene Zone');
check(Availability::zoneFor('', 0, 0) === null, 'ohne Land entsteht trotzdem eine Zone');
// Der Rueckfall ist UTC und nicht die Zeit des Servers: Eine unbekannte Zone
// soll auffallen und nicht stillschweigend "wie bei uns" bedeuten.
check(Availability::zone('Erfundene/Zone') === 'UTC', 'eine unbekannte Zone wird durchgereicht');
check(Availability::istZone('Europe/Lisbon') === true, 'eine echte Zone gilt als unbekannt');
check(Availability::istZone('Europe/Atlantis') === false, 'eine erfundene Zone gilt als bekannt');
ok('die Zeitzone kommt aus Land und Koordinaten - mit Bordmitteln, ohne Netzaufruf');

// Die lesbare Angabe traegt BEIDES: den Ort und den Abstand zu UTC. "UTC+1"
// allein sagt nicht, wo das gilt; "Europe/Lisbon" allein nicht, wie weit es
// vom Kunden weg ist.
$winter = new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'));
$sommer = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
check(Availability::zoneText('Europe/Berlin', $winter) === 'Europe/Berlin (UTC+1)',
    'im Winter steht Berlin nicht auf UTC+1: ' . Availability::zoneText('Europe/Berlin', $winter));
check(Availability::zoneText('Europe/Berlin', $sommer) === 'Europe/Berlin (UTC+2)',
    'die Sommerzeit wird nicht mitgerechnet: ' . Availability::zoneText('Europe/Berlin', $sommer));
check(Availability::zoneText('Asia/Kolkata', $winter) === 'Asia/Kolkata (UTC+5:30)',
    'halbe Stunden fallen unter den Tisch: ' . Availability::zoneText('Asia/Kolkata', $winter));
check(strpos(Availability::zoneText('America/New_York', $winter), 'UTC-5') !== false,
    'westlich von UTC fehlt das Minus');
ok('die Zone steht lesbar da, mit Sommerzeit und halben Stunden');

// --- 32f. Die Zeiten gehoeren zum Standort und werden gespeichert ----------
//
// Nicht zum Konto: Derselbe Guide kann in der Altstadt abends und am Hafen
// sonntags frueh unterwegs sein.
$zeitDb = new FakeConnection();
PdoConnect::$connection = $zeitDb;
FakeStatement::$affected = 1;

$locZeit = new Location();
$refl    = new ReflectionObject($locZeit);
$feld    = $refl->getProperty('id');
$feld->setAccessible(true);
$feld->setValue($locZeit, 42);
$locZeit->setAvailabilitySlots($werktags);
$locZeit->setTimezone('Europe/Lisbon');
$locZeit->updateLocation(7);

$sqlZeit = $zeitDb->statements[0]->sql;
check(strpos($sqlZeit, 'availability_slots') !== false, 'die ueblichen Zeiten werden nicht gespeichert');
check(strpos($sqlZeit, 'timezone') !== false, 'die Zeitzone wird nicht gespeichert');
check($zeitDb->statements[0]->params[':slots'] === $werktags, 'das Muster kommt nicht am Statement an');
check($zeitDb->statements[0]->params[':timezone'] === 'Europe/Lisbon', 'die Zone kommt nicht am Statement an');
// Ein leeres Raster ist KEINE Angabe und wird NULL - nicht 28 Nullen. Sonst
// liesse sich "nichts angegeben" nicht von "nie" unterscheiden.
$locLeer = new Location();
$locLeer->setAvailabilitySlots(Availability::leer());
$locLeer->setTimezone('Erfundene/Zone');
$refl2 = new ReflectionObject($locLeer);
foreach (['availability_slots' => null, 'timezone' => null] as $name => $erwartet) {
    $p = $refl2->getProperty($name);
    $p->setAccessible(true);
    check($p->getValue($locLeer) === $erwartet, "$name wird nicht auf NULL gesetzt");
}
ok('die Zeiten und die Zone haengen am Standort, ein leeres Raster wird NULL');

// Die Seite braucht beide Spalten - und die Laenderkennung, aus der sich die
// Zone notfalls ableiten laesst.
$seiteDb2 = new FakeConnection();
PdoConnect::$connection = $seiteDb2;
(new Location())->selectOneForPage(5);
$sqlSeite = $seiteDb2->statements[0]->sql;
foreach (['availability_slots', 'timezone', 'iso2'] as $spalte) {
    check(strpos($sqlSeite, $spalte) !== false, "der Seite fehlt $spalte");
}
ok('die Standortseite bekommt Raster, Zone und Laenderkennung aus einer Abfrage');

// Der Controller nimmt die Auswahl entgegen - und leitet die Zone ab, wenn
// keine gewaehlt wurde. Geraten wird dabei nichts: zoneFor kennt nur Land
// und Koordinaten, und der Guide kann das Ergebnis ueberschreiben.
$ctrlCode = file_get_contents($ROOT . '/class/Controller/LocationController.php');
check(strpos($ctrlCode, 'Availability::normalize') !== false,
    'die Auswahl aus dem Formular laeuft nicht durch normalize');
check(strpos($ctrlCode, 'Availability::istZone') !== false,
    'eine mitgeschickte Zone wird nicht geprueft');
check(strpos($ctrlCode, 'Availability::zoneFor') !== false,
    'ohne Auswahl wird keine Zone abgeleitet');
check(strpos($ctrlCode, "\$_POST['availability']") !== false,
    'das Raster wird nicht aus dem Formular gelesen');
ok('der Controller prueft die Auswahl und leitet die Zone ab, statt sie zu raten');

// --- 32g. Was der Kunde sieht ----------------------------------------------
$standortZeit = $standort;
$standortZeit['availability_slots'] = $werktags;
$standortZeit['timezone']           = 'Europe/Lisbon';
$standortZeit['iso2']               = 'PT';

$seiteZeit = LocationView::page($standortZeit, $bilder,
    ['eigen' => false, 'angemeldet' => true, 'viewer_id' => 3]);

check(strpos($seiteZeit, 'loc-hours') !== false, 'die ueblichen Zeiten stehen nicht auf der Seite');
check(strpos($seiteZeit, 'Mo-Fr abends') !== false, 'der Satz mit den Zeiten fehlt');
check(strpos($seiteZeit, 'Europe/Lisbon (UTC') !== false, 'die Ortszeit steht nicht dabei');
check(strpos($seiteZeit, 'data-timezone="Europe/Lisbon"') !== false,
    'das Skript findet die Zone des Standorts nicht');
// SIE STEHEN AM ANFRAGEKNOPF und nicht unten bei Dauer und Sprachen: Dort
// faellt die Entscheidung, ob sich eine Anfrage lohnt.
$posZeiten = strpos($seiteZeit, 'loc-hours');
$posForm   = strpos($seiteZeit, 'loc-req-submit');
$posFakten = strpos($seiteZeit, 'loc-facts');
check($posForm < $posZeiten && $posZeiten < $posFakten,
    'die ueblichen Zeiten stehen nicht zwischen Anfrageformular und Nebendaten');
// Der Platz fuer den Hinweis ist da, aber leer: Was ausserhalb der Zeiten
// liegt, weiss erst der Browser - er kennt die Zone des Kunden.
check(strpos($seiteZeit, 'id="loc-req-hint"') !== false, 'der Platz fuer den Hinweis fehlt');
check(preg_match('/id="loc-req-hint"[^>]*hidden/', $seiteZeit) === 1,
    'der Hinweis steht von vornherein da, ohne dass etwas anzumerken waere');
ok('der Kunde sieht die Zeiten samt Ortszeit, dort wo er den Zeitpunkt waehlt');

// OHNE ANGABE SIEHT EIN KUNDE NICHTS. Ein Kasten "keine Zeiten angegeben"
// waere eine Auskunft ueber das Formular des Guides und nicht ueber den
// Standort. Der EIGENTUEMER bekommt den Hinweis - er kann etwas daran aendern.
check(strpos($seiteKunde, 'loc-hours') === false,
    'ohne Angaben steht beim Kunden ein leerer Kasten');
check(strpos($seiteEigner, 'loc-hours--empty') !== false,
    'der Eigentuemer wird nicht auf die fehlenden Zeiten hingewiesen');
ok('ohne Angaben schweigt die Seite - ausser gegenueber dem Eigentuemer');

// Die Seitendaten tragen Raster, Zone UND die Grenzen der Abschnitte. Die
// Grenzen kommen vom Server, damit "abends" im Browser nicht bald etwas
// anderes heisst als in der Datenbank.
check(strpos($seiteZeit, '"hours"') !== false, 'die Seitendaten kennen die Zeiten nicht');
$datenZeile = [];
preg_match('/window\.locationPage\s*=\s*(\{.*?\});/s', $seiteZeit, $datenZeile);
check(!empty($datenZeile[1]), 'die Seitendaten stehen nicht als Objekt in der Seite');
$daten = json_decode($datenZeile[1], true);
check(is_array($daten), 'die Seitendaten sind kein gueltiges JSON');
check($daten['hours']['slots'] === $werktags, 'das Raster fehlt in den Seitendaten');
check($daten['hours']['timezone'] === 'Europe/Lisbon', 'die Zone fehlt in den Seitendaten');
check(count($daten['hours']['parts']) === 4, 'die Grenzen der Abschnitte fehlen in den Seitendaten');
check($daten['hours']['parts'][0]['von'] === 22 && $daten['hours']['parts'][0]['bis'] === 6,
    'die Nacht kommt im Browser mit anderen Grenzen an als auf dem Server');
// Ohne Angaben ist slots null und nicht "0000...": Das Skript soll ohne
// Auskunft nichts anmerken, und 28 Nullen liessen sich mit einer Angabe
// verwechseln.
preg_match('/window\.locationPage\s*=\s*(\{.*?\});/s', $seiteKunde, $ohneZeile);
$datenOhne = json_decode($ohneZeile[1], true);
check($datenOhne['hours']['slots'] === null, 'ohne Angaben stehen 28 Nullen in den Seitendaten');
ok('Raster, Zone und Abschnittsgrenzen gehen einmal mit der Seite mit');

// --- 32h. Was der Guide ausfuellt ------------------------------------------
//
// Die Namen der Felder gehen so an den Server, wie normalize sie erwartet -
// Beschriftung, Wert und gespeicherte Stelle stammen aus derselben Tabelle.
$raster = LocationView::zeitrasterHtml($werktags);
check(substr_count($raster, '<input type="checkbox"') === 28, 'das Raster hat nicht 28 Kaestchen');
check(substr_count($raster, 'name="availability[]"') === 28, 'die Kaestchen heissen nicht alle gleich');
check(substr_count($raster, ' checked') === 5, 'die gespeicherte Auswahl steht nicht im Formular');
check(strpos($raster, 'value="do-abend"') !== false, 'ein Feld traegt nicht die erwartete Kennung');
check(strpos($raster, 'aria-label="Donnerstag abends"') !== false,
    'ein Vorleseprogramm liest 28-mal dasselbe');
// Die Uhrzeiten stehen in der Kopfzeile: "abends" heisst nicht ueberall
// dasselbe, und der Kunde liest spaeter dieselben Grenzen.
check(strpos($raster, '18-22') !== false, 'die Uhrzeiten fehlen an den Abschnitten');
// Zeilen- und Spaltenkoepfe sind Knoepfe - eine Abkuerzung, kein Ersatz:
// OHNE Skript bleiben es 28 gewoehnliche Kaestchen.
check(substr_count($raster, 'data-zeile=') === 7, 'nicht jeder Tag laesst sich auf einmal setzen');
check(substr_count($raster, 'data-spalte=') === 4, 'nicht jeder Abschnitt laesst sich auf einmal setzen');
// Der Rundlauf: Was das Formular schickt, muss dasselbe Muster ergeben.
preg_match_all('/name="availability\[\]" value="([^"]+)"[^>]*checked/', $raster, $treffer);
check(Availability::normalize($treffer[1]) === $werktags,
    'was das Formular zurueckschickt, ergibt ein anderes Muster');
ok('das Raster im Formular und das gespeicherte Muster sind dieselbe Sache');

// Die Zone steht daneben, vorbelegt mit der erkannten - aber nicht
// festgenagelt: Die Ableitung kann an einer Zonengrenze danebenliegen, und
// das letzte Wort hat der Guide.
$auswahl = LocationView::zonenauswahlHtml('Europe/Lisbon');
check(strpos($auswahl, 'name="timezone"') !== false, 'die Zone hat kein Feld im Formular');
check(strpos($auswahl, '<option value="Europe/Lisbon" selected>') !== false,
    'die erkannte Zone ist nicht vorbelegt');
check(substr_count($auswahl, ' selected>') === 1, 'mehr als eine Zone ist vorbelegt');
check(strpos($auswahl, 'Asia/Tokyo') !== false, 'die Liste der Zonen ist unvollstaendig');
// Vorbelegt wird auch dann etwas, wenn nichts gespeichert ist - ein Feld
// ohne Auswahl waere ein Formular, das sich nicht abschicken laesst.
check(strpos(LocationView::zonenauswahlHtml(''), ' selected>') !== false,
    'ohne gespeicherte Zone steht keine zur Auswahl bereit');
$formular = LocationView::bearbeitenHtml($standortZeit, $bilder['cover'], $bilder['gallery'],
    ['max_images' => 5, 'max_bytes' => 100, 'max_source_edge' => 6000, 'accept' => 'image/jpeg',
     'titel_max' => 120, 'kurz_max' => 200, 'lang_max' => 5000, 'dauer_min' => 5,
     'dauer_max' => 480, 'dauer_vorgabe' => 5]);
check(strpos($formular, 'loc-grid') !== false, 'das Raster fehlt im Bearbeitungsformular');
check(strpos($formular, 'name="timezone"') !== false, 'die Zone fehlt im Bearbeitungsformular');
ok('Raster und Zone stehen im Bearbeitungsformular, die Zone vorbelegt und aenderbar');

// Ohne gespeicherte Zone leitet die Seite sie ab, statt zu schweigen - oder,
// schlimmer, die Zeit des Servers zu unterstellen.
$ohneZone = $standortZeit;
$ohneZone['timezone'] = null;
check(LocationView::zoneVon($ohneZone) === 'Europe/Lisbon',
    'ohne gespeicherte Zone wird nicht abgeleitet: ' . LocationView::zoneVon($ohneZone));
$ohneAlles = $ohneZone;
$ohneAlles['iso2'] = null;
check(LocationView::zoneVon($ohneAlles) === 'UTC',
    'ohne Land faellt die Seite nicht auf UTC zurueck');
ok('ein Standort ohne gespeicherte Zone bekommt die abgeleitete, notfalls UTC');

// --- 32i. Das Raster steht an EINER Stelle ---------------------------------
//
// Die Grenzen der Tagesabschnitte gehoeren in App\Helper\Availability. Eine
// zweite Tabelle im Skript oder in der Vorlage liefe beim naechsten Eintrag
// auseinander - dann hiesse "abends" im Browser etwas anderes als in der
// Datenbank.
$jsCode = file_get_contents($ROOT . '/assets/js/location_page.js');
check(strpos($jsCode, 'nachmittags') === false && strpos($jsCode, "'vormittag'") === false,
    'das Skript fuehrt eine eigene Tabelle der Tagesabschnitte');
check(strpos($jsCode, 'hours.parts') !== false || strpos($jsCode, 'zeiten.parts') !== false,
    'das Skript nimmt die Grenzen nicht vom Server');
// Die Abkuerzung im Formular muss auch eingehaengt werden. Sie war einmal
// gebaut, aber nicht aufgerufen - im Browser blieben die Zeilen- und
// Spaltenkoepfe dann wirkungslos, ohne dass etwas gemeldet worden waere.
$bindEdit = substr($jsCode, (int)strpos($jsCode, "\n    bindEdit() {"));
$bindEdit = substr($bindEdit, 0, (int)strpos($bindEdit, "\n    },"));
check(strpos($bindEdit, 'bindZeitraster') !== false,
    'die Zeilen- und Spaltenkoepfe des Rasters werden nirgends eingehaengt');
foreach (['assets/html/location_edit.html', 'assets/html/location_page.html'] as $datei) {
    $inhalt = file_get_contents($ROOT . '/' . $datei);
    check(strpos($inhalt, 'availability[]') === false,
        "$datei baut das Raster selbst, statt es einsetzen zu lassen");
}
ok('die Grenzen der Abschnitte stehen einmal, nicht in jeder Schicht');

// Die Wanderung liegt bei, und der Dump kennt die Spalten - sonst bekaeme
// eine frische Datenbank sie nie.
$wanderung = file_get_contents($ROOT . '/migrations/014_verfuegbarkeitszeiten.sql');
$dump      = file_get_contents($ROOT . '/database.sql');
foreach (['availability_slots', 'timezone'] as $spalte) {
    check(strpos($wanderung, $spalte) !== false, "die Wanderung legt $spalte nicht an");
    check(strpos($dump, $spalte) !== false, "im Dump fehlt $spalte");
}
check(strpos($wanderung, 'ADD COLUMN IF NOT EXISTS') !== false,
    'die Wanderung laesst sich kein zweites Mal ausfuehren');
ok('die neuen Spalten stehen in der Wanderung und im Dump');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n33) Ein Schluessel, eine Bedeutung - und eine Beschriftung\n");

// DER BEFUND
// In assets/js/location_page.js stand esc() ZWEIMAL: wortgleich, aber als
// derselbe Schluessel im selben Objektliteral. JavaScript meldet das nicht -
// die spaetere Fassung gewinnt stillschweigend. Wer die obere geaendert
// haette, haette nichts geaendert und lange gesucht.
//
// Geprueft wird deshalb nicht "esc kommt einmal vor", sondern die Regel
// dahinter: In diesen Objekten gibt es keinen Namen zweimal.
foreach (['assets/js/location_page.js', 'assets/js/requests.js'] as $datei) {
    $inhalt = file_get_contents($ROOT . '/' . $datei);

    // Die Methoden eines Objektliterals stehen in dieser Anwendung auf einer
    // Einrueckungsebene: vier Leerzeichen, Name, Klammer, Rumpf.
    preg_match_all('/^    ([A-Za-z_$][A-Za-z0-9_$]*)\(/m', $inhalt, $treffer);
    $namen = $treffer[1];
    $doppelt = array_keys(array_filter(array_count_values($namen), fn($n) => $n > 1));

    check($doppelt === [],
        "$datei vergibt einen Namen mehrfach: " . implode(', ', $doppelt));
    check(count($namen) > 5, "in $datei wurde gar keine Methode gefunden - die Suche greift nicht");
}
ok('kein Objekt vergibt denselben Methodennamen zweimal');

// DIE BESCHRIFTUNG steht an ZWEI Stellen, weil das Formular an zwei Stellen
// gebaut wird - vom Server beim ersten Aufruf, vom Skript nach jedem Takt.
// Laufen die beiden auseinander, aendert sich die Beschriftung beim ersten
// Nachziehen vor den Augen des Kunden.
$formularServer = LocationView::anfrageFormularHtml($standort);
$formularSkript = file_get_contents($ROOT . '/assets/js/location_page.js');

check(strpos($formularServer, '>Wunschzeitpunkt</label>') !== false,
    'das serverseitige Formular beschriftet das Feld anders');
check(strpos($formularSkript, '>Wunschzeitpunkt</label>') !== false,
    'das Skript beschriftet das Feld anders');
check(strpos($formularServer, 'Anderer Zeitpunkt') === false
      && strpos($formularSkript, 'Anderer Zeitpunkt') === false,
    'die alte Beschriftung steht noch irgendwo');

// Die Vorgabeknoepfe heissen NICHT genauso: Ein Vorleseprogramm nennt sonst
// die Gruppe und das Feld darunter mit demselben Wort, und es waere nicht
// mehr erkennbar, wovon gerade die Rede ist.
check(strpos($formularServer, 'aria-label="Vorgaben für den Wunschzeitpunkt"') !== false,
    'die Gruppe der Vorgaben traegt denselben Namen wie das Feld');
ok('Feld und Vorgaben heissen ueberall gleich - und nicht gleich wie einander');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n34) Der Guide ist ein Mensch, kein Benutzername\n");

// DER BEFUND
// Ein Kunde sah vom Guide einen Benutzernamen und einen farbigen Punkt. Auf
// dieser Grundlage sollte er einen Fremden losschicken und ihm Geld dafuer
// geben. Geprueft wird deshalb beides: dass die Angaben ueberhaupt ankommen -
// und dass der Benutzername dabei verschwindet, denn er ist die
// Anmeldekennung und nicht der Name eines Menschen.

// --- Der Anzeigename ersetzt den Benutzernamen -----------------------------
check(GuideProfile::anzeigename('Maria S.', 'guide1') === 'Maria S.',
    'der Anzeigename setzt sich nicht durch');
check(GuideProfile::anzeigename('', 'guide1') === 'guide1',
    'ohne Anzeigenamen steht gar nichts da');
check(GuideProfile::anzeigename(null, 'guide1') === 'guide1',
    'ein fehlender Anzeigename faellt nicht auf den Benutzernamen zurueck');
check(GuideProfile::anzeigename('   ', 'guide1') === 'guide1',
    'ein Anzeigename aus Leerzeichen gilt als gesetzt');
check(GuideProfile::nameAus(['display_name' => null, 'username' => 'guide1']) === 'guide1',
    'nameAus faellt nicht auf den Benutzernamen zurueck');
ok('der Anzeigename ersetzt den Benutzernamen, und nur er faellt darauf zurueck');

// Die Liste der Standorte liefert den Benutzernamen GAR NICHT MEHR aus. Das
// ist der Punkt: Was nicht ausgeliefert wird, kann auch nicht angezeigt
// werden - eine Ansicht, die es sich anders ueberlegt, gibt es dann nicht.
$listeDb = new FakeConnection();
PdoConnect::$connection = $listeDb;
(new Location())->selectAllLocations(3, false);
$listenSql = $listeDb->statements[0]->sql;
check(strpos($listenSql, 'guide_name') !== false,
    'die Liste liefert keinen Anzeigenamen: ' . $listenSql);
check(strpos($listenSql, 'user.username,') === false,
    'die Liste liefert weiterhin den Benutzernamen als eigene Spalte');
check(strpos($listenSql, 'guide_profile') !== false,
    'die Liste liest das Guide-Profil gar nicht');

$tabelleJs = file_get_contents($ROOT . '/assets/js/locations_table.js');
check(strpos($tabelleJs, 'item.guide_name') !== false,
    'die Tabelle zeigt den Anzeigenamen nicht an');
check(strpos($tabelleJs, 'item.username') === false,
    'die Tabelle greift weiterhin auf den Benutzernamen zu');
ok('die Standortliste kennt den Benutzernamen fremder Konten nicht mehr');

// --- Die Adresse des Profils traegt die Kennung, nicht den Namen -----------
//
// Der Benutzername soll intern bleiben. Stuende er in der Adresse, waere er
// oeffentlich - und zwar an der Stelle, die ein Guide selbst weitergibt.
$profilUrl = GuideView::profilUrl(42);
check(strpos($profilUrl, 'act=guide') !== false, 'die Profiladresse fuehrt woanders hin');
check(strpos($profilUrl, 'id=42') !== false, 'die Profiladresse traegt die Kennung nicht');
check(strpos($profilUrl, 'guide1') === false && strpos($profilUrl, 'name=') === false,
    'in der Profiladresse steht ein Name');
ok('die Profilseite haengt an der Kennung, nicht am Benutzernamen');

// --- Wer keinen Avatar hochlaedt, bekommt seine Initialen ------------------
check(Avatar::initials('Maria Silva')     === 'MS', 'zwei Woerter ergeben nicht zwei Buchstaben');
check(Avatar::initials('maria')           === 'M',  'ein Wort ergibt nicht einen Buchstaben');
check(Avatar::initials('Anna-Lena Böhm')  === 'AL', 'der Bindestrich trennt nicht');
check(Avatar::initials('öle')             === 'Ö',  'ein Umlaut ueberlebt die Grossschreibung nicht');
check(Avatar::initials('Maria Silva Costa') === 'MS', 'es werden mehr als zwei Buchstaben');
// Ein Fragezeichen ist ehrlicher als ein leerer Kreis: Es ist zu sehen, dass
// hier ein Mensch stehen sollte.
check(Avatar::initials('')    === '?', 'ein leerer Name ergibt keinen Ersatz');
check(Avatar::initials('...') === '?', 'ein Name aus Satzzeichen ergibt keinen Ersatz');

$mitBild = Avatar::html('Maria Silva', 'index.php?act=guide_avatar&id=3&size=thumb', 'x');
$ohneBild = Avatar::html('Maria Silva', null, 'x');
check(strpos($mitBild, '<img') === 0, 'mit Bild entsteht kein <img>');
check(strpos($ohneBild, 'MS') !== false, 'ohne Bild fehlen die Initialen');
// BEIDE tragen dieselbe Klasse: Groesse und Form stehen damit an einer
// Stelle im Stylesheet, und der Aufrufer muss nicht wissen, welcher Fall
// gerade eintritt.
check(strpos($mitBild, 'app-avatar') !== false && strpos($ohneBild, 'app-avatar') !== false,
    'Bild und Ersatz tragen nicht dieselbe Klasse');
// Die Adresse wird maskiert - sonst beendete das & das Attribut.
check(strpos($mitBild, '&amp;') !== false, 'die Bildadresse wird nicht maskiert');

// Das Benutzermenue der Kopfleiste baut seine Initialen ueber dieselbe
// Klasse. Zwei Fassungen davon liefen beim ersten Sonderfall auseinander.
check(strpos(file_get_contents($ROOT . '/class/Helper/ViewHelper.php'), 'Avatar::html') !== false,
    'das Benutzermenue baut seine Initialen weiterhin selbst');
ok('die Initialen entstehen an einer Stelle und sehen ueberall gleich aus');

// --- Der EINE Satz auf der Standortseite -----------------------------------
check(GuideView::ersterSatz('Ich zeige Lissabon. Seit 2010 lebe ich hier.')
      === 'Ich zeige Lissabon.', 'der erste Satz endet nicht am Punkt');
check(GuideView::ersterSatz("Ich zeige Lissabon\nSeit 2010 lebe ich hier.")
      === 'Ich zeige Lissabon', 'ein Zeilenumbruch beendet den Satz nicht');
check(GuideView::ersterSatz('Ohne Satzzeichen') === 'Ohne Satzzeichen',
    'ein Text ohne Satzzeichen geht verloren');
check(GuideView::ersterSatz('') === '', 'aus nichts wird etwas');

// Ein zu langer erster Satz wird an einer WORTGRENZE gekuerzt: Ein hart
// abgeschnittener Text endet mitten im Wort und liest sich wie ein Fehler.
$langerSatz = GuideView::ersterSatz(str_repeat('Lissabon ', 40) . 'Ende.');
check(mb_strlen($langerSatz) <= GuideView::SATZ_MAX + 1,
    'der gekuerzte Satz ist laenger als erlaubt: ' . mb_strlen($langerSatz));
check(mb_substr($langerSatz, -1) === '…', 'die Kuerzung wird nicht angezeigt');
check(strpos($langerSatz, 'Lissab…') === false, 'gekuerzt wird mitten im Wort');
ok('auf der Standortseite steht ein Satz und kein abgeschnittener Absatz');

// --- Der Guide steht auf der Standortseite, nicht in einer Fussnote --------
$standortMitGuide = array_merge($standort, [
    'display_name' => 'Maria S.',
    'about'        => 'Ich zeige Lissabon. Seit 2010 lebe ich hier.',
    'avatar_file'  => str_repeat('d', 32),
]);
$seiteMitGuide = LocationView::page($standortMitGuide, $bilder,
    ['eigen' => false, 'angemeldet' => true, 'viewer_id' => 3]);

check(strpos($seiteMitGuide, 'Maria S.') !== false, 'der Anzeigename steht nicht auf der Seite');
check(strpos($seiteMitGuide, 'guide1') === false,
    'der Benutzername des Guides steht auf der Standortseite');
check(strpos($seiteMitGuide, 'Ich zeige Lissabon.') !== false,
    'der Satz aus der Selbstbeschreibung fehlt');
check(strpos($seiteMitGuide, 'Seit 2010') === false,
    'auf der Standortseite steht die ganze Selbstbeschreibung');
check(strpos($seiteMitGuide, 'act=guide&amp;id=3') !== false,
    'der Guide ist nicht mit seinem Profil verlinkt');
check(strpos($seiteMitGuide, 'act=guide_avatar') !== false, 'das Bild des Guides fehlt');

// Der Streifen steht im INHALTSBEREICH unter der Beschreibung und nicht in
// der schmalen Spalte: Wer dieser Fremde ist, gehoert zur Entscheidung.
check(strpos($seiteMitGuide, 'loc-guide') < strpos($seiteMitGuide, 'loc__side'),
    'der Guide steht hinter der Randspalte');

// Ohne Profil bleibt der Streifen trotzdem stehen - mit dem Benutzernamen und
// den Initialen. Ein Standort ohne erkennbaren Anbieter waere schlimmer.
$ohneProfil = GuideView::streifenHtml($standort);
check(strpos($ohneProfil, 'guide1') !== false, 'ohne Profil steht dort gar kein Anbieter');
check(strpos($ohneProfil, 'act=guide_avatar') === false,
    'ohne hochgeladenes Bild wird trotzdem eines geladen');
ok('der Guide erscheint auf der Standortseite mit Bild, Namen und einem Satz');

// --- Fremdeingabe kann auch hier keine Ersetzung ausloesen -----------------
$boeserGuide = GuideView::streifenHtml(array_merge($standort, [
    'display_name' => '###USER###',
    'about'        => '<script>alert(1)</script> Hallo.',
]));
check(preg_match('/###[A-Z_]+###/', $boeserGuide) === 0,
    'ein Platzhaltername aus einem Anzeigenamen steht im Dokument');
check(strpos($boeserGuide, '<script>') === false, 'Markup aus der Selbstbeschreibung kommt durch');
ok('Anzeigename und Selbstbeschreibung sind Fremdeingabe und werden so behandelt');

// --- Die Profilseite wird wirklich gebaut ---------------------------------
$profilZeile = [
    'user_id' => 3, 'username' => 'guide1', 'type_id' => Role::GUIDE,
    'display_name' => 'Maria S.', 'about' => "Ich zeige Lissabon.\n\nSeit 2010 hier.",
    'languages' => 'de,pt', 'avatar_file' => str_repeat('d', 32),
    'joined_at' => '2024-03-17 08:00:00', 'resigned_at' => null,
];
$angebote = [[
    'id' => 7, 'title' => 'Alfama bei Nacht', 'description' => 'Die alten Gassen.',
    'city_name' => 'Lissabon', 'country_name' => 'Portugal',
    'availability' => 'live', 'blocked' => 0, 'cover_image_id' => 11,
]];
$profilSeite = GuideView::page($profilZeile, $angebote, ['eigen' => false]);

check(preg_match('/###[A-Z_]+###/', $profilSeite) === 0,
    'auf der Profilseite steht ein unbesetzter Platzhalter');
check(strpos($profilSeite, 'Maria S.') !== false, 'der Anzeigename fehlt');
check(strpos($profilSeite, 'guide1') === false, 'der Benutzername steht auf der Profilseite');
// Monat und Jahr, nicht der Tag: Der Tag ist keine Auskunft, die jemand
// braucht - und eine Angabe mehr ueber eine Person, die Stadtfuehrungen
// anbietet.
check(strpos($profilSeite, 'März 2024') !== false, '"Guide seit" fehlt oder ist zu genau');
check(strpos($profilSeite, '17') === false || strpos($profilSeite, '17. März') === false,
    'das Datum steht taggenau auf der Seite');
check(strpos($profilSeite, 'Português') !== false || strpos($profilSeite, 'Portugues') !== false,
    'die Sprachen des Guides fehlen');
check(strpos($profilSeite, 'Seit 2010 hier.') !== false, 'die Selbstbeschreibung fehlt');
check(strpos($profilSeite, 'act=location&amp;id=7') !== false,
    'die Standorte des Guides sind nicht verlinkt');
check(strpos($profilSeite, 'app-tag--live') !== false,
    'die Verfuegbarkeit steht nicht an der Kachel');
// Der Eigentuemer sieht denselben Aufbau und zusaetzlich den Weg zum
// Bearbeiten - und der fuehrt auf die Kontoseite, nicht auf ein zweites
// Formular hier.
$eigenesProfil = GuideView::page($profilZeile, $angebote, ['eigen' => true]);
check(strpos($eigenesProfil, 'act=settings') !== false,
    'der Eigentuemer findet den Weg zum Bearbeiten nicht');
check(strpos($profilSeite, 'act=settings') === false,
    'ein Fremder bekommt den Bearbeitungsknopf zu sehen');
ok('die Profilseite zeigt Mensch und Angebot - und keinen Benutzernamen');

// Jeder Platzhalter der Vorlage wird auch besetzt.
$guideViewCode = file_get_contents($ROOT . '/class/Helper/GuideView.php');
foreach (platzhalter($ROOT . '/assets/html/guide_page.html') as $marke) {
    check(strpos($guideViewCode, $marke) !== false,
        "$marke aus guide_page.html wird in GuideView nicht ersetzt");
}
// Und die Ansicht entscheidet nichts - dieselbe Regel wie bei LocationView.
$guideOhneKommentar = stripPhpNoise($guideViewCode);
foreach (['Auth::', 'Request::', '$_SESSION', '$_REQUEST', '$_GET', '$_POST',
          'PdoConnect', 'ImageStore::'] as $verboten) {
    check(strpos($guideOhneKommentar, $verboten) === false,
        "GuideView greift auf $verboten zu - dann ist sie keine reine Funktion mehr");
}
ok('guide_page.html hat keinen unbesetzten Platzhalter, GuideView baut nur HTML');

// --- Das Bild: ausserhalb des Webroots, quadratisch, eines je Guide --------
ImageStore::setConfig([
    'base_path' => '/var/www/uploads', 'max_images_per_location' => 5,
    'max_file_bytes' => 8388608, 'max_source_edge' => 6000, 'full_edge' => 1600,
    'thumb_width' => 480, 'thumb_height' => 320, 'avatar_edge' => 512,
    'avatar_thumb' => 128, 'jpeg_quality' => 82,
    'accepted_mime' => ['image/jpeg', 'image/png', 'image/webp'],
]);
$avatarVoll = ImageStore::avatarPathFor(3, str_repeat('d', 32), 'full');
check(strpos($avatarVoll, '/guides/3/') !== false,
    'das Bild liegt nicht im Ordner seines Kontos: ' . $avatarVoll);
check(strpos($avatarVoll, '/var/www/uploads') === 0,
    'das Bild liegt nicht unter dem konfigurierten Ablagepfad');
// Derselbe Namenspruefer wie bei den Standortbildern: Zwischen der
// Datenbankzeile und dem Dateisystem soll keine Annahme stehen.
check(ImageStore::avatarPathFor(3, '../../etc/passwd') === null,
    'ein Name aus der Datenbank landet ungeprueft im Pfad');
check(ImageStore::avatarPathFor(3, str_repeat('d', 32), '../x') === $avatarVoll,
    'die Groessenangabe aus der Anfrage landet im Pfad');

// EIN WEG FUER BEIDE BILDARTEN. Wer einen zweiten Upload baut, der die
// Pruefungen selbst nachbaut, vergisst als Erstes das Entfernen des EXIF.
$storeCode = stripPhpNoise(file_get_contents($ROOT . '/class/Helper/ImageStore.php'));
check(substr_count($storeCode, 'applyExifRotation(') === 2,
    'die EXIF-Drehung wird mehr als einmal angewandt oder gar nicht');
check(substr_count($storeCode, 'is_uploaded_file') === 1,
    'es gibt mehr als eine Stelle, die eine hochgeladene Datei annimmt');
foreach (['storeAvatar', 'store'] as $weg) {
    check(strpos(methodenRumpf($storeCode, $weg), 'self::pruefe(') !== false,
        "$weg geht an den gemeinsamen Pruefungen vorbei");
}
// Quadratisch: Beide Ausgaben des Avatars bekommen Breite UND Hoehe - das ist
// in writeScaled der Unterschied zwischen Beschneiden und Einpassen.
$avatarRumpf = methodenRumpf($storeCode, 'storeAvatar');
check(strpos($avatarRumpf, '$kante, $kante') !== false
      && strpos($avatarRumpf, '$klein, $klein') !== false,
    'das Avatarbild wird nicht quadratisch beschnitten');
ImageStore::setConfig(null);
ok('das Avatarbild geht denselben Weg wie ein Standortbild - quadratisch beschnitten');

// --- Zustimmung und Profil stehen in einer Zeile, aber nie in denselben
//     Spalten --------------------------------------------------------------
$profilDb = new FakeConnection();
PdoConnect::$connection = $profilDb;

GuideProfile::save(3, ['display_name' => 'Maria S.', 'about' => 'Hallo.', 'languages' => ['de', 'xx']]);
$saveSql = $profilDb->statements[0]->sql;
foreach (['terms_version', 'terms_accepted_at', 'guide_since', 'joined_at', 'resigned_at'] as $spalte) {
    check(strpos($saveSql, $spalte) === false,
        "das Speichern des Profils fasst $spalte an - das ist Vertragsstoff");
}
check(strpos($saveSql, 'display_name') !== false, 'der Anzeigename wird gar nicht gespeichert');
// Unbekannte Sprachkuerzel fallen weg, statt die ganze Eingabe abzuweisen.
check($profilDb->statements[0]->params[':languages'] === 'de',
    'unbekannte Sprachkuerzel werden gespeichert: '
    . var_export($profilDb->statements[0]->params[':languages'], true));

$profilDb->statements = [];
GuideProfile::setAvatar(3, str_repeat('d', 32));
$avatarSql = $profilDb->statements[0]->sql;
check(strpos($avatarSql, 'display_name') === false && strpos($avatarSql, 'about') === false,
    'das Bild ueberschreibt die uebrigen Profilangaben');
// Ein Name, den ImageStore nicht vergeben haben kann, kommt gar nicht erst in
// die Datenbank.
$profilDb->statements = [];
check(GuideProfile::setAvatar(3, '../../etc/passwd') === false,
    'ein unbrauchbarer Dateiname wird eingetragen');
check($profilDb->statements === [], 'fuer den unbrauchbaren Namen wurde ein Statement abgesetzt');

// Und umgekehrt: Die Zustimmung laesst das Profil stehen. Wer der neuen
// Fassung der Bedingungen zustimmt, verliert dabei nicht seinen Anzeigenamen.
$rolleCode = stripPhpNoise(file_get_contents($ROOT . '/class/Model/GuideRole.php'));
foreach (['display_name', 'about', 'avatar_file'] as $spalte) {
    check(strpos($rolleCode, $spalte) === false,
        "GuideRole schreibt $spalte - das gehoert dem Guide, nicht der Zustimmung");
}
// joined_at wird genau einmal gesetzt: Am Tag eines Bedingungswechsels waeren
// sonst alle Guides neu, und die Profilseite behauptete das auch.
check(strpos($rolleCode, 'COALESCE(joined_at') !== false,
    'joined_at wird bei jeder Zustimmung neu gesetzt');
ok('Zustimmung und Profil teilen sich eine Zeile, aber keine Spalte');

// --- Die neuen Spalten stehen in der Wanderung und im Dump -----------------
$wanderung15 = file_get_contents($ROOT . '/migrations/015_guide_profil.sql');
$dump        = file_get_contents($ROOT . '/database.sql');
foreach (['display_name', 'about', 'languages', 'avatar_file', 'joined_at'] as $spalte) {
    check(strpos($wanderung15, $spalte) !== false, "die Wanderung legt $spalte nicht an");
    check(strpos($dump, $spalte) !== false, "im Dump fehlt $spalte");
}
check(strpos($wanderung15, 'ADD COLUMN IF NOT EXISTS') !== false,
    'die Wanderung laesst sich kein zweites Mal ausfuehren');
// Der Bestand bekommt sein joined_at aus guide_since - erfunden wird nichts.
check(strpos($wanderung15, 'SET `joined_at` = `guide_since`') !== false,
    'bestehende Guides bekommen kein Eintrittsdatum');
ok('die neuen Spalten stehen in der Wanderung und im Dump');

// --- Die Routen: ansehen darf jeder, aendern nur der Angemeldete -----------
check($routes['guide'][2]               === Permission::GUIDE_VIEW,
    'die Profilseite haengt nicht am Recht guide.view');
check($routes['guide_avatar'][2]        === Permission::GUIDE_VIEW,
    'das Bild haengt an einem anderen Recht als die Seite, auf der es steht');
check($routes['guide_profile_save'][2]  === Permission::GUIDE_PROFILE_EDIT,
    'das Speichern haengt nicht am Bearbeitungsrecht');
check($routes['guide_avatar_delete'][2] === Permission::GUIDE_PROFILE_EDIT,
    'das Entfernen des Bildes haengt nicht am Bearbeitungsrecht');

// Bearbeiten darf, wer Standorte anbietet - dieselben Rollen wie bei
// location.offer. Ein Zuschauer haette ein Formular fuer eine Seite, die es
// fuer ihn nicht gibt.
foreach ([Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::GUIDE_PROFILE_EDIT) === true,
        'wer Standorte anbietet, darf sein Profil nicht pflegen');
}
foreach ([Permission::GUEST, Role::TRIAL, Role::USER] as $rolle) {
    check(Permission::has($rolle, Permission::GUIDE_PROFILE_EDIT) === false,
        'ein Zuschauer darf ein Guide-Profil bearbeiten');
    check(Permission::has($rolle, Permission::GUIDE_VIEW) === true,
        'ein Profil laesst sich nicht ansehen');
}
ok('ansehen darf jeder, bearbeiten nur, wer Standorte anbietet');

// --- Das Formular traegt, was schon da ist ---------------------------------
//
// DER BEFUND
// Das Formular zum Entfernen des Bildes stand INNERHALB des Hauptformulars.
// HTML kennt keine verschachtelten Formulare: Der Parser verwirft das innere
// <form> ersatzlos, und sein </form> schliesst dann das AEUSSERE. Anzeigename,
// Selbstbeschreibung, Sprachen und der Knopf "Profil speichern" standen
// anschliessend ausserhalb jedes Formulars.
//
// Sichtbar war davon nichts - die Werte standen im Quelltext, nur eben in
// keinem Formular. Bemerkbar machte es sich erst beim Speichern: Die Felder
// wurden nicht mitgeschickt und mit Leerwerten ueberschrieben, und "Bild
// entfernen" gehoerte dem Hauptformular und lud ein Bild hoch, statt eines zu
// loeschen.
$profilVoll = [
    'user_id' => 3, 'username' => 'guide1', 'type_id' => Role::GUIDE,
    'display_name' => 'Maria S.', 'about' => 'Ich zeige Lissabon.',
    'languages' => 'de,pt', 'avatar_file' => str_repeat('d', 32),
];
$formular = GuideView::formularHtml($profilVoll, [
    'name_max' => GuideProfile::NAME_MAX, 'about_max' => GuideProfile::ABOUT_MAX,
    'max_bytes' => 8388608, 'accept' => 'image/jpeg',
]);

check(strpos($formular, 'value="Maria S."') !== false,
    'der gespeicherte Anzeigename steht nicht im Feld');
check(strpos($formular, '>Ich zeige Lissabon.</textarea>') !== false,
    'die gespeicherte Selbstbeschreibung steht nicht im Feld');
foreach (['de', 'pt'] as $code) {
    check(preg_match('/id="guide-lang-' . $code . '"[^>]*checked/', $formular) === 1,
        "die gewaehlte Sprache $code ist nicht angehakt");
}
check(preg_match('/id="guide-lang-en"[^>]*checked/', $formular) === 0,
    'eine nicht gewaehlte Sprache ist angehakt');
check(strpos($formular, 'Bild entfernen') !== false,
    'ein hochgeladenes Bild laesst sich nicht entfernen');

// Ohne Bild gibt es nichts zu entfernen - und deshalb auch keinen Knopf und
// kein zweites Formular.
$ohneBild = GuideView::formularHtml(
    array_merge($profilVoll, ['avatar_file' => null]), []);
check(strpos($ohneBild, 'Bild entfernen') === false,
    'ohne Bild steht dort ein Knopf, der nichts zu tun hat');
check(strpos($ohneBild, 'guide-avatar-delete') === false,
    'ohne Bild steht dort ein Formular, das nichts zu tun hat');
ok('das Formular zeigt, was gespeichert ist - Name, Text, Haken und Bild');

// --- Kein Formular steht in einem Formular ---------------------------------
//
// Die Regel dahinter, und nicht nur der eine Fall: Verschachtelte Formulare
// gibt es in HTML nicht, und der Parser meldet nichts - er verwirft das
// innere und schliesst mit dessen </form> das aeussere. Was danach im
// Quelltext steht, gehoert zu keinem Formular mehr und wird beim Absenden
// nicht mitgeschickt.
//
// Geprueft werden die fertigen Formulare DIESER Anwendung und alle Vorlagen.
$formularQuellen = [
    'GuideView::formularHtml'    => $formular,
    'LocationView::bearbeitenHtml' => LocationView::bearbeitenHtml(
        $standort, null, [], ['dauer_vorgabe' => 5]),
];
foreach (glob($ROOT . '/assets/html/*.html') as $vorlage) {
    $formularQuellen[basename($vorlage)] = file_get_contents($vorlage);
}
foreach ($formularQuellen as $name => $html) {
    check(formularTiefe($html) <= 1,
        "$name verschachtelt Formulare - der Parser wirft das innere weg und "
        . "schliesst mit dessen </form> das aeussere");
}
ok('kein Formular steht in einem Formular');

// DER KNOPF UND SEIN FORMULAR finden sich ueber das form-Attribut: Der Knopf
// steht beim Bild, das Formular dahinter. Das ist der einzige Weg, der beides
// erlaubt - die richtige Stelle in der Seite UND ein eigenes Ziel.
check(preg_match('/<button[^>]*form="guide-avatar-delete"[^>]*>Bild entfernen/', $formular) === 1,
    'der Knopf zeigt nicht auf das Formular zum Entfernen');
check(strpos($formular, '<form id="guide-avatar-delete"') !== false,
    'das Formular zum Entfernen fehlt');
// Es steht HINTER dem Hauptformular. Stuende es davor oder darin, waere es
// wieder derselbe Fehler.
check(strpos($formular, '<form id="guide-avatar-delete"')
      > strpos($formular, '</form>'),
    'das Formular zum Entfernen steht nicht hinter dem Hauptformular');

// MIT RUECKFRAGE, und zwar ueber dieselbe Stelle wie ueberall sonst:
// data-confirm am Formular, ausgewertet von assets/js/ui.js.
check(strpos($formular, 'data-confirm=') !== false,
    'das Entfernen fragt nicht nach');
check(strpos($formular, 'data-confirm-danger') !== false,
    'die Rueckfrage steht nicht als endgueltig da');
$uiJs = file_get_contents($ROOT . '/assets/js/ui.js');
check(strpos($uiJs, 'bindConfirmForms') !== false,
    'die Rueckfrage wird nirgends ausgewertet');
check(strpos(file_get_contents($ROOT . '/assets/js/main.js'), 'bindConfirmForms()') !== false,
    'die Rueckfrage wird nicht eingehaengt');
ok('das Entfernen hat ein eigenes Ziel und fragt vorher nach');

// --- Wessen Profil bearbeitet wird, steht in der Sitzung -------------------
//
// Dieselbe Regel wie beim Passwortwechsel und beim Farbprofil: Eine
// Benutzerkennung aus der Anfrage waere der Weg, fremde Profile
// umzuschreiben.
$profilCtrl = stripPhpNoise(file_get_contents($ROOT . '/class/Controller/GuideProfileController.php'));
foreach (['saveProfile', 'deleteAvatar'] as $methode) {
    $rumpf = methodenRumpf($profilCtrl, $methode);
    check(strpos($rumpf, 'Auth::userId()') !== false,
        "$methode nimmt das Konto nicht aus der Sitzung");
    check(strpos($rumpf, "Request::g('user_id'") === false
          && strpos($rumpf, "'id'") === false,
        "$methode liest eine Benutzerkennung aus der Anfrage");
    // Nur per POST: Der Vorgang aendert Daten. Als Link in einer Mail oder in
    // einem fremden Bild aufrufbar darf so etwas nicht sein.
    check(strpos($rumpf, "REQUEST_METHOD'] !== 'POST'") !== false,
        "$methode laesst sich per GET ausloesen");
}

// REIHENFOLGE BEIM BILD: erst die Datei, dann die Zeile, DANN das alte Bild
// loeschen. Andersherum verwiese die Zeile auf ein Bild, das es nicht mehr
// gibt - und das zeigt jede Seite als kaputtes Bild an.
$bildRumpf = methodenRumpf($profilCtrl, 'uebernehmeBild');
$posSpeichern = strpos($bildRumpf, 'storeAvatar');
$posZeile     = strpos($bildRumpf, 'setAvatar');
$posLoeschen  = strrpos($bildRumpf, 'deleteAvatar');
check($posSpeichern !== false && $posZeile > $posSpeichern && $posLoeschen > $posZeile,
    'das alte Bild wird geloescht, bevor das neue eingetragen ist');
ok('das Profil gehoert dem Angemeldeten, und das alte Bild faellt zuletzt');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n35) Bewertungen: nur nach einer Fuehrung, und nur in eine Richtung\n");

// WORUM ES GEHT: Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn
// durch eine unbekannte Stadt fuehrt. Was ANDERE Kunden erlebt haben, stand
// nirgends. Bewertet wird deshalb nach der Fuehrung - vom Kunden, nie
// umgekehrt, und nie ohne eine Fuehrung, die stattgefunden hat.

$fake = new FakeConnection();
PdoConnect::$connection = $fake;
FakeStatement::$affected = 1;

// --- Die Skala: ganze Sterne von 1 bis 5 ----------------------------------
check(TourReview::STARS_MIN === 1 && TourReview::STARS_MAX === 5,
    'die Skala ist nicht 1 bis 5');
$sternnamen = TourReview::starNames();
for ($i = TourReview::STARS_MIN; $i <= TourReview::STARS_MAX; $i++) {
    check(isset($sternnamen[$i]) && $sternnamen[$i] !== '',
        "der Stern $i hat kein Wort - \"3 von 5\" heisst fuer jeden etwas anderes");
}
check(TourReview::isValidStars(1) && TourReview::isValidStars(5), 'die Raender sind nicht erlaubt');
check(!TourReview::isValidStars(0),   'null Sterne sind erlaubt');
check(!TourReview::isValidStars(6),   'sechs Sterne sind erlaubt');
check(!TourReview::isValidStars(-3),  'negative Sterne sind erlaubt');
check(!TourReview::isValidStars('x'), 'Text ist eine Sternzahl');
check(!TourReview::isValidStars(''),  'nichts ist eine Sternzahl');
// HALBE STERNE GIBT ES NUR IN DER ANZEIGE. Ein (int) allein wuerde aus 3,5
// klaglos eine 3 machen - eine Bewertung, die so niemand abgegeben hat.
check(!TourReview::isValidStars(3.5), 'ein halber Stern laesst sich abgeben');
ok('ganze Sterne von 1 bis 5, jeder mit einem Wort');

// --- Bewertet wird eine FUEHRUNG, die stattgefunden hat --------------------
//
// Der Schreibvorgang ist ein INSERT ... SELECT aus tour_request: Guide, Kunde
// und Standort kommen aus der Aufzeichnung und nicht aus der Anfrage des
// Browsers. Ein Kunde kann damit weder eine fremde Fuehrung bewerten noch
// eine, die nie stattgefunden hat, noch einen anderen Guide eintragen.
$fake->statements = [];
check(TourReview::create(9, 4, 5, 'War gut.') === 42, 'die Bewertung wird nicht angelegt');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'INSERT INTO tour_review') !== false, "es ist kein INSERT: $sql");
check(strpos($sql, 'FROM tour_request') !== false,
    "die Bewertung stuetzt sich nicht auf die Aufzeichnung der Fuehrung: $sql");
check(strpos($sql, "r.status           = 'done'") !== false
      || preg_match("/r\.status\s*=\s*'done'/", $sql) === 1,
    "eine nicht durchgefuehrte Fuehrung laesst sich bewerten: $sql");
check(strpos($sql, 'r.started_at IS NOT NULL') !== false,
    "eine Fuehrung ohne Gespraech laesst sich bewerten: $sql");
check(preg_match('/customer_user_id\s*=\s*:customer/i', $sql) === 1,
    "der Kunde fehlt in der Bedingung - fremde Fuehrungen waeren bewertbar: $sql");
check(strpos($sql, 'r.guide_user_id') !== false && strpos($sql, ':guide') === false,
    'der Guide kommt nicht aus der Aufzeichnung, sondern aus der Anfrage');
check($fake->statements[0]->params[':stars'] === 5, 'die Sterne werden nicht gebunden');
check($fake->statements[0]->params[':customer'] === 4, 'der Kunde wird nicht gebunden');
ok('bewertet wird nur, was durchgefuehrt wurde - und nur die eigene Fuehrung');

// Unbrauchbare Angaben erreichen die Datenbank gar nicht.
$fake->statements = [];
check(TourReview::create(0, 4, 5, '') === null, 'ohne Fuehrung wird geschrieben');
check(TourReview::create(9, 0, 5, '') === null, 'ohne Kunden wird geschrieben');
check(TourReview::create(9, 4, 0, '') === null, 'ohne Sterne wird geschrieben');
check(TourReview::create(9, 4, 9, '') === null, 'neun Sterne werden geschrieben');
check(count($fake->statements) === 0, 'unbrauchbare Angaben erzeugen ein Statement');
ok('was die Skala nicht kennt, erreicht die Datenbank nicht');

// --- Leerer Text ist NULL und nicht der Leerstring -------------------------
//
// "Nichts geschrieben" soll genau eine Schreibweise haben, sonst muss jede
// Lesestelle beide kennen. Dieselbe Regel wie bei guide_profile.about.
$fake->statements = [];
TourReview::create(9, 4, 5, "   \n  ");
check($fake->statements[0]->params[':body'] === null,
    'ein leerer Text wird als Leerstring gespeichert');
$fake->statements = [];
TourReview::create(9, 4, 5, str_repeat('a', TourReview::BODY_MAX + 50));
check(mb_strlen($fake->statements[0]->params[':body']) === TourReview::BODY_MAX,
    'ein zu langer Text wird nicht zurechtgeschnitten, sondern abgewiesen');
ok('ohne Text steht NULL, und zu viel Text wird gekuerzt statt abgewiesen');

// --- WENIGE BEWERTUNGEN SIND KEIN URTEIL ----------------------------------
//
// Der Kern dieser Aufgabe: "1 Bewertung, 3 Sterne" sieht aus wie ein Befund
// und ist eine einzelne Stimme. Der Durchschnitt entsteht deshalb erst ab
// TourReview::MIN_FOR_AVERAGE - und die Entscheidung faellt im MODELL, nicht
// in der Ansicht: Ein Wert, der einmal herauskommt, erscheint irgendwann auch
// auf einer Seite.
check(TourReview::MIN_FOR_AVERAGE >= 3, 'die Schwelle ist kleiner als drei');

FakeStatement::$row = ['anzahl' => 1, 'schnitt' => '3.0', 'fuehrungen' => 4];
$wenig = TourReview::summaryForGuide(3);
check($wenig['average'] === null, 'bei einer Bewertung kommt ein Durchschnitt heraus');
check($wenig['count'] === 1 && $wenig['tours'] === 4,
    'Anzahl und Zahl der Fuehrungen fehlen');

FakeStatement::$row = ['anzahl' => 2, 'schnitt' => '5.0', 'fuehrungen' => 9];
check(TourReview::summaryForGuide(3)['average'] === null,
    'bei zwei Bewertungen kommt ein Durchschnitt heraus');

FakeStatement::$row = ['anzahl' => 3, 'schnitt' => '4.3333', 'fuehrungen' => 9];
$genug = TourReview::summaryForGuide(3);
check($genug['average'] === 4.3, 'ab drei Bewertungen fehlt der Durchschnitt (' . var_export($genug['average'], true) . ')');
FakeStatement::$row = false;
ok('unter der Schwelle gibt das Modell gar keinen Durchschnitt heraus');

// --- Was anstelle des Durchschnitts dasteht -------------------------------
//
// Eine TATSACHE und keine Wertung: wie viele Fuehrungen stattgefunden haben.
// Sie ist ueberpruefbar und urteilt ueber niemanden.
$jung = ReviewView::blockHtml(['count' => 1, 'average' => null, 'tours' => 4], [], []);
check(strpos($jung, 'Führungen durchgeführt') !== false,
    'ohne Durchschnitt fehlt die Zahl der Fuehrungen');
check(strpos($jung, 'rev-stars--lg') === false,
    'ohne Durchschnitt steht trotzdem eine grosse Sternreihe da');
check(strpos($jung, 'rev-summary__value') === false,
    'ohne Durchschnitt steht trotzdem eine Zahl da');
check(strpos($jung, (string)TourReview::MIN_FOR_AVERAGE) !== false,
    'es steht nicht da, ab wann ein Durchschnitt erscheint');

$reif = ReviewView::blockHtml(['count' => 7, 'average' => 4.3, 'tours' => 9], [], []);
check(strpos($reif, 'rev-summary__value') !== false, 'der Durchschnitt fehlt');
check(strpos($reif, '4,3') !== false, 'die Zahl steht nicht mit Komma da');
check(strpos($reif, '7 Bewertungen') !== false, 'die Anzahl fehlt');
// Halbe Sterne gibt es nur hier - in der Anzeige.
check(strpos($reif, 'rev-star--half') !== false, '4,3 wird nicht auf einen halben Stern gerundet');
check(strpos(ReviewView::sterneHtml(5), 'rev-star--half') === false, '5 hat einen halben Stern');
// Keine Nachkommastelle, wo keine ist.
check(strpos(ReviewView::blockHtml(['count' => 4, 'average' => 5.0, 'tours' => 4], [], []), '5,0') === false,
    'aus 5 wird "5,0"');
ok('unter der Schwelle steht eine Tatsache, darueber ein Durchschnitt');

// --- Der Text einer Bewertung ist Fremdeingabe ----------------------------
//
// Dieselbe Regel wie ueberall: Er geht durch ViewHelper::esc(), und der
// entschaerft nicht nur die spitzen Klammern, sondern auch die drei Rauten -
// sonst loeste ein Kunde mit "###USER###" eine Ersetzung des Servers aus.
$boese = ReviewView::blockHtml(
    ['count' => 3, 'average' => 4.0, 'tours' => 5],
    [[
        'id' => 1, 'stars' => 4, 'created_at' => '2026-03-04 10:00:00',
        'title' => '<b>Ort</b>', 'body' => '<script>alert(1)</script> ###USER###',
    ]],
    ['mit_ort' => true]
);
check(strpos($boese, '<script>') === false, 'der Text einer Bewertung wird nicht maskiert');
check(strpos($boese, '###USER###') === false, 'die drei Rauten kommen durch');
check(strpos($boese, '<b>Ort</b>') === false, 'der Standorttitel wird nicht maskiert');
check(strpos($boese, 'März 2026') !== false, 'der Monat fehlt oder ist taggenau');
check(strpos($boese, '04') === false || strpos($boese, '4. März') === false,
    'das Datum ist taggenau');
ok('Text und Titel sind Fremdeingabe, und dabeisteht nur der Monat');

// --- Kein Name des Kunden -------------------------------------------------
//
// Ein Kunde hat in dieser Anwendung keinen Anzeigenamen, sondern nur einen
// Benutzernamen - und der ist die Anmeldekennung. Er geht Fremde nichts an,
// dieselbe Regel wie auf der Profilseite.
$reviewModell = stripPhpNoise(file_get_contents($ROOT . '/class/Model/TourReview.php'));
$listeRumpf   = methodenRumpf($reviewModell, 'letzte');
check(strpos($listeRumpf, 'username') === false,
    'die Bewertungsliste holt den Benutzernamen des Kunden');
check(strpos($reviewModell, 'customer_user_id') !== false, 'der Kunde steht nicht in der Zeile');
ok('eine Bewertung traegt keinen Namen');

// --- Gezeigt wird nur, was sichtbar ist und etwas zu sagen hat ------------
$fake->statements = [];
TourReview::latestForGuide(3);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'removed_at IS NULL') !== false,
    "eine entfernte Bewertung steht weiterhin in der Liste: $sql");
check(strpos($sql, "v.body <> ''") !== false && strpos($sql, 'v.body IS NOT NULL') !== false,
    'eine Bewertung ohne Text steht als leere Zeile in der Liste');
check(strpos($sql, 'ORDER BY v.created_at DESC') !== false,
    'gezeigt werden nicht die letzten - eine Auswahl waere eine Meinung');
check(strpos($sql, 'LEFT JOIN location') !== false,
    'ein geloeschter Standort nimmt die Bewertung mit');
ok('sichtbar, mit Text, die letzten zuerst');

// --- Der Standort filtert nach location_id, der Guide nach guide_user_id --
$fake->statements = [];
TourReview::latestForLocation(7);
check(strpos($fake->statements[0]->sql, 'v.location_id = :id') !== false,
    'die Standortseite zeigt die Bewertungen des ganzen Guides');
ok('Standortseite und Profil fragen dieselbe Tabelle verschieden');

// --- Entfernen ist Ausblenden und kein Loeschen ---------------------------
$fake->statements = [];
check(TourReview::remove(11, 2, 'Beleidigung') === true, 'entfernen scheitert');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'UPDATE tour_review') === 0, "es ist kein UPDATE: $sql");
check(strpos($sql, 'DELETE') === false, 'die Bewertung wird geloescht');
check(strpos($sql, 'removed_at     = NOW()') !== false || strpos($sql, 'removed_at') !== false,
    'der Zeitpunkt des Entfernens wird nicht festgehalten');
check(strpos($sql, 'removed_by') !== false, 'es bleibt nicht nachvollziehbar, wer entfernt hat');
check(strpos($sql, 'removed_at IS NULL') !== false,
    'eine bereits entfernte Bewertung wird ein zweites Mal angefasst');
check($fake->statements[0]->params[':admin'] === 2, 'der Entferner wird nicht gebunden');
ok('entfernt heisst ausgeblendet - die Zeile bleibt stehen');

// --- Nach dem Entfernen ist NICHT wieder frei -----------------------------
//
// Sonst waere das Entfernen eine Einladung, dasselbe noch einmal zu
// schreiben. Deshalb steht in der Frage nach der offenen Bewertung bewusst
// KEIN Filter auf removed_at: bewertet ist bewertet.
$fake->statements = [];
TourReview::pendingForCustomer(4);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'v.id IS NULL') !== false, 'gefragt wird auch nach bereits Bewertetem');
check(strpos($sql, 'removed_at') === false,
    'eine entfernte Bewertung macht die Fuehrung wieder bewertbar');
check(strpos($sql, "r.status           = 'done'") !== false
      || preg_match("/r\.status\s*=\s*'done'/", $sql) === 1,
    'gefragt wird auch nach nicht durchgefuehrten Fuehrungen');
check(strpos($sql, 'LIMIT 1') !== false,
    'gefragt wird nach mehreren Fuehrungen auf einmal');
ok('gefragt wird nach genau einer offenen Fuehrung - entfernt ist nicht offen');

// --- Nur diese Richtung, und der Guide entfernt nicht ---------------------
//
// Eine Bewertung, die der Bewertete loeschen oder aendern kann, ist keine
// Auskunft mehr ueber ihn.
check(Permission::has(Role::GUIDE, Permission::REVIEW_REMOVE) === false,
    'der Guide darf Bewertungen entfernen');
check(Permission::has(Role::ADMIN, Permission::REVIEW_REMOVE) === true,
    'der Admin darf keine Bewertungen entfernen');
foreach ([Role::TRIAL, Role::USER, Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::REVIEW_CREATE) === true,
        "die Rolle $rolle darf nicht bewerten - ein Guide ist anderswo Kunde");
}
check(Permission::has(Permission::GUEST, Permission::REVIEW_CREATE) === false,
    'ein Gast darf bewerten');
check(Permission::has(Permission::GUEST, Permission::REVIEW_REMOVE) === false,
    'ein Gast darf entfernen');

// Es gibt keine Route, ueber die ein Guide einen Zuschauer bewerten koennte.
$routen = require $ROOT . '/config/routes.php';
check(isset($routen['review_create']) && $routen['review_create'][2] === Permission::REVIEW_CREATE,
    'die Route zum Bewerten fehlt oder traegt das falsche Recht');
check(isset($routen['review_remove']) && $routen['review_remove'][2] === Permission::REVIEW_REMOVE,
    'die Route zum Entfernen fehlt oder traegt das falsche Recht');
check($routen['review_create'][3] === 'json' && $routen['review_remove'][3] === 'json',
    'die Bewertungsrouten antworten nicht als JSON');
check(Permission::routeErrors($routen) === [], 'die Routentabelle ist nach dem Zuwachs unvollstaendig');
ok('Kunden bewerten Guides - und nur die Moderation entfernt');

// --- Beide Seiten zeigen den Block, und der Guide sieht seinen eigenen ----
$standortSeite = file_get_contents($ROOT . '/assets/html/location_page.html');
$profilSeite   = file_get_contents($ROOT . '/assets/html/guide_page.html');
check(strpos($standortSeite, '###REVIEWS###') !== false,
    'die Standortseite hat keinen Platz fuer die Bewertungen');
check(strpos($profilSeite, '###GUIDE_REVIEWS###') !== false,
    'das Guide-Profil hat keinen Platz fuer die Bewertungen');
check(strpos(file_get_contents($ROOT . '/class/Helper/LocationView.php'), 'ReviewView::blockHtml') !== false,
    'die Standortseite baut den Block nicht');
check(strpos(file_get_contents($ROOT . '/class/Helper/GuideView.php'), 'ReviewView::blockHtml') !== false,
    'das Profil baut den Block nicht');
// Der Guide sieht seine eigenen - dieselbe Form, andere Ueberschrift.
$eigen = ReviewView::blockHtml(['count' => 0, 'average' => null, 'tours' => 0], [], ['eigen' => true]);
check(strpos($eigen, 'Ihre Bewertungen') !== false, 'der Guide sieht seine eigenen nicht als seine');
check(strpos(ReviewView::blockHtml(['count' => 0, 'average' => null, 'tours' => 0], [], []), 'Bewertungen') !== false,
    'die Ueberschrift fehlt');
// DER ENTFERNEN-KNOPF STEHT HIER GAR NICHT MEHR - fuer niemanden, auch nicht
// fuer die Moderation. Er ist in den Verwaltungsbereich gezogen. Geprueft
// wird deshalb beides: dass der Knopf fehlt UND dass ein von irgendwoher
// mitgegebener Schalter 'moderation' ihn nicht wiederbelebt. Der zweite Teil
// ist der wichtigere: Ein vergessener Schalter, der irgendwann wieder gesetzt
// wird, waere der Sonderfall zurueck.
$eintrag = [['id' => 3, 'stars' => 5, 'created_at' => '2026-01-02 09:00:00', 'body' => 'Gut.']];
check(strpos(ReviewView::blockHtml(['count' => 3, 'average' => 5.0, 'tours' => 3], $eintrag, []), 'rev-remove') === false,
    'jeder sieht den Entfernen-Knopf');
check(strpos(ReviewView::blockHtml(['count' => 3, 'average' => 5.0, 'tours' => 3], $eintrag, ['moderation' => true]), 'rev-remove') === false,
    'ein mitgegebener Moderationsschalter bringt den Knopf zurueck');
ok('Standortseite und Profil zeigen denselben Block');

// --- Gefragt wird nach dem Auflegen, ueber den Heartbeat ------------------
//
// Ein Dialog im Moment des Auflegens wird auf Telefonen vom Neuladen der
// Seite mitgenommen (assets/js/rtc.js). Der Heartbeat laeuft ohnehin, und was
// er mitbringt, ueberlebt jeden Seitenwechsel.
$heartbeat = methodenRumpf(stripPhpNoise(file_get_contents($ROOT . '/class/Controller/UserController.php')), 'heartbeat');
check(strpos($heartbeat, 'TourReview::pendingForCustomer') !== false,
    'der Heartbeat traegt die offene Bewertung nicht mit');
$reviewJs = file_get_contents($ROOT . '/assets/js/review.js');
$signalingJs = file_get_contents($ROOT . '/assets/js/signaling.js');
check(strpos($signalingJs, 'window.webrtcApp.review') !== false
      && strpos($signalingJs, 'sync(daten.review)') !== false,
    'die Antwort des Heartbeats erreicht das Bewertungsmodul nicht');
// NICHT AUFDRINGLICH: keine Karte waehrend eines Gespraechs, und sie laesst
// sich ueberspringen.
check(strpos($reviewJs, 'imGespraech()') !== false,
    'die Frage kommt auch mitten im Gespraech');
check(strpos($reviewJs, 'ueberspringen') !== false, 'die Frage laesst sich nicht ueberspringen');
check(strpos($reviewJs, 'localStorage') !== false,
    'das Ueberspringen wird nicht gemerkt - die Frage kaeme beim naechsten Takt wieder');
// NACHHOLBAR ueber die Anfragenseite - dort, wo in dieser Anwendung alles
// Verpasste wieder auftaucht.
$requestsJs = file_get_contents($ROOT . '/assets/js/requests.js');
check(strpos($requestsJs, 'rev-open') !== false,
    'auf der Anfragenseite laesst sich eine Bewertung nicht nachholen');
check(preg_match('/!eingehend\s*&&\s*zustand === .done.\s*&&\s*!laeuft\s*&&\s*!this\.wahr\(z\.reviewed\)/', $requestsJs) === 1,
    'der Knopf zum Bewerten steht nicht nur beim Kunden und nur bei beendeten Fuehrungen');
// Die Liste muss die Auskunft ueberhaupt mitbringen.
check(strpos(file_get_contents($ROOT . '/class/Model/TourRequest.php'), 'rev.id IS NOT NULL AS reviewed') !== false,
    'die Anfragenliste weiss nicht, ob eine Fuehrung bewertet ist');
ok('gefragt wird nach dem Auflegen, ueberspringbar, nachholbar');

// --- Die Skala steht an EINER Stelle --------------------------------------
//
// Das Formular baut der Browser - es erscheint nach dem Auflegen auf
// irgendeiner Seite. Die Woerter und Grenzen holt es sich trotzdem vom
// Server; zwei Fassungen einer Skala waeren eine zu viel.
check(strpos(file_get_contents($ROOT . '/class/Helper/ViewHelper.php'), 'window.reviewScale') !== false,
    'die Skala erreicht den Browser nicht');
check(strpos($reviewJs, 'window.reviewScale') !== false,
    'das Formular holt sich die Skala nicht vom Server');
foreach (array_values(TourReview::starNames()) as $wort) {
    check(strpos($reviewJs, $wort) === false,
        "das Wort \"$wort\" steht ein zweites Mal in JavaScript");
}
ok('Woerter und Grenzen der Skala stehen nur im Modell');

// --- Die Wanderung und der Dump kennen die Tabelle ------------------------
$wanderung = file_get_contents($ROOT . '/migrations/016_bewertungen.sql');
check(strpos($wanderung, 'CREATE TABLE IF NOT EXISTS `tour_review`') !== false,
    'die Wanderung legt die Tabelle nicht idempotent an');
check(strpos($wanderung, 'UNIQUE KEY `eine_je_fuehrung` (`request_id`)') !== false,
    'je Fuehrung waeren mehrere Bewertungen moeglich');
check(strpos($wanderung, 'FOREIGN KEY') === false,
    'die Tabelle hat Fremdschluessel - eine Bewertung soll den Standort ueberleben');
foreach (['removed_at', 'removed_by', 'removed_reason'] as $spalte) {
    check(strpos($wanderung, "`$spalte`") !== false, "die Spalte $spalte fehlt");
}
$dump = file_get_contents($ROOT . '/database.sql');
check(strpos($dump, '`tour_review`') !== false, 'der Dump kennt die Tabelle nicht');
ok('die Tabelle steht in der Wanderung und im Dump');


// ---------------------------------------------------------------------
fwrite(STDERR, "\n36) Auflegen ist nicht beenden - und die Bewertung in der Uebersicht\n");

$fake = new FakeConnection();
PdoConnect::$connection = $fake;
FakeStatement::$affected = 1;
$reqConfig = require $ROOT . '/config/requests.php';

// --- Der Guide beendet ausdruecklich --------------------------------------
//
// DER BEFUND: Auflegen ist zweideutig - "wir sind fertig" oder "das Netz ist
// weg". Vorher galt jedes Auflegen als Abschluss, und der Startknopf blieb
// trotzdem stehen: Der Kunde konnte dieselbe Fuehrung beliebig oft neu
// starten, und die Bewertungsfrage kam, waehrend der Guide noch zurueck
// wollte.
$fake->statements = [];
check(TourRequest::finish(5, 6) === true, 'die Fuehrung laesst sich nicht beenden');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'r.closed_at = NOW()') !== false, 'der Abschluss wird nicht festgehalten');
check(strpos($sql, "r.status    = 'done'") !== false, 'die Fuehrung wird nicht durchgefuehrt');
// DIE ZUSTAENDIGKEIT STEHT IN DER WHERE-KLAUSEL: Eine Rechtetabelle kann
// nicht wissen, wessen Fuehrung das ist.
check(strpos($sql, 'r.guide_user_id  = :guide') !== false,
    "der Guide fehlt in der Bedingung: $sql");
check(strpos($sql, 'r.started_at IS NOT NULL') !== false,
    'eine nie begonnene Fuehrung laesst sich beenden');
check(strpos($sql, 'r.closed_at IS NULL') !== false,
    'eine beendete Fuehrung laesst sich ein zweites Mal beenden');
// ended_at IST DAS ENDE DES GESPRAECHS und nicht der Zeitpunkt dieses Klicks:
// Er kann zehn Minuten spaeter kommen. Nur wenn nie aufgelegt wurde, wird es
// nachgetragen.
check(strpos($sql, 'r.ended_at  = COALESCE(r.ended_at, NOW())') !== false,
    'das Beenden ueberschreibt das Ende des Gespraechs');
check(TourRequest::finish(0, 6) === false && TourRequest::finish(5, 0) === false,
    'unvollstaendige Angaben werden geschrieben');
ok('beendet wird ausdruecklich, vom Guide, und nur einmal');

// --- Beenden ist ein eigenes Recht, und nur der Guide hat es --------------
$routen = require $ROOT . '/config/routes.php';
check(isset($routen['request_finish'])
      && $routen['request_finish'][2] === Permission::REQUEST_FINISH
      && $routen['request_finish'][3] === 'json',
    'die Route zum Beenden fehlt oder traegt das falsche Recht');
foreach ([Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::REQUEST_FINISH),
        "Rolle $rolle darf keine Fuehrung beenden");
}
foreach ([Role::TRIAL, Role::USER] as $rolle) {
    check(!Permission::has($rolle, Permission::REQUEST_FINISH),
        "Rolle $rolle darf eine Fuehrung beenden, obwohl sie keine anbietet");
}
check(!Permission::has(Permission::GUEST, Permission::REQUEST_FINISH), 'der Gast darf beenden');
// Es steht bei denselben Rollen wie location.offer: Wer keine Standorte
// anbietet, fuehrt auch keine Fuehrung, die er beenden koennte.
foreach ([Role::TRIAL, Role::USER, Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::REQUEST_FINISH)
          === Permission::has($rolle, Permission::LOCATION_OFFER),
        "Rolle $rolle: request.finish und location.offer stehen nicht beieinander");
}
ok('beenden ist ein eigenes Recht und liegt beim Guide');

// --- Der Abschluss wird GERECHNET, nicht geglaubt -------------------------
//
// Dieselbe Regel wie beim Ablauf einer Anfrage: Die Frist wirkt sofort und
// auch dann, wenn der Cronjob gar nicht eingerichtet ist.
$zu = TourRequest::closedSql('r');
check(strpos($zu, 'r.closed_at IS NOT NULL') !== false,
    'der ausdrueckliche Abschluss zaehlt nicht');
check(preg_match('/DATE_ADD\(r\.ended_at, INTERVAL ' . (int)$reqConfig['rejoin_window'] . ' SECOND\)/', $zu) === 1,
    "die Frist fuer den Wiedereinstieg steht nicht in der Abfrage: $zu");
// DIE REISSLEINE: Kam nie ein Auflegen an, zaehlt der Beginn samt der langen
// Frist - sie muss die laengste Fuehrung ueberdauern.
check(preg_match('/DATE_ADD\(r\.started_at, INTERVAL ' . (int)$reqConfig['stale_call'] . ' SECOND\)/', $zu) === 1,
    "ohne Auflegen bleibt die Fuehrung fuer immer offen: $zu");
check((int)$reqConfig['stale_call'] > (int)$reqConfig['rejoin_window'],
    'die Reissleine ist kuerzer als die Frist fuer den Wiedereinstieg');

// LAEUFT NOCH ist das Gegenstueck: begonnen und nicht zu.
$laeuft = TourRequest::runningSql('r');
check(strpos($laeuft, 'r.started_at IS NOT NULL') !== false,
    'eine nie begonnene Anfrage gilt als laufende Fuehrung');
check(strpos($laeuft, 'NOT ') !== false, 'eine beendete Fuehrung gilt als laufend');

// DURCHGEFUEHRT: begonnen UND zu. Daran haengt die Bewertung.
$fertig = TourRequest::conductedSql('r');
check(strpos($fertig, 'r.started_at IS NOT NULL') !== false
      && strpos($fertig, 'closed_at') !== false,
    'durchgefuehrt heisst nicht "begonnen und zu"');

// Der gerechnete Zustand kennt den neuen Fall: begonnen, nicht beendet,
// Frist vorbei - das ist durchgefuehrt, ohne dass jemand geklickt hat.
$statusSql = TourRequest::statusSql('r');
check(substr_count($statusSql, "'done'") >= 1 && strpos($statusSql, 'closed_at') !== false,
    'eine vergessene Fuehrung wird nie durchgefuehrt');
ok('der Abschluss wird in jeder Abfrage gerechnet - der Cronjob raeumt nur auf');

// --- Bewertbar erst nach dem Beenden --------------------------------------
//
// Die Klammer zwischen beiden Aufgaben: Solange die Fuehrung laeuft, ist sie
// nicht durchgefuehrt - und damit nicht bewertbar.
$fake->statements = [];
TourReview::create(9, 4, 5, '');
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'closed_at') !== false,
    'eine laufende Fuehrung laesst sich bewerten');
$fake->statements = [];
TourReview::pendingForCustomer(4);
check(strpos($fake->statements[0]->sql, 'closed_at') !== false,
    'der Kunde wird gefragt, bevor der Guide beendet hat');
ok('gefragt wird erst, wenn der Guide beendet hat');

// --- Beim Wiedereinstieg entscheidet die Fuehrung ueber die Rollen --------
//
// Bisher galt "wer angerufen wird, fuehrt". Meldet sich nach einem Abbruch
// der GUIDE zurueck, waere damit der Kunde der Guide - samt Steuerkreuz auf
// den Falschen.
$reqDb2 = new FakeRequestConnection();
$reqDb2->users     = [4 => fakeUser(4, 1), 6 => fakeUser(6, 2, false)];
$reqDb2->locations = [13 => fakeLocation(13, 6)];
PdoConnect::$connection = $reqDb2;

FakeRequestStatement::$zusage  = false;
FakeRequestStatement::$laufend = ['id' => 5, 'guide_user_id' => 6,
                                  'customer_user_id' => 4, 'location_id' => 13];

// Der Kunde ruft an: unveraendert.
check(WebRTCController::callRoles(4, 6, 13) === ['caller' => 'viewer', 'callee' => 'guide'],
    'der Kunde bleibt beim Wiedereinstieg nicht Zuschauer');
// Der GUIDE ruft an - und bleibt Guide.
check(WebRTCController::callRoles(6, 4, 13) === ['caller' => 'guide', 'callee' => 'viewer'],
    'meldet sich der Guide zurueck, wird der Kunde zum Guide');
// OHNE STANDORTKENNUNG geht es auch: Die Zeile ist die Aufzeichnung, die
// Kennung im Offer nur eine Behauptung.
check(WebRTCController::callRoles(6, 4, null) === ['caller' => 'guide', 'callee' => 'viewer'],
    'der Wiedereinstieg haengt an der Behauptung des Anrufers');

// OHNE LAUFENDE FUEHRUNG bleibt alles beim Alten: Wer nichts anbietet und
// nicht bereit ist, wird nicht zum Guide erklaert.
FakeRequestStatement::$laufend = false;
check(WebRTCController::callRoles(6, 4, 13) === null,
    'ohne laufende Fuehrung darf der Guide den Kunden anrufen');
FakeRequestStatement::$zusage = false;
PdoConnect::$connection = $fake;
ok('beim Wiedereinstieg entscheidet die Aufzeichnung, nicht wer gewaehlt hat');

// --- Der Zaehler der Kopfleiste zaehlt die offene Fuehrung mit ------------
$fake->statements = [];
FakeStatement::$row = ['incoming_open' => 1, 'outgoing_accepted' => 0, 'tours_running' => 2];
$zahlen = TourRequest::counters(6);
check($zahlen['tours_running'] === 2, 'die offenen Fuehrungen fehlen im Zaehler');
FakeStatement::$row = false;
check(strpos($fake->statements[0]->sql, 'tours_running') !== false,
    'der Zaehler fragt die offenen Fuehrungen nicht ab');

$viewHelper = file_get_contents($ROOT . '/class/Helper/ViewHelper.php');
check(strpos($viewHelper, 'tours_running') !== false,
    'die Kopfleiste kennt die offenen Fuehrungen nicht');
$requestsJs2 = file_get_contents($ROOT . '/assets/js/requests.js');
check(strpos($requestsJs2, 'tours_running') !== false,
    'der Zaehler im Browser kennt die offenen Fuehrungen nicht');
ok('eine nicht beendete Fuehrung steht im Zaehler der Kopfleiste');

// --- Die Karte nach dem Auflegen, beim Guide ------------------------------
$heartbeat2 = methodenRumpf(stripPhpNoise(file_get_contents($ROOT . '/class/Controller/UserController.php')), 'heartbeat');
check(strpos($heartbeat2, 'TourRequest::runningForGuide') !== false,
    'der Heartbeat traegt die laufende Fuehrung nicht mit');
$tourJs = file_get_contents($ROOT . '/assets/js/tour.js');
check(strpos(file_get_contents($ROOT . '/assets/js/signaling.js'), 'sync(daten.tour)') !== false,
    'die Antwort des Heartbeats erreicht das Fuehrungsmodul nicht');
check(strpos($tourJs, 'request_finish') !== false, 'die Karte beendet nichts');
check(strpos($tourJs, 'imGespraech()') !== false,
    'die Karte kommt auch mitten im Gespraech');
check(strpos($tourJs, 'notify.confirm') !== false, 'das Beenden fragt nicht nach');
// Der Wiedereinstieg vom Guide aus - mit Standortkennung, wie ueberall.
check(strpos($tourJs, 'rtc.startCall') !== false, 'der Guide kann nicht wieder einsteigen');
check(strpos($tourJs, 'data-locationid') !== false,
    'der Wiedereinstieg verliert die Standortkennung');
// Und der Knopf steht auch auf der Anfragenseite - dort, wo alles Verpasste
// wieder auftaucht.
check(strpos($requestsJs2, 'tour-finish') !== false,
    'auf der Anfragenseite laesst sich nichts beenden');
check(strpos($requestsJs2, 'Wieder einsteigen') !== false,
    'auf der Anfragenseite fehlt der Wiedereinstieg');
// Beide Karten teilen sich EINE Flaeche - zwei Fassungen liefen auseinander.
check(strpos(file_get_contents($ROOT . '/assets/css/theme.css'), '.app-ask {') !== false,
    'die Karte am Rand hat keine gemeinsame Form');
foreach (['assets/js/review.js', 'assets/js/tour.js'] as $modul) {
    check(strpos(file_get_contents($ROOT . '/' . $modul), 'app-ask') !== false,
        "$modul baut seine eigene Kartenform");
}
ok('nach dem Auflegen fragt die Karte den Guide - beenden oder zurueck');

// --- Die Bewertung dort, wo gewaehlt wird ---------------------------------
//
// Auf der Karte und in der Standortliste - nicht erst auf der Seite, die ein
// Kunde aufruft, nachdem er sich schon entschieden hat.
$fake->statements = [];
(new Location())->selectAllLocations(3);
$sql = $fake->statements[0]->sql;
check(strpos($sql, 'review_average') !== false && strpos($sql, 'review_count') !== false
      && strpos($sql, 'review_tours') !== false,
    'die Standortliste bekommt keine Bewertung');
// EINE gruppierte Teilabfrage und kein Ausdruck je Zeile: Bei fuenfzig Nadeln
// waeren das sonst fuenfzig Abfragen, alle fuenfzehn Sekunden.
check(substr_count($sql, 'GROUP BY') === 2,
    "die Bewertung wird nicht in zwei gruppierten Teilabfragen geholt: $sql");
// DIE SCHWELLE STEHT IM SQL: Unterhalb kommt gar kein Durchschnitt heraus.
check(preg_match('/>=\s*' . TourReview::MIN_FOR_AVERAGE . '\s*THEN ROUND/', $sql) === 1,
    "die Schwelle steht nicht in der Abfrage: $sql");

$fake->statements = [];
(new Location())->selectPublicMapLocations();
check(strpos($fake->statements[0]->sql, 'review_average') !== false,
    'die oeffentliche Karte bekommt keine Bewertung');

// Der Browser rechnet nichts nach - er kennt die Schwelle nicht einmal.
$reviewJs2 = file_get_contents($ROOT . '/assets/js/review.js');
check(strpos($reviewJs2, 'kurzHtml') !== false, 'es gibt keine kurze Fassung fuer die Uebersicht');
check(strpos($reviewJs2, 'MIN_FOR_AVERAGE') === false
      && !preg_match('/>=\s*' . TourReview::MIN_FOR_AVERAGE . '\s*\)/', $reviewJs2),
    'die Schwelle steht ein zweites Mal in JavaScript');
check(strpos(file_get_contents($ROOT . '/assets/js/home_map.js'), 'kurzHtml') !== false,
    'das Kartenfenster zeigt keine Bewertung');

// Die Standortliste bekommt eine EIGENE, SORTIERBARE Spalte - sie ist die
// Ansicht "zum Durchsuchen und Sortieren".
$tabelleJs = file_get_contents($ROOT . '/assets/js/locations_table.js');
check(strpos($tabelleJs, "'review'") !== false, 'die Standortliste hat keine Bewertungsspalte');
check(strpos($tabelleJs, 'data-order') !== false,
    'sortiert wird ueber die Sterne im Text statt ueber den Zahlenwert');
// Unbewertete Standorte landen am Ende und nicht zwischen den schlecht
// bewerteten: "noch keine Bewertung" ist nicht "schlecht bewertet".
check(strpos($tabelleJs, '-1') !== false, 'unbewertete Standorte mischen sich unter die Bewerteten');
// Kopfzeile und Zeilenaufbau muessen zusammenpassen - das prueft das Modul
// selbst, aber ein vergessenes <th> faellt hier schon auf.
foreach ([['assets/html/locations_table.html', 8], ['assets/html/settings.html', 7]] as $paar) {
    [$datei, $erwartet] = $paar;
    $kopf = file_get_contents($ROOT . '/' . $datei);
    check(strpos($kopf, '<th>Bewertung</th>') !== false, "$datei hat keine Bewertungsspalte");
}
ok('die Bewertung steht dort, wo zwischen Standorten gewaehlt wird');

// --- Auf der Standortseite steht "Wieder einsteigen" ----------------------
//
// "Führung starten" bei einer Fuehrung, die gerade laeuft, liest sich wie ein
// zweiter Termin. Und zurueckziehen laesst sie sich nicht mehr - was
// stattgefunden hat, wird nicht nachtraeglich zu "abgebrochen".
$laufendeAnfrage = ['id' => 5, 'status' => 'accepted', 'callable' => 1,
                    'running' => 1, 'wish_in' => -600];
$html = LocationView::anfrageZustandHtml($laufendeAnfrage, ['id' => 7, 'availability' => 'live'], 6);
check(strpos($html, 'Wieder einsteigen') !== false,
    'bei einer laufenden Fuehrung steht "Führung starten"');
check(strpos($html, 'loc-req-cancel') === false,
    'eine laufende Fuehrung laesst sich vom Kunden zurueckziehen');

$offeneAnfrage = ['id' => 5, 'status' => 'accepted', 'callable' => 1,
                  'running' => 0, 'wish_in' => 60];
$html = LocationView::anfrageZustandHtml($offeneAnfrage, ['id' => 7, 'availability' => 'live'], 6);
check(strpos($html, 'Führung starten') !== false, 'der erste Start heisst nicht "starten"');
check(strpos($html, 'loc-req-cancel') !== false,
    'eine noch nicht begonnene Zusage laesst sich nicht absagen');
ok('der Knopf sagt, ob gestartet oder wieder eingestiegen wird');

// --- Die Wanderung ---------------------------------------------------------
$wanderung17 = file_get_contents($ROOT . '/migrations/017_fuehrung_beenden.sql');
check(strpos($wanderung17, 'closed_at') !== false, 'die Wanderung legt closed_at nicht an');
check(strpos($wanderung17, 'information_schema') !== false,
    'die Wanderung ist nicht idempotent');
check(strpos(file_get_contents($ROOT . '/database.sql'), '`closed_at`') !== false,
    'der Dump kennt closed_at nicht');
ok('closed_at steht in der Wanderung und im Dump');


// =====================================================================
fwrite(STDERR, "\nDie Bremse (App\\Model\\RateLimit)\n");
// =====================================================================
//
// Geprueft wird das, was die Bremse zu einer Bremse macht: dass der Zaehler
// NICHT beim Aufrufer liegt, dass mehrere Schranken zugleich greifen, dass
// ein Erfolg nicht alles zuruecksetzt und dass die Grenzen an genau einer
// Stelle stehen. Ohne Datenbank - die abgesetzten Statements werden nur
// mitgeschrieben.

class FakeBremseStatement
{
    public $sql;
    public $params = [];
    public function __construct($sql) { $this->sql = $sql; }
    public function execute($params = null)
    {
        if ($params !== null) { $this->params = $params; }
        FakeBremseDb::$ausgefuehrt[] = $this;
        return true;
    }
    // restsperre() liest genau eine Spalte 'rest'.
    public function fetch($m = null) { return FakeBremseDb::$rest; }
    public function fetchAll($m = null) { return []; }
    public function fetchColumn($i = 0) { return 0; }
}

class FakeBremseDb
{
    /** @var FakeBremseStatement[] */
    public static $ausgefuehrt = [];
    /** @var string[] */
    public static $exec = [];
    /** Was die naechste Abfrage als Restsperre liefert. */
    public static $rest = ['rest' => 0];

    public function prepare($sql) { return new FakeBremseStatement($sql); }
    public function exec($sql) { self::$exec[] = $sql; return 0; }

    public static function leeren()
    {
        self::$ausgefuehrt = [];
        self::$exec = [];
        self::$rest = ['rest' => 0];
    }
}

$bremseDb = new FakeBremseDb();
PdoConnect::$connection = $bremseDb;
$limits = require $ROOT . '/config/limits.php';

// --- Die Grenzen stehen an EINER Stelle, und sie sind vollstaendig --------
//
// Eine Schranke ohne 'sperre' oder mit 'versuche' => 0 waere keine Bremse,
// sondern ein Loch - und zwar eines, das nur im Ernstfall auffiele.
foreach ($limits as $aktion => $schranken) {
    check($schranken !== [], "Aktion '$aktion' hat keine einzige Schranke");
    foreach ($schranken as $name => $e) {
        foreach (['teile', 'versuche', 'fenster', 'sperre', 'erfolg_loescht'] as $feld) {
            check(array_key_exists($feld, $e), "$aktion/$name: '$feld' fehlt");
        }
        check(is_array($e['teile']) && $e['teile'] !== [], "$aktion/$name: keine Teile");
        check($e['versuche'] >= 1, "$aktion/$name: 'versuche' unter 1 sperrt sofort jeden");
        check($e['fenster'] >= 1 && $e['sperre'] >= 1, "$aktion/$name: Frist unter 1 Sekunde");
    }
}
// Die drei Befunde, um die es geht, haben eine Aktion.
foreach (['login', '2fa', 'signup', 'signup_formular'] as $aktion) {
    check(isset($limits[$aktion]), "keine Bremse fuer '$aktion'");
}
ok('jede Schranke in config/limits.php ist vollstaendig');

// Ein Tippfehler im Controller darf keine Bremse ausschalten.
$geworfen = false;
try { RateLimit::schranken('gibtesnicht'); } catch (\InvalidArgumentException $e) { $geworfen = true; }
check($geworfen, 'eine unbekannte Aktion laeuft still ohne Bremse durch');
ok('eine unbekannte Aktion ist ein Fehler und kein "dann eben keine Bremse"');

// --- Geprueft wird VOR dem Versuch, und alle Schranken auf einmal ---------
FakeBremseDb::leeren();
RateLimit::restsperre('login', ['konto' => 'Anna', 'ip' => '198.51.100.7']);
check(count(FakeBremseDb::$ausgefuehrt) === 1,
    'die Pruefung setzt mehr als eine Abfrage ab (' . count(FakeBremseDb::$ausgefuehrt) . ')');
$abfrage = FakeBremseDb::$ausgefuehrt[0];
check(strpos($abfrage->sql, 'gesperrt_bis > NOW()') !== false,
    'gesperrt wird gegen eine Marke statt gegen NOW() geprueft');
check(strpos($abfrage->sql, 'MAX(') !== false, 'es gilt nicht die strengste Schranke');
// Aktion + drei Schranken zu je zwei Werten.
check(count($abfrage->params) === 1 + 3 * 2,
    'nicht alle drei Login-Schranken werden geprueft (' . count($abfrage->params) . ' Werte)');
check($abfrage->params[0] === 'login', 'die Aktion steht nicht als erster Wert');
ok('eine Abfrage prueft alle Schranken, es gilt die laengste Sperre');

// --- Der Schluessel: kleingeschrieben, und ohne Teil keine Schranke -------
//
// Die Datenbank vergleicht Benutzernamen ohne Ruecksicht auf Gross- und
// Kleinschreibung. Waeren "Anna" und "anna" zwei Schluessel, waere die Bremse
// mit der Umschalttaste umgangen.
$werte = FakeBremseDb::$ausgefuehrt[0]->params;
check(in_array('anna', $werte, true), 'der Kontoschluessel ist nicht kleingeschrieben');
check(!in_array('Anna', $werte, true), 'der Schluessel traegt die Schreibweise der Eingabe');

FakeBremseDb::leeren();
RateLimit::restsperre('login', ['konto' => '', 'ip' => '198.51.100.7']);
// Ohne Benutzernamen bleibt nur die IP-Schranke: Aktion + ein Paar.
check(count(FakeBremseDb::$ausgefuehrt[0]->params) === 3,
    'ein leeres Namensfeld legt alle Nutzer auf denselben Zaehler');
ok('eine Schranke ohne ihre Teile faellt weg, die anderen bleiben');

// --- Hochgezaehlt wird in EINEM Schritt -----------------------------------
//
// Lesen, Rechnen, Schreiben waere die Luecke, auf die ein Angriff mit vielen
// parallelen Verbindungen zielt: Beide Versuche lesen denselben Stand, beide
// schreiben denselben Wert - der zweite ist gratis.
FakeBremseDb::leeren();
RateLimit::verbuchen('login', ['konto' => 'anna', 'ip' => '198.51.100.7']);
$inserts = array_values(array_filter(FakeBremseDb::$ausgefuehrt,
    fn($st) => strpos($st->sql, 'INSERT INTO rate_limit') !== false));
check(count($inserts) === 3, 'nicht jede Schranke wird hochgezaehlt (' . count($inserts) . ')');
foreach ($inserts as $ins) {
    check(strpos($ins->sql, 'ON DUPLICATE KEY UPDATE') !== false,
        'hochgezaehlt wird mit Lesen und Schreiben statt in einem Schritt');
    check(strpos($ins->sql, 'SELECT') === false, 'im Hochzaehlen steht ein Lesevorgang');
}
// Die Zahlen kommen aus der Konfiguration und stehen nicht im Code.
$engster = $inserts[0]->sql;
check(strpos($engster, 'INTERVAL ' . $limits['login']['konto_und_ip']['fenster'] . ' SECOND') !== false,
    'das Fenster der engsten Login-Schranke stammt nicht aus config/limits.php');
check(strpos($engster, '>= ' . $limits['login']['konto_und_ip']['versuche']) !== false,
    'die Grenze der engsten Login-Schranke stammt nicht aus config/limits.php');
ok('jeder Zaehler steigt mit einem einzigen Statement');

// Eine abgesessene Sperre faengt bei eins an - sonst waere jede Sperre, die
// kuerzer ist als ihr Fenster, eine Endlosschleife aus Sperren.
check(strpos($engster, 'gesperrt_bis <= NOW()') !== false,
    'eine abgesessene Sperre setzt den Zaehler nicht zurueck');
ok('wer eine Sperre abgesessen hat, faengt wieder bei eins an');

// --- Ein Erfolg loescht nicht alles ---------------------------------------
//
// Wuerde ein erfolgreicher Login die IP-Schranke wegraeumen, koennte ein
// Angreifer mit einem einzigen eigenen Konto seinen IP-Zaehler beliebig oft
// zuruecksetzen - und damit genau die Schranke aushebeln, die das
// Durchprobieren von Benutzernamen begrenzt.
FakeBremseDb::leeren();
RateLimit::zuruecksetzen('login', ['konto' => 'anna', 'ip' => '198.51.100.7']);
$loeschungen = array_values(array_filter(FakeBremseDb::$ausgefuehrt,
    fn($st) => strpos($st->sql, 'DELETE') !== false));
check(count($loeschungen) === 1, 'das Zuruecksetzen setzt mehr als ein DELETE ab');
$geloescht = $loeschungen[0]->params;
check(in_array('konto_und_ip', $geloescht, true), 'die enge Kontoschranke bleibt nach dem Erfolg stehen');
check(in_array('konto', $geloescht, true), 'die weite Kontoschranke bleibt nach dem Erfolg stehen');
check(!in_array('ip', $geloescht, true), 'ein erfolgreicher Login raeumt den IP-Zaehler weg');
ok('ein Erfolg loescht die Kontoschranken, nicht die IP-Schranke');

// --- Aufraeumen ist kein Freispruch ---------------------------------------
FakeBremseDb::leeren();
RateLimit::aufraeumen();
check(count(FakeBremseDb::$exec) === 1, 'das Aufraeumen setzt nicht genau ein Statement ab');
$aufraeumen = FakeBremseDb::$exec[0];
check(strpos($aufraeumen, 'fenster_bis <= NOW()') !== false, 'das Aufraeumen sieht das Fenster nicht an');
check(strpos($aufraeumen, 'gesperrt_bis IS NULL OR gesperrt_bis <= NOW()') !== false,
    'das Aufraeumen loescht laufende Sperren mit');
ok('eine laufende Sperre ueberlebt das Aufraeumen');

// --- Die Adresse: IPv6 zaehlt als /64 -------------------------------------
//
// Ein gewoehnlicher Anschluss bekommt ein ganzes /64 zugeteilt. Eine Bremse
// an der Einzeladresse waere dort keine - der naechste Versuch kaeme von der
// naechsten Adresse desselben Anschlusses.
$alteIp = $_SERVER['REMOTE_ADDR'] ?? null;

$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
check(RateLimit::ip() === '198.51.100.7', 'eine IPv4-Adresse wird veraendert');

$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
$netz = RateLimit::ip();
check(substr($netz, -3) === '/64', 'IPv6 wird nicht auf das /64 gekuerzt: ' . $netz);
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:1111:2222:3333:4444';
check(RateLimit::ip() === $netz, 'zwei Adressen desselben /64 ergeben zwei Zaehler');
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3:aaaa:bbbb:cccc:dddd';
check(RateLimit::ip() !== $netz, 'zwei verschiedene /64 ergeben denselben Zaehler');

// Kein Vertrauen in einen Kopf, den der Aufrufer selbst schreibt.
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
check(RateLimit::ip() === '198.51.100.7', 'X-Forwarded-For wird ausgewertet und ist damit ein Textfeld');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
if ($alteIp === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $alteIp; }
ok('gezaehlt wird REMOTE_ADDR, bei IPv6 das /64');

// --- Ohne Anmeldung faellt die Kontoschranke weg --------------------------
//
// Auth::userId() liefert 0, wenn niemand angemeldet ist - und (string)0 ist
// "0", also ein NICHT LEERER Schluessel. Eine Kontoschranke wuerde damit
// nicht wegfallen, sondern saemtliche nicht angemeldeten Aufrufer auf EINEN
// gemeinsamen Zaehler legen: Der erste, der die Grenze erreicht, sperrt alle
// uebrigen mit.
check(RateLimit::konto(0) === '', 'die Kennung 0 ergibt einen zaehlbaren Schluessel');
check(RateLimit::konto(-1) === '', 'eine negative Kennung ergibt einen zaehlbaren Schluessel');
check(RateLimit::konto(42) === '42', 'eine echte Kennung geht nicht durch');

FakeBremseDb::leeren();
RateLimit::restsperre('turn_credentials', ['konto' => RateLimit::konto(0), 'ip' => '198.51.100.7']);
// Nur die IP-Schranke bleibt: Aktion + ein Paar.
check(count(FakeBremseDb::$ausgefuehrt[0]->params) === 3,
    'ohne Anmeldung landen alle Aufrufer auf einem gemeinsamen Kontozaehler');
ok('ohne Anmeldung zaehlt nur die IP, nicht ein Sammelkonto "0"');

// --- Die Wartezeit wird nach oben gerundet --------------------------------
//
// "noch 1 Minute" bei 61 Sekunden ist eine Zusage, die nicht gehalten wird.
check(RateLimit::wartehinweis(0) === 'gleich wieder', 'ohne Sperre steht eine Wartezeit da');
check(RateLimit::wartehinweis(1) === 'noch 1 Sekunde', 'die Einzahl fehlt');
check(RateLimit::wartehinweis(45) === 'noch 45 Sekunden', 'unter einer Minute wird nicht sekundengenau gezaehlt');
check(RateLimit::wartehinweis(61) === 'noch 2 Minuten', 'die Wartezeit wird abgerundet');
check(RateLimit::wartehinweis(900) === 'noch 15 Minuten', '15 Minuten stehen falsch da');
check(RateLimit::wartehinweis(3601) === 'noch 2 Stunden', 'ueber einer Stunde wird abgerundet');
ok('die Wartezeit wird nach oben gerundet und nicht in Sekunden genannt');

// --- Die Zaehler liegen NICHT mehr beim Aufrufer --------------------------
//
// Der eigentliche Befund. Bliebe irgendwo ein $_SESSION-Zaehler stehen,
// waere die alte Luecke an dieser Stelle wieder offen - und niemand saehe es
// den neuen Aufrufen an.
//
// GEPRUEFT WIRD DER CODE, NICHT DIE KOMMENTARE. Die Stellen, an denen die
// alten Sessionzaehler standen, erklaeren im Kommentar, warum sie weg sind -
// und nennen sie dabei beim Namen. Eine Suche im Rohtext wuerde genau diese
// Erklaerung als Rueckfall melden und damit dazu erziehen, sie zu loeschen.
$ohneKommentare = function (string $quelle): string {
    $text = '';
    foreach (token_get_all($quelle) as $stueck) {
        if (is_array($stueck) && in_array($stueck[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $text .= is_array($stueck) ? $stueck[1] : $stueck;
    }
    return $text;
};

$loginSrc = $ohneKommentare(file_get_contents($ROOT . '/class/Controller/LoginController.php'));
check(strpos($loginSrc, "login_attempts") === false,
    'der Fehlversuchszaehler liegt weiterhin in der Session');
check(strpos($loginSrc, "login_blocked_until") === false,
    'die Sperre liegt weiterhin in der Session');
check(strpos($loginSrc, 'RateLimit::restsperre') !== false, 'der Login prueft die Bremse nicht');
check(strpos($loginSrc, 'RateLimit::verbuchen') !== false, 'der Login zaehlt Fehlversuche nicht');
check(strpos($loginSrc, 'RateLimit::zuruecksetzen') !== false, 'ein erfolgreicher Login raeumt nichts weg');
// Keine zweite Zahl neben der Konfiguration.
check(!preg_match('/\$maxAttempts|\$lockoutTime/', $loginSrc),
    'im Login stehen wieder eigene Grenzen');
// Die Restversuche gehoeren nicht in die Meldung: Sie sagen dem, der
// durchprobiert, ab wann er die Verbindung wechseln muss.
check(strpos($loginSrc, 'Versuch(e)') === false,
    'die Fehlermeldung nennt die Zahl der Restversuche');
ok('der Login zaehlt serverseitig und nennt keine Restversuche');

$zweiSrc = $ohneKommentare(file_get_contents($ROOT . '/class/Controller/TwoFactorController.php'));
check(strpos($zweiSrc, "RateLimit::restsperre('2fa'") !== false,
    'die 2FA-Pruefung hat weiterhin keine Sperre');
check(strpos($zweiSrc, "RateLimit::verbuchen('2fa'") !== false,
    'die 2FA-Pruefung hat weiterhin keinen Versuchszaehler');
check(strpos($zweiSrc, "RateLimit::zuruecksetzen('2fa'") !== false,
    'ein richtiger Code raeumt den Zaehler nicht weg');
// Gezaehlt wird an der UserID aus der Session - der Aufrufer kann sie nicht
// waehlen, anders als den Benutzernamen im Loginformular.
check(preg_match('/\$teile\s*=\s*\[\s*\'konto\'\s*=>\s*RateLimit::konto\(\(int\)\$userId\)/', $zweiSrc) === 1,
    'der 2FA-Zaehler haengt nicht an der UserID');
// Beim Erreichen der Grenze wird die HALBANGEMELDETE SITZUNG verworfen
// ("Passwort stimmte, zweiter Faktor fehlt noch"). Sie blieb bisher nach
// jedem falschen Code stehen, unbegrenzt lange. Zweimal: beim Zuschlagen der
// Sperre und beim naechsten Anlauf, der auf die laufende Sperre trifft.
check(substr_count($zweiSrc, "unset(\$_SESSION['2fa_userid'])") === 3,
    'die halbangemeldete Sitzung bleibt nach der Sperre stehen');
// Geprueft wird VOR der Codepruefung, nicht danach - und zwar innerhalb der
// Methode, sonst zaehlt eine Fundstelle aus dem 2FA-Einrichtungsweg mit.
$verifyBlock = substr($zweiSrc, strpos($zweiSrc, 'function handle2FAVerify'));
$verifyBlock = substr($verifyBlock, 0, strpos($verifyBlock, 'function disable2FA'));
check(strpos($verifyBlock, "RateLimit::restsperre('2fa'") !== false
      && strpos($verifyBlock, "RateLimit::restsperre('2fa'") < strpos($verifyBlock, '->verify('),
    'der Code wird geprueft, bevor die Sperre geprueft wird');
ok('die 2FA-Pruefung hat Zaehler, Sperre und verwirft die halbe Anmeldung');

$signupSrc = $ohneKommentare(file_get_contents($ROOT . '/class/Controller/SignupController.php'));
// Geprueft werden BEIDE Aktionen, bevor irgendetwas passiert - im Code eine
// Schleife ueber die zwei Namen, nicht zwei ausgeschriebene Aufrufe.
check(preg_match("/\['signup',\s*'signup_formular'\]/", $signupSrc) === 1,
    'die Registrierung prueft nicht beide Grenzen');
check(strpos($signupSrc, 'RateLimit::restsperre($aktion') !== false,
    'die Registrierung prueft die Grenzen nicht vor dem Anlegen');
check(strpos($signupSrc, "RateLimit::verbuchen('signup_formular'") !== false,
    'ungueltige Formulare kosten nichts - die Grenze ist damit zu umgehen');
check(strpos($signupSrc, "RateLimit::verbuchen('signup'") !== false,
    'angelegte Konten werden nicht gezaehlt');
// Gezaehlt wird das KONTO erst nach dem Anlegen: Sonst kostet jeder
// Tippfehler ein Konto aus dem Kontingent.
$vorAnlage = strpos($signupSrc, "RateLimit::verbuchen('signup', \$teile)");
$anlage    = strpos($signupSrc, '$user->register(');
check($vorAnlage !== false && $anlage !== false && $vorAnlage > $anlage,
    'ein Tippfehler kostet ein Konto aus dem Kontingent');
ok('die Registrierung zaehlt Formulare und angelegte Konten getrennt');

// --- Die Wanderung und der Dump -------------------------------------------
$wanderung18 = file_get_contents($ROOT . '/migrations/018_bremse.sql');
check(strpos($wanderung18, 'CREATE TABLE IF NOT EXISTS `rate_limit`') !== false,
    'die Wanderung legt rate_limit nicht an');
check(strpos($wanderung18, 'IF NOT EXISTS') !== false, 'die Wanderung ist nicht idempotent');
check(strpos($wanderung18, 'UNIQUE KEY `ein_zaehler`') !== false,
    'ohne eindeutigen Schluessel ergeben gleichzeitige Versuche zwei Zeilen');
check(strpos(file_get_contents($ROOT . '/database.sql'), '`rate_limit`') !== false,
    'der Dump kennt rate_limit nicht');
// Die Spaltenbreite und die Kappungsgrenze im Code gehoeren zusammen.
check(strpos($wanderung18, '`schluessel` varchar(190)') !== false,
    'die Spaltenbreite passt nicht zu RateLimit::SCHLUESSEL_MAX');
check(strpos(file_get_contents($ROOT . '/cron/check_online_status.php'), 'RateLimit::aufraeumen') !== false,
    'abgelaufene Zaehler werden nie aufgeraeumt');
ok('rate_limit steht in der Wanderung, im Dump und im Aufraeum-Cronjob');


// =====================================================================
fwrite(STDERR, "\nDie Bremse an den sechs weiteren Endpunkten (N-10)\n");
// =====================================================================
//
// Derselbe Baustein, sechs weitere Verbraucher - und der Nachweis, dass es
// wirklich derselbe ist: keine zweite Zaehlweise, keine Zahl im Code.

$n10 = [
    'request_create'    => 'class/Controller/RequestController.php',
    'review_create'     => 'class/Controller/ReviewController.php',
    'chat_start'        => 'class/Controller/ChatController.php',
    'chat_message'      => 'class/Controller/ChatController.php',
    'turn_credentials'  => 'class/Controller/TurnController.php',
    'email_verify_send' => 'class/Controller/EmailVerificationController.php',
];

// --- Jede der sechs Aktionen ist eingetragen und vollstaendig -------------
//
// Die Vollstaendigkeit je Schranke prueft der Abschnitt darueber fuer ALLE
// Aktionen mit; hier geht es darum, dass keine der sechs fehlt.
foreach ($n10 as $aktion => $datei) {
    check(isset($limits[$aktion]), "keine Bremse fuer '$aktion'");
}
ok('alle sechs Endpunkte aus N-10 haben eine Aktion in config/limits.php');

// --- Gezaehlt wird am Konto, nicht an der IP ------------------------------
//
// Der Handelnde ist hier angemeldet. Eine IP-Schranke traefe ein Buero oder
// ein Mobilfunk-NAT, hinter dem viele ehrliche Nutzer sitzen; wer viele
// Konten will, laeuft zuerst in die Registrierungsbremse. Die beiden
// Ausnahmen sind die, bei denen ein Aufruf GELD AUSSERHALB DIESES SERVERS
// kostet - dort interessiert nicht, ueber wie viele Konten er verteilt wurde.
$mitIp = ['turn_credentials', 'email_verify_send'];
foreach ($n10 as $aktion => $datei) {
    $arten = [];
    foreach ($limits[$aktion] as $e) { $arten = array_merge($arten, $e['teile']); }
    check(in_array('konto', $arten, true), "$aktion zaehlt nicht am Konto");
    check(in_array('ip', $arten, true) === in_array($aktion, $mitIp, true),
        "$aktion zaehlt " . (in_array('ip', $arten, true) ? '' : 'nicht ') . 'je IP');
}
ok('je Konto - und je IP nur dort, wo ein Aufruf draussen Geld kostet');

// --- Die Sperre ist nie kuerzer als ihr Fenster ---------------------------
//
// Waere sie kuerzer, wuerde SIE zur eigentlichen Taktung: Wer sie abgesessen
// hat, faengt bei eins an und haette sofort das volle Kontingent des
// Fensters. Eine Tagesgrenze mit einstuendiger Sperre waere dann keine
// Tagesgrenze mehr - und neben einer echten Stundengrenze wertlos.
foreach ($limits as $aktion => $schranken) {
    foreach ($schranken as $name => $e) {
        check($e['sperre'] >= $e['fenster'],
            "$aktion/$name: die Sperre ({$e['sperre']}s) ist kuerzer als ihr Fenster ({$e['fenster']}s)");
    }
}
ok('keine Sperre ist kuerzer als ihr Fenster');

// --- Jeder Endpunkt prueft, zaehlt und hat keine eigene Zahl --------------
//
// Der Kern: Es ist derselbe Baustein und keine sechste Nachbildung davon.
foreach ($n10 as $aktion => $datei) {
    $quelle = $ohneKommentare(file_get_contents($ROOT . '/' . $datei));
    check(strpos($quelle, "RateLimit::restsperre('$aktion'") !== false,
        "$datei prueft die Sperre fuer '$aktion' nicht");
    check(strpos($quelle, "RateLimit::verbuchen('$aktion'") !== false,
        "$datei zaehlt '$aktion' nicht");
    // GEPRUEFT WIRD VOR DEM ZAEHLEN. Andersherum verlaengerte ein Client, der
    // stur weiterprobiert, seine eigene Sperre endlos - der abgewiesene
    // Aufruf soll nicht mitzaehlen.
    check(strpos($quelle, "RateLimit::restsperre('$aktion'") < strpos($quelle, "RateLimit::verbuchen('$aktion'"),
        "$datei zaehlt '$aktion', bevor es die Sperre prueft");
    // Der Aufrufer erfaehrt, wie lange er warten muss - ausser beim
    // TURN-Abruf: Der wird nicht abgewiesen, sondern faellt auf STUN zurueck,
    // und eine Wartezeit waere dort eine Auskunft ueber etwas, das gar nicht
    // wartet. Siehe die Pruefung weiter unten.
    if ($aktion !== 'turn_credentials') {
        check(strpos($quelle, 'RateLimit::wartehinweis') !== false,
            "$datei nennt dem Aufrufer die Wartezeit nicht");
    }
}
ok('alle sechs pruefen vor dem Zaehlen und nennen dem Aufrufer die Wartezeit');

// --- Keine Kennung geht ungeprueft in den Schluessel ----------------------
//
// (string)Auth::userId() waere die naheliegende Schreibweise und die falsche:
// Sie macht aus "niemand angemeldet" den Schluessel "0". Alle Aufrufer
// benutzen deshalb RateLimit::konto(), das daraus einen Leerstring macht -
// und der laesst die Kontoschranke wegfallen, statt alle auf einen Zaehler zu
// legen.
$kontoStellen = 0;
foreach (array_unique(array_values($n10)) as $datei) {
    $quelle = $ohneKommentare(file_get_contents($ROOT . '/' . $datei));
    // EINGEFANGEN STATT VERNEINT: Ein negativer Lookahead hinter \s* meldet
    // immer einen Treffer - das \s* faellt einfach auf null zurueck, und
    // dann steht an der Pruefstelle ein Leerzeichen und nicht der gesuchte
    // Aufruf. Geprueft wird deshalb der eingefangene Ausdruck selbst.
    preg_match_all("/'konto'\s*=>\s*([^,\]]+)/", $quelle, $treffer);
    foreach ($treffer[1] as $ausdruck) {
        $kontoStellen++;
        check(strpos(trim($ausdruck), 'RateLimit::konto(') === 0,
            "$datei baut den Kontoschluessel an RateLimit::konto() vorbei: " . trim($ausdruck));
    }
}
// Sonst ginge die Pruefung durch, weil sie nichts gefunden hat.
//
// SIEBEN UND NICHT SECHS: Der ChatController hat zwei Einstiege, die beide
// gegen 'chat_start' zaehlen - den Weg ueber den Standort und den
// Direktzugang der Verwaltung (Migration 019). Es ist derselbe Vorgang mit
// zwei Quellen fuer das Gegenueber, also auch derselbe Zaehler; deshalb steht
// die Kontoschranke dort zweimal im Code, aber nur einmal in
// config/limits.php.
check($kontoStellen === 7, "nicht sieben Kontoschluessel gefunden, sondern $kontoStellen");
ok('jeder Kontoschluessel geht durch RateLimit::konto()');

// --- Die Grenzen stehen an EINER Stelle, und nur eine Datei liest sie -----
//
// Das ist die eigentliche Zusicherung hinter "keine Zahl im Code", und sie
// laesst sich genau pruefen: config/limits.php wird ausschliesslich von
// App\Model\RateLimit geladen. Ein Controller, der sie selbst liest, waere
// der erste Schritt zurueck zu Grenzen, die an mehreren Stellen stehen.
//
// Ein Zahlenvergleich waere hier der falsche Weg: Er trifft jede zufaellige
// Uebereinstimmung mit einer ganz anderen Frist - die 86400 in
// EmailVerificationController ist die Gueltigkeit des Verifikations-Tokens
// und hat mit der Tagesgrenze nichts zu tun.
$leser = [];
foreach (array_merge(glob($ROOT . '/class/Controller/*.php'),
                     glob($ROOT . '/class/Model/*.php'),
                     glob($ROOT . '/class/Helper/*.php')) as $datei) {
    // Ohne Kommentare: Die Controller nennen die Datei in ihrer Begruendung,
    // und das sollen sie auch - gelesen wird sie deswegen nicht.
    if (strpos($ohneKommentare(file_get_contents($datei)), 'limits.php') !== false) {
        $leser[] = basename($datei);
    }
}
check($leser === ['RateLimit.php'],
    'config/limits.php wird ausser von RateLimit noch gelesen von: ' . implode(', ', $leser));
ok('nur App\Model\RateLimit liest config/limits.php');

// --- Der TURN-Abruf weist nicht ab, sondern faellt zurueck ----------------
//
// Ein HTTP 429 waere hier der falsche Weg: Der Endpunkt hat fuer den Ausfall
// des TURN-Dienstes bereits eine brauchbare Antwort - die STUN-Liste mit
// turnAvailable=false -, und ein Anruf im einfachen Netz gelingt damit
// weiterhin. Es unterbleibt nur der teure Weg nach draussen. Dieselbe
// Ueberlegung wie bei Befund F-18, dem dieser Endpunkt seine heutige Form
// verdankt.
$turnSrc = $ohneKommentare(file_get_contents($ROOT . '/class/Controller/TurnController.php'));
check(strpos($turnSrc, '429') === false, 'der gebremste TURN-Abruf antwortet mit einem Fehlercode');
// Der Abruf nach draussen steht im else-Zweig: gebremst wird Metered gar
// nicht erst gefragt.
$gebremst = strpos($turnSrc, "RateLimit::restsperre('turn_credentials'");
$metered  = strpos($turnSrc, 'fetch_turn_credentials()');
check($gebremst !== false && $metered !== false && $gebremst < $metered,
    'der TURN-Dienst wird gefragt, bevor die Bremse geprueft ist');
check(strpos($turnSrc, "RateLimit::verbuchen('turn_credentials'") < $metered,
    'der Aufruf nach draussen wird nicht verbucht');
// Die STUN-Liste haengt in JEDEM Fall dran - auch im gebremsten.
check(substr_count($turnSrc, 'IceServerConfig::merge') === 1,
    'die STUN-Liste haengt nicht mehr an genau einer Stelle dran');
ok('der gebremste TURN-Abruf liefert STUN statt eines Fehlers');

// --- Der Mailversand bremst nur den Weg ueber die Route -------------------
//
// Der Aufruf aus dem Registrierungsablauf ist die Folge einer Registrierung,
// und die ist bereits begrenzt (Aktion 'signup'). Zweimal fuer denselben
// Vorgang zu zaehlen hiesse, dass ein frisch angelegtes Konto seine erste
// Mail unter Umstaenden gar nicht bekommt.
$mailSrc = $ohneKommentare(file_get_contents($ROOT . '/class/Controller/EmailVerificationController.php'));
check(preg_match('/\$ueberRoute\s*=\s*\(\$user_id === null\)/', $mailSrc) === 1,
    'die Bremse unterscheidet nicht zwischen Route und Registrierungsablauf');
check(strpos($mailSrc, 'if ($ueberRoute) {') !== false,
    'die Bremse greift auch im Registrierungsablauf');
// Die Bestaetigungsseite sagt "die Mail ist unterwegs" - im gebremsten Fall
// stimmt das nicht, deshalb ein eigener Hinweis.
check(strpos($mailSrc, 'outputVerificationHinweis') !== false,
    'der gebremste Fall zeigt die Bestaetigungsseite und behauptet einen Versand');
ok('gebremst wird die Route, nicht der Registrierungsablauf');

// --- Die Chatgrenze passt zu dem, was der Client wirklich tut -------------
//
// assets/js/ui_chat.js ruft chat_start bei JEDEM Oeffnen eines Chatfensters
// auf, nicht nur beim Anlegen - es ist ein findOrCreate. Eine enge Grenze
// wuerde den wuergen, der zwischen seinen Gespraechen wechselt, und nicht
// den, der die Plattform absucht. Diese Pruefung haelt die Begruendung an
// den Tatsachen fest: Aendert sich der Client, faellt sie auf.
$uiChat = file_get_contents($ROOT . '/assets/js/ui_chat.js');
check(strpos($uiChat, "'?act=' + route") !== false, 'der Client ruft chat_start nicht mehr auf');
check(strpos($uiChat, 'openChatForLocation') < strpos($uiChat, "'?act=' + route"),
    'chat_start haengt nicht mehr am Oeffnen des Fensters - die Grenze darf enger werden');
check($limits['chat_start']['konto']['versuche'] >= 30,
    'die Chatgrenze ist zu eng fuer einen Client, der bei jedem Oeffnen aufruft');
ok('die Chatgrenze traegt dem findOrCreate bei jedem Oeffnen Rechnung');

// =====================================================================
fwrite(STDERR, "\nJeder Kasten hat einen Rumpf\n");
// =====================================================================
//
// DER BEFUND: Auf der Anfragenseite lagen Ueberschrift, Hinweis und Liste
// unmittelbar in der .app-panel - und damit buendig an deren Rand.
//
// .app-panel traegt nur die FLAECHE: Hintergrund, Rahmen, Rundung, Schatten
// (assets/css/theme.css). Den INNENABSTAND traegt .app-panel__body, und zwar
// als einzige Stelle. Ohne Rumpf fehlt er ringsum.
//
// Auffaellig war es an den Ueberschriften, aber sie waren nicht die Ursache:
// Die Karten sassen ebenso am Rand, sahen nur eingerueckt aus, weil sie ihren
// EIGENEN Innenabstand haben (.req-item). Die Ueberschrift daneben hatte
// nichts dergleichen.
//
// GEPRUEFT WIRD ALS REGEL UND NICHT ALS EINZELFALL - dieselbe Ueberlegung wie
// bei den verschachtelten Formularen (Abschnitt 35): Ein Bauteil, das man
// halb benutzen kann, wird irgendwann wieder halb benutzt.

/**
 * Die erlaubten unmittelbaren Kinder einer .app-panel.
 *
 * __body  der Rumpf mit dem Innenabstand - der Regelfall,
 * __head  die vertiefte Kopfzeile,
 * app-table-wrap
 *         eine Tabelle. SIE IST DIE AUSNAHME UND KEIN VERSEHEN: Eine Tabelle
 *         soll von Rand zu Rand laufen, ihre Zellen tragen den Abstand selbst
 *         (Bootstrap). Ein Rumpf darum wuerde sie ein zweites Mal einruecken
 *         und den Zweck der Flaeche zerstoeren. So gebaut sind die drei
 *         Listenseiten und der aufklappbare Standortkasten der Einstellungen.
 *
 * JEDES KIND MUSS EINES DAVON SEIN - nicht bloss irgendeines.
 *
 * DAS WAR DIE LUECKE DIESER PRUEFUNG. In ihrer ersten Fassung reichte EIN
 * erlaubtes Kind, und damit ging der naheliegendste Halbfehler durch: ein
 * Rumpf, der nur einen TEIL des Inhalts umschliesst.
 *
 *     <section class="app-panel">
 *         <h2>An meine Standorte</h2>          <-- steht weiter am Rand
 *         <p>Beschreibungstext.</p>            <-- ebenso
 *         <div class="app-panel__body">        <-- das eine erlaubte Kind
 *             <ul class="req-list"></ul>
 *         </div>
 *     </section>
 *
 * Der Kasten haette einen Rumpf, die Ueberschriften laegen trotzdem buendig
 * am Rand - genau das Bild, das gemeldet wurde. Die alte Fassung sah das
 * nicht; sie war zufrieden, sobald irgendwo ein Rumpf stand.
 *
 * Ein unmittelbarer TEXT im Kasten zaehlt mit: Auch er liegt am Rand, und ein
 * Element braucht es dafuer nicht.
 */
$erlaubteKinder = ['app-panel__body', 'app-panel__head', 'app-table-wrap'];

/** Liest die Klassenliste eines Elements. */
$klassen = function (\DOMElement $el): array {
    return preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
};

$geprueft = 0;
foreach (glob($ROOT . '/assets/html/*.html') as $datei) {
    $roh = file_get_contents($datei);
    if (strpos($roh, 'app-panel') === false) continue;

    // Die Vorlagen sind Bruchstuecke und keine ganzen Dokumente, und sie
    // enthalten Platzhalter wie ###CHAT_ROWS###. loadHTML meldet das als
    // Warnung - die interessiert hier nicht, geprueft wird der Baum.
    $doc = new \DOMDocument();
    $vorher = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $roh);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);

    $xp = new \DOMXPath($doc);
    foreach ($xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' app-panel ')]") as $panel) {
        $geprueft++;
        $rumpfe = 0;
        foreach ($panel->childNodes as $kind) {
            // Ein unmittelbarer Text liegt genauso am Rand wie ein Element.
            if ($kind instanceof \DOMText) {
                check(trim($kind->wholeText) === '', basename($datei) . ': Text liegt unmittelbar '
                    . 'im Kasten und damit buendig an seinem Rand: "'
                    . substr(trim($kind->wholeText), 0, 40) . '"');
                continue;
            }
            if (!($kind instanceof \DOMElement)) continue;

            // JEDES Kind, nicht irgendeines - siehe den Kopf von
            // $erlaubteKinder. Ein Rumpf neben einer Ueberschrift ist der
            // Fall, den die erste Fassung dieser Pruefung durchgelassen hat.
            check(array_intersect($klassen($kind), $erlaubteKinder) !== [],
                basename($datei) . ': <' . $kind->nodeName . ' class="'
                . $kind->getAttribute('class') . '"> liegt unmittelbar in einer .app-panel '
                . 'und damit buendig an ihrem Rand. Erlaubt sind '
                . implode(', ', $erlaubteKinder));
            $rumpfe++;
        }
        // Ein Kasten ganz ohne Kind hat auch keinen Rumpf.
        check($rumpfe > 0, basename($datei) . ': eine .app-panel ohne jeden Rumpf');
    }
}
// Sonst ginge die Pruefung durch, weil sie nichts gefunden hat.
check($geprueft >= 15, "nur $geprueft Kaesten geprueft - die Vorlagen wurden nicht gelesen");
ok("jede .app-panel in den Vorlagen hat einen Rumpf ($geprueft geprueft)");

// --- Und dieselbe Regel fuer die Kaesten, die aus PHP kommen --------------
//
// Grober, weil sie in Zeichenketten stehen und kein Baum daraus wird: Wer
// eine .app-panel baut, muss im selben Bauabschnitt auch eines der erlaubten
// Kinder bauen. Das faengt den Fall "Kasten hingeschrieben, Rumpf vergessen"
// ab, um den es hier geht.
$phpKaesten = 0;
foreach (array_merge(glob($ROOT . '/class/Controller/*.php'),
                     glob($ROOT . '/class/Helper/*.php'),
                     glob($ROOT . '/assets/js/*.js')) as $datei) {
    $roh = file_get_contents($datei);
    $anzahl = preg_match_all('/app-panel(?![_a-zA-Z-])/', $roh);
    if ($anzahl === 0) continue;
    $phpKaesten += $anzahl;
    $rumpfe = 0;
    foreach ($erlaubteKinder as $kind) { $rumpfe += substr_count($roh, $kind); }
    check($rumpfe >= $anzahl, basename($datei) . ": $anzahl Kaesten, aber nur $rumpfe Rumpf-"
        . 'oder Kopfzeilen - mindestens einer liegt ohne Innenabstand da');
}
check($phpKaesten >= 5, "nur $phpKaesten Kaesten aus PHP gefunden");
ok("jeder aus PHP gebaute Kasten bringt einen Rumpf mit ($phpKaesten geprueft)");

// --- Und dasselbe am AUSGELIEFERTEN Dokument ------------------------------
//
// WARUM NOCH EINMAL: Alles oben liest Dateien. Ausgeliefert wird aber nicht
// die Datei, sondern das, was App\Helper\ViewHelper daraus macht -
// template() schneidet den Kopfkommentar weg, output() setzt die Seite in
// assets/html/index.html ein und ersetzt darin Platzhalter. Eine Pruefung,
// die nur die Vorlage ansieht, sagt ueber das Ergebnis nichts aus; sie
// koennte gruen sein, waehrend im Browser etwas anderes ankommt.
//
// Geprueft wird die Anfragenseite, weil sie der Anlass war - und als Gast,
// weil der Seitenrumpf davon nicht abhaengt und der angemeldete Weg eine
// Datenbank braeuchte.
// IN EINEM UNTERPROZESS, und das ist kein Umweg, sondern der einzige Weg:
// ViewHelper::output() endet mit die($out). Es gibt die Seite nicht zurueck,
// sondern beendet das Programm - ein ob_start() davor faengt sie nicht ein,
// der Testlauf waere an dieser Stelle einfach zu Ende. Genau deshalb kann
// diese Pruefung auch nur EINE Seite ansehen; jede weitere braeuchte einen
// weiteren Prozess.
//
// Aufgerufen wird PHP_BINARY und nicht "php": In einer Umgebung, in der
// mehrere Fassungen liegen, soll es dieselbe sein, die diesen Test ausfuehrt.
$bau = '$R = ' . var_export($ROOT, true) . '; chdir($R);'
     . '$_SESSION = []; $_ENV["APP_BASE_URL"] = "https://beispiel.test/";'
     . 'foreach (["Helper/Role","Helper/Permission","Helper/Auth","Helper/Theme",'
     . '"Helper/Url","Helper/ViewHelper"] as $k) { require_once "$R/class/$k.php"; }'
     . 'App\\Helper\\ViewHelper::output('
     . 'App\\Helper\\ViewHelper::template("$R/assets/html/requests_page.html"));';
$ausgeliefert = (string)shell_exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($bau) . ' 2>/dev/null'
);

check(strlen($ausgeliefert) > 2000,
    'die Seite wurde gar nicht gebaut (' . strlen($ausgeliefert) . ' Zeichen)');
// Der Beleg, dass wirklich der Ausgabeweg gelaufen ist und nicht nur die
// Vorlage durchgereicht wurde: Das Grundgeruest kommt aus index.html.
check(strpos($ausgeliefert, '</html>') !== false,
    'die Ausgabe traegt kein Seitengeruest - output() ist nicht gelaufen');
check(strpos($ausgeliefert, '###CONTENT###') === false,
    'der Platzhalter steht noch in der Ausgabe');

$doc = new \DOMDocument();
$vorher = libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="UTF-8">' . $ausgeliefert);
libxml_clear_errors();
libxml_use_internal_errors($vorher);
$xp = new \DOMXPath($doc);

// Dieselbe strenge Regel wie oben, nur auf dem fertigen Dokument.
$kaesten = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' app-panel ')]");
check($kaesten->length === 2, 'die ausgelieferte Anfragenseite hat nicht zwei Kaesten, sondern '
    . $kaesten->length);
foreach ($kaesten as $panel) {
    foreach ($panel->childNodes as $kind) {
        if ($kind instanceof \DOMText) {
            check(trim($kind->wholeText) === '', 'ausgeliefert: Text liegt unmittelbar im Kasten');
            continue;
        }
        if (!($kind instanceof \DOMElement)) continue;
        check(array_intersect($klassen($kind), $erlaubteKinder) !== [],
            'ausgeliefert: <' . $kind->nodeName . ' class="' . $kind->getAttribute('class')
            . '"> liegt buendig am Rand seines Kastens');
    }
}

// Und die beiden Ueberschriften namentlich: Sie waren der Anlass, und ein
// Rumpf um irgendetwas anderes hilft ihnen nicht.
foreach (['req-in-title', 'req-out-title'] as $id) {
    $h = $xp->query("//*[@id='$id']")->item(0);
    check($h !== null, "ausgeliefert: die Ueberschrift $id fehlt");
    $imRumpf = false;
    for ($el = $h->parentNode; $el instanceof \DOMElement; $el = $el->parentNode) {
        if (in_array('app-panel__body', $klassen($el), true)) { $imRumpf = true; break; }
        if (in_array('app-panel', $klassen($el), true)) break;   // Kasten erreicht, kein Rumpf dazwischen
    }
    check($imRumpf, "ausgeliefert: $id liegt im Kasten, aber nicht in dessen Rumpf - "
        . 'die Ueberschrift steht buendig am Rand');
}
ok('auch im ausgelieferten Dokument liegt jeder Inhalt in einem Rumpf');

// --- Die Anfragenseite im Einzelnen ---------------------------------------
//
// Der Ausgangspunkt, festgehalten: Beide Abschnitte tragen ihren Rumpf, und
// die Liste liegt darin - nicht daneben. Ein Rumpf, der nur die Ueberschrift
// umschliesst, saehe im Zaehler oben genauso aus und waere trotzdem falsch:
// Dann stuende die Ueberschrift eingerueckt und die Karten wieder am Rand.
$anfragen = file_get_contents($ROOT . '/assets/html/requests_page.html');
foreach (['req-in-title' => 'req-incoming', 'req-out-title' => 'req-outgoing'] as $titel => $liste) {
    check(preg_match('/<div class="app-panel__body">.*?id="' . $titel . '".*?id="' . $liste . '".*?<\/div>/s',
        $anfragen) === 1,
        "requests_page.html: $titel und $liste liegen nicht im selben Rumpf");
}
ok('auf der Anfragenseite liegen Ueberschrift, Hinweis und Liste im selben Rumpf');

// =====================================================================
fwrite(STDERR, "\nDie Kopfleiste bricht um, die Knoepfe nicht\n");
// =====================================================================
//
// DER BEFUND: Bei knappem Platz gab die Aktionszeile nach - erst stapelten
// sich ihre beiden Knoepfe untereinander, dann brach auch noch die
// Beschriftung "Neue Lokation hinzufuegen" um. Die Leiste behielt dabei ihre
// festen 56 Punkte, der zu hohe Knopf stand also ueber ihren Rand hinaus.
// Gemessen im Browser (Chromium): Bei 960 Punkten Fensterbreite war die
// Aktionszeile 64 Punkte hoch, bei 820 sogar 83 - in einer 56 Punkte hohen
// Leiste.
//
// DIE REGEL DAGEGEN hat zwei Haelften, und einzeln taugt keine davon etwas:
// Die Bedienelemente behalten ihre Hoehe (sie schrumpfen nicht und brechen
// ihren Text nicht um), und DAFUER darf die Leiste umbrechen und hoeher
// werden. Geprueft werden hier beide - denn ohne die zweite Haelfte laufen
// die Knoepfe waagerecht aus der Leiste heraus statt senkrecht.
$topbar = $themeCss;

/**
 * Liest den Rumpf einer Regel aus der Stilvorlage.
 *
 * Uebergeben wird der Wahlausdruck MIT seiner geschweiften Klammer, damit
 * ".app-topbar {" nicht auf ".app-topbar__actions {" trifft.
 */
$regel = function (string $wahl) use ($topbar): string {
    $anfang = strpos($topbar, $wahl);
    if ($anfang === false) return '';
    $anfang += strlen($wahl);
    $ende = strpos($topbar, '}', $anfang);
    return $ende === false ? '' : substr($topbar, $anfang, $ende - $anfang);
};

// --- Erste Haelfte: die Leiste darf umbrechen -----------------------------
$bar = $regel('.app-topbar {');
check(strpos($bar, 'flex-wrap: wrap') !== false,
    'die Kopfleiste darf nicht umbrechen - dann bricht wieder ihr Inhalt');
check(strpos($bar, 'min-height: var(--app-topbar-height)') !== false,
    'die Kopfleiste hat keine Mindesthoehe');
// EINE FESTE HOEHE WAERE DER FEHLER SELBST: Sie war der Grund, aus dem der
// umgebrochene Knopf ueber den Rand hinausstand.
check(preg_match('/(^|;|\s)height:\s*var\(--app-topbar-height\)/', $bar) !== 1,
    'die Kopfleiste hat wieder eine feste Hoehe - dann ragt der Inhalt heraus');

// --- Zweite Haelfte: nichts darin gibt nach -------------------------------
check(strpos($regel('.app-topbar__actions > * {'), 'flex: none') !== false,
    'die Knoepfe der Aktionszeile schrumpfen wieder - dann bricht ihr Text um');
check(strpos($regel('.app-topbar__actions {'), 'flex-wrap: nowrap') !== false,
    'die Aktionszeile stapelt ihre Knoepfe wieder untereinander');

// Die Liste der Bedienelemente, die ihren Text nicht umbrechen duerfen -
// gesucht wird der EINE Block, in dem sie zusammen stehen. Zwei Bloecke mit
// derselben Angabe waeren zwei Gelegenheiten, das naechste Element nur in
// einem davon nachzutragen.
check(preg_match('/((?:\.[a-z_ .-]+,\s*\n)+[^{}\n]*)\{\s*\n\s*white-space: nowrap;\s*\n\s*\}/',
    $topbar, $mNowrap) === 1, 'der Block gegen den Zeilenumbruch fehlt');
$nowrapBlock = $mNowrap[1] ?? '';
foreach (['.app-topbar .btn', '.app-topbar__brand', '.app-menu__button',
          '.app-ready', '.app-requests', '.app-chats'] as $teil) {
    check(strpos($nowrapBlock, $teil) !== false, "$teil darf seinen Text umbrechen");
}

// --- Das Konto steht rechts, auch in der zweiten Zeile --------------------
//
// Der Fuellraum wirkt nur in der ERSTEN Zeile. Ohne das Aussenmass stuende
// das Konto nach einem Umbruch am linken Rand - unter rechtsbuendigen
// Knoepfen.
check(strpos($regel('.app-topbar__account {'), 'margin-left: auto') !== false,
    'das Konto rutscht beim Umbruch an den linken Rand');

// --- Und die schmalen Geraete --------------------------------------------
//
// Dort bekommt die Aktionszeile ihre eigene Zeile: Marke und Konto fuellen
// auf 320 Punkten die erste bereits aus, daneben blieben ihr null Punkte.
check(preg_match('/@media \(max-width: 700px\).*?\.app-topbar__actions \{[^}]*flex: 1 1 100%/s', $topbar) === 1,
    'auf schmalen Geraeten bekommt die Aktionszeile keine eigene Zeile');
check(preg_match('/@media \(max-width: 700px\).*?\.app-topbar__actions \{[^}]*order: 1/s', $topbar) === 1,
    'die Aktionszeile steht vor dem Konto - das gehoert nach oben rechts');
// Ein Gast hat dort nichts stehen. Ohne diese Regel bliebe eine leere zweite
// Zeile samt ihrem Abstand.
check(strpos($topbar, '.app-topbar__actions:not(:has(> :not(:empty)))') !== false,
    'eine leere Aktionszeile belegt weiterhin eine Zeile');
ok('die Leiste bricht um, die Bedienelemente behalten ihre Hoehe');

// --- Zwei Zaehler nebeneinander muessen unterscheidbar bleiben ------------
//
// Unter 700 Punkten faellt das Wort weg und nur die Zahl bleibt. Seit es
// ZWEI Zaehler gibt, staenden dort zwei gleich aussehende Kreise mit einer
// Zahl darin - und keiner sagte, wovon er spricht.
check(preg_match('/@media \(max-width: 700px\).*?\.app-requests::before[^}]*mask-image: var\(--icon-inbox\)/s', $topbar) === 1,
    'der Anfragenzaehler traegt im schmalen Fall kein eigenes Zeichen');
check(preg_match('/@media \(max-width: 700px\).*?\.app-chats::before[^}]*mask-image: var\(--icon-chat\)/s', $topbar) === 1,
    'der Nachrichtenzaehler traegt im schmalen Fall kein eigenes Zeichen');
check(strpos($topbar, '--icon-inbox:') !== false, 'das Zeichen der Ablage fehlt in der Palette');
ok('die beiden Zaehler sind auch ohne ihre Beschriftung auseinanderzuhalten');


// =====================================================================
fwrite(STDERR, "\nDer Verwaltungsbereich\n");
// =====================================================================
//
// DER BEFUND: Die Verwaltungsfunktionen hingen in der Kundenoberflaeche und
// erzeugten dort Sonderfaelle. Die Standortliste bekam fuer einen einzigen
// Betrachter zwei zusaetzliche Knoepfe UND zusaetzliche Zeilen (gesperrte),
// an jeder Bewertung der Standortseite und des Guide-Profils klebte fuer ihn
// ein "Entfernen", und im Kontomenue stand ein Eintrag, den sonst niemand sah.
//
// Jede dieser Stellen war ein "wenn Admin, dann anders" mitten in einer
// Seite, die fuer Kunden gebaut ist. Sie sind in einen eigenen Bereich
// gezogen (index.php?act=admin und die drei Listen daneben).
//
// GEPRUEFT WIRD BEIDES, und der zweite Teil ist der wichtigere:
//   1. Der Bereich gibt es, er haengt an den richtigen Rechten.
//   2. Die Sonderfaelle sind WEG und lassen sich nicht wiederbeleben - auch
//      nicht, indem jemand den alten Schalter wieder setzt.

$adminRouten = require $ROOT . '/config/routes.php';

// --- Die Routen des Bereichs ---------------------------------------------
foreach ([
    'admin'           => Permission::SYSTEM_ADMIN,
    'admin_locations' => Permission::LOCATION_BLOCK,
    'admin_requests'  => Permission::REQUEST_LIST_ALL,
    'admin_reviews'   => Permission::REVIEW_REMOVE,
] as $act => $recht) {
    check(isset($adminRouten[$act]), "die Route $act fehlt");
    check($adminRouten[$act][2] === $recht, "die Route $act traegt das falsche Recht");
    check($adminRouten[$act][3] === 'html', "die Route $act ist keine Seite");
    check($adminRouten[$act][0] === App\Controller\AdminController::class,
        "die Route $act fuehrt nicht in den Verwaltungsbereich");
}
// Die alte Route 'admin' zeigte auf SystemController::showAdmin und gab eine
// Zeile Text aus. Die Methode ist weg - bliebe sie stehen, gaebe es zwei
// Adminseiten, von denen eine nichts kann.
check(strpos(file_get_contents($ROOT . '/class/Controller/SystemController.php'),
    'function showAdmin') === false,
    'die alte, leere Adminseite steht noch im SystemController');
check(Permission::routeErrors($adminRouten) === [],
    'die Routentabelle ist nach dem Zuwachs unvollstaendig');
ok('vier Routen, vier Rechte - und die alte leere Adminseite ist weg');

// --- Wer hineinkommt ------------------------------------------------------
//
// Kein Rollenvergleich: Gefragt werden die drei Rechte, und zwar fuer JEDE
// Rolle einzeln. Bekaeme irgendwann eine weitere Rolle eines davon, faellt
// hier auf, dass sie damit in den Bereich kommt.
foreach ([Permission::SYSTEM_ADMIN, Permission::LOCATION_BLOCK,
          Permission::REVIEW_REMOVE, Permission::USER_LIST,
          Permission::REQUEST_LIST_ALL] as $recht) {
    check(Permission::has(Role::ADMIN, $recht) === true,
        "der Admin hat das Recht $recht nicht");
    foreach ([Role::TRIAL, Role::USER, Role::GUIDE, Permission::GUEST] as $rolle) {
        check(Permission::has($rolle, $recht) === false,
            "die Rolle " . var_export($rolle, true) . " kommt ueber $recht in den Bereich");
    }
}
ok('in den Bereich kommt nur, wer die Handlung darin auch ausfuehren darf');

// --- Die Navigation zeigt, wofuer das Recht da ist ------------------------
//
// Sie ist Anzeige und keine Absicherung - entschieden wird in index.php. Ein
// Reiter, der auf eine Absage fuehrt, waere trotzdem ein Fehler.
$sessionVorher = $_SESSION ?? [];
$_SESSION = ['auth_scheme' => App\Helper\Auth::SESSION_SCHEME,
             'user' => ['user_id' => 1, 'username' => 'chef', 'role_id' => Role::ADMIN]];
$seiteAdmin = App\Helper\AdminView::page('<p>Inhalt</p>', 'standorte');
foreach (['act=admin', 'act=list_user', 'act=admin_requests',
          'act=admin_locations', 'act=admin_reviews'] as $ziel) {
    check(strpos($seiteAdmin, $ziel) !== false || strpos($seiteAdmin, 'aria-current') !== false,
        "der Reiter zu $ziel fehlt");
}
check(substr_count($seiteAdmin, 'adm__tab') === 5, 'der Bereich hat nicht fuenf Reiter');
// Der aktive Reiter ist kein Verweis auf sich selbst.
check(strpos($seiteAdmin, 'aria-current="page"') !== false, 'kein Reiter steht gerade');
check(strpos($seiteAdmin, '<a class="adm__tab" href="index.php?act=admin_locations"') === false,
    'der aktive Reiter ist ein Verweis auf sich selbst');
check(strpos($seiteAdmin, '<p>Inhalt</p>') !== false, 'der Rahmen verschluckt den Inhalt');

// Ein Guide bekaeme keinen einzigen Reiter - und damit auch keine Sackgasse.
$_SESSION = ['auth_scheme' => App\Helper\Auth::SESSION_SCHEME,
             'user' => ['user_id' => 2, 'username' => 'gast', 'role_id' => Role::GUIDE]];
check(strpos(App\Helper\AdminView::page('<p>x</p>', ''), 'adm__tab') === false,
    'ein Guide bekommt Reiter in einen Bereich, den er nicht betreten darf');
$_SESSION = $sessionVorher;
ok('die Navigation zeigt genau die Reiter, deren Recht der Betrachter hat');

// --- DIE KUNDENOBERFLAECHE KENNT KEINEN ADMIN MEHR ------------------------
//
// Der Kern des Umbaus. Geprueft wird an jeder einzelnen Stelle, an der vorher
// ein Sonderfall stand.

// 1. Die Standortliste der Kunden: keine Sperrknoepfe, kein Moderationsweg.
$tabelleJs = file_get_contents($ROOT . '/assets/js/locations_table.js');
foreach (['block-location-btn', 'unblock-location-btn', 'userCan.blockLocation',
          'act=block_location', 'act=unblock_location'] as $rest) {
    check(strpos($tabelleJs, $rest) === false,
        "die Kundentabelle kennt weiterhin '$rest'");
}

// 2. Und der Server liefert ihr die gesperrten Zeilen nicht mehr mit.
$locCode = stripPhpNoise(file_get_contents($ROOT . '/class/Controller/LocationController.php'));
$holen   = methodenRumpf($locCode, 'getLocations');
check(strpos($holen, 'LOCATION_BLOCK') === false,
    'getLocations liefert der Moderation weiterhin die gesperrten Standorte mit');

// 3. Die Bewertungen: kein Entfernen-Knopf und kein Schalter, der ihn baut.
check(strpos(file_get_contents($ROOT . '/class/Helper/ReviewView.php'), 'rev-remove') === false,
    'der Entfernen-Knopf steht noch am Bewertungsblock');
foreach (['LocationView', 'GuideView'] as $ansicht) {
    check(strpos(file_get_contents($ROOT . "/class/Helper/$ansicht.php"), "'moderation' =>") === false,
        "$ansicht reicht weiterhin einen Moderationsschalter durch");
}
$reviewJs = file_get_contents($ROOT . '/assets/js/review.js');
check(strpos($reviewJs, 'act=review_remove') === false,
    'das Modul der Kundenfrage entfernt weiterhin Bewertungen');

// 4. Das Guide-Profil zeigt der Moderation keine gesperrten Standorte mehr.
$profilCode = file_get_contents($ROOT . '/class/Controller/GuideProfileController.php');
check(strpos($profilCode, 'Permission::') === false,
    'das Guide-Profil entscheidet weiterhin nach einem Recht, was es zeigt');

// 5. Das Kontomenue: EIN Eintrag, und der fuehrt in den Bereich.
$viewCode = file_get_contents($ROOT . '/class/Helper/ViewHelper.php');
check(strpos($viewCode, "'Benutzerliste'") === false,
    'die Benutzerliste steht weiterhin einzeln im Kontomenue');
check(strpos($viewCode, "\$eintraege['index.php?act=admin'] = 'Verwaltung';") !== false,
    'der Weg in den Verwaltungsbereich fehlt im Kontomenue');
check(strpos($viewCode, 'Permission::SYSTEM_ADMIN') !== false,
    'der Menueeintrag haengt nicht am Recht system.admin');

// 6. window.userCan traegt nichts Administratives mehr ins Frontend.
foreach (["'blockLocation'", "'manageUsers'"] as $rest) {
    check(strpos($viewCode, $rest) === false,
        "window.userCan traegt weiterhin $rest ins Frontend");
}

// 7. Der Inhaltsbereich heisst nicht mehr nach einem Adminpanel.
$layout = file_get_contents($ROOT . '/assets/html/index.html');
check(strpos($layout, '<div id="admin-panel"') === false,
    'der Inhaltsbereich jeder Seite heisst weiterhin admin-panel');
check(strpos($layout, '<div id="app-content"') !== false,
    'der Inhaltsbereich hat keinen Namen mehr');
ok('kein Sonderfall mehr in der Kundenoberflaeche - an sieben Stellen geprueft');

// --- Der Bereich benutzt dasselbe Erscheinungsbild ------------------------
//
// Er darf dichter aussehen, aber nicht anders: Jede Farbe, jeder Abstand und
// jede Schriftgroesse kommt aus den Variablen von theme.css. Eine feste Farbe
// hier wuerde beim Wechsel des Farbprofils stehenbleiben - und dann saehe
// genau eine Seite der Anwendung falsch aus.
$adminCss = file_get_contents($ROOT . '/assets/css/admin.css');
$cssOhneKommentar = preg_replace('#/\*.*?\*/#s', '', $adminCss);
check(preg_match('/#[0-9a-fA-F]{3,8}\b/', $cssOhneKommentar) === 0,
    'im Verwaltungsbereich steht eine feste Farbe statt einer Variablen');
check(preg_match('/\b(rgb|rgba|hsl)\(/', $cssOhneKommentar) === 0,
    'im Verwaltungsbereich steht eine ausgeschriebene Farbe');
// Und er wird ueberhaupt geladen - sonst waere die Datei folgenlos.
check(strpos($layout, 'assets/css/admin.css') !== false,
    'die Gestaltung des Bereichs wird nicht geladen');
check(strpos($layout, 'assets/js/admin.js') !== false,
    'die Aktionen des Bereichs werden nicht geladen');
ok('der Bereich sieht dichter aus, aber nicht anders - nur Variablen aus theme.css');

// --- Die Zahlen der Uebersicht -------------------------------------------
//
// Sie zaehlen ueber vier Tabellen, und keine davon gehoert ihnen. Was
// "durchgefuehrt" heisst, steht deshalb nicht in dieser Klasse, sondern in
// App\Model\TourRequest - eine zweite Fassung dieser Bedingung waere die, die
// beim naechsten Umbau vergessen wird.
$statsCode = file_get_contents($ROOT . '/class/Model/AdminStats.php');
check(strpos($statsCode, 'TourRequest::conductedSql') !== false,
    'die Uebersicht schreibt "durchgefuehrt" ein zweites Mal auf');
check(strpos($statsCode, 'TourRequest::runningSql') !== false,
    'die Uebersicht schreibt "laeuft gerade" ein zweites Mal auf');
// Geloeschte Konten zaehlen nicht mit - dieselbe Regel wie in User::getAll().
check(strpos($statsCode, 'deleted = 0') !== false,
    'die Uebersicht zaehlt geloeschte Konten mit');
// Die Rollen kommen aus Role und nicht aus der Abfrage: Eine Rolle ohne ein
// einziges Konto muss mit einer Null dastehen und nicht fehlen.
check(strpos($statsCode, 'Role::all()') !== false,
    'eine Rolle ohne Konten faellt aus der Aufstellung heraus');

// Die Kacheln entstehen aus den fertigen Zahlen; gerechnet wird in der
// Ansicht nichts. Geprueft an einem vollstaendigen Satz.
$kacheln = App\Helper\AdminView::bestandHtml([
    'konten'      => ['gesamt' => 42, 'je_rolle' => [Role::TRIAL => 5, Role::USER => 20,
                                                    Role::GUIDE => 16, Role::ADMIN => 1],
                      'neu' => 3],
    'standorte'   => ['gesamt' => 12, 'gesperrt' => 2, 'anbieter' => 7],
    'fuehrungen'  => ['gesamt' => 90, 'zeitraum' => 8, 'offen' => 1],
    'bewertungen' => ['sichtbar' => 30, 'entfernt' => 1, 'schnitt' => 4.2],
]);
check(strpos($kacheln, '>42<') !== false, 'die Zahl der Konten fehlt');
check(strpos($kacheln, 'Guide 16') !== false, 'die Aufstellung nach Rollen fehlt');
check(strpos($kacheln, '4,2') !== false, 'der Durchschnitt steht nicht deutsch da');
// KEINE KACHEL IST HERVORGEHOBEN UND KEINE FUEHRT IRGENDWOHIN. Hier stehen
// nur Bestandszahlen; was Arbeit bedeutet, steht im Vorratsblock darueber.
// Vorher trug die Standortkachel den Verweis auf die gesperrten - eine
// Aufgabe zwischen Bestandszahlen, und genau das soll sie nicht sein.
check(strpos($kacheln, 'adm-tile--achtung') === false,
    'eine Bestandskachel ist hervorgehoben');
check(strpos($kacheln, '<a ') === false,
    'aus einer Bestandskachel fuehrt ein Verweis heraus');
check(strpos($kacheln, '2 davon gesperrt') !== false,
    'die gesperrten Standorte fehlen im Bestand');
$ruhig = App\Helper\AdminView::bestandHtml([
    'konten'      => ['gesamt' => 1, 'je_rolle' => [], 'neu' => 0],
    'standorte'   => ['gesamt' => 0, 'gesperrt' => 0, 'anbieter' => 0],
    'fuehrungen'  => ['gesamt' => 0, 'zeitraum' => 0, 'offen' => 0],
    'bewertungen' => ['sichtbar' => 0, 'entfernt' => 0, 'schnitt' => null],
]);
check(strpos($ruhig, 'noch kein Durchschnitt') !== false,
    '"keine Bewertung" wird als Durchschnitt 0,0 ausgegeben');
ok('die Uebersicht zaehlt mit den Bedingungen der Modelle und deutet nichts selbst');

// --- Die Listen: Fremdeingabe bleibt Fremdeingabe -------------------------
//
// Titel, Sperrgrund und Bewertungstext stammen von Nutzern. Sie gehen durch
// ViewHelper::esc - also auch durch die Rautenregel: Ein Text mit drei Rauten
// wuerde sonst eine Ersetzung des Servers ausloesen (siehe ViewHelper::esc).
$boeseZeile = App\Helper\AdminView::standortZeilenHtml([[
    'id' => 5, 'title' => '<b>Ort</b>', 'blocked' => 1,
    'blocked_reason' => '<script>alert(1)</script>', 'blocked_at' => '2026-02-03 14:12:00',
    'user_id' => 9, 'username' => 'anna', 'guide_name' => '###USER###',
    'availability' => 'idle', 'country_name' => 'Portugal', 'city_name' => 'Lissabon',
]]);
check(strpos($boeseZeile, '<b>Ort</b>') === false, 'der Standorttitel wird nicht maskiert');
check(strpos($boeseZeile, '<script>') === false, 'der Sperrgrund wird nicht maskiert');
check(strpos($boeseZeile, '###USER###') === false, 'die drei Rauten kommen durch');
check(strpos($boeseZeile, '03.02.2026 14:12') !== false,
    'der Zeitpunkt der Sperre fehlt oder steht nicht als Datum da');
check(strpos($boeseZeile, 'adm-row--gesperrt') !== false, 'die gesperrte Zeile ist nicht erkennbar');
check(strpos($boeseZeile, 'adm-unblock') !== false, 'die gesperrte Zeile bietet kein Freigeben an');
check(strpos($boeseZeile, 'act=location&id=5') !== false,
    'aus der Liste fuehrt kein Weg auf den Standort selbst');
// Der Benutzername steht hier - anders als in der Kundenliste. Genau dafuer
// gibt es die Verwaltung.
check(strpos($boeseZeile, 'anna') !== false, 'die Verwaltung sieht das Konto nicht');

$boeseBewertung = App\Helper\AdminView::bewertungsZeilenHtml([[
    'id' => 8, 'stars' => 2, 'body' => '<i>schlecht</i> ###USER###',
    'created_at' => '2026-02-03 14:12:00', 'removed_at' => null,
    'location_id' => 5, 'title' => 'Alfama',
    'guide_username' => 'guide1', 'customer_username' => 'kunde1',
]]);
check(strpos($boeseBewertung, '<i>schlecht</i>') === false, 'der Bewertungstext wird nicht maskiert');
check(strpos($boeseBewertung, '###USER###') === false, 'die drei Rauten kommen durch');
check(strpos($boeseBewertung, 'adm-review-remove') !== false, 'es gibt keinen Weg zum Entfernen');
// Der Kunde steht mit Namen da - auf der Standortseite ausdruecklich nicht.
check(strpos($boeseBewertung, 'kunde1') !== false,
    'die Moderation sieht nicht, von wem die Bewertung kommt');

// Eine entfernte Bewertung bleibt stehen und bekommt keinen zweiten Knopf.
$entfernt = App\Helper\AdminView::bewertungsZeilenHtml([[
    'id' => 9, 'stars' => 1, 'body' => 'weg', 'created_at' => '2026-02-03 14:12:00',
    'removed_at' => '2026-02-04 08:00:00', 'removed_reason' => 'Beleidigung',
    'location_id' => 5, 'title' => 'Alfama',
    'guide_username' => 'guide1', 'customer_username' => 'kunde1',
]]);
check(strpos($entfernt, 'adm-row--entfernt') !== false, 'die entfernte Zeile ist nicht erkennbar');
check(strpos($entfernt, 'Beleidigung') !== false, 'der Grund der Entfernung fehlt');
check(strpos($entfernt, 'adm-review-remove') === false,
    'eine entfernte Bewertung laesst sich ein zweites Mal entfernen');

// Leere Listen sagen das, statt eine leere Tabelle zu zeigen.
check(strpos(App\Helper\AdminView::standortZeilenHtml([]), 'adm-empty') !== false,
    'die leere Standortliste sagt nichts');
check(strpos(App\Helper\AdminView::bewertungsZeilenHtml([]), 'adm-empty') !== false,
    'die leere Bewertungsliste sagt nichts');
ok('die Listen maskieren jede Fremdeingabe und zeigen, was die Verwaltung braucht');

// --- Die Filter kommen aus der Adresszeile --------------------------------
//
// Und sie gehen als Textbaustein in eine Abfrage. Was nicht in der Liste der
// erlaubten Werte steht, faellt auf die Vorgabe zurueck - abgewiesen wird
// nicht: Ein verstellter Wert soll keine Fehlerseite ergeben.
$locModell = stripPhpNoise(file_get_contents($ROOT . '/class/Model/Location.php'));
$adminAbfrage = methodenRumpf($locModell, 'selectAllForAdmin');
check(strpos($adminAbfrage, 'isset($wo[$in_filter])') !== false,
    'der Standortfilter wird in die Abfrage zusammengesetzt statt nachgeschlagen');
check(strpos($adminAbfrage, 'max(1, min(') !== false,
    'die Zeilengrenze geht ungeprueft in die Abfrage');
$revModell = stripPhpNoise(file_get_contents($ROOT . '/class/Model/TourReview.php'));
$revAbfrage = methodenRumpf($revModell, 'allForAdmin');
check(strpos($revAbfrage, 'isset($wo[$in_filter])') !== false,
    'der Bewertungsfilter wird in die Abfrage zusammengesetzt statt nachgeschlagen');

// Die oeffentliche Bewertungsliste holt weiterhin keinen Namen - das ist der
// Unterschied zwischen den beiden Abfragen und nicht ein Versehen.
check(strpos(methodenRumpf($revModell, 'letzte'), 'username') === false,
    'die oeffentliche Bewertungsliste holt jetzt auch Namen');
check(strpos($revAbfrage, 'customer_username') !== false,
    'die Verwaltung sieht nicht, von wem eine Bewertung kommt');
ok('die Filter werden geprueft, nicht zusammengesetzt - und die beiden Listen bleiben verschieden');

// --- Geaendert wird ueber die Routen, die es schon gab ---------------------
//
// Der Bereich ZEIGT. Ein zweiter Schreibweg "fuer den Adminbereich" waere
// genau die Doppelung, wegen der es ihn gibt.
$adminCtrl = file_get_contents($ROOT . '/class/Controller/AdminController.php');
foreach (['INSERT', 'UPDATE', 'DELETE', 'PdoConnect'] as $schreibt) {
    check(strpos($adminCtrl, $schreibt) === false,
        "der Verwaltungscontroller greift selbst zur Datenbank ($schreibt)");
}
$adminJs = file_get_contents($ROOT . '/assets/js/admin.js');
foreach (['act=block_location', 'act=unblock_location', 'act=review_remove'] as $ziel) {
    check(strpos($adminJs, $ziel) !== false, "der Bereich ruft $ziel nicht auf");
}
// Er meldet sich nur auf seinen eigenen Seiten - sonst haenge auf jeder Seite
// der Anwendung ein Handler fuer Knoepfe, die es dort nicht gibt.
check(strpos($adminJs, "document.querySelector('.adm')") !== false,
    'das Modul des Bereichs haengt sich auf jeder Seite ein');
ok('der Bereich zeigt und aendert nichts selbst - geschrieben wird ueber die alten Routen');

// =====================================================================
fwrite(STDERR, "\nDie Arbeitsvorraete\n");
// =====================================================================
//
// DER BEFUND: Zwei Dinge gingen bisher an jeder Stelle vorbei.
//
//   1. Eine FUEHRUNG, die begonnen hat und die niemand beendet, haelt beim
//      Kunden den Startknopf offen und die Bewertung zurueck. Der Guide
//      sieht das in seiner Kopfleiste - aber nur seine eigenen und nur,
//      solange er die Seite offen hat.
//   2. Eine ANFRAGE, die ein Guide verstreichen laesst, ist ein Kunde ohne
//      Antwort. Gemerkt hat das bisher nur er selbst.
//
// Beides steht jetzt auf der Uebersicht und hat eine Seite, auf der es sich
// abarbeiten laesst (index.php?act=admin_requests).

// --- Was "unbeantwortet" heisst -------------------------------------------
//
// Eng gefasst, und jede der drei Bedingungen hat einen Grund. Geprueft wird
// der SQL-Baustein, weil er die Definition IST.
$unbeantwortet = TourRequest::unansweredSql('r');
check(strpos($unbeantwortet, 'r.decided_at IS NULL') !== false,
    'eine beantwortete Anfrage zaehlt als unbeantwortet');
check(strpos($unbeantwortet, 'r.started_at IS NULL') !== false,
    'eine Anfrage, aus der ein Gespraech wurde, zaehlt als unbeantwortet');
// Beide Ablaufwege, weil die Auskunft nicht davon abhaengen darf, ob der
// Cronjob laeuft: gerechnet (status 'open' und Frist durch) und
// festgeschrieben (status 'expired').
check(strpos($unbeantwortet, "r.status = 'open' AND r.expires_at <= NOW()") !== false,
    'die gerechnete Variante fehlt - ohne Cronjob zaehlt nichts');
check(strpos($unbeantwortet, "r.status = 'expired'") !== false,
    'die festgeschriebene Variante fehlt - mit Cronjob zaehlt nichts');
// Eine ZURUECKGEZOGENE Anfrage traegt ebenfalls kein decided_at. Sie darf
// nicht mitzaehlen: Da hat sich jemand anders entschieden.
check(strpos($unbeantwortet, "'cancelled'") === false
      && strpos($unbeantwortet, "r.status = 'open'") !== false,
    'eine zurueckgezogene Anfrage koennte mitzaehlen');
ok('unbeantwortet heisst: nie zugesagt, nie abgesagt, nie stattgefunden - und abgelaufen');

// --- Ein Vorrat braucht ein Zeitfenster -----------------------------------
//
// Er laesst sich nicht abhaken: Eine vor einem halben Jahr verfallene
// Anfrage bleibt verfallen. Ohne Fenster waere die Zahl eine, die nur
// waechst - und die dann niemand mehr ansieht.
check(TourRequest::VORRAT_TAGE >= 14,
    'das Fenster ist kuerzer als der maximale Vorlauf einer Anfrage');
$config = require $ROOT . '/config/requests.php';
check(TourRequest::VORRAT_TAGE * 86400 >= (int)$config['lead_time_max'],
    'eine lange vorher gestellte Anfrage faellt aus dem Vorrat, bevor sie auffaellt');
check(strpos(TourRequest::imVorratSql('r'), 'r.expires_at >= DATE_SUB(NOW()') !== false,
    'das Fenster rechnet nicht ab dem Ablauf');
// Die HAENGENDEN tragen bewusst KEIN Fenster: Sie loesen sich von selbst auf
// (closedSql), koennen also gar nicht auflaufen.
$zaehler = stripPhpNoise(file_get_contents($ROOT . '/class/Model/TourRequest.php'));
$zaehlerRumpf = methodenRumpf($zaehler, 'adminCounters');
check(preg_match('/SUM\(\$laufend\)\s+AS haengend/', $zaehlerRumpf) === 1,
    'die haengenden Fuehrungen tragen ein Zeitfenster, das sie nicht brauchen');
check(strpos($zaehlerRumpf, 'SUM($offen AND $fenster) AS unbeantwortet') !== false,
    'die unbeantworteten Anfragen tragen kein Zeitfenster');
ok('der Vorrat, der auflaufen kann, hat ein Fenster - der andere nicht');

// --- Die Uebersicht zeigt nur, was offen ist ------------------------------
//
// Eine Zeile mit einer Null, die jeden Tag dasteht, erzieht dazu, den ganzen
// Block zu ueberlesen. Ist nichts offen, steht dort EIN Satz - und der
// zaehlt auf, was geprueft wurde, sonst waere die Leere keine Auskunft.
$sessionVorher2 = $_SESSION ?? [];
$_SESSION = ['auth_scheme' => App\Helper\Auth::SESSION_SCHEME,
             'user' => ['user_id' => 1, 'username' => 'chef', 'role_id' => Role::ADMIN]];

$leer = App\Helper\AdminView::vorratHtml(
    ['haengend' => 0, 'unbeantwortet' => 0, 'gesperrt' => 0]);
check(strpos($leer, 'adm-vorrat__item') === false, 'ein leerer Vorrat zeigt Zeilen');
check(strpos($leer, 'Nichts offen') !== false, 'ein leerer Vorrat sagt nichts');
foreach (['hängende Führung', 'unbeantwortete Anfrage', 'gesperrter Standort'] as $wort) {
    check(strpos($leer, $wort) !== false,
        "der leere Vorrat sagt nicht, dass auf '$wort' geprueft wurde");
}

$voll = App\Helper\AdminView::vorratHtml(
    ['haengend' => 3, 'unbeantwortet' => 1, 'gesperrt' => 2]);
check(substr_count($voll, 'adm-vorrat__item') === 3, 'nicht alle drei Vorraete stehen da');
// Die haengenden Fuehrungen stehen OBEN: Sie sind der einzige Vorrat, der
// gerade jemanden aufhaelt.
check(strpos($voll, 'Führungen hängen') < strpos($voll, 'Anfrage ohne Antwort'),
    'die haengenden Fuehrungen stehen nicht zuerst');
// Einzahl und Mehrzahl - eine "1 Anfragen" waere eine Kleinigkeit, die eine
// Seite billig aussehen laesst.
check(strpos($voll, '>1</span>') !== false && strpos($voll, 'Anfrage ohne Antwort') !== false,
    'die Einzahl fehlt');
check(strpos($voll, 'Führungen hängen') !== false, 'die Mehrzahl fehlt');
// Und die Einzahl beugt das Verb mit: "1 Führungen hängen" waere eine
// Kleinigkeit, die eine Seite billig aussehen laesst.
check(strpos(App\Helper\AdminView::vorratHtml(
        ['haengend' => 1, 'unbeantwortet' => 0, 'gesperrt' => 0]), 'Führung hängt') !== false,
    'die Einzahl der haengenden Fuehrung fehlt');
// Jede Zeile fuehrt dorthin, wo sie sich abarbeiten laesst.
check(strpos($voll, 'act=admin_requests&filter=haengend') !== false,
    'die haengenden Fuehrungen fuehren nirgendwohin');
check(strpos($voll, 'act=admin_requests&filter=unbeantwortet') !== false,
    'die unbeantworteten Anfragen fuehren nirgendwohin');
check(strpos($voll, 'act=admin_locations&filter=gesperrt') !== false,
    'die gesperrten Standorte fuehren nirgendwohin');
// Und der Satz sagt, WARUM die Zahl zaehlt. Ohne ihn ist sie ein Raetsel.
check(strpos($voll, 'Startknopf') !== false,
    'bei den haengenden Fuehrungen steht nicht, was sie aufhalten');

// OHNE DAS RECHT KEIN VERWEIS, aber die Zeile bleibt. Ein Betrachter mit
// system.admin, dem request.list_all fehlt, bekaeme sonst einen Klick in
// eine Absage.
$_SESSION = ['auth_scheme' => App\Helper\Auth::SESSION_SCHEME,
             'user' => ['user_id' => 2, 'username' => 'guide', 'role_id' => Role::GUIDE]];
$ohneRecht = App\Helper\AdminView::vorratHtml(
    ['haengend' => 3, 'unbeantwortet' => 1, 'gesperrt' => 2]);
check(strpos($ohneRecht, 'act=admin_requests') === false,
    'der Vorrat verweist auf eine Seite, die der Betrachter nicht aufrufen darf');
check(substr_count($ohneRecht, 'adm-vorrat__item') === 3,
    'ohne Recht verschwindet die Zahl statt nur des Verweises');
$_SESSION = $sessionVorher2;
ok('der Vorrat zeigt nur Offenes, nennt den Grund und verweist nur, wo es weitergeht');

// --- Die Seite zum Abarbeiten ---------------------------------------------
//
// Sie ZEIGT. Die Verwaltung nimmt keine Anfrage an, lehnt keine ab und
// beendet keine fremde Fuehrung - dafuer gibt es kein Recht und soll es
// keines geben.
$anfragenZeilen = App\Helper\AdminView::anfrageZeilenHtml([[
    'id' => 12, 'status' => 'accepted', 'running' => 1, 'running_since' => 11520,
    'location_id' => 5, 'title' => '<b>Alfama</b>', 'city_name' => 'Lissabon',
    'country_name' => 'Portugal', 'guide_user_id' => 9, 'guide_name' => '###USER###',
    'guide_username' => 'anna', 'customer_username' => 'kunde1',
    'wish_at' => '2026-02-03 14:00:00', 'expires_at' => '2026-02-03 15:00:00',
]]);
check(strpos($anfragenZeilen, '<b>Alfama</b>') === false, 'der Standorttitel wird nicht maskiert');
check(strpos($anfragenZeilen, '###USER###') === false, 'die drei Rauten kommen durch');
check(strpos($anfragenZeilen, 'adm-row--haengt') !== false, 'die haengende Zeile ist nicht erkennbar');
check(strpos($anfragenZeilen, 'hängt') !== false, 'der Zustand "haengt" fehlt');
// Die Dauer grob und lesbar: 11520 Sekunden sind 3 Stunden 12 Minuten.
check(strpos($anfragenZeilen, 'läuft seit 3 Std 12 Min') !== false,
    'die Laufzeit fehlt oder steht in Sekunden da');
// BEIDE Namen - sonst laesst sich nicht sehen, ob dieselben zwei Konten
// dreimal aneinander vorbeigelaufen sind.
check(strpos($anfragenZeilen, 'anna') !== false && strpos($anfragenZeilen, 'kunde1') !== false,
    'die Liste zeigt nicht beide Seiten der Verabredung');
// Der Weg zum Abarbeiten: derselbe Chatknopf wie in der Benutzerliste, und
// er zeigt auf den GUIDE - nicht auf den Kunden.
check(strpos($anfragenZeilen, 'start-chat-btn') !== false,
    'aus der Zeile fuehrt kein Weg zum Guide');
check(strpos($anfragenZeilen, 'data-userid="9"') !== false,
    'der Chatknopf zeigt nicht auf den Guide');

// Eine verfallene Anfrage: andere Marke, andere Zeitangabe.
$verfallen = App\Helper\AdminView::anfrageZeilenHtml([[
    'id' => 13, 'status' => 'expired', 'running' => 0,
    'location_id' => 5, 'title' => 'Alfama', 'guide_user_id' => 9,
    'guide_name' => 'Anna', 'guide_username' => 'anna', 'customer_username' => 'kunde2',
    'wish_at' => '2026-02-03 14:00:00', 'expires_at' => '2026-02-03 15:00:00',
]]);
check(strpos($verfallen, 'adm-row--haengt') === false,
    'eine verfallene Anfrage wird als haengend gezeichnet');
check(strpos($verfallen, 'verfallen 03.02.2026 15:00') !== false,
    'bei einer verfallenen Anfrage fehlt der Zeitpunkt des Ablaufs');
check(strpos(App\Helper\AdminView::anfrageZeilenHtml([]), 'adm-empty') !== false,
    'die leere Anfragenliste sagt nichts');

// Und der Controller schreibt auch hier nichts.
$ctrlRumpf = file_get_contents($ROOT . '/class/Controller/AdminController.php');
check(strpos($ctrlRumpf, 'TourRequest::accept') === false
   && strpos($ctrlRumpf, 'TourRequest::finish') === false
   && strpos($ctrlRumpf, 'TourRequest::cancel') === false,
    'die Verwaltung greift in eine Verabredung ein');
ok('die Anfragenliste zeigt beide Seiten und fuehrt zum Guide - eingegriffen wird nicht');

// =====================================================================
fwrite(STDERR, "\nEin geloeschtes Konto verschwindet\n");
// =====================================================================
//
// DER BEFUND: App\Model\User::del_it() setzt nur `deleted = 1` - die Zeile
// bleibt stehen, damit vergangene Fuehrungen und Bewertungen
// nachvollziehbar bleiben. Der Fremdschluessel half dabei nicht: ON DELETE
// CASCADE greift nur bei einem echten DELETE, und das findet nie statt.
//
// Die Folge war, dass ein geloeschtes Konto weiter oeffentlich sichtbar war:
// seine Nadeln auf der Karte, seine Standortseiten, seine Bilder, sein
// Profil - und anrufbar und anschreibbar war es auch noch. Das ist nicht nur
// falsch, es ist datenschutzrechtlich nicht haltbar.
//
// DIE REGEL: Jede Abfrage, die etwas an ANDERE ausliefert, prueft das
// Kennzeichen selbst - ueber den einen Baustein User::activeSql().

// Die schlichte Attrappe, die nur mitschreibt - die Abschnitte davor haben
// eigene, die auf ihre Abfragen antworten.
$fake = new FakeConnection();
PdoConnect::$connection = $fake;

check(User::activeSql('user') === 'user.deleted = 0',
    'der Baustein prueft nicht auf das Kennzeichen');
check(User::activeSql('g') === 'g.deleted = 0', 'der Alias wird nicht uebernommen');
// Der Alias ist ein Textbaustein in einer Abfrage und wird deshalb geprueft -
// dieselbe Regel wie bei TourRequest::alias().
check(User::activeSql("x; DROP TABLE user; --") === 'xDROPTABLEuser.deleted = 0',
    'der Alias geht ungeprueft in die Abfrage');
check(User::activeSql('') === 'user.deleted = 0', 'ein leerer Alias ergibt keine gueltige Bedingung');
ok('ein Baustein, ein Kennzeichen - und der Alias wird geprueft');

// --- Jede Abfrage, die an Kunden ausliefert -------------------------------
$fake->statements = [];
$L = new Location();
$L->selectAllLocations(7);
$L->selectPublicMapLocations();
$L->selectLocationsOfGuide(7);
$L->selectOneForPage(5);
$L->availabilityOf(5);
$L->guideIdOf(5);

$erwartet = [
    'selectAllLocations'       => 0,   // die Uebersicht
    'selectPublicMapLocations' => 1,   // die oeffentliche Karte
    'selectLocationsOfGuide'   => 2,   // das Guide-Profil
    'selectOneForPage'         => 3,   // die Standortseite
    'availabilityOf'           => 4,   // der Takt der Standortseite
    'guideIdOf'                => 5,   // das Gegenueber eines Chats
];
foreach ($erwartet as $name => $i) {
    check(isset($fake->statements[$i]), "keine Abfrage fuer $name");
    check(strpos($fake->statements[$i]->sql, 'user.deleted = 0') !== false,
        "$name liefert weiterhin die Standorte eines geloeschten Kontos aus");
}
// guideIdOf() kam vorher ganz ohne `user` aus - der Filter braucht dort erst
// einen JOIN. Ohne ihn liesse sich dem Konto eines geloeschten Guides weiter
// schreiben.
check(strpos($fake->statements[5]->sql, 'JOIN user') !== false,
    'guideIdOf verbindet nicht mit dem Konto');
ok('Karte, Uebersicht, Profil, Standortseite, Takt und Chatziel filtern das Kennzeichen');

// --- Und die Dateien, die zu diesen Seiten gehoeren ------------------------
//
// Bild und Avatar sind der EINZIGE Weg, auf dem eine hochgeladene Datei einen
// Browser erreicht (sie liegen ausserhalb des Webroots). Ohne Filter blieben
// sie abrufbar, nachdem die Seite verschwunden ist - und die Kennungen sind
// fortlaufend.
$fake->statements = [];
LocationImage::findWithLocation(3);
check(strpos($fake->statements[0]->sql, 'user.deleted = 0') !== false,
    'die Bilder eines geloeschten Kontos bleiben abrufbar');
$fake->statements = [];
GuideProfile::avatarOf(7);
check(strpos($fake->statements[0]->sql, 'user.deleted = 0') !== false,
    'das Profilbild eines geloeschten Kontos bleibt abrufbar');
// Das Profil selbst filterte schon vorher - geprueft wird, dass es dabei
// bleibt.
$fake->statements = [];
GuideProfile::forUser(7);
check(strpos($fake->statements[0]->sql, 'deleted = 0') !== false,
    'die Profilseite eines geloeschten Kontos geht wieder auf');
ok('auch die Dateien verschwinden, nicht nur die Seiten');

// --- Die Verwaltung findet sie - aber erst auf Nachfrage -----------------
//
// Sie ist der EINE Ort, an dem die uebriggebliebenen Zeilen ueberhaupt noch
// sichtbar sein koennen. Ungefragt stehen sie trotzdem nicht in der Liste:
// Die Vorgabe blendet sie aus, ein Schalter blendet sie ein - und dann sagt
// die Zeile mit einer Marke, warum sie auf keine Seite mehr verweist.
$fake->statements = [];
$L->selectAllForAdmin('alle');
check(strpos($fake->statements[0]->sql, 'user.deleted = 0') !== false,
    'die Verwaltung zeigt geloeschte Konten ungefragt in der Liste');
$fake->statements = [];
$L->selectAllForAdmin('alle', 500, true);
check(strpos($fake->statements[0]->sql, 'user.deleted = 0') === false,
    'der Schalter blendet die geloeschten Konten nicht ein');
// Und er wirft den Filter daneben nicht weg: "gesperrt" und "gehoert einem
// geloeschten Konto" schliessen sich nicht aus.
$fake->statements = [];
$L->selectAllForAdmin('gesperrt', 500, true);
check(strpos($fake->statements[0]->sql, 'location.blocked = 1') !== false,
    'der Schalter wirft den Filter daneben weg');
$fake->statements = [];
$L->selectAllForAdmin('gesperrt');
check(strpos($fake->statements[0]->sql, 'location.blocked = 1 AND user.deleted = 0') !== false,
    'Filter und Schalter wirken nicht zusammen');
check(strpos($fake->statements[0]->sql, 'user.deleted AS user_deleted') !== false,
    'der Verwaltung fehlt das Kennzeichen');

$zeileGeloescht = App\Helper\AdminView::standortZeilenHtml([[
    'id' => 5, 'title' => 'Alfama', 'blocked' => 0, 'blocked_reason' => null,
    'blocked_at' => null, 'user_id' => 9, 'username' => 'anna',
    'guide_name' => 'Anna', 'user_deleted' => 1, 'availability' => 'idle',
    'country_name' => 'Portugal', 'city_name' => 'Lissabon',
]]);
check(strpos($zeileGeloescht, 'Konto gelöscht') !== false,
    'die Verwaltung sieht nicht, dass das Konto geloescht ist');
check(strpos($zeileGeloescht, 'act=guide&id=9') === false,
    'die Zeile verweist auf ein Profil, das es nicht mehr gibt');
ok('die Verwaltung behaelt den Blick auf das, was uebrigbleibt');

// --- Kein Anruf, kein Chat, keine Sitzung ---------------------------------
//
// Fuer diese drei gibt es keine WHERE-Klausel, in die sich der Filter
// einsetzen liesse: Zu einem Anruf gehoeren zwei Kennungen aus dem Offer, und
// eine Sitzung ist gar keine Abfrage. Sie fragen deshalb User::isDeleted().
$rtcCode  = file_get_contents($ROOT . '/class/Controller/WebRTCController.php');
$rollen   = methodenRumpf(stripPhpNoise($rtcCode), 'callRoles');
check(strpos($rollen, 'User::isDeleted($callerId) || User::isDeleted($calleeId)') !== false,
    'ein geloeschtes Konto kann weiterhin anrufen oder angerufen werden');
// VOR allem anderen, auch vor dem Wiedereinstieg in eine laufende Fuehrung:
// Wer geloescht ist, ist nicht mehr erreichbar - auch nicht ueber eine Zusage
// von gestern.
check(strpos($rollen, 'isDeleted') < strpos($rollen, 'runningBetween'),
    'die Pruefung steht hinter dem Wiedereinstieg - eine Zusage von gestern haelt sie aus');

$chatCode = file_get_contents($ROOT . '/class/Controller/ChatController.php');
check(substr_count($chatCode, 'User::isDeleted') === 2,
    'Direktchat und Senden pruefen das Kennzeichen nicht beide');
// Der VERLAUF bleibt lesbar - er gehoert beiden Seiten. Was nicht mehr geht,
// ist etwas hinzuzufuegen.
check(strpos($chatCode, 'Der Verlauf bleibt erhalten') !== false,
    'mit dem Konto verschwindet auch der eigene Verlauf');
check(strpos($chatCode, 'User::getUsernamesByIds([$partnerId])') !== false,
    'die Chatliste holt den Benutzernamen an der Regel vorbei');

$authCode = file_get_contents($ROOT . '/class/Helper/Auth.php');
check(strpos($authCode, 'User::isDeleted($userId)') !== false,
    'die Sitzung eines geloeschten Kontos laeuft weiter');
ok('geloescht heisst: kein Anruf, keine neue Nachricht, keine laufende Sitzung');

// --- Und der Name selbst --------------------------------------------------
//
// Der Benutzername ist die Anmeldekennung. Er gehoert nicht zu einem Konto,
// das es nicht mehr gibt - stehen bleibt ein Platzhalter, damit ein
// Chatverlauf nicht namenlos wird.
check(User::NAME_GELOESCHT !== '' && stripos(User::NAME_GELOESCHT, 'gelösch') !== false,
    'der Platzhalter sagt nicht, was er meint');
$fake->statements = [];
User::getUsernamesByIds([4, 5]);
check(strpos($fake->statements[0]->sql, 'deleted') !== false,
    'die Namensabfrage weiss nicht, ob ein Konto geloescht ist');
ok('der Benutzername eines geloeschten Kontos wird nicht mehr herausgegeben');

// =====================================================================
fwrite(STDERR, "\nDie Sicht des EIGENTUEMERS bleibt\n");
// =====================================================================
//
// DER ANLASS: Beim Umbau auf den Verwaltungsbereich ist die Moderationssicht
// aus mehreren Kundenseiten verschwunden. Die Sicht des EIGENTUEMERS stand
// an denselben Stellen und in derselben Zeile - sie durfte dabei nicht
// mitgehen.
//
// Diese Pruefungen halten sie fest, damit der naechste Umbau sie nicht
// mitnimmt. Ein gesperrter Standort muss fuer seinen Guide sichtbar bleiben,
// gekennzeichnet und mit dem Grund: Sonst weiss er nicht, was passiert ist,
// und kann nichts aendern.

// 1. Die EIGENE Standortliste (Einstellungsseite) verbirgt nichts.
$fake->statements = [];
$L->selectAllLocationsOfOneUser(7);
$eigeneSql = $fake->statements[0]->sql;
check(strpos($eigeneSql, 'location.blocked = 0') === false,
    'die eigene Liste verbirgt den gesperrten Standort');
check(strpos($eigeneSql, 'location.blocked') !== false
   && strpos($eigeneSql, 'blocked_reason') !== false,
    'der eigenen Liste fehlen Sperre oder Grund');
// Und sie filtert NICHT auf geloescht: Es ist die eigene Liste, und wer sie
// aufruft, ist angemeldet.
check(strpos($eigeneSql, 'user.deleted') === false,
    'die eigene Liste filtert auf ein Kennzeichen, das fuer sie nicht gilt');

// 2. Das EIGENE Guide-Profil zeigt die gesperrten mit.
$fake->statements = [];
$L->selectLocationsOfGuide(7, true);      // $in_with_blocked, wie beim Eigentuemer
check(strpos($fake->statements[0]->sql, 'location.blocked = 0') === false,
    'das eigene Profil verbirgt den gesperrten Standort');
$fake->statements = [];
$L->selectLocationsOfGuide(7, false);     // wie bei jedem anderen Betrachter
check(strpos($fake->statements[0]->sql, 'location.blocked = 0') !== false,
    'ein fremder Betrachter sieht die gesperrten Standorte');

// 3. Die Kennzeichnung auf dem eigenen Profil.
$eigenesProfil = App\Helper\GuideView::angeboteHtml(
    [['id' => 5, 'title' => 'Alfama', 'blocked' => 1, 'availability' => 'idle',
      'city_name' => 'Lissabon', 'country_name' => 'Portugal']],
    'Anna', true);
check(strpos($eigenesProfil, 'Gesperrt') !== false,
    'auf dem eigenen Profil steht nicht, dass der Standort gesperrt ist');

// 4. Der Controller entscheidet das ueber das EIGENTUM und nicht ueber ein
//    Recht. Stuende dort ein Recht, waere die Eigentuemersicht beim naechsten
//    Umbau der Moderation wieder mit weg.
$profilCode = stripPhpNoise(file_get_contents($ROOT . '/class/Controller/GuideProfileController.php'));
$profilRumpf = methodenRumpf($profilCode, 'showProfilePage');
check(strpos($profilRumpf, 'selectLocationsOfGuide($user_id, $eigen)') !== false,
    'das Guide-Profil entscheidet nicht mehr ueber das Eigentum');

$ortRumpf = methodenRumpf(stripPhpNoise(file_get_contents(
    $ROOT . '/class/Controller/LocationController.php')), 'showLocationPage');
check(strpos($ortRumpf, '$ist_eigen') !== false,
    'die Standortseite kennt den Eigentuemer nicht mehr');
check(preg_match('/blocked.*?===\s*1\s*&&\s*!\$ist_eigen/s', $ortRumpf) === 1,
    'der Eigentuemer kommt nicht mehr auf die Seite seines gesperrten Standorts');
ok('der Guide sieht seinen gesperrten Standort - in der Liste, auf dem Profil und auf der Seite');

// =====================================================================
fwrite(STDERR, "\nZwei Vorraete, die heute nirgends auffallen\n");
// =====================================================================

$fake = new FakeConnection();
PdoConnect::$connection = $fake;

// --- Unvollstaendige Angebote ---------------------------------------------
//
// Fuenf Dinge, und jedes einzelne kostet den Guide Kunden, ohne dass er es
// merkt: Ein halb ausgefuelltes Angebot sieht fuer seinen Eigentuemer fertig
// aus - er weiss ja, was er anbietet.
$unvoll = Location::unvollstaendigSql('location');
foreach (['latitude', 'longitude', 'title', 'description_long',
          'availability_slots'] as $spalte) {
    check(strpos($unvoll, "location.$spalte") !== false,
        "die Vollstaendigkeit prueft $spalte nicht");
}
// Das Bild ueber NOT EXISTS und nicht ueber einen JOIN: Ein JOIN auf
// location_image vervielfachte die Zeile, und die Bedingung soll sich auch
// in ein WHERE und in ein SUM() einsetzen lassen.
check(strpos($unvoll, 'NOT EXISTS') !== false && strpos($unvoll, 'location_image') !== false,
    'das fehlende Bild wird nicht geprueft');
check(strpos($unvoll, 'JOIN location_image') === false,
    'das Bild wird ueber einen JOIN geprueft - der vervielfacht die Zeile');
// Leerstring UND NULL, beides: Ein Formular, das ein Feld leer laesst,
// schreibt je nach Weg das eine oder das andere.
check(substr_count($unvoll, "= ''") === 2,
    'ein leeres Textfeld gilt weiterhin als ausgefuellt');
// Der Alias ist ein Textbaustein in einer Abfrage.
check(strpos(Location::unvollstaendigSql('x; DROP TABLE location; --'), 'xDROPTABLElocation.latitude') !== false,
    'der Alias geht ungeprueft in die Abfrage');

// Die Maengel stehen in JEDER Zeile, nicht nur im Filter - ein Standort kann
// gesperrt UND unvollstaendig sein.
$fake->statements = [];
(new Location())->selectAllForAdmin('gesperrt');
check(strpos($fake->statements[0]->sql, 'AS fehlt_bild') !== false,
    'die Sperrliste verschweigt, was am Angebot fehlt');
$fake->statements = [];
(new Location())->selectAllForAdmin('unvollstaendig');
check(strpos($fake->statements[0]->sql, 'WHERE (location.latitude IS NULL') !== false,
    'der Filter "unvollstaendig" filtert nicht');

// Und die Zeile sagt, WAS fehlt - sonst muesste man jeden Standort einzeln
// aufmachen.
$luecken = App\Helper\AdminView::standortZeilenHtml([[
    'id' => 5, 'title' => '', 'blocked' => 0, 'user_id' => 9, 'username' => 'anna',
    'guide_name' => 'Anna', 'availability' => 'idle',
    'country_name' => 'Portugal', 'city_name' => 'Lissabon',
    'fehlt_ort' => 1, 'fehlt_bild' => 1, 'fehlt_titel' => 1,
    'fehlt_text' => 0, 'fehlt_zeiten' => 0,
]]);
foreach (['keine Nadel', 'kein Bild', 'kein Titel'] as $wort) {
    check(strpos($luecken, $wort) !== false, "die Zeile nennt '$wort' nicht");
}
check(strpos($luecken, 'kein Text') === false && strpos($luecken, 'keine Zeiten') === false,
    'die Zeile nennt Maengel, die es nicht gibt');
$vollstaendig = App\Helper\AdminView::standortZeilenHtml([[
    'id' => 6, 'title' => 'Alfama', 'blocked' => 0, 'user_id' => 9, 'username' => 'anna',
    'guide_name' => 'Anna', 'availability' => 'idle',
    'fehlt_ort' => 0, 'fehlt_bild' => 0, 'fehlt_titel' => 0,
    'fehlt_text' => 0, 'fehlt_zeiten' => 0,
]]);
check(strpos($vollstaendig, 'vollständig') !== false,
    'ein vollstaendiges Angebot hinterlaesst eine leere Zelle');

// Kopf und Zeile muessen gleich viele Spalten haben - sonst steht die
// Tabelle schief, und zwar erst im Browser. Die Tabellen des
// Verwaltungsbereichs tragen keine id (sie werden nicht von DataTables
// gebaut), deshalb wird der Kopf hier direkt gezaehlt.
$adminVorlage = file_get_contents($ROOT . '/assets/html/admin_locations.html');
preg_match('/<thead\b.*?<tr\b(.*?)<\/tr>/is', $adminVorlage, $kopfTreffer);
$spaltenKopf  = preg_match_all('/<th\b/i', $kopfTreffer[1] ?? '');
$spaltenZeile = substr_count(explode('</tr>', $luecken)[0], '<td');
check($spaltenKopf === $spaltenZeile,
    "Kopf und Zeile der Standortliste haben verschiedene Spaltenzahlen "
    . "($spaltenKopf gegen $spaltenZeile)");
ok('unvollstaendig ist genau definiert, steht in jeder Zeile und sagt, was fehlt');

// --- Der Cronjob laeuft nicht ---------------------------------------------
//
// Der stillste Ausfall dieser Anwendung: check_online_status.php ist die
// einzige Stelle, die user_status je auf 'offline' setzt. Laeuft der Job
// nicht, bleibt jedes Konto fuer immer online.
$statsCode2 = stripPhpNoise(file_get_contents($ROOT . '/class/Model/AdminStats.php'));
$cronRumpf  = methodenRumpf($statsCode2, 'cronRueckstand');
check(strpos($cronRumpf, "user_status <> 'offline'") !== false,
    'gezaehlt wird nicht der stehengebliebene Status');
check(strpos($cronRumpf, 'updated_at < DATE_SUB(NOW()') !== false,
    'gezaehlt wird ohne Altersgrenze');
check(strpos($cronRumpf, 'deleted = 0') !== false,
    'geloeschte Konten zaehlen im Rueckstand mit');
// GEGEN EIN VIELFACHES DES TIMEOUTS und nicht gegen den Timeout selbst: Der
// ist mit 45 Sekunden so knapp, dass zwischen zwei Laeufen staendig Konten
// darueber liegen. Eine Zahl, die bei laufendem Job dauernd ungleich null
// ist, waere kein Vorrat, sondern Rauschen.
check(strpos($cronRumpf, "presence['offline_timeout']") !== false,
    'die Grenze steht als Zahl im Code statt in config/presence.php');
check(strpos($cronRumpf, 'self::CRON_FAKTOR') !== false,
    'gerechnet wird gegen den Timeout selbst - das ist ein Rennen, kein Befund');
$presence = require $ROOT . '/config/presence.php';
check(App\Model\AdminStats::CRON_FAKTOR * (int)$presence['offline_timeout'] >= 600,
    'die Grenze liegt unter zehn Minuten - dann meldet sie auch einen laufenden Cronjob');
ok('der Rueckstand wird gegen ein Vielfaches des Timeouts gerechnet, nicht gegen ihn selbst');

// --- Beide stehen im Vorrat und fuehren irgendwohin ----------------------
$sessionVorher3 = $_SESSION ?? [];
$_SESSION = ['auth_scheme' => App\Helper\Auth::SESSION_SCHEME,
             'user' => ['user_id' => 1, 'username' => 'chef', 'role_id' => Role::ADMIN]];

$alleFuenf = App\Helper\AdminView::vorratHtml([
    'haengend' => 1, 'unbeantwortet' => 1, 'gesperrt' => 1,
    'unvollstaendig' => 2, 'cron' => 3,
]);
check(substr_count($alleFuenf, 'adm-vorrat__item') === 5, 'es stehen nicht alle fuenf Vorraete da');
check(strpos($alleFuenf, 'act=admin_locations&filter=unvollstaendig') !== false,
    'die unvollstaendigen Angebote fuehren nirgendwohin');
// Der Cronjob ist KEIN Vorgang, sondern ein Befund ueber die Installation -
// abzuarbeiten ist er nicht, einzurichten schon. Er fuehrt deshalb dorthin,
// wo der Schaden zu sehen ist: in die Benutzerliste.
check(strpos($alleFuenf, 'act=list_user') !== false,
    'der Cron-Rueckstand fuehrt nicht dorthin, wo er sich auswirkt');
check(strpos($alleFuenf, 'check_online_status.php') !== false,
    'der Cron-Rueckstand sagt nicht, was einzurichten ist');
// Und er steht ZULETZT: Die vier davor sind Vorgaenge, er ist eine
// Betriebsmeldung.
check(strrpos($alleFuenf, 'check_online_status.php') > strpos($alleFuenf, 'unvollständig'),
    'die Betriebsmeldung draengt sich vor die Vorgaenge');

$leerFuenf = App\Helper\AdminView::vorratHtml([
    'haengend' => 0, 'unbeantwortet' => 0, 'gesperrt' => 0,
    'unvollstaendig' => 0, 'cron' => 0,
]);
check(strpos($leerFuenf, 'adm-vorrat__item') === false, 'ein leerer Vorrat zeigt Zeilen');
foreach (['unvollständiges Angebot', 'Aufräumjob läuft'] as $wort) {
    check(strpos($leerFuenf, $wort) !== false,
        "der leere Vorrat sagt nicht, dass auf '$wort' geprueft wurde");
}
$_SESSION = $sessionVorher3;
ok('fuenf Vorraete, jeder mit einem Weg - und der leere Fall zaehlt alle fuenf auf');

// =====================================================================
fwrite(STDERR, "\nDer Weg zu den eigenen Standorten haengt nicht an JavaScript\n");
// =====================================================================
//
// DER BEFUND: Auf der Einstellungsseite standen ein Knopf mit
// style="display:none" und ein Bereich mit style="display:none". SICHTBAR
// wurden beide erst, wenn ein Skript lief -
// $('#showOwnLocationsBtn').show(). Blieb das aus - eine Bibliothek, die
// nicht laedt, ein anderes Modul, das vorher wirft -, verlor ein Guide den
// Weg zu seinen eigenen Standorten VOLLSTAENDIG: kein Knopf, kein Bereich,
// keine Meldung. Er haette nicht einmal gemerkt, dass etwas fehlt.
//
// Dieselbe Antwort wie beim Benutzermenue der Kopfleiste:
// <details>/<summary>. Es geht mit der Tastatur auf und auch ohne Skript.

$einstellungen = file_get_contents($ROOT . '/assets/html/settings.html');
$tabellenJs2   = file_get_contents($ROOT . '/assets/js/locations_table.js');

// Der Bereich klappt von selbst auf.
check(preg_match('/<details\b[^>]*id="myLocationsSection"/', $einstellungen) === 1,
    'der Bereich der eigenen Standorte ist kein <details>');
check(preg_match('/<summary\b/', $einstellungen) === 1,
    'dem Bereich fehlt die anklickbare Zusammenfassung');

// KEIN display:none MEHR auf dem Weg dorthin. Das ist der Kern: Was ein
// Skript einblenden muss, ist ohne Skript weg.
check(preg_match('/id="myLocationsSection"[^>]*display:\s*none/', $einstellungen) === 0,
    'der Bereich ist weiterhin per Stil versteckt');
check(strpos($einstellungen, 'id="showOwnLocationsBtn"') === false,
    'der Knopf, den erst ein Skript sichtbar macht, steht noch da');

// Und das Skript blendet nichts mehr ein - es laedt nur noch nach.
check(strpos($tabellenJs2, "\$('#showOwnLocationsBtn')") === false,
    'das Modul macht weiterhin einen Knopf sichtbar');
// Geprueft wird der INITIALISIERUNGSBLOCK und nicht die ganze Datei: Ein
// .show() gibt es dort weiterhin, aber fuer die Kartenvorschau beim
// Ueberfahren - die kann es ohne Skript ohnehin nicht geben. Was hier nichts
// zu suchen hat, ist ein Einblenden beim SEITENAUFBAU.
$readyBlock = substr($tabellenJs2, (int)strpos($tabellenJs2, '$(document).ready'));
// Ohne Kommentare: Dort steht .show() als Beschreibung dessen, was frueher
// passierte - und genau das soll die Pruefung nicht als Rueckfall lesen.
$readyCode  = preg_replace('#//[^\n]*#', '', $readyBlock);
check(strpos($readyCode, '.show()') === false,
    'beim Seitenaufbau blendet das Modul etwas ein, was ohne Skript fehlt');
check(strpos($tabellenJs2, "\$eigene.on('toggle'") !== false,
    'das Nachladen haengt nicht am Aufklappen');
// NUR beim Aufklappen: Das Ereignis kommt in beide Richtungen, und der alte
// Klick-Handler lud die Tabelle auch beim Zuklappen neu.
check(strpos($tabellenJs2, 'if (this.open) tabellen.bindEvents') !== false,
    'beim Zuklappen wird die Tabelle erneut geladen');

// Der Kasten darin bleibt ein Kasten mit Rumpf - die Regel aus dem Abschnitt
// "Jeder Kasten hat einen Rumpf" gilt auch innerhalb eines <details>.
check(preg_match('/<details.*?<div class="app-panel".*?<\/details>/s', $einstellungen) === 1,
    'im aufklappbaren Bereich steht kein Kasten mehr');
ok('die eigenen Standorte sind auch ohne Skript erreichbar');

// =====================================================================
fwrite(STDERR, "\nDie Verbindung steht, bevor jemand sie braucht\n");
// =====================================================================
//
// DER AUSFALL: Jede Seite endete mit "Call to a member function prepare() on
// null". Die Ursache war eine REIHENFOLGE in index.php, kein Fehler in einer
// Abfrage:
//
//     Auth::discardOutdatedSession();          // fragt seit dem Filter auf
//                                              // geloeschte Konten die DB
//     $pdo_instance = new PdoConnect();        // ... erst HIER entstand sie
//
// Die Verbindung entstand als NEBENWIRKUNG eines Konstruktors, dessen
// Ergebnis niemand benutzt - eine Zeile, die aussieht, als koenne man sie
// verschieben. Genau das war der Fehler: Die Pruefung darueber kam dazu, die
// Zeile blieb, wo sie war.
//
// WARUM KEINE PRUEFUNG DAS GEFUNDEN HAT: Alle setzen PdoConnect::$connection
// selbst auf eine Attrappe und rufen die Methoden direkt auf. Die
// Reihenfolge in index.php sah keine an. Das aendert sich hier.

// OHNE KOMMENTARE: Der Kommentar an der neuen Stelle nennt die alte Zeile
// beim Namen, damit nachvollziehbar bleibt, was dort schiefging - und genau
// das soll die Pruefung nicht als Rueckfall lesen.
$startCode = stripPhpNoise(file_get_contents($ROOT . '/index.php'));

// 1. Die Verbindung heisst jetzt, was sie tut.
check(strpos($startCode, 'PdoConnect::sicherstellen();') !== false,
    'index.php baut die Verbindung nicht ueber den benannten Aufruf auf');
check(strpos($startCode, '$pdo_instance') === false,
    'die Verbindung entsteht weiterhin als Nebenwirkung einer ungenutzten Variablen');

// 2. UND SIE STEHT VOR ALLEM, WAS SIE BRAUCHT. Das ist der eigentliche
//    Schutz: Jeder kuenftige Aufruf, der die Datenbank braucht, steht
//    dahinter - sonst schlaegt diese Pruefung an.
$posVerbindung = strpos($startCode, 'PdoConnect::sicherstellen();');
foreach (['Auth::discardOutdatedSession', 'Auth::can', 'new $class'] as $braucht) {
    $pos = strpos($startCode, $braucht);
    if ($pos === false) continue;
    check($posVerbindung < $pos,
        "in index.php steht '$braucht' vor dem Aufbau der Verbindung");
}

// 3. Und sie steht NACH der HTTPS-Weiterleitung: Eine Anfrage, die nur
//    umgeleitet wird, soll keine Verbindung oeffnen.
check(strpos($startCode, "header('Location: ' . \$httpsUrl") < $posVerbindung,
    'jede http-Anfrage oeffnet eine Datenbankverbindung, nur um umgeleitet zu werden');
ok('die Verbindung entsteht benannt, nach der Weiterleitung und vor allem, was sie braucht');

// --- Der Ausfall selbst, nachgestellt -------------------------------------
//
// IM UNTERPROZESS, und das ist kein Umweg: Der Fehler war ein uncaught Error.
// Er wuerde diesen Testlauf beenden, statt eine Pruefung fehlschlagen zu
// lassen - und dann saehe man nicht, WELCHE Annahme verletzt ist.
//
// Nachgestellt wird die alte Reihenfolge: angemeldete Sitzung, KEINE
// Verbindung, und dann der Aufruf aus dem Startpfad. Erwartet wird, dass er
// nicht mehr am Nullwert scheitert. Ob danach wirklich eine Datenbank da ist,
// spielt keine Rolle - ohne sie meldet sich PdoConnect selbst und ordentlich.
$nachstellung = '$R = ' . var_export($ROOT, true) . '; chdir($R);'
     . 'foreach (["Model/PdoConnect","Helper/Role","Helper/Permission","Model/User",'
     . '"Helper/Auth"] as $k) require_once "$R/class/$k.php";'
     . '$_SESSION = ["auth_scheme" => App\\Helper\\Auth::SESSION_SCHEME,'
     . ' "user" => ["user_id" => 7, "role_id" => App\\Helper\\Role::GUIDE]];'
     . 'App\\Model\\PdoConnect::$connection = null;'
     . 'App\\Helper\\Auth::discardOutdatedSession();'
     . 'echo "DURCHGELAUFEN";';
$ausgabe = (string)shell_exec(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($nachstellung) . ' 2>&1'
);
check(strpos($ausgabe, 'on null') === false,
    "der Startpfad scheitert weiterhin an einer fehlenden Verbindung:\n$ausgabe");
check(strpos($ausgabe, 'Uncaught Error') === false,
    "der Startpfad endet weiterhin mit einem unbehandelten Fehler:\n$ausgabe");
// Zwei Ausgaenge sind in Ordnung, und beide sind KEIN Absturz: Entweder es
// gibt eine Datenbank - dann laeuft der Aufruf durch -, oder es gibt keine -
// dann meldet PdoConnect das selbst und beendet geordnet.
check(strpos($ausgabe, 'DURCHGELAUFEN') !== false
   || strpos($ausgabe, 'Interner Serverfehler') !== false,
    "der Startpfad endet unerwartet:\n$ausgabe");
ok('der Startpfad scheitert nicht mehr an einer Verbindung, die es noch nicht gibt');

// =====================================================================
fwrite(STDERR, "\nGeloeschte Konten: ausgeblendet, aber auffindbar\n");
// =====================================================================
//
// DER BEFUND: In der Standortliste der Verwaltung standen die Standorte
// geloeschter Konten ungefragt mitten in der normalen Ansicht. Sie GEHOEREN
// dorthin - die Verwaltung ist der eine Ort, an dem sie ueberhaupt noch
// sichtbar sein koennen -, aber nicht ungefragt.
//
// DIE REGEL FUER ALLE DREI LISTEN: per Vorgabe ohne, ein Schalter blendet
// ein, betroffene Zeilen sind gekennzeichnet.

$fake = new FakeConnection();
PdoConnect::$connection = $fake;

// --- Ein Schalter, kein Filterwert ---------------------------------------
//
// "gesperrt" und "gehoert einem geloeschten Konto" schliessen sich nicht aus.
// Waere das Geloeschte einer der Filterwerte, liesse sich "gesperrte
// Standorte geloeschter Konten" gar nicht mehr ansehen - und das ist genau
// die Liste, die man nach einer Loeschung durchgeht.
$ctrlQuelle = stripPhpNoise(file_get_contents($ROOT . '/class/Controller/AdminController.php'));
foreach (['showLocations', 'showReviews'] as $methode) {
    $rumpf = methodenRumpf($ctrlQuelle, $methode);
    check(strpos($rumpf, "self::schalter(Request::g('geloescht'))") !== false,
        "$methode liest den Schalter nicht aus der Adresszeile");
    check(strpos($rumpf, 'geloeschtSchalterHtml') !== false,
        "$methode zeigt den Schalter nicht an");
}
// Nur "1" ist ja - ein verstellter Wert ergibt die Vorgabe und keine
// Fehlerseite.
$schalterRumpf = methodenRumpf($ctrlQuelle, 'schalter');
check(strpos($schalterRumpf, "=== '1'") !== false,
    'ein beliebiger Wert in der Adresse blendet die geloeschten Konten ein');

// Der Schalter erhaelt den Filter, und der Filter erhaelt den Schalter -
// sonst faellt man beim ersten Klick auf die Vorgabe zurueck.
$sessionVorher4 = $_SESSION ?? [];
$_SESSION = ['auth_scheme' => App\Helper\Auth::SESSION_SCHEME,
             'user' => ['user_id' => 1, 'username' => 'chef', 'role_id' => Role::ADMIN]];

$schalterAus = App\Helper\AdminView::geloeschtSchalterHtml(
    'admin_locations', ['filter' => 'gesperrt'], false);
check(strpos($schalterAus, 'filter=gesperrt') !== false,
    'der Schalter wirft den Filter daneben weg');
check(strpos($schalterAus, 'geloescht=1') !== false,
    'der ausgeschaltete Schalter blendet nicht ein');
check(strpos($schalterAus, 'aria-pressed="false"') !== false,
    'der Zustand des Schalters steht nur in einer Klasse');

$schalterAn = App\Helper\AdminView::geloeschtSchalterHtml(
    'admin_locations', ['filter' => 'gesperrt'], true);
// AUSGESCHALTET WIRD DURCH WEGLASSEN und nicht durch "geloescht=0": Die
// Vorgabe soll die kurze Adresse sein.
check(strpos($schalterAn, 'geloescht=') === false,
    'der eingeschaltete Schalter fuehrt nicht zurueck in die Vorgabe');
check(strpos($schalterAn, 'aria-pressed="true"') !== false,
    'der eingeschaltete Schalter sagt es Vorleseprogrammen nicht');
$_SESSION = $sessionVorher4;
ok('ein Schalter neben dem Filter, nicht in ihm - und beide erhalten einander');

// --- Die Bewertungsliste: nach dem GUIDE, nicht nach dem Kunden ----------
//
// Ist das Konto des GUIDES geloescht, steht die Bewertung nirgends mehr -
// seine Standorte und sein Profil sind weg. Sie zu entfernen aendert nichts.
// Ist das Konto des KUNDEN geloescht, steht sie WEITERHIN oeffentlich beim
// Guide: Sie ist eine Auskunft ueber ihn und traegt keinen Namen. Sie muss
// also moderierbar bleiben.
$fake->statements = [];
TourReview::allForAdmin('alle');
check(strpos($fake->statements[0]->sql, 'g.deleted = 0') !== false,
    'die Bewertungsliste zeigt Bewertungen geloeschter Guides ungefragt');
check(strpos($fake->statements[0]->sql, 'k.deleted = 0') === false,
    'die Bewertungsliste blendet aus, was noch oeffentlich steht - '
    . 'die Bewertung eines geloeschten KUNDEN gehoert weiter moderiert');
$fake->statements = [];
TourReview::allForAdmin('schwach', 200, true);
check(strpos($fake->statements[0]->sql, 'g.deleted = 0') === false,
    'der Schalter blendet die geloeschten Guides nicht ein');
check(strpos($fake->statements[0]->sql, 'v.stars <=') !== false,
    'der Schalter wirft den Filter daneben weg');
// Beide Kennzeichen kommen immer mit.
check(strpos($fake->statements[0]->sql, 'g.deleted AS guide_deleted') !== false
   && strpos($fake->statements[0]->sql, 'k.deleted AS customer_deleted') !== false,
    'der Bewertungsliste fehlt eines der beiden Kennzeichen');

$bewertungGeloescht = App\Helper\AdminView::bewertungsZeilenHtml([[
    'id' => 8, 'stars' => 2, 'body' => 'Text', 'created_at' => '2026-02-03 14:12:00',
    'removed_at' => null, 'location_id' => 5, 'title' => 'Alfama',
    'guide_username' => 'anna', 'customer_username' => 'kunde1',
    'guide_deleted' => 0, 'customer_deleted' => 1,
]]);
check(substr_count($bewertungGeloescht, 'Konto gelöscht') === 1,
    'der geloeschte Kunde ist nicht gekennzeichnet - oder der Guide faelschlich mit');
// Und die Bewertung bleibt entfernbar: Sie steht ja weiterhin oeffentlich.
check(strpos($bewertungGeloescht, 'adm-review-remove') !== false,
    'eine Bewertung mit geloeschtem Kunden laesst sich nicht mehr entfernen');
ok('bei den Bewertungen entscheidet der Guide ueber das Ausblenden, der Kunde ueber die Marke');

// --- Die Benutzerliste: hier fehlte die Gegenrichtung --------------------
//
// Sie zeigte geloeschte Konten nicht ungefragt - sie zeigte sie NIE.
// User::getAll() filterte sie fest heraus, und damit war ein geloeschtes
// Konto auch fuer den Admin unauffindbar. Auf die Frage "ist das Konto von
// gestern wirklich weg" gab es keine Antwort.
$fake->statements = [];
$u = new User();
$u->getAll();
check(strpos($fake->statements[0]->sql, 'user.deleted = 0') !== false,
    'die Benutzerliste zeigt geloeschte Konten ungefragt');
$fake->statements = [];
$u->getAll(true);
check(strpos($fake->statements[0]->sql, 'deleted') === false,
    'der Schalter blendet die geloeschten Konten nicht ein');
// Ueber DENSELBEN Baustein wie ueberall - nicht mit einem eigenen Literal.
check(strpos(methodenRumpf(stripPhpNoise(file_get_contents($ROOT . '/class/Model/User.php')),
    'getAll'), 'self::activeSql') !== false,
    'die Benutzerliste schreibt das Kennzeichen ein zweites Mal aus');

$userCode = stripPhpNoise(file_get_contents($ROOT . '/class/Controller/UserController.php'));
$listeRumpf2 = methodenRumpf($userCode, 'listUser');
check(strpos($listeRumpf2, "Request::g('geloescht') === '1'") !== false,
    'die Benutzerliste liest den Schalter nicht aus der Adresszeile');
check(strpos($listeRumpf2, 'geloeschtSchalterHtml') !== false,
    'die Benutzerliste zeigt den Schalter nicht an');
// AN EINEM GELOESCHTEN KONTO GIBT ES NICHTS MEHR ZU TUN: kein Anruf, keine
// Nachricht, kein Bearbeiten, kein zweites Loeschen.
$zeilenRumpf = methodenRumpf($userCode, 'generateUserRows');
check(strpos($zeilenRumpf, '$tmp_user->isGeloescht()') !== false,
    'die Zeile fragt das Kennzeichen nicht ab');
check(substr_count($zeilenRumpf, '$ist_geloescht') >= 4,
    'die Zeile wertet das Kennzeichen nicht an allen Stellen aus '
    . '(Aktionen, Status, Anruf, Nachricht)');
ok('die Benutzerliste blendet geloeschte Konten ein statt sie zu verschweigen');

// --- Die Anfragenliste bekommt bewusst keinen ----------------------------
//
// Ihre Vorraete sind Vorgaenge zwischen zwei Konten, und der Weg zum
// Abarbeiten fuehrt ueber ein Gespraech mit dem Guide - mit einem geloeschten
// Konto gibt es keines. Was dort stehenbliebe, waere eine Aufgabe, die
// niemand mehr erledigen kann.
$anfragenRumpf = methodenRumpf($ctrlQuelle, 'showRequests');
check(strpos($anfragenRumpf, 'geloeschtSchalterHtml') === false,
    'die Anfragenliste bekommt einen Schalter, den sie nicht braucht');
check(strpos(file_get_contents($ROOT . '/assets/html/admin_requests.html'), '###GELOESCHT###') === false,
    'die Anfragenvorlage haelt einen Platz fuer den Schalter frei');
// Die drei anderen halten ihn frei - sonst bliebe der Platzhalter im
// Dokument stehen.
foreach (['admin_locations', 'admin_reviews', 'list_user'] as $vorlage) {
    check(strpos(file_get_contents($ROOT . "/assets/html/$vorlage.html"), '###GELOESCHT###') !== false,
        "der Vorlage $vorlage fehlt der Platz fuer den Schalter");
}
ok('drei Listen mit Schalter, eine ohne - und jede Vorlage haelt genau den Platz frei, den sie braucht');








PdoConnect::$connection = new FakeConnection();

fwrite(STDERR, "\n$passed Pruefungen bestanden.\n");
