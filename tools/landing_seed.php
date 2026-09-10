<?php
/**
 * Demodaten fuer die Aufnahmen der Landingpage.
 *
 * WOZU DAS HIERHER GEHOERT UND NICHT IN EINE MIGRATION
 * ----------------------------------------------------
 * Die Bilder auf der Landingpage sind Aufnahmen aus der laufenden Anwendung
 * (tools/landing_shots.js). Eine Anwendung, in der nichts drinsteht, sieht
 * auf einer Aufnahme aus wie eine Anwendung, die niemand benutzt - es braucht
 * also Daten. Diese Daten gehoeren aber NICHT in eine Migration: Sie sind
 * erfunden, und eine Migration laeuft auf dem Produktivsystem.
 *
 * Deshalb ein eigenes Skript, das man von Hand aufruft, und deshalb die
 * Sicherung weiter unten: Es weigert sich, in eine Datenbank zu schreiben, in
 * der schon echte Konten stehen.
 *
 * AUFRUF
 * ------
 *     php tools/landing_seed.php
 *
 * Es ist WIEDERHOLBAR: Ein zweiter Aufruf legt nichts doppelt an, sondern
 * frischt die vorhandenen Zeilen auf (die Anfrage bekommt einen neuen
 * Zeitpunkt, die Bereitschaft des Guides eine neue Frist). Genau das braucht
 * man, wenn eine Aufnahme misslungen ist.
 *
 * WAS ES ANLEGT
 * -------------
 *   - vier Guide-Konten mit Profil und je einem Standort,
 *   - ein Kundenkonto,
 *   - eine ANGENOMMENE Anfrage des Kunden an den ersten Guide. Ohne sie
 *     kommt kein Anruf zustande: Der Server laesst eine Fuehrung nur zu,
 *     wenn eine Zusage vorliegt (App\Controller\WebRTCController).
 *   - Bereitschaft und Bewertungen, damit die Standortseite nicht leer
 *     aussieht.
 *
 * Das Passwort aller Konten ist 'Demo!2345' und steht auch in
 * tools/landing_shots.js (LP_PW).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/env.php';

use App\Model\PdoConnect;

// Dieselbe Verbindung wie die Anwendung: Zugangsdaten aus der .env, ein
// Aufbau, eine Fehlerbehandlung. Ein eigenes new PDO() hier waere eine
// zweite Stelle, an der die Zugangsdaten stehen.
PdoConnect::sicherstellen();
$pdo = PdoConnect::$connection;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------------------------------------------------------------------
// DIE SICHERUNG. Erfundene Konten in einer Datenbank mit echten Nutzern waeren
// ein Schaden, den niemand rueckgaengig macht - also wird gar nicht erst
// geschrieben.
//
// Das Kriterium ist absichtlich grob: Steht dort mehr als die Handvoll
// Konten, die dieses Skript selbst anlegt, ist es keine Spielwiese mehr.
// ---------------------------------------------------------------------------
$eigene  = "'mara_l','tobias_n','keiko_k','youssef_m','gast_anna'";
$fremde  = (int)$pdo->query("SELECT COUNT(*) FROM user WHERE username NOT IN ($eigene)")
                    ->fetchColumn();

if ($fremde > 0) {
    fwrite(STDERR,
        "Abbruch: In dieser Datenbank stehen $fremde Konten, die dieses Skript nicht angelegt hat.\n"
      . "Demodaten gehoeren in eine leere Entwicklungsdatenbank und nirgendwo sonst.\n");
    exit(1);
}

/**
 * Verschluesselt ein Passwort so, wie App\Model\User::pwdEncrypt es tut.
 *
 * NACHGEBAUT UND NICHT AUFGERUFEN: pwdEncrypt() ist eine Methode am Objekt
 * User, und ein User-Objekt gibt es hier noch nicht - die Konten entstehen
 * ja gerade erst. Die eine Zeile, um die es geht (HMAC mit dem Pepper, dann
 * Argon2i), steht deshalb hier ein zweites Mal. Aendert sie sich dort,
 * aendert sie sich hier: Dieses Skript ist Werkzeug, kein Produktivcode.
 *
 * @param string $in_pw
 * @return string
 */
function seed_pwd(string $in_pw): string
{
    $pepper = $_ENV['PEPPER'] ?? '';
    if ($pepper === '') {
        fwrite(STDERR, "Abbruch: PEPPER ist nicht gesetzt (.env).\n");
        exit(1);
    }
    return password_hash(hash_hmac('sha256', $in_pw, $pepper), PASSWORD_ARGON2I);
}

/**
 * Legt eine Stadt an oder gibt die vorhandene zurueck.
 *
 * @param PDO    $in_pdo
 * @param string $in_name
 * @param string $in_iso2
 * @return int
 */
function seed_stadt(PDO $in_pdo, string $in_name, string $in_iso2): int
{
    $land = $in_pdo->prepare("SELECT id FROM country WHERE iso2 = ?");
    $land->execute([$in_iso2]);
    $landId = (int)$land->fetchColumn();

    $vorhanden = $in_pdo->prepare("SELECT id FROM city WHERE city_name = ? AND country_id = ?");
    $vorhanden->execute([$in_name, $landId]);
    $id = (int)$vorhanden->fetchColumn();
    if ($id > 0) return $id;

    $in_pdo->prepare("INSERT INTO city (city_name, country_id) VALUES (?, ?)")
           ->execute([$in_name, $landId]);
    return (int)$in_pdo->lastInsertId();
}

/**
 * Legt ein Konto an oder gibt das vorhandene zurueck.
 *
 * @param PDO    $in_pdo
 * @param string $in_name
 * @param string $in_mail
 * @param int    $in_typ  Rollennummer aus App\Helper\Role
 * @param string $in_hash
 * @return int
 */
function seed_konto(PDO $in_pdo, string $in_name, string $in_mail, int $in_typ, string $in_hash): int
{
    $vorhanden = $in_pdo->prepare("SELECT id FROM user WHERE username = ?");
    $vorhanden->execute([$in_name]);
    $id = (int)$vorhanden->fetchColumn();
    if ($id > 0) return $id;

    $in_pdo->prepare(
        "INSERT INTO user (username, email, pwd, status, type_id, email_verified, created_at)
         VALUES (?, ?, ?, 1, ?, 1, NOW())"
    )->execute([$in_name, $in_mail, $in_hash, $in_typ]);

    return (int)$in_pdo->lastInsertId();
}

$hash = seed_pwd('Demo!2345');

// ---------------------------------------------------------------------------
// Die Guides und ihre Standorte.
//
// VIER UND NICHT EINER: Auf der Aufnahme der Anfragenliste und in der Karte
// soll etwas los sein. Ein einziger Eintrag sieht aus wie ein Testsystem.
// ---------------------------------------------------------------------------
$guides = [
    [
        'name'  => 'mara_l',
        'mail'  => 'mara@example.org',
        'zeige' => 'Mara L.',
        'ueber' => 'Ich lebe seit zwölf Jahren in der Alfama und kenne jede Treppe. Am liebsten zeige ich die Gassen, in denen noch Wäsche hängt.',
        'sprachen' => 'de,en,pt',
        'stadt' => ['Lissabon', 'PT'],
        'zone'  => 'Europe/Lisbon',
        'lat'   => 38.71150000, 'lon' => -9.12750000,
        'titel' => 'Alfama – die Gassen über dem Fluss',
        'text'  => 'Wir starten am Miradouro de Santa Luzia und gehen die Treppen hinunter bis zum Fischmarkt. Unterwegs: Wäscheleinen, Kacheln, Katzen, und der eine Laden, in dem es seit 1940 nur Konserven gibt.',
        'kurz'  => 'Von der Aussicht hinunter zum Fischmarkt, durch die engsten Gassen der Stadt.',
        'dauer' => 45,
    ],
    [
        'name'  => 'tobias_n',
        'mail'  => 'tobias@example.org',
        'zeige' => 'Tobias N.',
        'ueber' => 'Neapel ist laut, und das ist der Punkt. Ich gehe mit Ihnen dorthin, wo die Stadt arbeitet.',
        'sprachen' => 'de,en,it',
        'stadt' => ['Neapel', 'IT'],
        'zone'  => 'Europe/Rome',
        'lat'   => 40.85100000, 'lon' => 14.25700000,
        'titel' => 'Spaccanapoli von oben nach unten',
        'text'  => 'Die Gerade, die die Altstadt teilt. Krippenfiguren, Pizza fritta, und die Seitengassen, in die sonst niemand abbiegt.',
        'kurz'  => 'Die Gerade, die die Altstadt teilt – und die Gassen daneben.',
        'dauer' => 60,
    ],
    [
        'name'  => 'keiko_k',
        'mail'  => 'keiko@example.org',
        'zeige' => 'Keiko K.',
        'ueber' => 'Ich zeige Kyoto abseits der Tempelrouten: Werkstätten, kleine Märkte, den Fluss am Morgen.',
        'sprachen' => 'en',
        'stadt' => ['Kyoto', 'JP'],
        'zone'  => 'Asia/Tokyo',
        'lat'   => 35.00400000, 'lon' => 135.76800000,
        'titel' => 'Nishiki-Markt am Morgen',
        'text'  => 'Bevor die Reisegruppen kommen: Fischhändler, eingelegtes Gemüse, der Messerschleifer an der Ecke.',
        'kurz'  => 'Der Markt, bevor die Reisegruppen kommen.',
        'dauer' => 30,
    ],
    [
        'name'  => 'youssef_m',
        'mail'  => 'youssef@example.org',
        'zeige' => 'Youssef M.',
        'ueber' => 'Die Souks von innen. Ich übersetze, was gerufen wird, und erkläre, was gehandelt wird.',
        'sprachen' => 'en,fr',
        'stadt' => ['Marrakesch', 'MA'],
        'zone'  => 'Africa/Casablanca',
        'lat'   => 31.62600000, 'lon' => -7.98900000,
        'titel' => 'Souk el Attarine',
        'text'  => 'Gewürze, Leder, Messing – und der Weg zurück, den ohne Begleitung niemand findet.',
        'kurz'  => 'Gewürze, Leder, Messing – und der Weg zurück.',
        'dauer' => 40,
    ],
];

$guideIds = [];
$standortIds = [];

foreach ($guides as $g) {
    $uid = seed_konto($pdo, $g['name'], $g['mail'], 2, $hash);
    $guideIds[$g['name']] = $uid;

    $pdo->prepare(
        "REPLACE INTO guide_profile
            (user_id, display_name, about, languages, guide_since, joined_at,
             terms_version, terms_accepted_at)
         VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 400 DAY),
                 DATE_SUB(NOW(), INTERVAL 430 DAY), 1, NOW())"
    )->execute([$uid, $g['zeige'], $g['ueber'], $g['sprachen']]);

    $stadtId = seed_stadt($pdo, $g['stadt'][0], $g['stadt'][1]);

    $vorhanden = $pdo->prepare("SELECT id FROM location WHERE user_id = ? LIMIT 1");
    $vorhanden->execute([$uid]);
    $lid = (int)$vorhanden->fetchColumn();

    if ($lid === 0) {
        $pdo->prepare(
            "INSERT INTO location
                (user_id, city_id, latitude, longitude, description, title,
                 description_long, duration_minutes, languages, timezone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$uid, $stadtId, $g['lat'], $g['lon'], $g['kurz'], $g['titel'],
                    $g['text'], $g['dauer'], $g['sprachen'], $g['zone']]);
        $lid = (int)$pdo->lastInsertId();
    }

    $standortIds[$g['name']] = $lid;
}

// Der erste Guide ist bereit - sonst weist der Server den Anruf ab.
$pdo->prepare(
    "UPDATE user
        SET available_until = DATE_ADD(NOW(), INTERVAL 2 HOUR),
            user_status = 'online',
            last_aktive = NOW()
      WHERE id = ?"
)->execute([$guideIds['mara_l']]);

// ---------------------------------------------------------------------------
// Der Kunde und seine Anfragen.
//
// EINE ANGENOMMENE fuer den Anruf (ohne Zusage kommt keine Fuehrung zustande)
// und EINE OFFENE, damit auf der Anfragenliste des Guides etwas zu entscheiden
// ist - das ist die Aufnahme guide-anfragen.png.
// ---------------------------------------------------------------------------
$kundeId = seed_konto($pdo, 'gast_anna', 'anna@example.org', 1, $hash);

// Beim zweiten Lauf nicht doppelt: Erst die Anfragen dieses Skripts weg,
// dann neu. Die Bewertungen haengen per Fremdschluessel daran und gehen
// dabei mit - deshalb wird ihre Tabelle zuerst geleert.
$pdo->prepare("DELETE FROM tour_review WHERE location_id IN
                 (SELECT id FROM location WHERE user_id IN
                    (SELECT id FROM user WHERE username IN ($eigene)))")->execute();
$pdo->prepare("DELETE FROM tour_request WHERE customer_user_id IN
                 (SELECT id FROM user WHERE username IN ($eigene))")->execute();

$pdo->prepare(
    "INSERT INTO tour_request
        (location_id, guide_user_id, customer_user_id, status, wish_at,
         expires_at, created_at, decided_at)
     VALUES (?, ?, ?, 'accepted', NOW(), DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW(), NOW())"
)->execute([$standortIds['mara_l'], $guideIds['mara_l'], $kundeId]);

$pdo->prepare(
    "INSERT INTO tour_request
        (location_id, guide_user_id, customer_user_id, status, wish_at,
         expires_at, created_at)
     VALUES (?, ?, ?, 'open', DATE_ADD(NOW(), INTERVAL 3 HOUR),
             DATE_ADD(NOW(), INTERVAL 4 HOUR), NOW())"
)->execute([$standortIds['tobias_n'], $guideIds['tobias_n'], $kundeId]);

// ---------------------------------------------------------------------------
// Bewertungen fuer den Standort, der aufgenommen wird.
//
// OHNE SIE STEHT AUF DER STANDORTSEITE "Noch keine Bewertung" - eine wahre
// Auskunft ueber ein leeres Testsystem und ein schlechtes Bild fuer eine
// Seite, die zeigen soll, wie es aussieht, wenn die Anwendung benutzt wird.
//
// EINE BEWERTUNG HAENGT AN EINER ABGESCHLOSSENEN FUEHRUNG (Fremdschluessel
// auf tour_request), also wird die Fuehrung mit angelegt. Als Kunden treten
// die anderen Guides auf - wer fuehrt, darf auch mitgehen.
//
// DER ZUSTAND HEISST 'done' UND NICHT 'finished'. Die sechs erlaubten Werte
// stehen als Konstanten in App\Model\TourRequest; jeder andere kommt durch
// die Datenbank, hat aber keinen Namen im Sprachkatalog und steht dann als
// nackter Schluessel auf der Seite.
// ---------------------------------------------------------------------------
$bewertungen = [
    ['kunde' => 'keiko_k',   'sterne' => 5, 'tage' => 12,
     'text'  => 'Sie ist stehengeblieben, wo ich stehenbleiben wollte. Die Stunde ging viel zu schnell vorbei.'],
    ['kunde' => 'youssef_m', 'sterne' => 5, 'tage' => 31,
     'text'  => 'Ich hatte nach dem Fischmarkt gefragt und bekam den Weg dorthin, den kein Reiseführer aufschreibt.'],
    ['kunde' => 'tobias_n',  'sterne' => 4, 'tage' => 54,
     'text'  => 'Die Verbindung hat einmal gehakt, sonst nichts zu bemängeln.'],
];

foreach ($bewertungen as $b) {
    $kid = $guideIds[$b['kunde']];

    $pdo->prepare(
        "INSERT INTO tour_request
            (location_id, guide_user_id, customer_user_id, status, wish_at,
             expires_at, created_at, decided_at, started_at, ended_at, closed_at)
         VALUES (?, ?, ?, 'done',
                 DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY),
                 DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY),
                 DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY),
                 DATE_SUB(NOW(), INTERVAL ? DAY))"
    )->execute([$standortIds['mara_l'], $guideIds['mara_l'], $kid,
                $b['tage'], $b['tage'], $b['tage'], $b['tage'],
                $b['tage'], $b['tage'], $b['tage']]);

    $anfrageId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO tour_review
            (request_id, guide_user_id, customer_user_id, location_id,
             stars, body, created_at)
         VALUES (?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))"
    )->execute([$anfrageId, $guideIds['mara_l'], $kid, $standortIds['mara_l'],
                $b['sterne'], $b['text'], $b['tage']]);
}

// ---------------------------------------------------------------------------
// DIE KENNUNGEN FUER DAS AUFNAHMEWERKZEUG.
//
// tools/landing_shots.js braucht Standort- und Benutzerkennung, um den Anruf
// zu starten. Fest eingetragen waeren sie falsch, sobald jemand seine
// Entwicklungsdatenbank neu aufsetzt - die Kennungen zaehlen dann anders
// weiter. Deshalb schreibt das Skript, das sie vergibt, sie auch auf.
//
// Die Datei ist Zwischenstand und gehoert nicht ins Repository (.gitignore).
// ---------------------------------------------------------------------------
$kennungen = [
    'guideUserId' => $guideIds['mara_l'],
    'locationId'  => $standortIds['mara_l'],
    'guideName'   => 'mara_l',
    'kundeName'   => 'gast_anna',
];
file_put_contents(__DIR__ . '/landing_seed.json',
                  json_encode($kennungen, JSON_PRETTY_PRINT) . "\n");

echo "Demodaten stehen.\n";
echo "  Guide:  mara_l    (Konto " . $guideIds['mara_l']
   . ", Standort " . $standortIds['mara_l'] . ")\n";
echo "  Kunde:  gast_anna\n";
echo "  Passwort: Demo!2345\n";
echo "  Kennungen fuer die Aufnahmen: tools/landing_seed.json\n";
