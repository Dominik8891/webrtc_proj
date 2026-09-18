/**
 * Die Landingpage als EINE Datei zum Verschicken.
 *
 * WOZU ES DIESE DATEI GIBT
 * ------------------------
 * Fuer eine Durchsicht per Mail: Der Empfaenger bekommt einen Anhang, macht
 * einen Doppelklick, und die Seite steht da - ohne Server, ohne Netz, ohne
 * Zugang zu irgendetwas.
 *
 * SIE WIRD ERZEUGT UND NICHT GEPFLEGT. Das ist der Punkt: Eine zweite,
 * abgetippte Fassung der Landingpage waere nach dem ersten Umbau falsch, und
 * zwar unbemerkt - niemand oeffnet den Anhang von letztem Monat, um ihn mit
 * der Seite zu vergleichen. Dieses Werkzeug oeffnet die LAUFENDE Seite und
 * friert ein, was es vorfindet. Was auf der Landingpage steht, steht in der
 * Datei; was dort fehlt, fehlt auch hier.
 *
 * ===========================================================================
 * VORAUSSETZUNGEN
 * ===========================================================================
 *   - Die Anwendung laeuft und ist erreichbar (Vorgabe: 127.0.0.1:8080).
 *   - Die Aufnahmen stehen unter assets/img/landing/<sprache>/ - sie sind
 *     Teil der Seite und werden mit eingebettet (tools/landing_shots.js).
 *   - Node.js mit Playwright (npm i -D playwright).
 *
 * ===========================================================================
 * AUFRUF
 * ===========================================================================
 *     node tools/landing_offline.js
 *
 * Ergebnis: offline/landingpage-de.html und offline/landingpage-en.html.
 *
 * ZWEI DATEIEN UND NICHT EINE MIT UMSCHALTER: Jede haelt nur ihre eigenen
 * Aufnahmen und Texte und ist damit etwa halb so gross - man haengt die an,
 * die passt. Es entspricht ausserdem der Seite selbst, die auch immer eine
 * Sprache zeigt.
 *
 * ===========================================================================
 * EINSTELLUNGEN UEBER UMGEBUNGSVARIABLEN
 * ===========================================================================
 *   LP_BASE    Adresse der laufenden Anwendung. Vorgabe http://127.0.0.1:8080
 *   LP_LANGS   Sprachen, kommagetrennt. Vorgabe "de,en"
 *   LP_OUT     Zielverzeichnis. Vorgabe <projekt>/offline
 *   LP_PUBLIC  Die OEFFENTLICHE Adresse der Anwendung, z. B.
 *              https://example.org/app. Ist sie gesetzt, fuehren die Knoepfe
 *              der Datei dorthin. OHNE SIE SIND DIE KNOEPFE TOT, und das ist
 *              die richtige Vorgabe: Ein Knopf, der den Empfaenger auf
 *              127.0.0.1 schickt, ist schlimmer als einer, der nichts tut.
 *
 * ===========================================================================
 * WAS BEIM EINFRIEREN PASSIERT
 * ===========================================================================
 * 1. STILVORLAGEN: Alle CSS-Dateien werden eingesammelt (mitgelesen, waehrend
 *    der Browser sie laedt - so gibt es keine Frage nach CORS) und im
 *    Dokument als <style> eingesetzt. Danach bleibt nur, was WIRKLICH
 *    GEBRAUCHT wird: Jede Regel wird gegen das Dokument geprueft. Aus den
 *    gut 230 KB Bootstrap werden so ein paar Kilobyte.
 *
 * 2. DIE KARTE. Sie ist der Knackpunkt, denn ohne Server laedt Leaflet keine
 *    Kacheln. Sie wird deshalb EINMAL abfotografiert und als Data-URI
 *    eingebettet - das ist die echte Karte samt ihrer Herkunftsangabe, die
 *    die ODbL verlangt.
 *
 *    DIE NADELN BLEIBEN ECHT. Sie sind der Blickfang, und ein Standbild
 *    haette ihn verschenkt: Sie werden als DOM-Elemente in Prozentpositionen
 *    ueber das Bild gelegt und tauchen weiter nacheinander auf - den Takt
 *    macht statt JavaScript eine Verzoegerung je Nadel (--lp-delay in
 *    assets/css/landing.css).
 *
 *    Der Kasten bekommt dabei ein FESTES SEITENVERHAELTNIS. Ohne das
 *    verschoebe sich das Bild gegen die Nadeln, sobald das Fenster eine
 *    andere Form hat als bei der Aufnahme.
 *
 * 3. DIE AUFNAHMEN werden umkodiert und eingebettet. Roh sind es ueber
 *    sieben Megabyte je Sprache - die beiden Bilder aus dem Anruf enthalten
 *    ein Foto, und dafuer ist PNG das falsche Format. Umkodiert nach WebP
 *    und auf eine vernuenftige Breite gebracht, bleibt ein Bruchteil davon
 *    uebrig. Gerechnet wird das im Browser (Canvas) - so braucht dieses
 *    Werkzeug keine Bildbibliothek.
 *
 * 4. JAVASCRIPT FAELLT WEG. Jedes Modul der Anwendung setzt einen Server
 *    voraus; in einer Datei ohne Server melden sie Fehler und tun nichts.
 *    Was ohne sie fehlen wuerde, ist genau zweierlei: der Takt der Nadeln
 *    (jetzt in CSS, siehe oben) und die Farbprofilwahl - dafuer kommen ein
 *    paar Zeilen als einziges Skript in die Datei.
 *
 * 5. DIE VERWEISE gehen auf LP_PUBLIC oder ins Leere - siehe oben.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE     = process.env.LP_BASE   || 'http://127.0.0.1:8080';
const SPRACHEN = (process.env.LP_LANGS || 'de,en').split(',').map(s => s.trim());
const OUT      = process.env.LP_OUT    || path.join(__dirname, '..', 'offline');
const PUBLIC   = (process.env.LP_PUBLIC || '').replace(/\/+$/, '');

/**
 * Die Fenstergroesse, in der eingefroren wird.
 *
 * Sie bestimmt das Seitenverhaeltnis der Karte und damit, wie der Blickfang
 * spaeter aussieht. Breit und nicht zu hoch: So sieht die Datei aus wie die
 * Seite auf einem gewoehnlichen Bildschirm.
 */
const BREITE = 1440;
const HOEHE  = 900;

/** Auf diese Breite werden die eingebetteten Aufnahmen gebracht. */
const BILD_BREITE = 1600;

/**
 * Sammelt die Stilvorlagen ein, waehrend der Browser sie laedt.
 *
 * UEBER DIE ANTWORTEN UND NICHT AUS DER SEITE HERAUS: Im Dokument stehen die
 * Vorlagen von fremden Adressen (die CDN-Bibliotheken), und deren cssRules
 * darf eine Seite nicht lesen - das ist die Gleiche-Herkunft-Regel und keine
 * Einstellung, die sich umgehen liesse. Hier laufen wir aber ausserhalb des
 * Browsers und bekommen den Text einfach mit.
 *
 * @param {Object} in_seite Playwright-Page
 * @returns {Map<string,string>} Adresse => CSS-Text
 */
function stilvorlagenMitlesen(in_seite) {
    const vorlagen = new Map();

    in_seite.on('response', async (antwort) => {
        const art = (antwort.headers()['content-type'] || '');
        if (!art.includes('text/css')) return;
        try {
            vorlagen.set(antwort.url(), await antwort.text());
        } catch (e) {
            // Eine Antwort, die nicht mehr da ist (Weiterleitung, Abbruch).
            // Fehlt sie, sieht man es sofort an der Datei - deshalb hier nur
            // eine Notiz und kein Abbruch.
            console.warn('  Stilvorlage nicht lesbar: ' + antwort.url());
        }
    });

    return vorlagen;
}

/**
 * Baut die Datei fuer EINE Sprache.
 *
 * @param {Object} in_browser Playwright-Browser
 * @param {string} in_sprache Sprachkuerzel
 */
async function bauen(in_browser, in_sprache) {
    const kontext = await in_browser.newContext({
        viewport: { width: BREITE, height: HOEHE },
        deviceScaleFactor: 2
    });

    // Die Sprache ueber das Cookie, also als Gast - dieselbe Quelle, aus der
    // sie auch ein Besucher bekommt (App\Helper\I18n).
    await kontext.addCookies([
        { name: 'webrtcapp_lang', value: in_sprache, url: BASE }
    ]);

    const seite    = await kontext.newPage();
    const vorlagen = stilvorlagenMitlesen(seite);

    await seite.goto(BASE + '/index.php?act=landing', { waitUntil: 'load' });

    // Warten, bis alle Nadeln stehen - vorher waere die Karte halb leer.
    await seite.waitForFunction(
        () => document.querySelectorAll('.lp-pin').length >= 100,
        null, { timeout: 30000 });
    // Und bis die Kacheln da sind. Ein fester Wert und keine Bedingung: Ob
    // ein Bild "fertig" ist, sagt Leaflet nicht zuverlaessig, und zu frueh
    // fotografiert heisst graue Flecken.
    await seite.waitForTimeout(4000);

    // ---------------------------------------------------------------
    // ZUERST DIE NADELN AUSLESEN UND AUSBLENDEN, DANN FOTOGRAFIEREN.
    //
    // Die Reihenfolge ist keine Kleinigkeit: Ein Foto der Karte MIT Nadeln
    // haette sie eingebacken - und die neuen kaemen obendrauf. Sie stuenden
    // dann doppelt da, die eingebackenen zusaetzlich unter dem Schleier und
    // deshalb blass.
    // ---------------------------------------------------------------
    const nadeln = await seite.evaluate(() => {
        const flaeche = document.getElementById('lp-map');
        const kasten  = flaeche.getBoundingClientRect();

        const gelesen = [...document.querySelectorAll('.lp-pin')].map(nadel => {
            const r = nadel.getBoundingClientRect();
            return {
                links: ((r.left + r.width / 2 - kasten.left) / kasten.width) * 100,
                oben:  ((r.top + r.height / 2 - kasten.top) / kasten.height) * 100,
                live:  nadel.classList.contains('lp-pin--live')
            };
        }).filter(n => n.links >= 0 && n.links <= 100 && n.oben >= 0 && n.oben <= 100);

        // UND JETZT WEG, WAS NICHT INS FOTO GEHOERT.
        //
        // Nicht nur die Nadeln: Ein Elementfoto nimmt auf, was in diesem
        // Ausschnitt GEZEICHNET wird - also auch, was darueber liegt. Der
        // Textkasten und der Hinweis sind Geschwister der Karte und lagen
        // deshalb mit im Bild. Auf breiten Schirmen faellt das nicht auf,
        // weil der echte Kasten genau darauf zu liegen kommt; auf einem
        // Telefon stehen sie untereinander, und dann klebt eine zweite,
        // winzige Fassung des Kastens mitten in der Karte.
        //
        // visibility und nicht display: Der Platz bleibt erhalten, die
        // Karte behaelt also ihre Groesse - und damit stimmen die eben
        // gelesenen Nadelpositionen weiterhin.
        document.querySelectorAll(
            '.leaflet-marker-pane, .lp-hero__body, .lp-hero__note'
        ).forEach(el => { el.style.visibility = 'hidden'; });

        return gelesen;
    });

    // Die Karte abfotografieren. JPEG und nicht PNG: Es ist ein Bild mit
    // Farbverlaeufen und ohne Schrift - ausser der Herkunftsangabe, und die
    // bleibt auch bei dieser Qualitaet lesbar.
    //
    // WAS DAS FOTO ENTHAELT: die Kacheln, den Schleier darueber
    // (.lp-hero__map::after) und die Herkunftsangabe. Weil der Schleier schon
    // drin ist, wird er in der Offline-Fassung abgeschaltet - sonst laege er
    // zweimal da (siehe ZUSATZ_CSS).

    const kartenBild = await seite.locator('#lp-map').screenshot({
        type: 'jpeg', quality: 80
    });
    const karte = 'data:image/jpeg;base64,' + kartenBild.toString('base64');

    const kartenMass = await seite.locator('#lp-map').boundingBox();

    // ---------------------------------------------------------------
    // Und jetzt das Umbauen - alles im Browser, weil dort das Dokument
    // steht, an dem gearbeitet wird.
    // ---------------------------------------------------------------
    const html = await seite.evaluate(async (opt) => {

        // --- 1. Stilvorlagen einsetzen -----------------------------
        //
        // Erst als <style> ins Dokument, damit ihre Regeln lesbar werden
        // (siehe stilvorlagenMitlesen im Werkzeug), dann aussortieren.
        document.querySelectorAll('link[rel="stylesheet"]').forEach(el => {
            const text = opt.vorlagen[el.href];
            if (text === undefined) {
                console.warn('keine Stilvorlage fuer ' + el.href);
                el.remove();
                return;
            }
            const stil = document.createElement('style');
            stil.textContent = text;
            el.replaceWith(stil);
        });

        // --- 2. Die Karte ------------------------------------------
        //
        // Die Lage der Nadeln ist schon gelesen (vor dem Foto, siehe dort).
        const flaeche = document.getElementById('lp-map');
        const nadeln  = opt.nadeln;

        // Leaflet und seine Kacheln fliegen raus, das Bild kommt an ihre
        // Stelle. Das Seitenverhaeltnis wird festgehalten - sonst
        // verschoeben sich die Nadeln gegen das Bild, sobald das Fenster
        // eine andere Form hat.
        flaeche.innerHTML = '';
        flaeche.className = 'lp-hero__map lp-hero__map--fest';
        // ALS VARIABLE UND NICHT DIREKT ALS background-image: Das
        // Dunkelprofil braucht dasselbe Bild ein zweites Mal in einer
        // eigenen Ebene (::before, siehe ZUSATZ_CSS), und "inherit" haette
        // dort geerbt, was am Elternelement steht - naemlich "none".
        flaeche.style.setProperty('--lp-karte', 'url(' + opt.karte + ')');
        flaeche.style.aspectRatio = opt.kartenBreite + ' / ' + opt.kartenHoehe;

        // Die Nadeln zurueck - als einfache Punkte, ohne Leaflet.
        nadeln.forEach((n, i) => {
            const punkt = document.createElement('span');
            punkt.className = 'lp-pin' + (n.live ? ' lp-pin--live' : '');
            punkt.style.position = 'absolute';
            punkt.style.left = n.links.toFixed(3) + '%';
            punkt.style.top  = n.oben.toFixed(3) + '%';
            punkt.style.transform = 'translate(-50%, -50%)';
            // Der Takt, den im Browser assets/js/landing.js macht.
            punkt.style.setProperty('--lp-delay', (i * 90) + 'ms');
            flaeche.appendChild(punkt);
        });

        // Der Textkasten und der Hinweis waren fuer das Foto ausgeblendet -
        // jetzt gehoeren sie wieder dazu.
        document.querySelectorAll('.lp-hero__body, .lp-hero__note').forEach(
            el => { el.style.visibility = ''; });

        // --- 3. Die Aufnahmen einbetten ----------------------------
        //
        // Umkodiert ueber ein Canvas: WebP schlaegt PNG bei einem Foto um
        // ein Vielfaches und bei einem Bildschirmfoto immer noch deutlich.
        // Das Bild wird dabei auf eine vernuenftige Breite gebracht - die
        // Aufnahmen sind fuer doppelte Aufloesung gemacht und auf der Seite
        // halb so breit zu sehen.
        for (const bild of document.querySelectorAll('.lp-shot img')) {
            await bild.decode().catch(() => {});

            const breite = Math.min(bild.naturalWidth, opt.bildBreite);
            const hoehe  = Math.round(bild.naturalHeight * (breite / bild.naturalWidth));

            const leinwand = document.createElement('canvas');
            leinwand.width  = breite;
            leinwand.height = hoehe;
            leinwand.getContext('2d').drawImage(bild, 0, 0, breite, hoehe);

            bild.src = leinwand.toDataURL('image/webp', 0.82);
            bild.removeAttribute('loading');
        }

        // --- 4. Skripte raus, eines rein ---------------------------
        document.querySelectorAll('script').forEach(el => el.remove());

        // --- 5. Verweise -------------------------------------------
        //
        // Auf die oeffentliche Adresse, wenn es eine gibt. Sonst tot: Ein
        // Knopf, der den Empfaenger auf 127.0.0.1 schickt, ist schlimmer
        // als einer, der nichts tut.
        document.querySelectorAll('a[href]').forEach(a => {
            const ziel = a.getAttribute('href');
            if (!ziel || ziel.startsWith('#')) return;

            if (opt.oeffentlich) {
                a.setAttribute('href', opt.oeffentlich + '/' + ziel.replace(/^\/+/, ''));
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener');
            } else {
                a.removeAttribute('href');
                a.classList.add('lp-offline-tot');
            }
        });

        // --- 6. Nur die gebrauchten Regeln behalten ----------------
        //
        // Jede Regel wird gegen das Dokument geprueft. Was niemand trifft,
        // faellt weg - und das ist bei Bootstrap, DataTables und select2 der
        // allergroesste Teil.
        //
        // STEHEN BLEIBEN OHNE PRUEFUNG: @keyframes (sie werden ueber ihren
        // Namen benutzt, nicht ueber einen Selektor), :root (dort stehen
        // saemtliche Farben und Abstaende) und alles unter [data-theme] (die
        // Farbprofile - sie greifen erst, wenn jemand umschaltet).
        const gebraucht = [];

        const pruefen = (regel) => {
            if (regel.type === CSSRule.STYLE_RULE) {
                const auswahl = regel.selectorText || '';
                if (/^:root|\[data-theme/.test(auswahl)) return regel.cssText;
                // Selektoren wie ::-webkit-... verstehen manche Browser
                // nicht als Anfrage - dann lieber behalten als verlieren.
                try {
                    const ohnePseudo = auswahl.replace(/::?[a-z-]+(\([^)]*\))?/gi, '');
                    if (ohnePseudo.trim() === '') return regel.cssText;
                    return document.querySelector(ohnePseudo) ? regel.cssText : null;
                } catch (e) {
                    return regel.cssText;
                }
            }

            if (regel.type === CSSRule.MEDIA_RULE) {
                const innen = [...regel.cssRules].map(pruefen).filter(Boolean);
                return innen.length
                     ? '@media ' + regel.conditionText + '{' + innen.join('') + '}'
                     : null;
            }

            if (regel.type === CSSRule.KEYFRAMES_RULE) return regel.cssText;
            if (regel.type === CSSRule.SUPPORTS_RULE) {
                const innen = [...regel.cssRules].map(pruefen).filter(Boolean);
                return innen.length
                     ? '@supports ' + regel.conditionText + '{' + innen.join('') + '}'
                     : null;
            }
            // Alles Uebrige (@font-face, @charset, @page) bleibt.
            return regel.cssText;
        };

        for (const blatt of document.styleSheets) {
            let regeln;
            try { regeln = [...blatt.cssRules]; } catch (e) { continue; }
            regeln.map(pruefen).filter(Boolean).forEach(r => gebraucht.push(r));
        }

        document.querySelectorAll('style').forEach(el => el.remove());

        const stil = document.createElement('style');
        stil.textContent = gebraucht.join('\n') + '\n' + opt.zusatzCss;
        document.head.appendChild(stil);

        // --- 7. Das einzige Skript der Datei -----------------------
        const skript = document.createElement('script');
        skript.textContent = opt.skript;
        document.body.appendChild(skript);

        return '<!DOCTYPE html>\n' + document.documentElement.outerHTML;

    }, {
        karte,
        kartenBreite: Math.round(kartenMass.width),
        kartenHoehe:  Math.round(kartenMass.height),
        nadeln,
        bildBreite:   BILD_BREITE,
        oeffentlich:  PUBLIC,
        vorlagen:     Object.fromEntries(vorlagen),
        zusatzCss:    ZUSATZ_CSS,
        skript:       SKRIPT
    });

    fs.mkdirSync(OUT, { recursive: true });
    const ziel = path.join(OUT, 'landingpage-' + in_sprache + '.html');
    fs.writeFileSync(ziel, html, 'utf8');

    console.log('  ' + path.basename(ziel) + '  '
              + (Buffer.byteLength(html) / 1024 / 1024).toFixed(2) + ' MB');

    await kontext.close();
}

/**
 * Was die Datei zusaetzlich braucht.
 *
 * NUR DREI DINGE, und jedes davon ist eine Folge des Einfrierens - keine
 * Gestaltung, die es auf der Seite nicht gaebe.
 */
const ZUSATZ_CSS = `
/* Die Karte als Bild. Die Hoehe kommt jetzt aus dem Seitenverhaeltnis und
   nicht mehr daraus, dass sie den Blickfang ausfuellt - deshalb steht sie
   hier im Fluss und nicht mehr absolut darueber.

   height:auto ist dabei kein Fuellwort: Auf schmalen Geraeten gibt
   assets/css/landing.css der Karte 240 Pixel Hoehe. Das schlaegt das
   Seitenverhaeltnis, der Ausschnitt wird beschnitten - und die Nadeln, die
   in Prozent sitzen, zeigen dann auf die falschen Stellen. */
.lp-hero__map--fest {
    position: relative;
    inset: auto;
    width: 100%;
    height: auto;
    background-image: var(--lp-karte);
    background-size: cover;
    background-position: center;
}
/* DER SCHLEIER IST SCHON IM BILD. Er gehoert zum Foto der Karte
   (.lp-hero__map::after war beim Fotografieren aktiv); ein zweiter darueber
   liesse die Karte ausgewaschen aussehen - und die Nadeln, die jetzt Kinder
   dieses Kastens sind, laegen darunter. */
.lp-hero__map--fest::after { content: none; }
/* AUF BREITEN SCHIRMEN liegt der Text ueber der Karte - so wie im Browser,
   nur dass die Karte jetzt der Kasten darunter ist statt eine Flaeche
   dahinter.

   NUR HIER, und das ist der Punkt: Auf schmalen Geraeten stehen Karte, Text
   und Hinweis untereinander (assets/css/landing.css ab 860px), und dafuer
   ist nichts zu tun. Waeren diese Regeln nicht begrenzt, laege der Text auf
   einem Telefon quer ueber der Karte - die Offline-Fassung haette die
   Seite dort kaputtgemacht, obwohl sie sie nur einfrieren soll. */
@media (min-width: 861px) {
    .lp-hero { display: block; min-height: 0; padding: 0; }

    .lp-hero__body {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        z-index: 2;
    }

    .lp-hero__note { z-index: 2; }
}

/* DAS DUNKELPROFIL UND DIE KARTE.

   Im Browser dreht das Dunkelprofil die Kacheln um - der Filter liegt auf
   .leaflet-tile-pane (assets/css/theme.css). Offline gibt es diese Ebene
   nicht mehr; ohne die folgende Zeile bliebe eine helle Karte unter einer
   dunklen Seite stehen.

   DERSELBE FILTER, NUR AN ANDERER STELLE - und der Grund, aus dem er dort
   NUR auf der Kachelebene liegt, gilt hier genauso: Er darf die Nadeln nicht
   erwischen, sonst wuerde aus dem Gruen der verfuegbaren Guides ein Rot. Er
   sitzt deshalb am Hintergrundbild und nicht am Kasten - die Nadeln sind
   Kinder dieses Kastens und blieben sonst nicht verschont.

   Ein Hintergrundbild allein laesst sich nicht filtern; dafuer steht es in
   einer eigenen Ebene hinter allem (::before). */
[data-theme="dunkel"] .lp-hero__map--fest {
    background-image: none;
    background-color: #14181e;
}
[data-theme="dunkel"] .lp-hero__map--fest::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image: var(--lp-karte);
    background-size: cover;
    background-position: center;
    filter: invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.90) saturate(0.80);
}

/* Wer Bewegung und starke Kontraste reduziert haben will, bekommt die Karte
   nur abgedunkelt statt umgekehrt - genau wie in der Anwendung. */
@media (prefers-contrast: more), (prefers-reduced-motion: reduce) {
    [data-theme="dunkel"] .lp-hero__map--fest::before {
        filter: brightness(0.72) saturate(0.85);
    }
}

/* Ein Verweis ohne Ziel. Er sieht aus wie vorher, tut aber nichts - siehe
   LP_PUBLIC im Kopf des Werkzeugs. */
.lp-offline-tot { cursor: default; }
`;

/**
 * Das einzige Skript der Datei: die Farbprofilwahl.
 *
 * Sie ist der einzige Teil der Seite, der ohne Server etwas tun KANN - die
 * Farben stehen als CSS-Variablen, umgeschaltet wird ein Attribut. Alles
 * andere braucht die Anwendung und ist deshalb draussen.
 */
const SKRIPT = `
(function () {
    var punkte = document.getElementById('theme-mini');
    if (!punkte) return;

    function markieren() {
        var gilt = document.documentElement.getAttribute('data-theme');
        punkte.querySelectorAll('[data-theme-value]').forEach(function (knopf) {
            var an = knopf.getAttribute('data-theme-value') === gilt;
            knopf.classList.toggle('app-theme-dot--on', an);
            knopf.setAttribute('aria-pressed', an ? 'true' : 'false');
        });
    }

    punkte.addEventListener('click', function (e) {
        var knopf = e.target.closest('[data-theme-value]');
        if (!knopf) return;
        document.documentElement.setAttribute('data-theme',
            knopf.getAttribute('data-theme-value'));
        markieren();
    });

    markieren();
})();
`;

(async () => {
    const browser = await chromium.launch();

    console.log('Offline-Fassung nach ' + OUT);
    if (!PUBLIC) {
        console.log('  (LP_PUBLIC ist nicht gesetzt - die Knoepfe der Datei sind tot)');
    }

    for (const sprache of SPRACHEN) {
        await bauen(browser, sprache);
    }

    await browser.close();
    console.log('fertig.');
})().catch(err => {
    console.error(err);
    process.exit(1);
});
