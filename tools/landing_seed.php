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
 * JE SPRACHE EINEN GUIDE mit vier Fuehrungen, Bewertungen und einer
 * Anfragenliste, dazu die Kunden, von denen die Anfragen kommen.
 *
 * ZWEI GUIDES, WEIL ES ZWEI SPRACHFASSUNGEN DER LANDINGPAGE GIBT. Was ein
 * Guide schreibt, uebersetzt die Anwendung nicht - zu Recht: Es ist sein
 * Text und nicht ihrer. Fuer die AUFNAHMEN heisst das trotzdem, dass auf
 * der englischen Fassung sonst deutsche Titel und Beschreibungen staenden,
 * und das ist auf einer Werbeseite ein Fehler. Also gibt es hier zwei
 * Guides in derselben Stadt: einer schreibt deutsch, einer englisch, und
 * tools/landing_shots.js nimmt je Sprache den passenden auf.
 *
 * DAS IST EINE EIGENSCHAFT DER DEMODATEN UND KEINE DER ANWENDUNG. Sie
 * bekommt dadurch keine mehrsprachigen Nutzerinhalte; es sind schlicht zwei
 * Konten, die verschiedene Sprachen sprechen - so wie es auf einer echten
 * Plattform auch waere.
 *
 * VIER FUEHRUNGEN JE GUIDE UND NICHT EINE: Auf der Anfragenliste stand
 * sonst fuenfmal derselbe Titel untereinander, und das sieht nach
 * Testdaten aus.
 *
 * Dazu je Guide:
 *   - eine OFFENE Anfrage. Sie zeigt, dass ein Guide etwas zu entscheiden
 *     hat - "Annehmen" und "Ablehnen" stehen darunter.
 *   - eine ANGENOMMENE Anfrage des anrufenden Kunden. Ohne sie kommt kein
 *     Anruf zustande: Der Server laesst eine Fuehrung nur zu, wenn eine
 *     Zusage vorliegt (App\Controller\WebRTCController).
 *   - fuenf abgeschlossene Fuehrungen mit Bewertung, ueber die Standorte
 *     verteilt. Drei davon liegen am ERSTEN Standort - er ist der, den die
 *     Aufnahme der Standortseite zeigt, und ohne Bewertungen stuende dort
 *     "Noch keine Bewertung".
 *   - Bereitschaft, damit der Anruf durchgeht.
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
$eigene  = "'mara_l','nora_w','gast_anna','sam_t','jonas_p','keiko_k',"
         . "'youssef_m','tobias_n','ellen_b','marek_s'";
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
// DIE KUNDEN. Sie fragen an und bewerten.
//
// 'gast_anna' und 'sam_t' sind die, mit denen tools/landing_shots.js anruft -
// EINER JE SPRACHE, und das ist kein Schmuck:
//
// Der Durchgang beendet die Fuehrung, die er aufgenommen hat. Danach fragt
// die Anwendung den Kunden nach einer Bewertung - zu Recht, so ist sie
// gebaut. Waere es in beiden Durchgaengen derselbe Kunde, laege diese Frage
// im ZWEITEN Durchgang als Karte quer ueber der Standortseite, und zwar mit
// dem Titel der Fuehrung aus dem ersten: auf der englischen Aufnahme ein
// deutscher Satz.
//
// Die uebrigen sind Namen unter Bewertungen und offenen Anfragen; sie melden
// sich nie an.
// ---------------------------------------------------------------------------
$kunden = [
    'gast_anna' => 'anna@example.org',
    'sam_t'     => 'sam@example.org',
    'jonas_p'   => 'jonas@example.org',
    'keiko_k'   => 'keiko@example.org',
    'youssef_m' => 'youssef@example.org',
    'tobias_n'  => 'tobias@example.org',
    'ellen_b'   => 'ellen@example.org',
    'marek_s'   => 'marek@example.org',
];

$kundenIds = [];
foreach ($kunden as $name => $mail) {
    $kundenIds[$name] = seed_konto($pdo, $name, $mail, 1, $hash);
}

// ---------------------------------------------------------------------------
// DIE BEIDEN GUIDES - einer je Sprachfassung der Landingpage.
//
// BEIDE IN DERSELBEN STADT, und zwar in der, die auf dem Kamerabild zu sehen
// ist (LP_VIDEO in tools/landing_shots.js): eine deutsche Fachwerkaltstadt
// in der Daemmerung. Auf der Landingpage stehen Standortseite und Anruf
// nebeneinander und sollen dieselbe Fuehrung zeigen.
//
// WER EIGENE INHALTE EINSETZT, TAUSCHT BEIDES ZUSAMMEN: Bild und Standort
// gehoeren zusammen, und das faellt nur auf, wenn man es aufschreibt.
//
// DIE REIHENFOLGE DER FUEHRUNGEN IST NICHT BELIEBIG: Die erste ist die, die
// auf der Standortseite aufgenommen wird - sie bekommt unten die meisten
// Bewertungen.
// ---------------------------------------------------------------------------
$stadt = ['Quedlinburg', 'DE'];

$guides = [
    'de' => [
        'name'  => 'mara_l',
        'mail'  => 'mara@example.org',
        'kunde' => 'gast_anna',
        'zeige' => 'Mara L.',
        'ueber' => 'Ich wohne seit zwölf Jahren zwei Gassen weiter und kenne hier jeden Türsturz. Am liebsten gehe ich los, wenn die Laternen angehen.',
        'sprachen' => 'de,en',
        'zone'  => 'Europe/Berlin',
        'orte'  => [
            [
                'lat' => 51.78780000, 'lon' => 11.14140000, 'dauer' => 45,
                'titel' => 'Durch die Fachwerkgassen zur Dämmerung',
                'kurz'  => 'Die Kopfsteingasse hinunter bis zum Torturm, wenn die Laternen angehen.',
                'text'  => 'Wir gehen die Kopfsteingasse hinunter bis zum Torturm. Unterwegs: schiefe Giebel aus fünf Jahrhunderten, die Apotheke mit dem alten Schild, und die Bank vor dem Café, auf der abends immer dieselben zwei sitzen.',
            ],
            [
                'lat' => 51.78920000, 'lon' => 11.13760000, 'dauer' => 30,
                'titel' => 'Markttag: zwischen den Ständen',
                'kurz'  => 'Der Markt am Samstagmorgen, bevor das Brot alle ist.',
                'text'  => 'Samstags ab acht. Wir gehen die Reihen ab, ich frage für Sie nach, und Sie sehen, was hier wirklich auf den Tisch kommt. Am Ende bleibt Zeit für den Bäcker in der Ecke – aber nur, wenn wir früh genug dort sind.',
            ],
            [
                'lat' => 51.78560000, 'lon' => 11.13820000, 'dauer' => 60,
                'titel' => 'Vom Schlossberg hinunter',
                'kurz'  => 'Oben die Aussicht, unten die Höfe, dazwischen 200 Stufen.',
                'text'  => 'Wir fangen oben an, wo man über alle Dächer sieht, und arbeiten uns hinunter. Der Weg führt durch drei Höfe, die man von der Straße aus nicht vermutet, und endet an der Stelle, an der die Stadtmauer einfach im Garten von jemandem weitergeht.',
            ],
            [
                'lat' => 51.79040000, 'lon' => 11.14520000, 'dauer' => 40,
                'titel' => 'Die Höfe hinter der Hauptstraße',
                'kurz'  => 'Was hinter den Toren liegt, an denen alle vorbeigehen.',
                'text'  => 'Fünf Minuten von der Hauptstraße und trotzdem eine andere Stadt: Werkstätten, Wäscheleinen, ein Hof mit einem Feigenbaum, der hier eigentlich nicht wachsen dürfte. Ich klingle nicht, aber ich weiß, welche Tore offen stehen.',
            ],
        ],
    ],

    'en' => [
        'name'  => 'nora_w',
        'mail'  => 'nora@example.org',
        'kunde' => 'sam_t',
        'zeige' => 'Nora W.',
        'ueber' => 'I moved here from Bristol eight years ago and never quite left. I walk these lanes most evenings anyway – you may as well come along.',
        'sprachen' => 'en,de',
        'zone'  => 'Europe/Berlin',
        'orte'  => [
            [
                'lat' => 51.78810000, 'lon' => 11.14210000, 'dauer' => 45,
                'titel' => 'Timber-framed lanes at dusk',
                'kurz'  => 'Down the cobbled lane to the gate tower, just as the lamps come on.',
                'text'  => 'We walk the cobbled lane down to the gate tower. On the way: crooked gables from five centuries, the pharmacy with the old sign, and the bench outside the café where the same two men sit every evening.',
            ],
            [
                'lat' => 51.78950000, 'lon' => 11.13710000, 'dauer' => 30,
                'titel' => 'Market morning',
                'kurz'  => 'The Saturday market, before the good bread runs out.',
                'text'  => 'Saturdays from eight. We walk the rows, I ask the questions for you, and you see what people here actually cook. There is time for the baker in the corner at the end – but only if we get there early.',
            ],
            [
                'lat' => 51.78600000, 'lon' => 11.13900000, 'dauer' => 60,
                'titel' => 'Down from the castle hill',
                'kurz'  => 'The view at the top, the courtyards below, 200 steps in between.',
                'text'  => 'We start up where you can see across every roof and work our way down. The route goes through three courtyards you would never guess at from the street, and ends where the old town wall simply carries on through somebody\'s garden.',
            ],
            [
                'lat' => 51.79010000, 'lon' => 11.14480000, 'dauer' => 40,
                'titel' => 'Behind the gates on the main street',
                'kurz'  => 'What sits behind the doors everyone walks straight past.',
                'text'  => 'Five minutes off the main street and it is a different town: workshops, washing lines, a courtyard with a fig tree that has no business growing here. I do not ring any bells, but I know which gates stand open.',
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// DIE ANFRAGEN JE GUIDE.
//
// 'ort' ist der Platz in der Liste oben (0 = die erste Fuehrung). DREI der
// abgeschlossenen liegen am ersten Standort: Er ist der, den die Aufnahme
// der Standortseite zeigt, und dort soll eine Durchschnittsnote stehen und
// nicht "Noch keine Bewertung".
//
// DIE REIHENFOLGE AUF DER SEITE MACHT NICHT DIESE LISTE, sondern
// App\Model\TourRequest::sortierung(): offen zuerst, dann angenommen, dann
// die abgeschlossenen von neu nach alt. Dass oben vier verschiedene Titel
// stehen, ist deshalb kein Zufall, sondern ueber die Tage gesteuert.
// ---------------------------------------------------------------------------
$anfragen = [
    // Offen - hier ist etwas zu entscheiden.
    ['ort' => 1, 'kunde' => 'jonas_p', 'status' => 'open', 'in_stunden' => 3],

    // Angenommen - daran haengt der Anruf des Aufnahmewerkzeugs. Der Kunde
    // steht als null, weil er von der SPRACHE abhaengt: Er wird unten aus
    // $g['kunde'] eingesetzt (siehe der Kommentar bei den Kunden oben).
    ['ort' => 0, 'kunde' => null, 'status' => 'accepted', 'in_stunden' => 0],

    // Abgeschlossen, mit Bewertung. 'tage' ist, wie lange es her ist.
    ['ort' => 2, 'kunde' => 'keiko_k',   'status' => 'done', 'tage' => 3,  'sterne' => 5,
     'de' => 'Zweihundert Stufen und keine davon zu viel. Ich habe unterwegs dreimal gesagt "warten Sie, was ist das da" – und jedes Mal kam eine Geschichte.',
     'en' => 'Two hundred steps and not one too many. Three times I said "wait, what is that" – and every time there was a story.'],

    ['ort' => 3, 'kunde' => 'youssef_m', 'status' => 'done', 'tage' => 9,  'sterne' => 5,
     'de' => 'Der Hof mit dem Feigenbaum. Ich hätte nie gedacht, dass so etwas hinter diesen Toren liegt.',
     'en' => 'The courtyard with the fig tree. I would never have guessed that was behind those gates.'],

    ['ort' => 0, 'kunde' => 'tobias_n',  'status' => 'done', 'tage' => 21, 'sterne' => 5,
     'de' => 'Sie ist stehengeblieben, wo ich stehenbleiben wollte. Die Stunde ging viel zu schnell vorbei.',
     'en' => 'She stopped wherever I wanted to stop. The hour went by far too quickly.'],

    ['ort' => 0, 'kunde' => 'ellen_b',   'status' => 'done', 'tage' => 38, 'sterne' => 4,
     'de' => 'Die Verbindung hat einmal gehakt, sonst nichts zu bemängeln.',
     'en' => 'The connection stuttered once, otherwise nothing to complain about.'],

    ['ort' => 0, 'kunde' => 'marek_s',   'status' => 'done', 'tage' => 54, 'sterne' => 5,
     'de' => 'Ich hatte nach dem Torturm gefragt und bekam den Weg dorthin, den kein Reiseführer aufschreibt.',
     'en' => 'I asked about the gate tower and got the way there that no guidebook writes down.'],
];

// ---------------------------------------------------------------------------
// Anlegen. Erst aufraeumen, damit ein zweiter Lauf nichts verdoppelt - die
// Bewertungen zuerst, denn sie haengen an den Anfragen.
// ---------------------------------------------------------------------------
$pdo->prepare("DELETE FROM tour_review WHERE guide_user_id IN
                 (SELECT id FROM user WHERE username IN ($eigene))")->execute();
$pdo->prepare("DELETE FROM tour_request WHERE guide_user_id IN
                 (SELECT id FROM user WHERE username IN ($eigene))")->execute();

$stadtId  = seed_stadt($pdo, $stadt[0], $stadt[1]);
$kennung  = [];

foreach ($guides as $sprache => $g) {
    $uid = seed_konto($pdo, $g['name'], $g['mail'], 2, $hash);

    $pdo->prepare(
        "REPLACE INTO guide_profile
            (user_id, display_name, about, languages, guide_since, joined_at,
             terms_version, terms_accepted_at)
         VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 400 DAY),
                 DATE_SUB(NOW(), INTERVAL 430 DAY), 1, NOW())"
    )->execute([$uid, $g['zeige'], $g['ueber'], $g['sprachen']]);

    // DIE STANDORTE. Angelegt oder aufgefrischt - nicht geloescht und neu:
    // An einem Standort haengen Bewertungen und Anfragen, und eine neue
    // Kennung liesse ihre Fremdschluessel ins Leere zeigen.
    //
    // Zugeordnet wird ueber die REIHENFOLGE: Der n-te vorhandene Standort
    // dieses Guides bekommt den n-ten Eintrag aus der Liste oben.
    $vorhanden = $pdo->prepare("SELECT id FROM location WHERE user_id = ? ORDER BY id");
    $vorhanden->execute([$uid]);
    $alteIds = $vorhanden->fetchAll(PDO::FETCH_COLUMN);

    $ortIds = [];
    foreach ($g['orte'] as $i => $ort) {
        if (isset($alteIds[$i])) {
            $pdo->prepare(
                "UPDATE location
                    SET city_id = ?, latitude = ?, longitude = ?, description = ?,
                        title = ?, description_long = ?, duration_minutes = ?,
                        languages = ?, timezone = ?
                  WHERE id = ?"
            )->execute([$stadtId, $ort['lat'], $ort['lon'], $ort['kurz'], $ort['titel'],
                        $ort['text'], $ort['dauer'], $g['sprachen'], $g['zone'],
                        $alteIds[$i]]);
            $ortIds[] = (int)$alteIds[$i];
            continue;
        }

        $pdo->prepare(
            "INSERT INTO location
                (user_id, city_id, latitude, longitude, description, title,
                 description_long, duration_minutes, languages, timezone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$uid, $stadtId, $ort['lat'], $ort['lon'], $ort['kurz'],
                    $ort['titel'], $ort['text'], $ort['dauer'], $g['sprachen'],
                    $g['zone']]);
        $ortIds[] = (int)$pdo->lastInsertId();
    }

    // Bereitschaft - sonst weist der Server den Anruf ab.
    $pdo->prepare(
        "UPDATE user
            SET available_until = DATE_ADD(NOW(), INTERVAL 2 HOUR),
                user_status = 'online',
                last_aktive = NOW()
          WHERE id = ?"
    )->execute([$uid]);

    // Die Anfragen und ihre Bewertungen.
    foreach ($anfragen as $a) {
        $ortId = $ortIds[$a['ort']];
        // null heisst "der anrufende Kunde dieser Sprache".
        $kid   = $kundenIds[$a['kunde'] ?? $g['kunde']];

        if ($a['status'] === 'open') {
            $pdo->prepare(
                "INSERT INTO tour_request
                    (location_id, guide_user_id, customer_user_id, status, wish_at,
                     expires_at, created_at)
                 VALUES (?, ?, ?, 'open', DATE_ADD(NOW(), INTERVAL ? HOUR),
                         DATE_ADD(NOW(), INTERVAL ? HOUR), NOW())"
            )->execute([$ortId, $uid, $kid, $a['in_stunden'], $a['in_stunden'] + 1]);
            continue;
        }

        if ($a['status'] === 'accepted') {
            $pdo->prepare(
                "INSERT INTO tour_request
                    (location_id, guide_user_id, customer_user_id, status, wish_at,
                     expires_at, created_at, decided_at)
                 VALUES (?, ?, ?, 'accepted', NOW(),
                         DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW(), NOW())"
            )->execute([$ortId, $uid, $kid]);
            continue;
        }

        // 'done' - abgeschlossen, und dazu die Bewertung des Kunden.
        //
        // DER ZUSTAND HEISST 'done' UND NICHT 'finished'. Die sechs erlaubten
        // Werte stehen als Konstanten in App\Model\TourRequest; jeder andere
        // kommt zwar durch die Datenbank, hat aber keinen Namen im
        // Sprachkatalog und stuende dann als nackter Schluessel auf der Seite.
        $tage = $a['tage'];
        $pdo->prepare(
            "INSERT INTO tour_request
                (location_id, guide_user_id, customer_user_id, status, wish_at,
                 expires_at, created_at, decided_at, started_at, ended_at, closed_at)
             VALUES (?, ?, ?, 'done',
                     DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY),
                     DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY),
                     DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY),
                     DATE_SUB(NOW(), INTERVAL ? DAY))"
        )->execute([$ortId, $uid, $kid, $tage, $tage, $tage, $tage, $tage, $tage, $tage]);

        $pdo->prepare(
            "INSERT INTO tour_review
                (request_id, guide_user_id, customer_user_id, location_id,
                 stars, body, created_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))"
        )->execute([(int)$pdo->lastInsertId(), $uid, $kid, $ortId,
                    $a['sterne'], $a[$sprache], $tage]);
    }

    $kennung[$sprache] = [
        'guideName'   => $g['name'],
        'guideUserId' => $uid,
        'locationId'  => $ortIds[0],
        'kundeName'   => $g['kunde'],
    ];
}

// ---------------------------------------------------------------------------
// DIE KENNUNGEN FUER DAS AUFNAHMEWERKZEUG - je Sprache eine Gruppe.
//
// tools/landing_shots.js braucht Standort- und Benutzerkennung, um den Anruf
// zu starten. Fest eingetragen waeren sie falsch, sobald jemand seine
// Entwicklungsdatenbank neu aufsetzt - die Kennungen zaehlen dann anders
// weiter. Deshalb schreibt das Skript, das sie vergibt, sie auch auf.
//
// Die Datei ist Zwischenstand und gehoert nicht ins Repository (.gitignore).
// ---------------------------------------------------------------------------
file_put_contents(__DIR__ . '/landing_seed.json',
                  json_encode($kennung, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo "Demodaten stehen.\n";
foreach ($kennung as $sprache => $k) {
    echo "  $sprache: " . $k['guideName'] . " (Konto " . $k['guideUserId']
       . ", Standort " . $k['locationId'] . ")\n";
}
foreach ($kennung as $sprache => $k) {
    echo "  Kunde $sprache: " . $k['kundeName'] . "\n";
}
echo "  Passwort: Demo!2345\n";
echo "  Kennungen fuer die Aufnahmen: tools/landing_seed.json\n";
