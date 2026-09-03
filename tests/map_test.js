/**
 * Prüft die Standortauswahl auf der Seite "Standort anbieten"
 * (assets/js/map.js, assets/html/set_location.html).
 *
 * Ausführen:  node tests/map_test.js
 * Siehe tests/README.md.
 *
 * WARUM ES DIESE DATEI GIBT
 * -------------------------
 * Das Setzen des Punktes per Mausklick war kaputt, und zwar auf eine Art,
 * die man beim Lesen leicht übersieht: Der Klick schrieb die Koordinaten
 * korrekt in #latitude und #longitude, und ein zweiter change-Horcher auf
 * dem Länderfeld löschte sie eine halbe Sekunde später wieder - ausgelöst
 * von genau dem Klick, der sie gesetzt hatte. Der Marker blieb liegen, also
 * sah alles richtig aus; erst der Server wies das Formular ab.
 *
 * Geprüft wird deshalb nicht "der Klick setzt die Felder" (das tat er
 * vorher auch), sondern: die Felder stehen NOCH, nachdem die Antwort von
 * OpenStreetMap verarbeitet wurde.
 *
 * Die jQuery-, Leaflet- und fetch-Attrappen unten sind bewusst klein
 * gehalten: gerade so viel, wie assets/js/map.js anfasst.
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0;
function ok(name) { console.error('  ok  ' + name); passed++; }
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// =====================================================================
// Attrappen
// =====================================================================

/** Ein Element im nachgebauten Dokument. */
class El {
    constructor(id, tag) {
        this.id = id;
        this.tag = tag || 'div';
        this.value = '';
        this.textContent = '';
        this.dataset = {};
        this.children = [];
        this.selected = false;
        this.disabled = false;
        this.handlers = {};
    }
}

/** Die Elemente aus assets/html/set_location.html, die map.js braucht. */
let store;
function baueSeite() {
    store = {};
    for (const [id, tag] of [
        ['map', 'div'], ['countrySelect', 'select'], ['citySelect', 'select'],
        ['latitude', 'input'], ['longitude', 'input'],
        ['lat', 'span'], ['lon', 'span'], ['osm_place', 'span'],
        ['current-location', 'button'], ['location-hinweis', 'div']
    ]) store[id] = new El(id, tag);
    return store;
}

/** Ergebnismenge der jQuery-Attrappe. */
function Q(els) { this.els = els; this.length = els.length; }
Q.prototype.each = function (fn) { this.els.forEach((e, i) => fn.call(e, i, e)); return this; };
Q.prototype.val = function (v) {
    if (v === undefined) return this.els[0] ? this.els[0].value : undefined;
    // Wie im echten DOM: der Wert eines Feldes ist immer eine Zeichenkette.
    this.els.forEach(e => {
        e.value = String(v);
        if (e.tag === 'select') {
            e.children.forEach(o => { if (o instanceof El) o.selected = (String(o.value) === String(v)); });
        }
    });
    return this;
};
Q.prototype.text = function (t) {
    if (t === undefined) return this.els[0] ? this.els[0].textContent : '';
    this.els.forEach(e => e.textContent = t);
    return this;
};
Q.prototype.data = function (k) { return this.els[0] ? this.els[0].dataset[k] : undefined; };
Q.prototype.prop = function (k, v) { this.els.forEach(e => e[k] = v); return this; };
Q.prototype.empty = function () { this.els.forEach(e => e.children = []); return this; };
Q.prototype.append = function (x) {
    const kinder = x instanceof Q ? x.els : [x];
    this.els.forEach(e => {
        kinder.forEach(k => { if (k) e.children.push(k); });
        // Wie im echten DOM: eine angehängte Option mit selected=true wird
        // zur Auswahl des <select>. map.js setzt die Stadt genau so.
        if (e.tag === 'select') {
            const gewaehlt = e.children.filter(c => c instanceof El && c.selected).pop();
            if (gewaehlt) e.value = String(gewaehlt.value);
        }
    });
    return this;
};
Q.prototype.find = function (sel) {
    let out = [];
    this.els.forEach(e => {
        const opts = e.children.filter(c => c instanceof El);
        if (sel === 'option:selected') out = out.concat(opts.filter(o => o.selected));
        else {
            const m = sel.match(/option\[value="?(.*?)"?\]/);
            out = out.concat(m ? opts.filter(o => String(o.value) === m[1]) : opts);
        }
    });
    return new Q(out);
};
Q.prototype.filter = function (fn) { return new Q(this.els.filter((e, i) => fn.call(e, i, e))); };
Q.prototype.next = function () { return new Q([]); };
Q.prototype.show = function () { return this; };
Q.prototype.hide = function () { return this; };
Q.prototype.ready = function (fn) { global.__ready = fn; return this; };
Q.prototype.select2 = function () { return this; };
Q.prototype.on = function (ev, fn) {
    this.els.forEach(e => (e.handlers[ev] = e.handlers[ev] || []).push(fn));
    return this;
};
Q.prototype.trigger = function (ev) {
    const name = String(ev).split('.')[0];   // 'change.select2' -> 'change'
    this.els.forEach(e => (e.handlers[name] || []).slice().forEach(fn => fn.call(e, { type: name })));
    return this;
};

function $(sel, opts) {
    if (typeof sel === 'string' && sel === '<option>' && opts) {
        const o = new El(null, 'option');
        o.value = opts.value;
        o.textContent = opts.text;
        o.dataset['country-name'] = opts['data-country-name'];
        o.dataset.iso2 = opts['data-iso2'];
        return new Q([o]);
    }
    if (typeof sel === 'string' && sel.startsWith('<span')) return new Q([new El(null, 'span')]);
    if (typeof sel === 'string' && sel.startsWith('<option')) return new Q([new El(null, 'option')]);
    if (typeof sel === 'function') { global.__ready = sel; return new Q([]); }
    if (sel instanceof El) return new Q([sel]);
    if (typeof sel !== 'string') return new Q([]);
    if (sel === '#countrySelect option') return new Q(store.countrySelect.children.filter(c => c instanceof El));
    if (sel === '#countrySelect option:selected') {
        return new Q(store.countrySelect.children.filter(c => c instanceof El && c.selected));
    }
    return new Q(sel.split(',').map(t => store[t.trim().replace('#', '')]).filter(Boolean));
}
$.fn = { select2: function () {} };
$.ajax = () => ({ done: () => ({ fail: () => {} }) });

global.$ = global.jQuery = $;
global.Option = function (text, value, defaultSelected, selected) {
    const o = new El(null, 'option');
    o.textContent = text;
    o.value = value;
    o.selected = !!selected;
    return o;
};

// Leaflet: die Karte merkt sich nur ihren Klick-Horcher, damit der Test
// einen Mausklick auslösen kann.
let karteAttrappe;
global.L = {
    map: () => karteAttrappe,
    tileLayer: () => ({ addTo: () => {} }),
    marker: () => ({ addTo: () => {} })
};

// Antworten von index.php und Nominatim. __osmFehler schaltet den
// Reverse-Aufruf auf Störung.
const LAENDER = [
    { id: 1, country_name: 'Deutschland', iso2: 'DE' },
    { id: 2, country_name: 'Österreich', iso2: 'AT' }
];
global.__osmFehler = false;
global.fetch = (url) => {
    if (url.includes('act=get_country')) {
        return Promise.resolve({ ok: true, json: () => Promise.resolve(LAENDER) });
    }
    if (url.includes('/reverse')) {
        if (global.__osmFehler) return Promise.reject(new Error('Netzwerkfehler'));
        return Promise.resolve({
            json: () => Promise.resolve({
                display_name: 'Berlin, Deutschland',
                address: { country_code: 'de', city: 'Berlin' }
            })
        });
    }
    return Promise.resolve({ json: () => Promise.resolve([{ lat: '52.5', lon: '13.4' }]) });
};

global.window = global;
global.document = { querySelector: () => ({ focus() {} }) };
// Standortbestimmung des Geraets. __gps liefert die Antwort, __gpsFehler
// laesst sie scheitern.
global.__gps = { latitude: 52.52, longitude: 13.405 };
global.__gpsFehler = null;
// Node bringt selbst ein navigator-Objekt mit, und zwar nur als Getter -
// eine einfache Zuweisung liefe wirkungslos ins Leere. Deshalb ersetzen.
Object.defineProperty(global, 'navigator', {
    configurable: true,
    value: {
    geolocation: {
        getCurrentPosition(erfolg, fehler) {
            if (global.__gpsFehler) fehler({ message: global.__gpsFehler });
            else erfolg({ coords: { latitude: global.__gps.latitude, longitude: global.__gps.longitude } });
        }
    }
    }
});
global.location = { search: '' };
global.__meldungen = [];
window.webrtcApp = {
    notify: {
        success: t => global.__meldungen.push(String(t)),
        error: t => global.__meldungen.push(String(t)),
        info: t => global.__meldungen.push(String(t))
    }
};

const QUELLE = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'map.js'), 'utf8');
eval(QUELLE);
const karte = window.webrtcApp.locationMap;

/** Setzt die Seite zurück und startet map.js neu. */
async function neueSeite() {
    baueSeite();
    karteAttrappe = {
        klick: null,
        setView() { return this; },
        removeLayer() {},
        on(ev, fn) { if (ev === 'click') this.klick = fn; return this; }
    };
    karte.map = null;
    karte.marker = null;
    karte.selectedCountryCode = null;
    karte.countryJustSetByLocation = false;
    global.__osmFehler = false;
    global.__gpsFehler = null;
    global.__meldungen.length = 0;
    karte.init();
    await sleep(20);          // loadCountries() abwarten
    return store;
}

/** Löst einen Mausklick auf der Karte aus. */
function klickeAufKarte(lat, lon) {
    assert.ok(typeof karteAttrappe.klick === 'function',
        'map.js hat gar keinen Klick-Horcher an der Karte angemeldet');
    karteAttrappe.klick({ latlng: { lat: lat, lng: lon } });
}

const koordinaten = () => ({ lat: store.latitude.value, lon: store.longitude.value });

// =====================================================================
// Prüfungen
// =====================================================================
(async () => {

    console.error('\n1) Am Länderfeld hängt genau EIN change-Horcher');
    {
        // Der Fehler war ein zweiter Horcher, der die Koordinaten des
        // Kartenklicks loeschte. Wer wieder einen anhaengt, faellt hier auf.
        await neueSeite();
        const anzahl = (store.countrySelect.handlers.change || []).length;
        assert.strictEqual(anzahl, 1,
            'auf #countrySelect haengen ' + anzahl + ' change-Horcher, erwartet war genau einer - ' +
            'was beim Landwechsel zu geschehen hat, gehoert in onCountryChange()');
        ok('genau ein change-Horcher auf dem Länderfeld');

        assert.ok(karteAttrappe.klick, 'die Karte nimmt keine Klicks entgegen');
        ok('der Klick-Horcher der Karte ist gebunden');
    }

    console.error('\n2) Klick ohne vorher gewähltes Land setzt den Punkt dauerhaft');
    {
        // Der eigentliche Fehlerfall: Ohne gewaehltes Land aendert der Klick
        // das Land, und daran hing das Loeschen der Koordinaten.
        await neueSeite();
        assert.strictEqual(store.countrySelect.value, '', 'Vorbedingung: kein Land gewaehlt');

        klickeAufKarte(52.52, 13.405);
        assert.strictEqual(koordinaten().lat, '52.52', 'der Klick setzt #latitude nicht');
        assert.strictEqual(koordinaten().lon, '13.405', 'der Klick setzt #longitude nicht');
        ok('der Klick schreibt die Koordinaten');

        await sleep(30);   // Reverse-Geocoding und Landwechsel abwarten
        assert.strictEqual(koordinaten().lat, '52.52',
            '#latitude wurde nach der Antwort von OpenStreetMap wieder geleert');
        assert.strictEqual(koordinaten().lon, '13.405',
            '#longitude wurde nach der Antwort von OpenStreetMap wieder geleert');
        assert.strictEqual(store.lat.textContent, '52.520000', 'die Anzeige des Breitengrads ist leer');
        assert.strictEqual(store.lon.textContent, '13.405000', 'die Anzeige des Längengrads ist leer');
        ok('die Koordinaten überleben das Setzen des Landes');

        assert.strictEqual(store.countrySelect.value, '1', 'das erkannte Land wurde nicht gesetzt');
        assert.strictEqual(store.osm_place.textContent, 'Berlin, Deutschland', 'der Ortsname fehlt');
        ok('Land und Ortsname kommen aus der Antwort');

        // Ein gesperrtes Feld wird vom Browser nicht mitgeschickt - die
        // Stadt kaeme dann leer beim Server an (LocationController).
        assert.strictEqual(store.citySelect.disabled, false,
            'das Stadtfeld bleibt gesperrt und wuerde nicht mit abgeschickt');
        assert.strictEqual(store.citySelect.value, 'Berlin', 'die Stadt wurde nicht gesetzt');
        ok('das Stadtfeld ist freigegeben und gefüllt');
    }

    console.error('\n3) Klick im bereits gewählten Land setzt den Punkt ebenso');
    {
        // Der Fall, der frueher als einziger funktionierte: Land schon
        // eingestellt, also gar kein Landwechsel und damit kein Loeschen.
        await neueSeite();
        $('#countrySelect').val('1').trigger('change');
        await sleep(20);

        klickeAufKarte(48.137, 11.575);
        await sleep(30);
        assert.strictEqual(koordinaten().lat, '48.137', '#latitude ist nach dem Klick leer');
        assert.strictEqual(koordinaten().lon, '11.575', '#longitude ist nach dem Klick leer');
        ok('der Punkt steht auch ohne Landwechsel');
    }

    console.error('\n4) Ein selbst gewähltes Land verwirft den alten Punkt weiterhin');
    {
        // Die Gegenprobe: Das Zuruecksetzen ist richtig, wenn der NUTZER das
        // Land wechselt - der alte Punkt liegt dann woanders.
        await neueSeite();
        klickeAufKarte(52.52, 13.405);
        await sleep(30);
        assert.strictEqual(koordinaten().lat, '52.52', 'Vorbedingung: Punkt gesetzt');

        $('#countrySelect').val('2').trigger('change');   // Nutzer waehlt Österreich
        assert.strictEqual(koordinaten().lat, '', 'der alte Punkt blieb beim Landwechsel stehen');
        assert.strictEqual(koordinaten().lon, '', 'der alte Punkt blieb beim Landwechsel stehen');
        assert.strictEqual(store.osm_place.textContent, '', 'der alte Ortsname blieb stehen');
        ok('der Landwechsel durch den Nutzer räumt Punkt und Ortsname ab');

        assert.strictEqual(store.citySelect.disabled, false, 'das Stadtfeld ist trotz Land gesperrt');
        ok('das Stadtfeld bleibt bei gewähltem Land freigegeben');

        $('#countrySelect').val('').trigger('change');    // Land wieder abwählen
        assert.strictEqual(store.citySelect.disabled, true,
            'ohne Land muss das Stadtfeld gesperrt sein - die Städtesuche braucht den Ländercode');
        ok('ohne Land wird das Stadtfeld gesperrt');
    }

    console.error('\n5) Antwortet OpenStreetMap nicht, bleibt der Punkt trotzdem gesetzt');
    {
        // Die Koordinaten stehen im Browser fest; sie duerfen nicht davon
        // abhaengen, dass ein fremder Dienst erreichbar ist.
        await neueSeite();
        global.__osmFehler = true;

        klickeAufKarte(52.52, 13.405);
        await sleep(30);
        assert.strictEqual(koordinaten().lat, '52.52', 'der Punkt ging mit dem fehlgeschlagenen Aufruf verloren');
        assert.strictEqual(koordinaten().lon, '13.405', 'der Punkt ging mit dem fehlgeschlagenen Aufruf verloren');
        assert.ok(store.osm_place.textContent.includes('nicht ermittelbar'),
            'der Nutzer erfaehrt nicht, warum der Ortsname fehlt');
        ok('ein Ausfall von OpenStreetMap kostet nur den Ortsnamen');
    }

    console.error('\n6) Der Standort-Knopf setzt den Punkt sofort und behält ihn');
    {
        // Derselbe Weg wie beim Klick: Die Koordinaten kommen aus dem Geraet,
        // das Land wird danach aus der Antwort gesetzt - und darf sie nicht
        // wieder loeschen.
        await neueSeite();
        $('#current-location').trigger('click');

        // Ohne Wartezeit: frueher standen die Felder erst nach 500 ms.
        assert.strictEqual(koordinaten().lat, '52.52',
            'der Standort-Knopf setzt #latitude nicht sofort');
        assert.strictEqual(koordinaten().lon, '13.405',
            'der Standort-Knopf setzt #longitude nicht sofort');
        ok('die Koordinaten stehen ohne Wartezeit');

        await sleep(30);
        assert.strictEqual(koordinaten().lat, '52.52',
            '#latitude wurde nach der Antwort von OpenStreetMap wieder geleert');
        assert.strictEqual(store.countrySelect.value, '1', 'das erkannte Land wurde nicht gesetzt');
        assert.strictEqual(store.citySelect.value, 'Berlin', 'die Stadt wurde nicht gesetzt');
        ok('Punkt, Land und Stadt stehen nach der Antwort');

        // Auch hier: ein Ausfall von OpenStreetMap kostet nur die Adresse.
        await neueSeite();
        global.__osmFehler = true;
        $('#current-location').trigger('click');
        await sleep(30);
        assert.strictEqual(koordinaten().lat, '52.52',
            'der Punkt ging mit dem fehlgeschlagenen Aufruf verloren');
        ok('ein Ausfall von OpenStreetMap kostet auch hier nur den Ortsnamen');

        // Verweigert das Geraet den Standort, wird der Nutzer benachrichtigt.
        await neueSeite();
        global.__gpsFehler = 'abgelehnt';
        $('#current-location').trigger('click');
        assert.strictEqual(koordinaten().lat, '', 'ohne Standort darf kein Punkt entstehen');
        assert.ok(global.__meldungen.some(m => m.includes('abgelehnt')),
            'der Nutzer erfaehrt nicht, warum kein Standort kam');
        ok('ein verweigerter Standort meldet sich und setzt nichts');
    }

    console.error('\n7) Kein 500-ms-Behelf mehr im Code');
    {
        // Das setTimeout(500) war ein Wettlauf gegen denselben Loeschzweig.
        const ohneKommentare = QUELLE.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
        assert.ok(!/setTimeout\([\s\S]{0,400}?,\s*500\)/.test(ohneKommentare),
            'in map.js steht wieder eine 500-ms-Wartezeit - vermutlich als Behelf gegen einen Wettlauf');
        ok('kein 500-ms-Behelf mehr im Code');
    }

    console.error('\n' + passed + ' Pruefungen bestanden.');
    process.exit(0);
})().catch(e => {
    console.error('\nFEHLGESCHLAGEN:', e.message, '\n', e.stack);
    process.exit(1);
});
