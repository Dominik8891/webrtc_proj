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
require_once $ROOT . '/class/Helper/LocationView.php';
require_once $ROOT . '/class/Model/GuideRole.php';
require_once $ROOT . '/class/Helper/Theme.php';
require_once $ROOT . '/class/Helper/ViewHelper.php';
require_once $ROOT . '/class/Helper/Auth.php';
require_once $ROOT . '/class/Helper/Request.php';
require_once $ROOT . '/class/Helper/Url.php';
require_once $ROOT . '/class/Model/Chat.php';
require_once $ROOT . '/class/Model/ChatMessage.php';
require_once $ROOT . '/class/Controller/ChatController.php';
// Die Guide-Frage und die Stelle, die sie beim Standortformular stellt.
require_once $ROOT . '/class/Controller/GuideController.php';
require_once $ROOT . '/class/Controller/LocationController.php';

use App\Model\IceServerConfig;
use App\Model\PdoConnect;
use App\Model\TourRequest;
use App\Model\WebRTCHandler;
use App\Controller\TurnController;
use App\Controller\WebRTCController;
use App\Controller\UserController;
use App\Controller\LocationController;
use App\Model\Location;
use App\Model\LocationImage;
use App\Helper\ImageStore;
use App\Helper\Languages;
use App\Helper\LocationView;
use App\Model\GuideRole;
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
class FakeStatement {
    public $sql; public $params = [];
    /** Zeilen, die das naechste execute() angeblich getroffen hat. */
    public static $affected = 1;
    public function __construct($sql) { $this->sql = $sql; }
    public function bindParam($k, &$v, $type = null) { $this->params[$k] = $v; }
    public function execute() { return true; }
    public function rowCount() { return self::$affected; }
    public function fetch($mode = null) { return false; }
    public function fetchAll($mode = null) { return []; }
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
$oeffentlich = [Permission::SYSTEM_HOME, Permission::AUTH_LOGIN, Permission::AUTH_SIGNUP,
                Permission::AUTH_PASSWORD_RESET, Permission::AUTH_EMAIL_VERIFY,
                Permission::AUTH_TWOFACTOR_VERIFY, Permission::LOCATION_MAP_PUBLIC,
                Permission::LOCATION_VIEW];
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
$spalten = [];
foreach (explode(',', $t[1]) as $stueck) {
    // Ausgabename ist der letzte Bezeichner des Ausdrucks - mit AS oder ohne.
    if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*$/s', trim($stueck), $n)) {
        $spalten[] = strtolower($n[1]);
    }
}
sort($spalten);
// title steht seit migrations/011 dabei: Das Kartenfenster zeigt die
// Ueberschrift des Angebots statt nur des Ortsnamens. Er ist Inhalt des
// Angebots wie die Beschreibung auch - keine Personenangabe.
$erlaubt = ['availability', 'city_name', 'country_name', 'description', 'id',
            'latitude', 'longitude', 'title'];
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
 * Schneidet einen Regelblock aus der CSS-Datei und liest seine Variablen.
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
    $werte = [];
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', substr($css, $a, $b - $a), $m, PREG_SET_ORDER);
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
// cssBlock() liest nur --variablen; color-scheme ist eine normale
// Eigenschaft und wird deshalb direkt im Text gesucht.
check(preg_match('/\[data-theme="dunkel"\]\s*\{[^}]*color-scheme:\s*dark/', $themeCss) === 1,
    'das Dunkelprofil setzt color-scheme nicht auf dark');
ok('der Browser weiss, welche Grundstimmung gilt');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n21) Fremde Bibliotheken folgen dem Farbprofil\n");

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
fwrite(STDERR, "\n24) Chat: die Beteiligung wird geprueft\n");

/**
 * Attrappe fuer die Chat-Pruefungen.
 *
 * Liefert auf jede Abfrage der Tabelle `chat` dieselbe vorbereitete Zeile
 * und schreibt alles mit, was sonst abgesetzt wird. Damit laesst sich
 * pruefen, was der Controller bei einem unerlaubten Zugriff NICHT tut -
 * und genau darauf kommt es hier an: Eine Fehlermeldung nuetzt nichts,
 * wenn die Nachricht trotzdem in der Datenbank landet.
 */
class ChatAttrappeStatement {
    public $sql; public $params = []; private $zeile;
    public function __construct($sql, $zeile) { $this->sql = $sql; $this->zeile = $zeile; }
    public function bindParam($k, &$v, $type = null) { $this->params[$k] = $v; }
    public function execute($params = null) { if ($params !== null) $this->params = $params; return true; }
    public function fetch($mode = null) { return $this->zeile; }
    public function fetchAll($mode = null) { return []; }
    public function rowCount() { return 1; }
}
class ChatAttrappe {
    public $statements = [];
    /** Zeile, die eine Abfrage auf `chat` liefert; false = gibt es nicht. */
    public $chat = false;
    public function prepare($sql) {
        $zeile = preg_match('/^\s*SELECT\s.*\sFROM\s+chat\s/i', $sql) ? $this->chat : false;
        $s = new ChatAttrappeStatement($sql, $zeile);
        $this->statements[] = $s;
        return $s;
    }
    public function lastInsertId() { return 42; }
    /** Alle abgesetzten Statements, die etwas veraendern. */
    public function schreibend(): array {
        $treffer = [];
        foreach ($this->statements as $s) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\s/i', $s->sql)) $treffer[] = $s;
        }
        return $treffer;
    }
    public function vergessen() { $this->statements = []; }
}

$chatDb = new ChatAttrappe();
PdoConnect::$connection = $chatDb;

// Ein Chat zwischen 2 und 3. Gefragt ist die 3 - sie hat noch nicht
// geantwortet. Die 9 hat mit dem Chat nichts zu tun.
$chatZeile = ['id' => 5, 'user1_id' => 2, 'user2_id' => 3, 'is_active' => 1,
              'last_msg_at' => null, 'pending_for' => 3, 'deleted' => 0];

/** Ruft eine Controller-Methode als Benutzer $wer auf und liest die JSON-Antwort. */
$alsBenutzer = function ($wer, array $anfrage, string $methode) use ($chatDb) {
    $_SESSION = $wer === null ? [] : ['user' => ['user_id' => $wer, 'role_id' => Role::USER]];
    $_REQUEST = $anfrage;
    $chatDb->vergessen();
    ob_start();
    (new ChatController())->$methode();
    return json_decode(ob_get_clean(), true);
};

// --- sendMessage: der Kern des Befundes -----------------------------------
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

// --- acceptChat: annehmen darf nur der Gefragte ---------------------------
// Vorher las diese Methode $_SESSION gar nicht und setzte den Chat ohne
// jeden Datenbankzugriff aktiv.
$antwort = $alsBenutzer(9, ['chat_id' => 5], 'acceptChat');
check($antwort['success'] === false, 'ein Unbeteiligter nimmt an');
check($chatDb->schreibend() === [], 'ein Unbeteiligter aktiviert den Chat');
ok('ein Unbeteiligter nimmt keine fremde Einladung an');

$antwort = $alsBenutzer(2, ['chat_id' => 5], 'acceptChat');
check($antwort['success'] === false, 'der Fragende nimmt seine eigene Einladung an');
check($chatDb->schreibend() === [], 'der Fragende aktiviert den Chat selbst');
ok('wer gefragt hat, beantwortet die Frage nicht selbst');

$antwort = $alsBenutzer(3, ['chat_id' => 5], 'acceptChat');
check($antwort['success'] === true, 'der Gefragte darf annehmen');
$geschrieben = $chatDb->schreibend();
check(count($geschrieben) === 1 && preg_match('/UPDATE chat SET is_active/i', $geschrieben[0]->sql) === 1,
    'der Chat wird nicht aktiv gesetzt');
ok('der Gefragte nimmt an - dieselbe Bedingung wie beim Ablehnen');

// Annehmen und Ablehnen sind dieselbe Entscheidung und pruefen dasselbe.
$antwort = $alsBenutzer(2, ['chat_id' => 5], 'declineChat');
check($antwort['success'] === false, 'der Fragende lehnt seine eigene Einladung ab');
$antwort = $alsBenutzer(9, ['chat_id' => 5], 'declineChat');
check($antwort['success'] === false, 'ein Unbeteiligter lehnt ab');
ok('Annehmen und Ablehnen haengen an derselben Bedingung');

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
preg_match_all('/public function (\w+)\(\).*?(?=\n    public function |\z)/s', $chatCode, $mMethoden, PREG_SET_ORDER);
check(count($mMethoden) >= 9, 'die Methoden des ChatControllers wurden nicht gefunden');
$geprueft = 0;
foreach ($mMethoden as $methode) {
    if (strpos($methode[0], "Request::g('chat_id')") === false) continue;
    $geprueft++;
    check(strpos($methode[0], 'Auth::userId()') !== false,
        "ChatController::{$methode[1]}() nimmt eine chat_id entgegen, fragt aber "
        . 'nicht, wer angemeldet ist');
    check(preg_match('/getUser1Id\(\)|getPendingFor\(\)/', $methode[0]) === 1,
        "ChatController::{$methode[1]}() prueft die Beteiligung nicht");
}
check($geprueft >= 5, "nur $geprueft Methoden mit chat_id gefunden - erwartet werden mindestens 5");
ok("alle $geprueft Methoden mit chat_id aus der Anfrage pruefen die Beteiligung");

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
    $ende = preg_match('/\n    (?:public|private|protected) function /', $rest, $m, PREG_OFFSET_CAPTURE, 10)
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
$ctaAnfang = strpos($seiteKunde, 'class="loc-cta"');
$cta       = substr($seiteKunde, $ctaAnfang, 1200);
check(strpos($cta, 'loc-cta__action') < strpos($cta, 'loc-facts'),
    'die Datenzeilen stehen vor dem Knopf');
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

// Anrufbar ist eine Zusage nur im vereinbarten Fenster - und dann auch nach
// einem Abbruch der Verbindung noch einmal ('done' zaehlt mit).
$callSql = TourRequest::callableSql('r');
check(strpos($callSql, "'accepted'") !== false && strpos($callSql, "'done'") !== false,
    'nach einem Verbindungsabbruch laesst sich nicht zurueckrufen');
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

$fake->statements = [];
TourRequest::markEnded(4, 3);
$sql = $fake->statements[0]->sql;
check(strpos($sql, "status   = 'done'") !== false, 'aus der Fuehrung wird keine durchgefuehrte');
check(strpos($sql, 'r.started_at IS NOT NULL') !== false,
    'ein Anruf, der nie zustande kam, gilt als durchgefuehrte Fuehrung');
// WELCHE SEITE AUFLEGT, IST OFFEN - deshalb das Paar in beide Richtungen.
check(substr_count($sql, 'customer_user_id') === 2 && substr_count($sql, 'guide_user_id') === 2,
    "das Paar wird nur in einer Richtung geprueft: $sql");
check(TourRequest::markEnded(4, 4) === false, 'ein Selbstgespraech schliesst eine Fuehrung ab');
ok('Beginn und Ende der Fuehrung werden im Signaling festgehalten');

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
$sql = $fake->statements[0]->sql;
check(strpos($sql, "status = 'done'") !== false, 'haengende Fuehrungen werden nicht abgeschlossen');
check(strpos($sql, 'ended_at') !== false && strpos($sql, 'ended_at = NOW()') === false,
    'der Cronjob erfindet ein Ende');

// Der Cronjob ruft beides auf - sonst waere es Code ohne Aufrufer.
$cron = file_get_contents($ROOT . '/cron/check_online_status.php');
check(strpos($cron, 'TourRequest::expireDue') !== false, 'der Cronjob raeumt keine Anfragen auf');
check(strpos($cron, 'TourRequest::closeStale') !== false, 'haengende Fuehrungen bleiben stehen');
ok('der Cronjob raeumt auf, ohne ein Ende zu erfinden');

// --- Die Fristen stehen an genau einer Stelle ------------------------------
foreach (['response_timeout', 'wish_grace', 'lead_time_max',
          'call_window_before', 'call_window_after', 'stale_call'] as $schluessel) {
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
    public function fetch($mode = null) {
        if (strpos($this->sql, 'FROM tour_request') !== false) {
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
FakeRequestStatement::$zusage = false;
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

PdoConnect::$connection = new FakeConnection();

fwrite(STDERR, "\n$passed Pruefungen bestanden.\n");
