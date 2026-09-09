<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\I18n;
use App\Helper\Role;
use App\Helper\Request;
use App\Helper\ViewHelper;
use App\Model\GuideRole;
use App\Model\User;

/**
 * Die Frage "möchtest du Guide werden?" - stellen und beantworten.
 *
 * WARUM ES DIESEN DIALOG GIBT
 * ---------------------------
 * Vorher wurde man Guide, ohne es zu merken: Wer einen Standort anlegte,
 * bekam die Rolle stillschweigend dazu (LocationController::setLocation,
 * alter Stand). Guide zu sein heißt aber, sich von fremden Menschen vor Ort
 * steuern zu lassen - und künftig kostet jede Führung Geld. Das ist nichts,
 * was als Nebenwirkung eines Formulars passieren darf.
 *
 * WO DER DIALOG AUFTAUCHT
 * -----------------------
 *   1. Über die Einstellungsseite (App\Controller\SettingsController).
 *   2. Über den Knopf der Kopfleiste, der für einen Zuschauer "Jetzt
 *      Tour-Guide werden!" heißt (assets/js/ui.js).
 *   3. Vor dem Standortformular, wenn die Zustimmung zu den Bedingungen
 *      fehlt oder veraltet ist - siehe requireCurrentTerms().
 *
 * Alle Wege zeigen dieselbe Seite, nur mit anderen Knöpfen - der Text, der
 * die Rolle erklärt, steht damit an genau einer Stelle.
 *
 * NICHT MEHR NACH DEM LOGIN. Die Frage stand früher als ganzseitiger Dialog
 * direkt hinter der ersten Anmeldung - gestellt, bevor der Nutzer die
 * Anwendung überhaupt gesehen hatte. Wer sich für eine Rolle entscheiden
 * soll, in der er sich von Fremden vor Ort steuern lässt, muss vorher wissen,
 * worum es geht. Die Entscheidung ist keine Einbahnstraße und lässt sich
 * jederzeit ändern; sie muss nicht am ersten Tag fallen.
 *
 * Zugang: Recht user.guide_role, geprüft in index.php. Der Admin hat es
 * nicht - er würde beim Annehmen der Guide-Rolle seine Adminrechte verlieren.
 */
class GuideController
{
    /**
     * Zeigt die Seite mit der Guide-Frage.
     *
     * @return never
     */
    public function showGuideRolePage(): void
    {
        $out = ViewHelper::template('assets/html/guide_role.html');

        $role      = Auth::roleId();
        $is_guide  = Role::isGuide($role);
        $undecided = Role::isUndecided($role);
        // Guide, dessen Zustimmung eine ältere Fassung trägt oder ganz fehlt.
        // Er ist hier meist nicht freiwillig, sondern weil ihn das
        // Standortformular hergeschickt hat (requireCurrentTerms).
        $terms_open = $is_guide && GuideRole::needsDecision(Auth::userId(), $role);

        // Der Hinweis auf die Kosten steht im Template und gilt für alle
        // Fälle. Hier unterscheiden sich nur Statuszeile und Knöpfe.
        if ($terms_open) {
            // OHNE DIESEN ZWEIG WÄRE DIE WEITERLEITUNG EINE SACKGASSE: Ein
            // Guide bekam bisher nur "Guide-Rolle zurückgeben" zu sehen - also
            // keine Möglichkeit, das zu tun, wozu er hergeschickt wurde.
            // GuideRole::accept() kann den Fall längst (es frischt bei einem
            // Guide nur das Profil auf und lässt die Rolle stehen), es fehlte
            // allein der Knopf.
            $status  = self::statusHtml('guide.rolle.status_bedingungen', 'guide.rolle.wort_guide');
            $actions = self::button('accept', I18n::t('guide.rolle.bestaetigen'), 'btn-success')
                     . self::button('resign', I18n::t('guide.rolle.zurueckgeben'), 'btn-outline-danger');
        } elseif ($is_guide) {
            $status  = self::statusHtml('guide.rolle.status_guide', 'guide.rolle.wort_guide');
            $actions = self::button('resign', I18n::t('guide.rolle.zurueckgeben'), 'btn-outline-danger');
        } else {
            $status  = self::statusHtml('guide.rolle.status_zuschauer', 'guide.rolle.wort_zuschauer');
            $actions = self::button('accept', I18n::t('guide.rolle.ja'), 'btn-success');
            if ($undecided) {
                // Nur solange die Frage wirklich offen ist, gibt es ein
                // ausdrückliches "Nein". Wer schon Zuschauer ist, hat es
                // bereits gesagt.
                $actions .= self::button('decline', I18n::t('guide.rolle.nein'), 'btn-outline-secondary');
            }
        }

        // "Später entscheiden" führt einfach zur Startseite: Die Rolle bleibt
        // Trial, und die Frage steht weiter in den Einstellungen und auf dem
        // Knopf der Kopfleiste. Der Dialog ist eine Frage, keine Sperre.
        $later = $undecided
            ? '<div class="mt-3"><a href="index.php?act=home" class="link-secondary">'
              . htmlspecialchars(I18n::t('guide.rolle.spaeter')) . '</a></div>'
            : '';

        $hint = self::hintFor($role, $terms_open);

        // DIE BEIDEN ERKLAERENDEN ABSAETZE. Sie standen bis zum Umzug der
        // Seitentexte in der Vorlage - dort geht es nicht mehr: Beide tragen
        // eine Hervorhebung MITTEN im Satz, und ein Marker {{t:...}} nimmt
        // keine Werte entgegen. Zerschnitten waeren es Satzteile, und die
        // naechste Sprache bekaeme die deutsche Wortstellung aufgezwungen.
        $out = str_replace('###GUIDE_ROLE_TEXT###',
            ViewHelper::tHtml('guide.rolle.was_guide.text', [
                'rolle' => '<strong>' . ViewHelper::esc(I18n::t('guide.rolle.wort_guide')) . '</strong>',
                'regie' => '<strong>' . ViewHelper::esc(I18n::t('guide.rolle.was_guide.regie')) . '</strong>',
            ]), $out);
        $out = str_replace('###GUIDE_VIEWER_TEXT###',
            ViewHelper::tHtml('guide.rolle.was_zuschauer.text', [
                'rolle' => '<strong>' . ViewHelper::esc(I18n::t('guide.rolle.wort_zuschauer')) . '</strong>',
            ]), $out);

        $out = str_replace('###GUIDE_STATUS###',  $status,  $out);
        $out = str_replace('###GUIDE_ACTIONS###', $actions, $out);
        $out = str_replace('###GUIDE_LATER###',   $later,   $out);
        $out = str_replace('###GUIDE_HINT###',    $hint,    $out);

        ViewHelper::output($out);
    }

    /**
     * Haelt an, solange die Zustimmung zu den Guide-Bedingungen fehlt oder
     * veraltet ist - und fuehrt in dem Fall zur Frage.
     *
     * WO DAS GILT UND WARUM GENAU DORT
     * --------------------------------
     * Aufgerufen wird das aus App\Controller\LocationController, also dort,
     * wo ein Guide seine Rolle tatsaechlich benutzt: Er stellt ein Angebot in
     * die Welt. Das ist der einzige guide-eigene Vorgang, der NICHT in
     * Echtzeit laeuft - man kann dort anhalten und fragen, ohne dass jemand
     * am anderen Ende wartet.
     *
     * Im Signalweg waere die Pruefung fachlich naeher dran (dort entsteht die
     * Leistung, an der spaeter Geld haengt), praktisch aber die schlechteste
     * Stelle: Waehrend es klingelt, kann der Guide nichts entscheiden, und
     * der Anrufer bekaeme "bietet keine Fuehrungen an" - was nicht stimmt.
     * Eine Frage, die der Betroffene nicht beantworten kann, ist keine Frage.
     *
     * DER ADMIN KOMMT HIER NICHT HAENGEN. GuideRole::needsDecision() meldet
     * sich nur fuer Trial und fuer Guides mit veralteter Zustimmung. Der
     * Admin ist beides nicht - und das ist wesentlich: Er darf Standorte
     * anlegen (location.create), hat aber kein user.guide_role. Eine
     * Weiterleitung auf die Dialogseite endete fuer ihn in einer Absage von
     * index.php.
     *
     * @return void kehrt nur zurueck, wenn nichts offen ist
     */
    public static function requireCurrentTerms(): void
    {
        if (!GuideRole::needsDecision(Auth::userId(), Auth::roleId())) return;

        header('Location: index.php?act=guide_role_page');
        exit;
    }

    /**
     * Nimmt die Antwort entgegen.
     *
     * Nur per POST: Die Antwort ändert die Rolle des Kontos. Als Link in einer
     * Mail oder in einem fremden Bild aufrufbar darf so etwas nicht sein.
     *
     * @return never
     */
    public function handleGuideRole(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?act=guide_role_page');
            exit;
        }

        $decision = Request::g('decision');
        $user_id  = Auth::userId();
        $role     = Auth::roleId();

        switch ($decision) {

            case 'accept':
                // Der Rollenwechsel und das Festhalten der Zustimmung stehen
                // beide in App\Model\GuideRole - hier wird nur das Ergebnis
                // ausgewertet.
                if (!GuideRole::accept($user_id, $role)) {
                    $this->outputError(I18n::t('guide.rolle.fehler.uebernehmen'));
                }
                Auth::refreshRole(Role::GUIDE);

                // Hier schloss sich früher die Standortabfrage an. Sie schrieb
                // nach user.latitude/longitude - Spalten, die keine Lesestelle
                // haben - und ist mitsamt der Route save_location entfallen.
                // Was ein Guide wirklich angibt, ist ein Standort in
                // App\Controller\LocationController, und den legt er an, wenn
                // er ihn anbieten will.
                header('Location: index.php?act=home');
                exit;

            case 'decline':
                // Aus Trial wird User: die Frage ist beantwortet und wird
                // nicht wieder gestellt. Das ist kein Rechteverlust - Trial
                // und User haben dieselben Rechte.
                if (!Role::mayBecomeGuide($role)) {
                    // Ein Guide, der "nein" schickt, meint 'resign'. Dass das
                    // Formular so etwas nicht anbietet, heißt nicht, dass es
                    // nicht ankommen kann.
                    $this->outputError(I18n::t('guide.rolle.fehler.zustand'));
                }
                $user = new User($user_id);
                if (!$user->setRoleId(Role::USER) || !$user->save()) {
                    $this->outputError(I18n::t('guide.rolle.fehler.antwort'));
                }
                Auth::refreshRole(Role::USER);
                header('Location: index.php?act=home');
                exit;

            case 'resign':
                if (GuideRole::hasLocations($user_id)) {
                    // Ein Standort ohne Guide wäre ein Angebot, das niemand
                    // einlösen kann. Gelöscht wird hier nichts - das bleibt
                    // eine ausdrückliche Handlung in der eigenen
                    // Standortliste.
                    $this->outputError(I18n::t('guide.rolle.fehler.standorte',
                        ['locations' => I18n::t('guide.rolle.locations')]));
                }
                if (!GuideRole::resign($user_id, $role)) {
                    $this->outputError(I18n::t('guide.rolle.fehler.zurueckgeben'));
                }
                Auth::refreshRole(Role::USER);
                header('Location: index.php?act=settings');
                exit;

            default:
                header('Location: index.php?act=guide_role_page');
                exit;
        }
    }

    /**
     * Zeigt die Seite erneut mit einer Meldung darüber.
     *
     * @param string $msg
     * @return never
     */
    private function outputError(string $msg): void
    {
        $out = ViewHelper::template('assets/html/guide_role.html');

        $box = '<div class="alert alert-warning">' . htmlspecialchars($msg) . '</div>';

        // Auf der Fehlerseite steht die Erklaerung nicht - hier zaehlt die
        // Meldung. Gefuellt werden die beiden Platzhalter trotzdem: Ein
        // ungefuellter stuende als ###...### im Dokument.
        $out = str_replace('###GUIDE_ROLE_TEXT###',   '', $out);
        $out = str_replace('###GUIDE_VIEWER_TEXT###', '', $out);

        $out = str_replace('###GUIDE_STATUS###',  $box, $out);
        $out = str_replace('###GUIDE_ACTIONS###', '<a href="index.php?act=settings" '
            . 'class="btn btn-outline-secondary">'
            . htmlspecialchars(I18n::t('guide.rolle.zurueck')) . '</a>', $out);
        $out = str_replace('###GUIDE_LATER###',   '', $out);
        $out = str_replace('###GUIDE_HINT###',    '', $out);

        ViewHelper::output($out);
    }

    /**
     * Zusätzlicher Hinweis unter den Knöpfen, je nach Zustand des Kontos.
     *
     * @param mixed $in_role
     * @param bool  $in_terms_open Zustimmung fehlt oder ist veraltet
     * @return string
     */
    private static function hintFor($in_role, bool $in_terms_open = false): string
    {
        if ($in_terms_open) {
            return '<p class="text-muted small mb-0">'
                 . htmlspecialchars(I18n::t('guide.rolle.hinweis_bedingungen')) . '</p>';
        }
        if (Role::isGuide($in_role)) {
            return '<p class="text-muted small mb-0">'
                 . htmlspecialchars(I18n::t('guide.rolle.hinweis_guide',
                       ['locations' => I18n::t('guide.rolle.locations')])) . '</p>';
        }
        return '<p class="text-muted small mb-0">'
             . htmlspecialchars(I18n::t('guide.rolle.hinweis_offen')) . '</p>';
    }

    /**
     * Die Statuszeile - mit dem Rollenwort als Hervorhebung MITTEN im Satz.
     *
     * WARUM DER GANZE SATZ IM KATALOG STEHT und nicht "Sie sind " davor und
     * " und können Standorte anbieten." dahinter: Im Englischen gehoert ein
     * Artikel vor das Rollenwort ("You are a guide"), im Deutschen nicht.
     * Wer den Satz im Code zusammensetzt, schreibt damit die deutsche
     * Grammatik fest - und die naechste Sprache bekommt sie aufgezwungen.
     *
     * @param string $in_satz  Schluessel des ganzen Satzes
     * @param string $in_rolle Schluessel des Rollenwortes
     * @return string HTML
     */
    private static function statusHtml(string $in_satz, string $in_rolle): string
    {
        return ViewHelper::tHtml($in_satz, [
            'rolle' => '<strong>' . ViewHelper::esc(I18n::t($in_rolle)) . '</strong>',
        ]);
    }

    /**
     * Ein Formularknopf, der genau eine Antwort abschickt.
     *
     * Jede Antwort ist ein eigenes Formular mit ihrem eigenen versteckten
     * Feld. Ein einzelnes Formular mit mehreren Submit-Knöpfen würde die
     * Antwort vom Namen des gedrückten Knopfes abhängig machen - und der wird
     * bei einem Absenden per Enter-Taste gar nicht mitgeschickt.
     *
     * @param string $decision Wert für das Feld 'decision'
     * @param string $label    Beschriftung
     * @param string $css      Bootstrap-Klasse
     * @return string
     */
    private static function button(string $decision, string $label, string $css): string
    {
        return '<form action="index.php?act=guide_role" method="post" class="d-inline">'
             . '<input type="hidden" name="decision" value="' . htmlspecialchars($decision) . '">'
             . '<button type="submit" class="btn ' . $css . '">' . htmlspecialchars($label) . '</button>'
             . '</form>';
    }
}
