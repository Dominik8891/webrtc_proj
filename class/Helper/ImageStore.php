<?php
namespace App\Helper;

/**
 * Die hochgeladenen Bilder dieser Anwendung: pruefen, umrechnen, ablegen.
 *
 * ZWEI ARTEN, EIN WEG. Standortbilder (bis zu fuenf je Standort) und das
 * Avatarbild eines Guides (genau eines je Konto) durchlaufen dieselben
 * Pruefungen und dieselbe Umrechnung; sie unterscheiden sich nur darin,
 * WOHIN sie kommen und in WELCHEN MASSEN. Genau dafuer sind pruefe() und
 * schreibe() getrennt: Ein zweiter Upload-Weg mit eigenen Pruefungen waere
 * ein zweiter Weg, eine davon zu vergessen - und die vergessene waere die,
 * die das EXIF entfernt.
 *
 * DIESE KLASSE FASST DATEIEN AN, KEINE DATENBANK. Welche Bilder es zu einem
 * Standort gibt und in welcher Reihenfolge sie stehen, weiss
 * App\Model\LocationImage; welches Bild zu einem Guide gehoert, weiss
 * App\Model\GuideProfile. Die Trennung ist Absicht: Eine verwaiste Zeile
 * ohne Datei ist ein Anzeigefehler, eine verwaiste Datei ohne Zeile ist
 * belegter Plattenplatz - beides laesst sich getrennt aufraeumen, wenn beide
 * Seiten getrennt ansprechbar sind.
 *
 * DREI ENTSCHEIDUNGEN, DIE ALLES WEITERE BESTIMMEN
 * ------------------------------------------------
 * 1. AUSSERHALB DES WEBROOTS. Eine hochgeladene Datei ist Fremdeingabe. Liegt
 *    sie unter dem Document Root, ist sie ueber HTTP erreichbar - und was der
 *    Webserver mit ihr macht, entscheidet dann seine Konfiguration und nicht
 *    diese Anwendung. Der Pfad kommt aus config/uploads.php.
 *
 * 2. ALLES WIRD ZU JPEG UMGERECHNET. Nicht gespeichert, sondern NEU
 *    GEZEICHNET: Die Datei wird eingelesen, in ein GD-Bild verwandelt und
 *    daraus neu geschrieben. Was dabei nicht Bildpunkt ist, ueberlebt es
 *    nicht - kein EXIF, kein eingebetteter Kommentar, kein angehaengter
 *    Datenblock, keine als Bild getarnte Datei mit HTML- oder PHP-Anteil.
 *
 *    Der zweite Grund ist Datenschutz und wiegt schwerer: In den EXIF-Daten
 *    eines Handyfotos stehen GPS-Koordinaten. Ein Guide, der zuhause ein Foto
 *    aussucht, wuerde sonst seine Wohnadresse mitveroeffentlichen - an einem
 *    Standort, dessen Treffpunkt er bewusst woanders gesetzt hat.
 *
 *    Bezahlt wird das mit der Transparenz eines PNG und mit etwas Schaerfe
 *    bei Schrift. Fuer Ortsfotos ist beides ohne Bedeutung.
 *
 * 3. DER NAME IST ZUFALLIG. Gespeichert wird unter 32 Hexzeichen, der
 *    urspruengliche Dateiname wird verworfen. Er ist Fremdeingabe (".. /",
 *    "bild.php", ein Name mit Steuerzeichen) und wird nirgends gebraucht. Zu
 *    einer Datei fuehrt allein die Zeile in location_image.
 *
 * WAS BEIM AUSLIEFERN PASSIERT
 * ----------------------------
 * Nichts von hier - das ist Sache der Controller
 * (LocationController::serveImage, GuideProfileController::serveAvatar), denn
 * dort haengt die Frage dran, ob der Standort gesperrt ist bzw. ob es das
 * Konto ueberhaupt noch gibt. Diese Klasse sagt nur, WO die Datei liegt.
 */
class ImageStore
{
    /** Endung der Vollansicht. */
    private const SUFFIX_FULL = '.jpg';

    /** Endung des Vorschaubildes - derselbe Name, anderes Ende. */
    private const SUFFIX_THUMB = '_t.jpg';

    /** Zwischenspeicher fuer config/uploads.php, einmal je Anfrage. */
    private static $config = null;

    /**
     * Die Konfiguration aus config/uploads.php.
     *
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../../config/uploads.php';
        }
        return self::$config;
    }

    /**
     * Setzt die Konfiguration zur Laufzeit (nur fuer die Pruefskripte).
     *
     * @param array<string,mixed>|null $werte null stellt auf die Datei zurueck
     * @return void
     */
    public static function setConfig(?array $werte): void
    {
        self::$config = $werte;
    }

    /**
     * Wie viele Bilder darf dieses Konto an einem Standort zeigen?
     *
     * DIE EINZIGE LESESTELLE der Obergrenze. Heute gibt sie fuer jedes Konto
     * denselben Wert zurueck und beachtet $in_user_id gar nicht - die
     * Staffelung je Konto ist ausdruecklich noch nicht gebaut.
     *
     * Der Parameter steht trotzdem schon da, und das ist der ganze Sinn
     * dieser Methode: Kommt die Staffelung, bekommt genau dieser Rumpf seine
     * Abfrage, und kein einziger Aufrufer aendert sich. Ohne die Methode
     * stuende die Zahl an drei Stellen im Controller.
     *
     * SIE GILT FUER DIE SUMME BEIDER BILDARTEN - Titelbild UND
     * Beispielbilder (App\Model\LocationImage::ROLE_COVER bzw.
     * ROLE_GALLERY). Das ist eine Entscheidung und keine Nachlaessigkeit: Eine
     * getrennte Grenze je Art waere ueber den Umweg "als Titelbild markieren"
     * zu umgehen, und gezaehlt wird ohnehin, was auf der Platte liegt.
     *
     * Soll spaeter je Kontoart auch die AUFTEILUNG unterschiedlich sein - etwa
     * "vier Beispielbilder plus ein Titelbild" -, bekommt diese Methode einen
     * zweiten Parameter fuer die Rolle. Sie bleibt die eine Lesestelle; die
     * Aufrufer fragen dann nach dem, was sie gerade ablegen wollen.
     *
     * @param int $in_user_id Eigentuemer des Standorts
     * @return int Obergrenze fuer Titelbild und Beispielbilder zusammen
     */
    public static function maxImages($in_user_id): int
    {
        $config = self::config();
        return max(1, (int)($config['max_images_per_location'] ?? 5));
    }

    /**
     * Verzeichnis eines Standorts.
     *
     * @param int  $in_location_id
     * @param bool $in_create true legt es an, wenn es fehlt
     * @return string|null null, wenn es nicht angelegt werden konnte
     */
    public static function locationDir($in_location_id, bool $in_create = false): ?string
    {
        $id = (int)$in_location_id;
        if ($id < 1) return null;

        $config = self::config();
        $pfad   = $config['base_path'] . '/locations/' . $id;

        if (!is_dir($pfad)) {
            if (!$in_create) return $pfad;
            // 0750 wie beim Logverzeichnis: Eigentuemer voll, Gruppe lesend,
            // sonst niemand. Das @ faengt den Fall ab, dass eine parallele
            // Anfrage schneller war.
            if (!@mkdir($pfad, 0750, true) && !is_dir($pfad)) {
                error_log('ImageStore: Verzeichnis nicht anlegbar: ' . $pfad);
                return null;
            }
            @chmod($pfad, 0750);
        }
        return $pfad;
    }

    /**
     * Verzeichnis der Avatarbilder eines Kontos.
     *
     * EIN VERZEICHNIS JE GUIDE, obwohl darin hoechstens ein Bild liegt (in
     * zwei Groessen). Der Grund ist derselbe wie bei den Standorten: Beim
     * Ersetzen entsteht kurz die neue Datei neben der alten, und beim
     * Aufraeumen soll sich loeschen lassen, was zu genau diesem Konto
     * gehoert - ohne dass ein Muster ueber ein gemeinsames Verzeichnis
     * laufen muss.
     *
     * @param int  $in_user_id
     * @param bool $in_create true legt es an, wenn es fehlt
     * @return string|null null, wenn es nicht angelegt werden konnte
     */
    public static function guideDir($in_user_id, bool $in_create = false): ?string
    {
        $id = (int)$in_user_id;
        if ($id < 1) return null;

        $config = self::config();
        $pfad   = $config['base_path'] . '/guides/' . $id;

        if (!is_dir($pfad)) {
            if (!$in_create) return $pfad;
            if (!@mkdir($pfad, 0750, true) && !is_dir($pfad)) {
                error_log('ImageStore: Verzeichnis nicht anlegbar: ' . $pfad);
                return null;
            }
            @chmod($pfad, 0750);
        }
        return $pfad;
    }

    /**
     * Ist das ein Name, den diese Klasse selbst vergeben hat?
     *
     * Der Name kommt aus der Datenbank, wird aber trotzdem geprueft, bevor er
     * in einen Pfad geht: Zwischen der Zeile und dem Dateisystem soll keine
     * Annahme stehen, sondern eine Pruefung. 32 Hexzeichen koennen kein
     * "..", keinen Schraegstrich und kein Nullbyte enthalten.
     *
     * @param mixed $in_name
     * @return bool
     */
    public static function isValidName($in_name): bool
    {
        return is_string($in_name) && preg_match('/^[0-9a-f]{32}$/', $in_name) === 1;
    }

    /**
     * Vollstaendiger Pfad einer abgelegten Datei.
     *
     * @param int    $in_location_id
     * @param string $in_name  Basisname ohne Endung (32 Hexzeichen)
     * @param string $in_size  'full' oder 'thumb'
     * @return string|null null bei unbrauchbaren Angaben
     */
    public static function pathFor($in_location_id, $in_name, string $in_size = 'full'): ?string
    {
        if (!self::isValidName($in_name)) return null;
        $dir = self::locationDir($in_location_id);
        if ($dir === null) return null;

        return $dir . '/' . $in_name . ($in_size === 'thumb' ? self::SUFFIX_THUMB : self::SUFFIX_FULL);
    }

    /**
     * Vollstaendiger Pfad eines Avatarbildes.
     *
     * Dieselben zwei Groessen wie bei den Standortbildern und derselbe
     * Namenspruefer: 'thumb' fuer Listen und Kacheln, 'full' fuer die
     * Kopfzeile der Profilseite.
     *
     * @param int    $in_user_id
     * @param string $in_name  Basisname ohne Endung (32 Hexzeichen)
     * @param string $in_size  'full' oder 'thumb'
     * @return string|null null bei unbrauchbaren Angaben
     */
    public static function avatarPathFor($in_user_id, $in_name, string $in_size = 'full'): ?string
    {
        if (!self::isValidName($in_name)) return null;
        $dir = self::guideDir($in_user_id);
        if ($dir === null) return null;

        return $dir . '/' . $in_name . ($in_size === 'thumb' ? self::SUFFIX_THUMB : self::SUFFIX_FULL);
    }

    /**
     * Nimmt eine hochgeladene Datei an.
     *
     * Der Ablauf in der Reihenfolge, in der abgewiesen wird - jede Stufe
     * kostet mehr als die davor, deshalb kommt die billigste zuerst:
     *
     *   1. Der Uploadfehler von PHP selbst (Datei zu gross fuer die php.ini,
     *      abgebrochene Uebertragung).
     *   2. Ist es wirklich eine hochgeladene Datei? is_uploaded_file()
     *      verhindert, dass ein Pfad aus der Anfrage auf eine beliebige
     *      Datei des Servers zeigt.
     *   3. Die Groesse in Byte.
     *   4. Der ECHTE Typ aus dem Inhalt, nicht aus dem Namen und auch nicht
     *      aus dem vom Browser gemeldeten Content-Type - beide sind
     *      Fremdeingabe.
     *   5. Die Kantenlaenge. Erst danach wird das Bild ueberhaupt in den
     *      Speicher geladen: Ein Bild mit 30000 Punkten Kantenlaenge ist als
     *      Datei winzig und als GD-Bild mehrere Gigabyte.
     *   6. Umrechnen und schreiben.
     *
     * DIE PRUEFUNGEN STEHEN IN pruefe(), DAS SCHREIBEN IN schreibe(). Diese
     * Methode setzt nur beides zusammen und weiss als Einzige, dass es um
     * einen Standort geht. Das Avatarbild eines Guides geht denselben Weg
     * mit anderen Massen - siehe storeAvatar().
     *
     * @param array  $in_file        Ein Eintrag aus $_FILES
     * @param int    $in_location_id Standort, zu dem das Bild gehoert
     * @return array{ok:bool, name?:string, error?:string}
     */
    public static function store(array $in_file, $in_location_id): array
    {
        $config = self::config();

        $geprueft = self::pruefe($in_file);
        if (!$geprueft['ok']) return $geprueft;
        $quelle = $geprueft['bild'];

        // Das Verzeichnis entsteht erst JETZT: Eine abgewiesene Datei soll
        // keinen leeren Ordner hinterlassen.
        $verzeichnis = self::locationDir($in_location_id, true);
        if ($verzeichnis === null) {
            imagedestroy($quelle);
            return self::fehler(I18n::t('bild.fehler.nicht_gespeichert'));
        }

        return self::schreibe($quelle, $verzeichnis, [
            [self::SUFFIX_FULL,  (int)$config['full_edge'],   null],
            [self::SUFFIX_THUMB, (int)$config['thumb_width'], (int)$config['thumb_height']],
        ]);
    }

    /**
     * Nimmt das Avatarbild eines Guides an.
     *
     * DERSELBE WEG WIE BEI EINEM STANDORTBILD - dieselben Pruefungen,
     * dieselbe Umrechnung, dasselbe Entfernen des EXIF-Blocks. Zwei
     * Unterschiede, und beide stehen in dieser Methode:
     *
     *   1. Es landet unter <base>/guides/<user_id>/ statt unter locations/.
     *   2. BEIDE GROESSEN SIND QUADRATISCH. Ein Portraet ist mal hochkant,
     *      mal quer aufgenommen; nebeneinandergestellt ergaebe das eine
     *      unruhige Reihe. Geschnitten wird mittig - dort steht auf einem
     *      Portraet der Kopf.
     *
     * EIN BILD JE GUIDE: Diese Methode legt nur die Datei ab und gibt ihren
     * Namen zurueck. Dass das vorherige Bild danach geloescht wird, ist
     * Sache des Aufrufers - er ist auch derjenige, der den neuen Namen in
     * die Datenbank schreibt, und geloescht werden darf erst danach.
     *
     * @param array $in_file    Ein Eintrag aus $_FILES
     * @param int   $in_user_id Konto, zu dem das Bild gehoert
     * @return array{ok:bool, name?:string, error?:string}
     */
    public static function storeAvatar(array $in_file, $in_user_id): array
    {
        $config = self::config();

        $geprueft = self::pruefe($in_file);
        if (!$geprueft['ok']) return $geprueft;
        $quelle = $geprueft['bild'];

        $verzeichnis = self::guideDir($in_user_id, true);
        if ($verzeichnis === null) {
            imagedestroy($quelle);
            return self::fehler(I18n::t('bild.fehler.nicht_gespeichert'));
        }

        $kante  = max(1, (int)($config['avatar_edge']  ?? 512));
        $klein  = max(1, (int)($config['avatar_thumb'] ?? 128));

        return self::schreibe($quelle, $verzeichnis, [
            [self::SUFFIX_FULL,  $kante, $kante],
            [self::SUFFIX_THUMB, $klein, $klein],
        ]);
    }

    /**
     * Prueft eine hochgeladene Datei und gibt sie als GD-Bild zurueck.
     *
     * DIE STELLE, AN DER FREMDEINGABE ZU EINEM BILD WIRD - und die einzige.
     * Wer einen weiteren Upload baut, ruft diese Methode auf; wer sie
     * umgeht, umgeht auch das Entfernen des EXIF-Blocks.
     *
     * Der Aufrufer bekommt ein GD-Bild und ist ab da fuer dessen Freigabe
     * zustaendig (imagedestroy) - schreibe() erledigt das.
     *
     * @param array $in_file Ein Eintrag aus $_FILES
     * @return array{ok:bool, bild?:mixed, error?:string}
     */
    private static function pruefe(array $in_file): array
    {
        $config = self::config();

        $fehler = isset($in_file['error']) ? (int)$in_file['error'] : UPLOAD_ERR_NO_FILE;
        if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
            return self::fehler(I18n::t('bild.fehler.zu_gross'));
        }
        if ($fehler !== UPLOAD_ERR_OK) {
            error_log('ImageStore: Upload-Fehlercode ' . $fehler);
            return self::fehler(I18n::t('bild.fehler.nicht_empfangen'));
        }

        $tmp = $in_file['tmp_name'] ?? '';
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            error_log('ImageStore: tmp_name ist keine hochgeladene Datei.');
            return self::fehler(I18n::t('bild.fehler.nicht_empfangen'));
        }

        $bytes = (int)@filesize($tmp);
        $grenze = (int)$config['max_file_bytes'];
        if ($bytes < 1 || $bytes > $grenze) {
            return self::fehler(I18n::t('bild.fehler.grenze',
                ['mb' => round($grenze / 1048576)]));
        }

        // getimagesize() liest den Kopf der Datei und liefert Typ UND Masse
        // in einem Zug. Ein PDF, ein ZIP oder ein Textstueck mit der Endung
        // .jpg kommt hier als false zurueck.
        $info = @getimagesize($tmp);
        if ($info === false || empty($info['mime'])) {
            return self::fehler(I18n::t('bild.fehler.kein_bild'));
        }
        if (!in_array($info['mime'], $config['accepted_mime'], true)) {
            return self::fehler(I18n::t('bild.fehler.format'));
        }

        [$breite, $hoehe] = $info;
        $maxKante = (int)$config['max_source_edge'];
        if ($breite < 1 || $hoehe < 1 || $breite > $maxKante || $hoehe > $maxKante) {
            return self::fehler(I18n::t('bild.fehler.masse', ['kante' => $maxKante]));
        }

        $quelle = self::readImage($tmp, $info['mime']);
        if ($quelle === null) {
            return self::fehler(I18n::t('bild.fehler.nicht_lesbar'));
        }

        // Die Drehung steht bei einem Handyfoto NUR im EXIF-Block, und der
        // faellt beim Umrechnen weg. Wird sie nicht vorher angewandt, liegt
        // jedes Hochkantfoto anschliessend auf der Seite.
        $quelle = self::applyExifRotation($quelle, $tmp, $info['mime']);

        return ['ok' => true, 'bild' => $quelle];
    }

    /**
     * Schreibt ein geprueftes Bild in seinen Groessen weg.
     *
     * DER NAME ENTSTEHT HIER und ist zufaellig: 32 Hexzeichen, der
     * urspruengliche Dateiname wird verworfen (siehe Klassenkopf, Punkt 3).
     * Alle Groessen tragen denselben Namen und unterscheiden sich nur in der
     * Endung - so fuehrt EIN Eintrag in der Datenbank zu allen Dateien.
     *
     * Das GD-Bild wird in jedem Fall freigegeben, auch im Fehlerfall: Es
     * belegt ein Vielfaches der Dateigroesse im Arbeitsspeicher.
     *
     * @param \GdImage|resource $in_quelle
     * @param string $in_verzeichnis
     * @param array<int,array{0:string,1:int,2:int|null}> $in_ausgaben
     *        Je Eintrag: Endung, Breite, Hoehe (null = einpassen statt
     *        beschneiden, siehe writeScaled)
     * @return array{ok:bool, name?:string, error?:string}
     */
    private static function schreibe($in_quelle, string $in_verzeichnis, array $in_ausgaben): array
    {
        $config = self::config();
        $name   = bin2hex(random_bytes(16));
        $ok     = true;

        foreach ($in_ausgaben as [$endung, $breite, $hoehe]) {
            $ok = $ok && self::writeScaled($in_quelle, $in_verzeichnis . '/' . $name . $endung,
                             $breite, $hoehe, (int)$config['jpeg_quality']);
        }

        imagedestroy($in_quelle);

        if (!$ok) {
            // Halb geschriebene Bilder nicht liegen lassen - sonst zeigt die
            // Seite spaeter eine Vorschau ohne Vollansicht.
            foreach ($in_ausgaben as [$endung, , ]) {
                $pfad = $in_verzeichnis . '/' . $name . $endung;
                if (is_file($pfad)) @unlink($pfad);
            }
            return self::fehler(I18n::t('bild.fehler.nicht_gespeichert'));
        }

        return ['ok' => true, 'name' => $name];
    }

    /**
     * Loescht beide Dateien eines Bildes.
     *
     * Ein fehlendes File ist kein Fehler: Wer aufraeumt, will den Zustand
     * "weg" erreichen, und der ist dann schon da.
     *
     * @param int    $in_location_id
     * @param string $in_name
     * @return void
     */
    public static function delete($in_location_id, $in_name): void
    {
        foreach (['full', 'thumb'] as $groesse) {
            $pfad = self::pathFor($in_location_id, $in_name, $groesse);
            if ($pfad !== null && is_file($pfad)) {
                @unlink($pfad);
            }
        }
    }

    /**
     * Loescht beide Dateien eines Avatarbildes.
     *
     * Gebraucht beim Ersetzen und beim Entfernen. Ein fehlendes File ist
     * kein Fehler - wer aufraeumt, will den Zustand "weg" erreichen, und der
     * ist dann schon da.
     *
     * @param int    $in_user_id
     * @param string $in_name
     * @return void
     */
    public static function deleteAvatar($in_user_id, $in_name): void
    {
        foreach (['full', 'thumb'] as $groesse) {
            $pfad = self::avatarPathFor($in_user_id, $in_name, $groesse);
            if ($pfad !== null && is_file($pfad)) {
                @unlink($pfad);
            }
        }
    }

    /**
     * Loescht das gesamte Verzeichnis eines Standorts.
     *
     * Gebraucht beim Loeschen eines Standorts: Die Zeilen in location_image
     * nimmt der Fremdschluessel mit (ON DELETE CASCADE), die Dateien nicht -
     * die Datenbank kennt das Dateisystem nicht. Ohne diesen Aufruf blieben
     * sie fuer immer liegen.
     *
     * Geloescht wird nur, was diese Klasse selbst angelegt haben kann: ein
     * Verzeichnis unterhalb der Basis, und darin nur Dateien mit dem eigenen
     * Namensmuster. Ein rekursives Loeschen ohne diese Einschraenkung waere
     * bei einem falsch gesetzten UPLOAD_PATH eine Katastrophe.
     *
     * @param int $in_location_id
     * @return void
     */
    public static function deleteLocationDir($in_location_id): void
    {
        $dir = self::locationDir($in_location_id);
        if ($dir === null || !is_dir($dir)) return;

        foreach ((array)@scandir($dir) as $eintrag) {
            if (preg_match('/^[0-9a-f]{32}(_t)?\.jpg$/', (string)$eintrag) === 1) {
                @unlink($dir . '/' . $eintrag);
            }
        }
        // Nur ein leeres Verzeichnis - liegt darin noch etwas anderes, ist
        // das ein Fall fuer einen Menschen und nicht fuer ein rmdir.
        @rmdir($dir);
    }

    /**
     * Liest eine Bilddatei als GD-Bild ein.
     *
     * @param string $in_pfad
     * @param string $in_mime
     * @return \GdImage|resource|null
     */
    private static function readImage(string $in_pfad, string $in_mime)
    {
        $bild = false;
        if ($in_mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $bild = @imagecreatefromjpeg($in_pfad);
        } elseif ($in_mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $bild = @imagecreatefrompng($in_pfad);
        } elseif ($in_mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $bild = @imagecreatefromwebp($in_pfad);
        }

        if ($bild === false) {
            error_log('ImageStore: ' . $in_mime . ' konnte nicht gelesen werden - fehlt die GD-Extension?');
            return null;
        }
        return $bild;
    }

    /**
     * Wendet die im EXIF vermerkte Drehung an.
     *
     * Nur bei JPEG - nur dort gibt es diesen Block. Fehlt die exif-Extension,
     * bleibt das Bild wie es ist; ein liegendes Foto ist ein Schoenheitsfehler
     * und kein Grund, den Upload abzuweisen.
     *
     * @param \GdImage|resource $in_bild
     * @param string $in_pfad
     * @param string $in_mime
     * @return \GdImage|resource
     */
    private static function applyExifRotation($in_bild, string $in_pfad, string $in_mime)
    {
        if ($in_mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $in_bild;

        $exif = @exif_read_data($in_pfad);
        if (!is_array($exif) || empty($exif['Orientation'])) return $in_bild;

        $winkel = 0;
        switch ((int)$exif['Orientation']) {
            case 3: $winkel = 180; break;
            case 6: $winkel = -90; break;
            case 8: $winkel =  90; break;
        }
        if ($winkel === 0) return $in_bild;

        $gedreht = @imagerotate($in_bild, $winkel, 0);
        if ($gedreht === false) return $in_bild;

        imagedestroy($in_bild);
        return $gedreht;
    }

    /**
     * Schreibt ein verkleinertes JPEG.
     *
     * ZWEI ARTEN ZU VERKLEINERN, ueber $in_hoehe unterschieden:
     *
     *   $in_hoehe === null  Einpassen. Die laengste Kante bekommt $in_breite,
     *                       das Seitenverhaeltnis bleibt. So entsteht die
     *                       Vollansicht - ein Bild soll nicht beschnitten
     *                       werden, nur weil es hochkant ist.
     *
     *   $in_hoehe gesetzt   Ausschnitt. Das Ergebnis hat genau diese Masse,
     *                       ueberstehende Raender fallen weg. So entsteht die
     *                       Vorschau: In einer Reihe gleich grosser Kacheln
     *                       stoert ein Hochformat zwischen Querformaten mehr
     *                       als ein beschnittener Rand.
     *
     * Kleiner als das Original wird nie hochgerechnet - das brachte nur
     * Dateigroesse ohne Bildinformation.
     *
     * @param \GdImage|resource $in_quelle
     * @param string   $in_ziel
     * @param int      $in_breite
     * @param int|null $in_hoehe
     * @param int      $in_qualitaet
     * @return bool
     */
    private static function writeScaled($in_quelle, string $in_ziel, int $in_breite, ?int $in_hoehe, int $in_qualitaet): bool
    {
        $qb = imagesx($in_quelle);
        $qh = imagesy($in_quelle);
        if ($qb < 1 || $qh < 1) return false;

        if ($in_hoehe === null) {
            // Einpassen. Ein bereits kleineres Bild bleibt, wie es ist.
            $faktor = min(1.0, $in_breite / max($qb, $qh));
            $zb = max(1, (int)round($qb * $faktor));
            $zh = max(1, (int)round($qh * $faktor));
            $sx = 0; $sy = 0; $sb = $qb; $sh = $qh;
        } else {
            // Ausschnitt: den groessten mittigen Bereich im Zielverhaeltnis
            // waehlen und diesen auf die Zielmasse rechnen.
            $zb = $in_breite;
            $zh = $in_hoehe;
            $zielVerhaeltnis   = $zb / $zh;
            $quelleVerhaeltnis = $qb / $qh;

            if ($quelleVerhaeltnis > $zielVerhaeltnis) {
                $sh = $qh;
                $sb = (int)round($qh * $zielVerhaeltnis);
                $sx = (int)round(($qb - $sb) / 2);
                $sy = 0;
            } else {
                $sb = $qb;
                $sh = (int)round($qb / $zielVerhaeltnis);
                $sx = 0;
                $sy = (int)round(($qh - $sh) / 2);
            }
        }

        $ziel = @imagecreatetruecolor($zb, $zh);
        if ($ziel === false) return false;

        // JPEG kennt keine Transparenz. Ohne diese Flaeche wuerde ein
        // transparentes PNG auf schwarzem Grund landen - Weiss ist der
        // Hintergrund, den ein Betrachter erwartet.
        $weiss = imagecolorallocate($ziel, 255, 255, 255);
        imagefilledrectangle($ziel, 0, 0, $zb, $zh, $weiss);

        $ok = @imagecopyresampled($ziel, $in_quelle, 0, 0, $sx, $sy, $zb, $zh, $sb, $sh)
           && @imagejpeg($ziel, $in_ziel, $in_qualitaet);

        imagedestroy($ziel);

        if ($ok) {
            // 0640: Der Webserver liest, sonst niemand. Die Datei wird ohnehin
            // nur ueber den Controller ausgeliefert.
            @chmod($in_ziel, 0640);
        }
        return (bool)$ok;
    }

    /**
     * Baut eine Fehlerantwort.
     *
     * @param string $in_text Meldung fuer den Nutzer
     * @return array{ok:bool, error:string}
     */
    private static function fehler(string $in_text): array
    {
        return ['ok' => false, 'error' => $in_text];
    }
}
