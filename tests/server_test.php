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
require_once $ROOT . '/class/Controller/TurnController.php';
require_once $ROOT . '/class/Model/User.php';
require_once $ROOT . '/class/Helper/Role.php';
require_once $ROOT . '/class/Controller/WebRTCController.php';

use App\Model\IceServerConfig;
use App\Model\PdoConnect;
use App\Model\WebRTCHandler;
use App\Controller\TurnController;
use App\Controller\WebRTCController;
use App\Helper\Role;

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
    public function __construct($sql) { $this->sql = $sql; }
    public function bindParam($k, &$v) { $this->params[$k] = $v; }
    public function execute() { return true; }
    public function fetchAll($mode = null) { return []; }
}
class FakeConnection {
    public $statements = [];
    public function prepare($sql) { $s = new FakeStatement($sql); $this->statements[] = $s; return $s; }
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
 * Attrappe, die Benutzer aus einer Tabelle im Speicher liefert. Die
 * Rollenvergabe ist die einzige Serverlogik des Steuerprotokolls; geprueft
 * wird sie damit ohne Datenbank.
 */
class FakeUserStatement {
    public $sql; public $params = []; private $users;
    public function __construct($sql, $users) { $this->sql = $sql; $this->users = $users; }
    public function bindParam($k, &$v) { $this->params[$k] = $v; }
    public function execute() { return true; }
    public function fetch($mode = null) {
        $id = (int)($this->params[':user_id'] ?? 0);
        return $this->users[$id] ?? false;
    }
    public function fetchAll($mode = null) { return []; }
}
class FakeUserConnection {
    public $users = [];
    public function prepare($sql) { return new FakeUserStatement($sql, $this->users); }
}

/** Baut eine Benutzerzeile, wie sie aus der Tabelle user kaeme. */
function fakeUser($id, $typeId) {
    return [
        'id' => $id, 'username' => 'u' . $id, 'email' => 'u' . $id . '@example.org',
        'pwd' => 'x', 'type_id' => $typeId, 'deleted' => 0
    ];
}

$userDb = new FakeUserConnection();
// usertype laut database.sql: 0=Admin, 1=Guide, 2=User, 3=Trial
$userDb->users = [
    1 => fakeUser(1, 0),   // Admin
    2 => fakeUser(2, 1),   // Guide
    3 => fakeUser(3, 1),   // Guide
    4 => fakeUser(4, 2),   // User
    5 => fakeUser(5, 3),   // Trial
];
PdoConnect::$connection = $userDb;

// Regelfall: Der Zuschauer sucht einen Standort und ruft den Guide an.
$r = WebRTCController::callRoles(5, 2);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'Zuschauer ruft Guide an');
ok('Anrufer wird Zuschauer, angerufener Guide wird Guide');

// Umgekehrter Anruf: Der Guide meldet sich beim Zuschauer.
$r = WebRTCController::callRoles(2, 5);
check($r['caller'] === 'guide' && $r['callee'] === 'viewer', 'Guide ruft Zuschauer an');
ok('auch beim Rueckruf bleibt der Guide der Guide');

// Zwei Guides: Es gibt keinen Grund, einen davon zu bevorzugen - also gilt
// die zweite Regel, der Angerufene ist vor Ort.
$r = WebRTCController::callRoles(2, 3);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'zwei Guides');
ok('bei zwei Guides ist der Angerufene der Guide');

// Kein Guide beteiligt: dieselbe zweite Regel, damit die Steuerung ueberhaupt
// funktioniert statt auf beiden Seiten tot zu sein.
$r = WebRTCController::callRoles(4, 5);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'kein Guide beteiligt');
ok('ohne Guide-Konto ist der Angerufene der Guide');

// Admin ist kein Guide - der Kontotyp 0 darf nicht mit 1 verwechselt werden
// (Befunde F-7/F-8 der Bestandsaufnahme).
$r = WebRTCController::callRoles(1, 2);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'Admin ruft Guide an');
ok('Admin gilt nicht als Guide');

// Unbekannter Benutzer darf die Vergabe nicht sprengen.
$r = WebRTCController::callRoles(99, 5);
check($r['caller'] === 'viewer' && $r['callee'] === 'guide', 'unbekannter Anrufer');
ok('unbekannter Benutzer fuehrt zu einer eindeutigen Rolle statt zu einem Fehler');

// Beide Seiten fragen unabhaengig - und muessen zusammenpassen.
foreach ([[5, 2], [2, 5], [2, 3], [4, 5], [1, 2]] as [$caller, $callee]) {
    $a = WebRTCController::roleForCall($caller, $callee, $caller);
    $b = WebRTCController::roleForCall($caller, $callee, $callee);
    check($a !== $b, "Rollen muessen sich unterscheiden ($caller -> $callee)");
    check(in_array($a, ['guide', 'viewer'], true), 'gueltige Rolle fuer den Anrufer');
    check(in_array($b, ['guide', 'viewer'], true), 'gueltige Rolle fuer den Angerufenen');
}
check(WebRTCController::roleForCall(5, 2, 4) === null, 'Unbeteiligter bekommt keine Rolle');
ok('beide Seiten bekommen zueinander passende Rollen, Dritte gar keine');

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

// ---------------------------------------------------------------------
fwrite(STDERR, "\n6) Rollen-Normalisierung (Befunde F-5/F-6)\n");

// Der Kern der beiden Befunde: usertype.name ist gross geschrieben, verglichen
// wurde gegen kleingeschriebene Literale. Der Helfer macht die Schreibweise
// egal.
check(Role::id('Guide')   === Role::GUIDE, "'Guide' ist die Guide-Rolle");
check(Role::id('guide')   === Role::GUIDE, "'guide' ebenso");
check(Role::id('GUIDE')   === Role::GUIDE, "'GUIDE' ebenso");
check(Role::id(' Guide ') === Role::GUIDE, 'Leerzeichen stoeren nicht');
check(Role::id('Admin')   === Role::ADMIN, "'Admin' ist 0 - und 0 ist nicht falsy zu verwechseln");
check(Role::id('Trial')   === Role::TRIAL, "'Trial' ist 3");
ok('Rollennamen werden unabhaengig von der Schreibweise erkannt');

// PDO liefert je nach Treibereinstellung '1' statt 1. Ein === 1 scheitert
// daran still - der Helfer nicht.
check(Role::id(1)   === Role::GUIDE, 'int 1');
check(Role::id('1') === Role::GUIDE, "Zahlenstring '1'");
check(Role::id('0') === Role::ADMIN, "Zahlenstring '0'");
ok('Zahl und Zahlenstring bedeuten dasselbe');

// Alles Unbekannte ist null und darf nirgends als Berechtigung durchgehen.
foreach ([null, '', '   ', 'tourist', 'Tourist', 'viewer', 7, -1, '2.5', [], true] as $bad) {
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
check(Role::isAdmin(0) === true && Role::isAdmin(1) === false, 'isAdmin');
ok('Signaling und Rollenhelfer sind sich ueber die Guide-ID einig');

fwrite(STDERR, "\n$passed Pruefungen bestanden.\n");
