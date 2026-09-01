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

use App\Model\IceServerConfig;
use App\Model\PdoConnect;
use App\Model\WebRTCHandler;
use App\Controller\TurnController;

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

fwrite(STDERR, "\n$passed Pruefungen bestanden.\n");
