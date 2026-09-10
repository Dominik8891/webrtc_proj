/**
 * Die Aufnahmen fuer die Landingpage - aus der LAUFENDEN Anwendung.
 *
 * WOZU ES DIESE DATEI GIBT
 * ------------------------
 * Die Bilder auf assets/html/landing.html sind Bildschirmfotos und keine
 * nachgebauten Abbildungen. Das ist eine Entscheidung mit Folgen: Eine
 * nachgebaute Abbildung veraltet, ohne dass es jemand merkt - die Oberflaeche
 * aendert sich, das Bild bleibt, und irgendwann zeigt die Werbeseite eine
 * Anwendung, die es nicht mehr gibt. Ein Bildschirmfoto veraltet genauso,
 * ABER es laesst sich neu machen. Diese Datei ist der Weg dorthin.
 *
 * Sie faehrt eine vollstaendige Fuehrung durch - zwei Browser, ein echter
 * WebRTC-Anruf, ein echter Steuerbefehl - und nimmt dabei auf. Was auf den
 * Bildern steht, hat die Anwendung wirklich so ausgegeben.
 *
 * ===========================================================================
 * VORAUSSETZUNGEN
 * ===========================================================================
 *   - Die Anwendung laeuft und ist erreichbar (Vorgabe: 127.0.0.1:8080).
 *   - In der Datenbank stehen die Demodaten, die tools/landing_seed.php
 *     anlegt: ein Guide mit Standort, ein Kunde, eine angenommene Anfrage.
 *     Dasselbe Skript schreibt tools/landing_seed.json, aus dem dieses
 *     Werkzeug die Kennungen liest.
 *   - Node.js mit Playwright (npm i -D playwright).
 *
 * ===========================================================================
 * AUFRUF
 * ===========================================================================
 *     php  tools/landing_seed.php          # Demodaten anlegen
 *     node tools/landing_shots.js          # aufnehmen
 *
 * Die Bilder landen unter assets/img/landing/<sprache>/ und ueberschreiben
 * die vorhandenen. Die Landingpage aendert sich dadurch nicht - sie verweist
 * auf dieselben Dateinamen.
 *
 * ===========================================================================
 * ZWEIMAL, EINMAL JE SPRACHE
 * ===========================================================================
 * Die Landingpage gibt es auf Deutsch und auf Englisch, und die Aufnahmen
 * gehoeren dazu: Auf den Bildern stehen Knopfbeschriftungen ("Fuehrung
 * starten" / "Start tour"), und ein englischer Besucher, der deutsche
 * Knoepfe abgebildet sieht, bekommt genau die Unstimmigkeit, wegen der es
 * den Sprachumschalter gibt.
 *
 * Deshalb laeuft der ganze Durchgang zweimal und legt seine Bilder in
 * assets/img/landing/de/ und assets/img/landing/en/. Die Vorlage waehlt das
 * Verzeichnis ueber ###LANG### (siehe assets/html/landing.html).
 *
 * UMGESTELLT WIRD UEBER DIE ANWENDUNG SELBST - die Route set_lang, also
 * derselbe Weg, den auch ein Besucher nimmt. Ein direkter Schreibzugriff auf
 * user.lang waere schneller und ginge an der Stelle vorbei, die im Betrieb
 * wirklich laeuft.
 *
 * ===========================================================================
 * EINSTELLUNGEN UEBER UMGEBUNGSVARIABLEN
 * ===========================================================================
 *   LP_BASE      Adresse der Anwendung. Vorgabe http://127.0.0.1:8080
 *   LP_PW        Passwort der Demokonten. Vorgabe Demo!2345
 *   LP_VIDEO     Was Chromium ALS KAMERA benutzt. OHNE DIESE ANGABE zeigt
 *                das Kamerabild im Anruf das Testmuster des Browsers (ein
 *                gruener Kreis) - fuer eine Werbeseite unbrauchbar.
 *
 *                EIN EINZELNES FOTO GENUEGT, und das ist der bequeme Teil:
 *                Chromium liest hier auch eine gewoehnliche JPEG-Datei und
 *                haelt sie als Standbild. Ein MJPEG-Strom ist nichts anderes
 *                als aneinandergehaengte JPEGs, und ein einzelnes ist davon
 *                der kuerzeste Fall - eine Umwandlung braucht es nicht:
 *
 *                    LP_VIDEO=$PWD/gasse.jpg node tools/landing_shots.js
 *
 *                Das Bild fuellt die Buehne. Ein Querformat passt deshalb
 *                besser als ein Hochformat, und ein ruhiges Motiv besser als
 *                eines mit Schrift - darauf liegen Steuerkreuz und
 *                Richtungsanzeige.
 *
 *                Wer Bewegung will, gibt eine .y4m-Datei an; die entsteht
 *                aus einem Video mit
 *                    ffmpeg -i gasse.mp4 -t 10 -pix_fmt yuv420p gasse.y4m
 *   LP_OUT       Zielverzeichnis. Vorgabe assets/img/landing
 *
 * ===========================================================================
 * WAS AUFGENOMMEN WIRD
 * ===========================================================================
 *   <sprache>/standortseite.png    Die Seite eines Standorts, aus Kundensicht.
 *   <sprache>/steuerkreuz.png      Die laufende Fuehrung, aus Kundensicht:
 *                                  das Bild des Guides mit dem Steuerkreuz.
 *   <sprache>/richtungsanzeige.png Dieselbe Fuehrung, aus Sicht des Guides,
 *                                  im Moment eines Steuerbefehls.
 *   <sprache>/guide-anfragen.png   Die Anfragenliste des Guides.
 *
 * NICHT UEBERSETZT WIRD, WAS DIE NUTZER GESCHRIEBEN HABEN: Standorttexte und
 * Bewertungen stehen auf beiden Fassungen so da, wie sie in der Datenbank
 * stehen. Das ist keine Nachlaessigkeit, sondern richtig - die Anwendung
 * uebersetzt sie im Betrieb auch nicht.
 *
 * Die Startseite mit der Karte ist NICHT dabei, und das ist kein Versehen:
 * Die Landingpage bettet die Karte im Blickfang direkt ein (assets/js/
 * landing.js) statt eine Abbildung davon zu zeigen. Ein Foto einer Karte
 * neben einer echten Karte waere die schlechtere von beiden.
 *
 * ===========================================================================
 * DIE AUFLOESUNG
 * ===========================================================================
 * Aufgenommen wird mit deviceScaleFactor 2 und dargestellt in halber
 * Groesse - sonst sieht die Aufnahme auf einem heutigen Bildschirm
 * ausgefranst aus. Die Angaben width/height in assets/html/landing.html
 * nennen deshalb die HALBE Pixelzahl der Datei.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE  = process.env.LP_BASE  || 'http://127.0.0.1:8080';
const PW    = process.env.LP_PW    || 'Demo!2345';
const VIDEO = process.env.LP_VIDEO || '';
const OUT   = process.env.LP_OUT   || path.join(__dirname, '..', 'assets', 'img', 'landing');

/**
 * Die Sprachen, in denen aufgenommen wird.
 *
 * Dieselben wie in App\Helper\I18n::SPRACHEN. Kommt dort eine dazu, gehoert
 * sie auch hierher - sonst zeigt die neue Fassung der Landingpage Bilder in
 * einer Sprache, die der Besucher gerade abgewaehlt hat.
 */
const SPRACHEN = (process.env.LP_LANGS || 'de,en').split(',');

/**
 * Konten und Kennungen. Sie kommen aus tools/landing_seed.json, das
 * tools/landing_seed.php beim Anlegen der Demodaten schreibt.
 *
 * NICHT FEST EINGETRAGEN, und das ist der Punkt: Wer seine
 * Entwicklungsdatenbank neu aufsetzt, bekommt andere Kennungen - die
 * Zaehler fangen nicht wieder bei eins an. Eine fest eingetragene 1 waere
 * dann ein fremder Standort oder gar keiner, und das Werkzeug liefe in
 * einen Zeitablauf statt in eine Fehlermeldung.
 */
const SEED_DATEI = path.join(__dirname, 'landing_seed.json');

if (!fs.existsSync(SEED_DATEI)) {
    console.error('Es fehlt ' + SEED_DATEI + ' - bitte zuerst: php tools/landing_seed.php');
    process.exit(1);
}

const seed = JSON.parse(fs.readFileSync(SEED_DATEI, 'utf8'));

const GUIDE       = seed.guideName;
const KUNDE       = seed.kundeName;
const LOCATION_ID = String(seed.locationId);
const GUIDE_ID    = String(seed.guideUserId);

/**
 * Meldet ein Konto an, stellt die Sprache um und gibt die Seite zurueck.
 *
 * DIE SPRACHE UEBER DIE ROUTE set_lang und nicht ueber ein Cookie von aussen:
 * Angemeldet gewinnt die Einstellung des KONTOS (App\Helper\I18n), ein
 * gesetztes Cookie liefe also ins Leere. set_lang schreibt beides.
 *
 * @param {Object} in_kontext Playwright-BrowserContext
 * @param {string} in_name    Benutzername
 * @param {string} in_sprache Sprachkuerzel, z. B. 'de'
 */
async function anmelden(in_kontext, in_name, in_sprache) {
    const seite = await in_kontext.newPage();
    await seite.goto(BASE + '/index.php?act=login_page', { waitUntil: 'load' });
    await seite.fill('input[name="username"]', in_name);
    await seite.fill('input[type=password]', PW);
    await seite.click('button[type=submit], input[type=submit]');
    await seite.waitForTimeout(2000);

    await seite.goto(BASE + '/index.php?act=set_lang&lang=' + in_sprache
                          + '&back=' + encodeURIComponent('act=home'),
                     { waitUntil: 'load' });
    await seite.waitForTimeout(1000);

    return seite;
}

/**
 * Nimmt auf und meldet, was entstanden ist.
 *
 * @param {Object} in_seite   Playwright-Page
 * @param {string} in_sprache Sprachkuerzel - zugleich das Unterverzeichnis
 * @param {string} in_datei   Dateiname ohne Pfad
 */
async function aufnehmen(in_seite, in_sprache, in_datei) {
    const ordner = path.join(OUT, in_sprache);
    fs.mkdirSync(ordner, { recursive: true });
    await in_seite.screenshot({ path: path.join(ordner, in_datei) });
    console.log('  ' + in_sprache + '/' + in_datei);
}

/**
 * Ein vollstaendiger Durchgang in EINER Sprache.
 *
 * Jeder Durchgang bekommt frische Kontexte: Sprache und Anmeldung haengen an
 * der Sitzung, und ein zweiter Durchgang im selben Kontext traege die
 * Einstellungen des ersten mit sich.
 *
 * @param {Object} in_browser Playwright-Browser
 * @param {string} in_sprache Sprachkuerzel
 */
async function durchgang(in_browser, in_sprache) {
    const kontext = () => in_browser.newContext({
        viewport: { width: 1280, height: 800 },
        deviceScaleFactor: 2,
        permissions: ['camera', 'microphone']
    });

    const guideKontext = await kontext();
    const kundeKontext = await kontext();

    // ---------------------------------------------------------------
    // 1. Die Standortseite, aus Kundensicht.
    //
    // Hoeher als die uebrigen Aufnahmen: Auf dieser Seite steht die halbe
    // Geschichte - Beschreibung, Guide, Bewertungen, Anfrageformular -, und
    // die passt nicht in 800 Pixel.
    // ---------------------------------------------------------------
    const kunde = await anmelden(kundeKontext, KUNDE, in_sprache);
    await kunde.setViewportSize({ width: 1280, height: 1000 });
    await kunde.goto(BASE + '/index.php?act=location&id=' + LOCATION_ID, { waitUntil: 'load' });
    await kunde.waitForTimeout(3000);
    await aufnehmen(kunde, in_sprache, 'standortseite.png');
    await kunde.setViewportSize({ width: 1280, height: 800 });

    // ---------------------------------------------------------------
    // 2. Die Anfragenliste des Guides.
    // ---------------------------------------------------------------
    const guide = await anmelden(guideKontext, GUIDE, in_sprache);
    await guide.goto(BASE + '/index.php?act=requests_page', { waitUntil: 'load' });
    await guide.waitForTimeout(2500);
    await aufnehmen(guide, in_sprache, 'guide-anfragen.png');

    // ---------------------------------------------------------------
    // 3. und 4. Die Fuehrung. EIN ECHTER ANRUF - beide Seiten laufen in
    // diesem Browser, das Signaling geht ueber den Server, die Verbindung
    // ueber die Loopback-Adresse.
    // ---------------------------------------------------------------
    await guide.goto(BASE + '/index.php?act=location&id=' + LOCATION_ID, { waitUntil: 'load' });
    await guide.waitForTimeout(1500);
    await kunde.goto(BASE + '/index.php?act=location&id=' + LOCATION_ID, { waitUntil: 'load' });
    await kunde.waitForTimeout(2500);

    await kunde.evaluate(([u, l]) => window.webrtcApp.rtc.startCall(u, l), [GUIDE_ID, LOCATION_ID]);

    // Der Guide nimmt an. Gewartet wird auf den Knopf und nicht auf eine Zahl
    // Sekunden: Der Dialog kommt erst, wenn das Offer eingetroffen ist.
    await guide.waitForSelector('#media-accept-btn', { state: 'visible', timeout: 30000 });
    await guide.waitForTimeout(1000);
    await guide.click('#media-accept-btn');

    // Bis Bild und Ton wirklich stehen. Ein zu frueher Ausloeser nimmt eine
    // schwarze Flaeche auf.
    await kunde.waitForFunction(
        () => document.getElementById('connection-status')?.textContent?.trim().length > 0,
        null, { timeout: 30000 });
    await kunde.waitForTimeout(6000);

    await aufnehmen(kunde, in_sprache, 'steuerkreuz.png');

    // Ein echter Steuerbefehl. Die Richtungsanzeige beim Guide steht nur ein
    // paar Sekunden - deshalb wird unmittelbar danach aufgenommen.
    await kunde.click('#btn-forward');
    await guide.waitForTimeout(700);
    await aufnehmen(guide, in_sprache, 'richtungsanzeige.png');

    // Auflegen, damit der naechste Durchgang mit einer Fuehrung anfaengt und
    // nicht in der vorigen steckt.
    await kunde.click('#end-call-btn').catch(() => {});
    await kunde.waitForTimeout(1500);

    await kundeKontext.close();
    await guideKontext.close();
}

(async () => {
    // DIE KAMERA. Ohne eigene Datei nimmt Chromium sein Testmuster - siehe
    // LP_VIDEO im Kopf dieser Datei.
    const args = ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream',
                  '--autoplay-policy=no-user-gesture-required'];
    if (VIDEO) args.push('--use-file-for-fake-video-capture=' + VIDEO);
    else console.warn('LP_VIDEO ist nicht gesetzt - das Kamerabild zeigt das Testmuster '
                    + 'von Chromium. Ein einzelnes Foto genuegt: LP_VIDEO=/pfad/zu/foto.jpg');

    const browser = await chromium.launch({ args });

    console.log('Aufnahmen nach ' + OUT);

    for (const sprache of SPRACHEN) {
        await durchgang(browser, sprache.trim());
    }

    await browser.close();
    console.log('fertig.');
})().catch(err => {
    console.error(err);
    process.exit(1);
});
