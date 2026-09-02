<?php
namespace App\Controller;

use App\Helper\Auth;
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
 *   1. Nach dem Login, solange die Frage offen ist (Rolle Trial) - siehe
 *      LoginController::handleLogin.
 *   2. Jederzeit über die Einstellungsseite. Die Entscheidung ist keine
 *      Einbahnstraße: Ein Guide kann die Rolle zurückgeben, ein Zuschauer sie
 *      annehmen.
 *
 * Beide Wege zeigen dieselbe Seite, nur mit anderen Knöpfen - der Text, der
 * die Rolle erklärt, steht damit an genau einer Stelle.
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

        // Der Hinweis auf die Kosten steht im Template und gilt für alle drei
        // Fälle. Hier unterscheiden sich nur Statuszeile und Knöpfe.
        if ($is_guide) {
            $status  = 'Sie sind <strong>Guide</strong> und können Standorte anbieten.';
            $actions = self::button('resign', 'Guide-Rolle zurückgeben', 'btn-outline-danger');
        } else {
            $status  = 'Sie sind <strong>Zuschauer</strong>. Sie können Führungen buchen, '
                     . 'aber keine Standorte anbieten.';
            $actions = self::button('accept', 'Ja, ich möchte Guide werden', 'btn-success');
            if ($undecided) {
                // Nur solange die Frage wirklich offen ist, gibt es ein
                // ausdrückliches "Nein". Wer schon Zuschauer ist, hat es
                // bereits gesagt.
                $actions .= self::button('decline', 'Nein, ich möchte nur zuschauen', 'btn-outline-secondary');
            }
        }

        // "Später entscheiden" führt einfach zur Startseite: Die Rolle bleibt
        // Trial, die Frage kommt beim nächsten Login wieder. Der Dialog ist
        // eine Frage, keine Sperre.
        $later = $undecided
            ? '<div class="mt-3"><a href="index.php?act=home" class="link-secondary">Später entscheiden</a></div>'
            : '';

        $hint = self::hintFor($role);

        $out = str_replace('###GUIDE_STATUS###',  $status,  $out);
        $out = str_replace('###GUIDE_ACTIONS###', $actions, $out);
        $out = str_replace('###GUIDE_LATER###',   $later,   $out);
        $out = str_replace('###GUIDE_HINT###',    $hint,    $out);

        ViewHelper::output($out);
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
                    $this->outputError('Die Guide-Rolle konnte nicht übernommen werden. '
                        . 'Bitte versuchen Sie es später erneut.');
                }
                Auth::refreshRole(Role::GUIDE);

                // Frisch gebackener Guide: Jetzt ist die eigene Position
                // interessant, vorher war sie es nicht. Deshalb schließt sich
                // die Standortabfrage genau hier an - und nur hier.
                $this->askForPositionOrGoHome();
                break;

            case 'decline':
                // Aus Trial wird User: die Frage ist beantwortet und wird
                // nicht wieder gestellt. Das ist kein Rechteverlust - Trial
                // und User haben dieselben Rechte.
                if (!Role::mayBecomeGuide($role)) {
                    // Ein Guide, der "nein" schickt, meint 'resign'. Dass das
                    // Formular so etwas nicht anbietet, heißt nicht, dass es
                    // nicht ankommen kann.
                    $this->outputError('Für diese Antwort ist Ihr Konto nicht im richtigen Zustand.');
                }
                $user = new User($user_id);
                if (!$user->setRoleId(Role::USER) || !$user->save()) {
                    $this->outputError('Ihre Antwort konnte nicht gespeichert werden.');
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
                    $this->outputError('Sie bieten noch Standorte an. Bitte löschen Sie diese zuerst '
                        . 'in Ihren Einstellungen unter "Meine Locations" - danach können Sie die '
                        . 'Guide-Rolle zurückgeben.');
                }
                if (!GuideRole::resign($user_id, $role)) {
                    $this->outputError('Die Guide-Rolle konnte nicht zurückgegeben werden. '
                        . 'Bitte versuchen Sie es später erneut.');
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
     * Standortabfrage anbieten, falls sie in dieser Sitzung noch nicht kam.
     *
     * Dieselbe Bedingung wie im LoginController, damit ein Nutzer die Frage
     * nicht zweimal in einer Sitzung bekommt.
     *
     * @return never
     */
    private function askForPositionOrGoHome(): void
    {
        if (!isset($_SESSION['location_prompt_shown'])) {
            $_SESSION['location_prompt_shown'] = true;
            $html = ViewHelper::template('assets/html/location_prompt.html');
            ViewHelper::output($html);
        }
        header('Location: index.php?act=home');
        exit;
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

        $out = str_replace('###GUIDE_STATUS###',  $box, $out);
        $out = str_replace('###GUIDE_ACTIONS###', '<a href="index.php?act=settings" '
            . 'class="btn btn-outline-secondary">Zurück zu den Einstellungen</a>', $out);
        $out = str_replace('###GUIDE_LATER###',   '', $out);
        $out = str_replace('###GUIDE_HINT###',    '', $out);

        ViewHelper::output($out);
    }

    /**
     * Zusätzlicher Hinweis unter den Knöpfen, je nach Zustand des Kontos.
     *
     * @param mixed $in_role
     * @return string
     */
    private static function hintFor($in_role): string
    {
        if (Role::isGuide($in_role)) {
            return '<p class="text-muted small mb-0">Solange Sie noch Standorte anbieten, '
                 . 'lässt sich die Rolle nicht zurückgeben - löschen Sie diese zuerst unter '
                 . '"Meine Locations".</p>';
        }
        return '<p class="text-muted small mb-0">Sie können diese Entscheidung jederzeit in '
             . 'Ihren Einstellungen ändern.</p>';
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
