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
require_once $ROOT . '/class/Helper/Permission.php';
require_once $ROOT . '/class/Controller/WebRTCController.php';
require_once $ROOT . '/class/Controller/UserController.php';
require_once $ROOT . '/class/Model/Location.php';
require_once $ROOT . '/class/Model/GuideRole.php';
require_once $ROOT . '/class/Helper/Theme.php';
require_once $ROOT . '/class/Helper/ViewHelper.php';

use App\Model\IceServerConfig;
use App\Model\PdoConnect;
use App\Model\WebRTCHandler;
use App\Controller\TurnController;
use App\Controller\WebRTCController;
use App\Controller\UserController;
use App\Model\Location;
use App\Model\GuideRole;
use App\Helper\Role;
use App\Helper\Permission;
use App\Helper\Theme;
use App\Helper\ViewHelper;

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
// usertype laut database.sql: 0=Trial, 1=User, 2=Guide, 10=Admin
$userDb->users = [
    1 => fakeUser(1, 10),  // Admin
    2 => fakeUser(2,  2),  // Guide
    3 => fakeUser(3,  2),  // Guide
    4 => fakeUser(4,  1),  // User
    5 => fakeUser(5,  0),  // Trial
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

// Admin ist kein Guide - die beiden Kontotypen duerfen nicht verwechselt
// werden (Befunde F-7/F-8 der Bestandsaufnahme).
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
$oeffentlich = [Permission::SYSTEM_HOME, Permission::AUTH_LOGIN, Permission::AUTH_SIGNUP,
                Permission::AUTH_PASSWORD_RESET, Permission::AUTH_EMAIL_VERIFY,
                Permission::AUTH_TWOFACTOR_VERIFY, Permission::LOCATION_MAP_PUBLIC];
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
// Die Guide-Frage: wer sie beantworten darf und wer die eigene Position
// melden soll.
// ---------------------------------------------------------------------

// Die eigene Position ist nur fuer den von Belang, der Standorte anbietet.
// Ein Zuschauer sucht sich einen Standort auf der Karte aus, er wird nicht
// gefunden - deshalb fragt der Login ihn nicht mehr danach
// (LoginController::continueAfterLogin) und die Route save_location weist ihn
// ab.
foreach ([Role::GUIDE, Role::ADMIN] as $rolle) {
    check(Permission::has($rolle, Permission::USER_POSITION) === true,
        'wer Standorte anbietet, meldet seine Position');
}
foreach ([Role::TRIAL, Role::USER, Permission::GUEST] as $rolle) {
    check(Permission::has($rolle, Permission::USER_POSITION) === false,
        'ein Zuschauer wird nicht nach seiner Position gefragt');
}
check($routes['save_location'][2] === Permission::USER_POSITION,
    'save_location haengt an genau diesem Recht');
ok('nach der Position wird nur gefragt, wer Standorte anbietet');

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
// Beide haben dieselben Rechte - der Unterschied liegt allein darin, wem der
// Dialog nach dem Login gezeigt wird.
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
];
foreach ($vorlagen as $vorlage => $controller) {
    $code = file_get_contents($ROOT . '/' . $controller);
    foreach (platzhalter($ROOT . '/' . $vorlage) as $marke) {
        check(strpos($code, $marke) !== false,
            "$marke aus $vorlage wird in $controller nicht ersetzt");
    }
}
ok('guide_role.html und settings.html haben keinen unbesetzten Platzhalter');

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
$erlaubt = ['availability', 'city_name', 'country_name', 'description', 'id',
            'latitude', 'longitude'];
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
fwrite(STDERR, "\n24) Die Chat-Aufbewahrung hat genau eine Stellschraube\n");

// Chatnachrichten dienen der Absprache vor einer Fuehrung und werden nach
// einer festen Frist geloescht. Die Frist steht an EINER Stelle. Geprueft
// wird hier vor allem, dass sie nirgendwo sonst noch einmal steht - eine
// zweite Zahl faellt erst auf, wenn die erste geaendert wurde und die
// Anzeige etwas anderes verspricht als der Loeschlauf tut.

$retention = require $ROOT . '/config/chat_retention.php';
check(is_array($retention) && array_key_exists('retention_days', $retention),
    'config/chat_retention.php liefert kein Array mit retention_days');
check((int)$retention['retention_days'] === 30,
    'die Vorgabe ist nicht 30 Tage, sondern ' . var_export($retention['retention_days'], true));
ok('die Dauer steht in config/chat_retention.php');

$cronCode = file_get_contents($ROOT . '/cron/cleanup_chat_messages.php');

// Der Lauf holt die Frist aus der Konfiguration und schreibt sie nicht selbst
// hin.
check(strpos($cronCode, "config/chat_retention.php") !== false,
    'der Cronjob liest die Aufbewahrungsdauer nicht aus der Konfiguration');
check(preg_match('/INTERVAL\s+\d+\s+DAY/', $cronCode) === 0,
    'im Cronjob steht eine feste Tageszahl - dann laeuft sie gegen die Konfiguration');
ok('der Loeschlauf nimmt die Dauer aus der Konfiguration');

// Geloescht wird wirklich. Ein Soft-Delete waere keine Loeschung, sondern
// eine Ausblendung - der Zweck der Aufbewahrungsfrist ist aber, dass die
// Daten weg sind.
check(preg_match('/DELETE\s+FROM\s+chat_message/i', $cronCode) === 1,
    'der Cronjob loescht die Nachrichten nicht wirklich');
check(preg_match('/UPDATE\s+chat_message\s+SET\s+deleted/i', $cronCode) === 0,
    'der Cronjob markiert Nachrichten nur als geloescht');
ok('geloescht wird endgueltig, nicht als Markierung');

// Abschaltbar: 0 oder kleiner heisst "unbegrenzt aufbewahren". Dann darf der
// Lauf nichts anfassen.
check(preg_match('/\$days\s*<=\s*0/', $cronCode) === 1,
    'eine abgeschaltete Aufbewahrung (0 oder kleiner) haelt den Loeschlauf nicht auf');
ok('die Aufbewahrung laesst sich abschalten');

// Der Chat bleibt, sein Datum der letzten Nachricht geht mit. Sonst stuende
// in der Uebersicht ein Zeitpunkt, zu dem es nichts mehr zu sehen gibt.
check(preg_match('/UPDATE\s+chat\s+SET\s+last_msg_at\s*=\s*NULL/i', $cronCode) === 1,
    'nach dem Loeschen bleibt last_msg_at stehen und zeigt auf einen leeren Verlauf');
check(preg_match('/DELETE\s+FROM\s+chat\b/i', $cronCode) === 0,
    'der Cronjob loescht ganze Chats - der Chat ist die Verbindung, nicht der Inhalt');
ok('der Chat bleibt, sein Datum wandert mit');

// ---------------------------------------------------------------------
fwrite(STDERR, "\n25) Der Hinweis nennt dieselbe Frist, nach der geloescht wird\n");

// Beide Anzeigen - das Chatfenster im Browser und die Verlaufsseite - holen
// die Zahl vom Server. Stuende sie im Text, versprachen sie nach einer
// Aenderung der Konfiguration etwas anderes als der Loeschlauf tut.
$viewCode = file_get_contents($ROOT . '/class/Helper/ViewHelper.php');
check(strpos($viewCode, 'window.chatRetentionDays') !== false,
    'ViewHelper reicht die Aufbewahrungsdauer nicht ins Frontend');
check(strpos($viewCode, "config/chat_retention.php") !== false,
    'ViewHelper nimmt die Dauer nicht aus der Konfiguration');
ok('das Frontend bekommt die Dauer vom Server');

$uiChatCode = file_get_contents($ROOT . '/assets/js/ui_chat.js');
check(strpos($uiChatCode, 'window.chatRetentionDays') !== false,
    'das Chatfenster liest die Aufbewahrungsdauer nicht');
check(preg_match('/nach 30 Tagen/', $uiChatCode) === 0,
    'im Chatfenster steht die Zahl 30 im Text statt in der Konfiguration');
// Ohne Wert kein Hinweis: Eine angekuendigte Loeschung, die nicht
// stattfindet, ist schlimmer als gar kein Hinweis.
check(preg_match('/if\s*\(!tage\s*\|\|\s*tage\s*<=\s*0\)\s*return\s*\x27\x27/', $uiChatCode) === 1,
    'das Chatfenster zeigt den Hinweis auch bei abgeschalteter Aufbewahrung');
ok('das Chatfenster kuendigt nur an, was auch passiert');

$chatCtrlCode = file_get_contents($ROOT . '/class/Controller/ChatController.php');
check(strpos($chatCtrlCode, '###RETENTION_HINT###') !== false,
    'die Verlaufsseite bekommt keinen Hinweis');
check(strpos($chatCtrlCode, "config/chat_retention.php") !== false,
    'die Verlaufsseite nimmt die Dauer nicht aus der Konfiguration');
check(strpos(file_get_contents($ROOT . '/assets/html/show_chat.html'), '###RETENTION_HINT###') !== false,
    'die Vorlage show_chat.html hat keinen Platz fuer den Hinweis');
ok('die Verlaufsseite nennt dieselbe Frist, ohne JavaScript');

fwrite(STDERR, "\n$passed Pruefungen bestanden.\n");
