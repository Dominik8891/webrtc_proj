<?php
/**
 * Bilder zu einem Standort: Ablage, Obergrenzen, Masse.
 *
 * DIES IST DIE EINE STELLE, an der diese Zahlen stehen. Sie gehen von hier
 * aus an drei Verbraucher:
 *
 *   App\Helper\ImageStore        prueft und verkleinert die hochgeladene
 *                                Datei, legt sie ab und liefert sie aus,
 *   App\Controller\LocationController  weist ab, sobald die Obergrenze
 *                                erreicht ist, und gibt sie an den Browser,
 *   assets/js/location_page.js   meldet dem Guide vorab, was zu gross ist,
 *                                statt ihn acht Megabyte hochladen zu lassen,
 *                                die der Server anschliessend verwirft.
 *
 * Der Browser bekommt die Werte ueber window.uploadLimits
 * (App\Helper\ViewHelper). Eine zweite Zahl im JavaScript waere eine Zahl,
 * die niemand mit dieser hier zusammen pflegt.
 *
 * WARUM DIE OBERGRENZE HIER UND NICHT AM KONTO STEHT
 * --------------------------------------------------
 * Sie soll sich spaeter je Konto unterscheiden koennen - ein Guide mit
 * bezahltem Zugang zeigt mehr Bilder als einer ohne. Gebaut ist das noch
 * nicht, und deshalb steht hier eine einzelne Zahl und keine Tabelle.
 *
 * Vorbereitet ist der Weg dorthin trotzdem: Gelesen wird die Grenze
 * ausschliesslich ueber App\Helper\ImageStore::maxImages($user_id). Kommt die
 * Staffelung, bekommt diese eine Methode ihre Abfrage - und kein Aufrufer
 * aendert sich. Wer die Zahl stattdessen direkt aus diesem Array liest,
 * macht genau das kaputt.
 *
 * WO DIE DATEIEN LIEGEN
 * ---------------------
 * AUSSERHALB DES DOCUMENT ROOT, aus demselben Grund wie das Fehlerlog (siehe
 * config/log_path.php): Was unter dem Webroot liegt, ist ueber HTTP
 * abrufbar - und zwar auch dann, wenn es gar kein Bild ist. Eine
 * hochgeladene Datei ist Fremdeingabe; sie darf den Webserver nie direkt
 * erreichen. Ausgeliefert wird sie ueber index.php?act=location_image, also
 * durch einen Controller, der vorher prueft, ob der Standort gesperrt ist.
 *
 * Der Pfad kommt aus der Umgebungsvariablen UPLOAD_PATH, sonst aus dem
 * Fallback ../uploads eine Ebene oberhalb des Webroots. Anders als LOG_PATH
 * darf UPLOAD_PATH auch in der .env stehen: Diese Datei wird erst aus einem
 * Controller heraus geladen, also lange nachdem config/env.php gelaufen ist.
 */

// -----------------------------------------------------------------------
// Ablagepfad bestimmen. Reihenfolge wie in config/log_path.php:
// $_SERVER (Apache SetEnv, nginx fastcgi_param), $_ENV (phpdotenv),
// getenv() (Docker, systemd) - und zuletzt der Fallback.
// -----------------------------------------------------------------------
$webrtc_upload_path = null;
foreach ([$_SERVER['UPLOAD_PATH'] ?? null, $_ENV['UPLOAD_PATH'] ?? null, getenv('UPLOAD_PATH')] as $kandidat) {
    if (is_string($kandidat) && $kandidat !== '') {
        $webrtc_upload_path = $kandidat;
        break;
    }
}
if ($webrtc_upload_path === null) {
    // __DIR__ ist <Webroot>/config, also ist __DIR__/../.. der Ordner ueber
    // dem Webroot - dieselbe Ebene, auf der auch das Log liegt.
    $webrtc_upload_path = __DIR__ . '/../../uploads';
}

return [
    /**
     * Basisverzeichnis. Darunter legt ImageStore je Standort einen Ordner an:
     * <base>/locations/<location_id>/<hash>.jpg
     */
    'base_path' => rtrim($webrtc_upload_path, '/\\'),

    /**
     * Wie viele Bilder ein Standort tragen darf.
     *
     * NICHT DIREKT LESEN - siehe der Hinweis oben: ImageStore::maxImages()
     * ist die Lesestelle, damit die spaetere Staffelung je Konto genau eine
     * Methode betrifft.
     */
    'max_images_per_location' => 5,

    /**
     * Groesste angenommene Datei in Byte.
     *
     * Acht Megabyte sind reichlich fuer ein Handyfoto und klein genug, dass
     * eine Handvoll paralleler Uploads den Server nicht belegt. Die
     * verbindliche Grenze steht trotzdem zusaetzlich in der php.ini
     * (upload_max_filesize, post_max_size): Was PHP schon beim Einlesen
     * abschneidet, erreicht diese Pruefung gar nicht mehr.
     */
    'max_file_bytes' => 8 * 1024 * 1024,

    /**
     * Groesste angenommene Kantenlaenge des Originals in Pixeln.
     *
     * Der Grund ist nicht der Plattenplatz, sondern der Arbeitsspeicher:
     * imagecreatefromjpeg() legt das ganze Bild unkomprimiert ab, rund vier
     * Byte je Bildpunkt. Ein Bild mit 30000x30000 Punkten waere ein einziger
     * Aufruf, der den Prozess umbringt ("Decompression Bomb") - und es ist
     * nur ein paar hundert Kilobyte gross, faellt also durch jede
     * Groessenpruefung.
     */
    'max_source_edge' => 6000,

    /**
     * Laengste Kante des gespeicherten Bildes.
     *
     * 1600 reicht fuer eine bildschirmfuellende Ansicht auf einem
     * gewoehnlichen Bildschirm und auf den meisten Mobilgeraeten. Groesser
     * gespeichert hiesse: laengeres Laden fuer eine Aufloesung, die niemand
     * sieht.
     */
    'full_edge' => 1600,

    /**
     * Masse des Vorschaubildes. Fester Ausschnitt, kein Einpassen: In einer
     * Reihe gleich grosser Kacheln stoert ein Hochformat zwischen
     * Querformaten mehr als der beschnittene Rand.
     */
    'thumb_width'  => 480,
    'thumb_height' => 320,

    /**
     * JPEG-Qualitaet der abgelegten Bilder. 82 ist der uebliche Kompromiss:
     * bei einem Foto vom Original nicht zu unterscheiden, aber rund ein
     * Drittel kleiner als 95.
     */
    'jpeg_quality' => 82,

    /**
     * Angenommene Bildarten.
     *
     * Gelesen wird der Typ aus dem INHALT der Datei (finfo bzw.
     * getimagesize), nie aus der Endung des Namens: Die kommt vom Browser des
     * Hochladenden und sagt gar nichts. Gespeichert wird anschliessend
     * ohnehin nur JPEG - siehe ImageStore.
     */
    'accepted_mime' => ['image/jpeg', 'image/png', 'image/webp'],
];
