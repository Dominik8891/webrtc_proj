<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\GuideView;
use App\Helper\ImageStore;
use App\Helper\MailGate;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\Role;
use App\Helper\Theme;
use App\Helper\ViewHelper;
use App\Model\GuideProfile;
use App\Model\GuideRole;
use App\Model\User;

/**
 * Controller für Settingseite
 */
class SettingsController
{
    /**
     * Zeigt die Einstellungsseite des Benutzers inkl. 2FA-Status, Username und E-Mail.
     * @return void
     */
    public function showSettingsPage(): void
    {
        // Anmeldung und Recht user.settings sind in index.php geprueft.
        $user = new User(Auth::userId());

        // Status für 2FA
        $is2fa = $user->getTotpEnabled();
        $status2fa = $is2fa ? 'Aktiviert' : 'Nicht aktiviert';

        // Button für 2FA
        if ($is2fa) {
            $twofaBtn = '<form action="index.php?act=2fa_disable" method="post" class="d-inline">
                            <button type="submit" class="btn btn-outline-danger btn-sm">2FA deaktivieren</button>
                        </form>';
        } else {
            $twofaBtn = "<a href='index.php?act=2fa_setup' class='btn btn-outline-primary btn-sm'>2FA einrichten</a>";
        }

        // Guide-Rolle. Angezeigt wird die Rolle, geknuepft ist der Knopf an
        // das Recht user.guide_role: Der Admin sieht seinen Status, aber
        // keinen Knopf - er wuerde beim Wechsel seine Adminrechte verlieren.
        // Der erklaerende Text steht auf der Dialogseite, nicht hier.
        //
        // DRITTER ZUSTAND: "Aktiv, neue Bedingungen offen". Ein Guide, dessen
        // Zustimmung eine aeltere Fassung traegt (GuideRole::TERMS_VERSION),
        // ist weiterhin Guide - aber sein naechster Standort geht erst
        // durch, wenn er der neuen Fassung zugestimmt hat
        // (GuideController::requireCurrentTerms). Das gehoert hierhin
        // geschrieben, statt ihn erst am gesperrten Formular davon erfahren
        // zu lassen.
        $isGuide     = Role::isGuide(Auth::roleId());
        $termsOpen   = $isGuide && GuideRole::needsDecision(Auth::userId(), Auth::roleId());
        $guideStatus = $isGuide ? 'Aktiv' : 'Nicht aktiv';
        if ($termsOpen) {
            $guideStatus = 'Aktiv <span class="text-warning">&ndash; neue Bedingungen offen</span>';
        }
        $guideBtn    = '';
        if (Auth::can(Permission::USER_GUIDE_ROLE)) {
            if ($termsOpen) {
                $guideLabel = 'Neue Bedingungen bestätigen';
            } else {
                $guideLabel = $isGuide ? 'Guide-Rolle ändern' : 'Guide werden';
            }
            // Der offene Punkt traegt den Akzent, der Normalfall nicht: Ein
            // Knopf, der etwas erledigen soll, muss sich von einem
            // unterscheiden, der nur eine Seite oeffnet.
            $guideCss   = $termsOpen ? 'btn-primary' : 'btn-outline-primary';
            $guideBtn   = "<a href='index.php?act=guide_role_page' class='btn $guideCss btn-sm'>"
                        . $guideLabel . '</a>';
        }

        // DER BESTAETIGUNGSSTAND DER ADRESSE - WIEDER IN BETRIEB.
        //
        // WAS HIER STAND: derselbe Block auskommentiert, dazu ein
        // method_exists($user, 'getEmailVerified') davor - und den Getter gab
        // es gar nicht. Die Zeile haette also auch eingeschaltet nichts
        // angezeigt; sie stand nur deshalb nie auf, weil sie ohnehin
        // ausgeschaltet war. Der Getter ist jetzt da, und der Konstruktor laedt
        // den Wert (App\Model\User).
        //
        // GEZEIGT WIRD SIE NUR, WENN SIE ETWAS BEDEUTET - also sobald einer
        // der beiden Schalter an ist. Solange weder verschickt noch verlangt
        // wird, waere "Nicht bestätigt" eine Aufforderung zu etwas, das die
        // Anwendung gar nicht anbietet.
        $mailConfirm = '';
        if (MailGate::versandAktiv() || MailGate::bestaetigungPflicht()) {
            $mailConfirm = $user->getEmailVerified()
                ? '<dt>E-Mail bestätigt</dt><dd>Bestätigt</dd>'
                : '<dt>E-Mail bestätigt</dt><dd>Nicht bestätigt '
                  . '<a href="index.php?act=send_email_verify" '
                  . 'class="btn btn-outline-primary btn-sm">Bestätigungsmail senden</a></dd>';
        }

        $out = ViewHelper::template('assets/html/settings.html');
        $out = str_replace('###USERNAME###', $user->getUsername(), $out);
        $out = str_replace('###EMAIL###', $user->getEmail(), $out);
        $out = str_replace('###TWOFASTATUS###', $status2fa, $out);
        $out = str_replace('###TWOFABTN###', $twofaBtn, $out);
        $out = str_replace('###GUIDESTATUS###', $guideStatus, $out);
        $out = str_replace('###GUIDEBTN###', $guideBtn, $out);
        $out = str_replace('###MAILCONFIRM###', $mailConfirm, $out);
        $out = str_replace('###THEMES###', self::themeChoices($user->getTheme()), $out);

        // Die Rueckmeldung nach dem Speichern des Guide-Profils. Sie reist in
        // der Adresse mit (Post/Redirect/Get) und wird maskiert wieder
        // herausgeholt - ViewHelper::hinweisHtml ist dieselbe Meldung, die
        // auch die Standortseite zeigt.
        $out = str_replace('###NOTICE###', ViewHelper::hinweisHtml(
            (string)Request::g('fehler', ''),
            Request::g('gespeichert') === '1'
        ), $out);

        $out = str_replace('###GUIDEPROFILE###', self::guideProfilBereich(), $out);

        ViewHelper::output($out);
    }

    /**
     * Der Bereich, in dem ein Guide sein oeffentliches Profil pflegt.
     *
     * WARUM HIER UND NICHT AUF DER PROFILSEITE
     * ----------------------------------------
     * Weil die Profilseite das ist, was ein KUNDE sieht - und sie soll fuer
     * den Eigentuemer genauso aussehen wie fuer alle anderen. Wer sein Profil
     * aendert, geht dorthin, wo er auch sein Passwort aendert und seine
     * Standorte verwaltet.
     *
     * NUR FUER KONTEN, DIE STANDORTE ANBIETEN duerfen (Recht
     * guide.profile_edit). Ein Zuschauer haette hier ein Formular fuer eine
     * Seite, die es fuer ihn nicht gibt.
     *
     * @return string HTML oder Leerstring
     */
    private static function guideProfilBereich(): string
    {
        if (!Auth::can(Permission::GUIDE_PROFILE_EDIT)) return '';

        $user_id = Auth::userId();
        $profil  = GuideProfile::forUser($user_id);
        if ($profil === null) return '';

        $config = ImageStore::config();

        return '<div class="app-panel" style="margin-top: var(--app-space-4);">'
             . '<div class="app-panel__head">'
             .   '<h2 class="app-page-head__title">Mein Guide-Profil</h2>'
             .   '<a class="btn btn-secondary btn-sm" href="index.php?act=guide&id=' . $user_id . '">'
             .     'Öffentliches Profil ansehen</a>'
             . '</div>'
             . '<div class="app-panel__body">'
             .   '<p class="app-page-head__sub" style="margin-top:0;">'
             .     'Das sieht ein Kunde, bevor er eine Führung anfragt: auf jeder Ihrer '
             .     'Standortseiten und auf Ihrer eigenen Seite, die Sie weitergeben können. '
             .     'Ein Benutzername und ein farbiger Punkt sind keine Grundlage dafür, '
             .     'einem Fremden Geld zu geben.'
             .   '</p>'
             .   GuideView::formularHtml($profil, [
                     'name_max'  => GuideProfile::NAME_MAX,
                     'about_max' => GuideProfile::ABOUT_MAX,
                     'max_bytes' => (int)$config['max_file_bytes'],
                     'accept'    => implode(',', $config['accepted_mime']),
                 ])
             . '</div>'
             . '</div>';
    }

    /**
     * Baut die Auswahl der Farbprofile.
     *
     * Radioknoepfe und kein Aufklappmenue: Es sind vier Eintraege, sie haben
     * je ein Farbmuster, und eine Auswahl, die man sieht, ohne sie zu
     * oeffnen, ist schneller verstanden. Das Muster zeigt Grundflaeche,
     * Karte und Akzent - genau die drei Werte, an denen sich die Profile
     * unterscheiden.
     *
     * Die Liste kommt aus App\Helper\Theme, damit ein neues Profil nicht an
     * drei Stellen nachgetragen werden muss.
     *
     * @param string|null $gewaehlt Roher Wert aus der Datenbank
     * @return string HTML
     */
    private static function themeChoices($gewaehlt): string
    {
        $aktiv = Theme::normalize($gewaehlt);
        $html  = '';

        foreach (Theme::PROFILE as $schluessel => $profil) {
            $id  = 'theme-' . $schluessel;
            $an  = ($schluessel === $aktiv) ? ' checked' : '';

            // Die Muster stehen als inline-style und nicht als Klasse: Es
            // sind Daten aus Theme.php, keine Gestaltung. Eine Klasse je
            // Profil waere eine zweite Liste, die mitgepflegt werden muesste.
            $muster = '';
            foreach ($profil['muster'] as $farbe) {
                $muster .= '<span class="app-swatch__chip" style="background:'
                         . htmlspecialchars($farbe) . '"></span>';
            }

            $html .= '<label class="app-swatch" for="' . $id . '">'
                   .   '<input type="radio" name="theme" id="' . $id . '"'
                   .          ' value="' . htmlspecialchars($schluessel) . '"' . $an . '>'
                   .   '<span class="app-swatch__preview" aria-hidden="true">' . $muster . '</span>'
                   .   '<span class="app-swatch__text">'
                   .     '<span class="app-swatch__name">' . htmlspecialchars($profil['name']) . '</span>'
                   .     '<span class="app-swatch__desc">' . htmlspecialchars($profil['text']) . '</span>'
                   .   '</span>'
                   . '</label>';
        }
        return $html;
    }

    /**
     * Speichert das gewaehlte Farbprofil des angemeldeten Kontos.
     *
     * Zugang: Recht user.settings, geprueft in index.php. Gespeichert wird
     * immer fuer den Angemeldeten - eine Benutzer-ID aus der Anfrage wird
     * bewusst NICHT gelesen, sonst koennte jemand fremde Konten umfaerben.
     *
     * @return void
     */
    public function setTheme(): void
    {
        header('Content-Type: application/json');

        $profil = Request::g('theme', '');

        // Erst pruefen, dann speichern. Was Theme nicht kennt, kommt nicht in
        // die Datenbank - und damit auch nie in das data-theme-Attribut.
        if (!Theme::isValid($profil)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unbekanntes Farbprofil.']);
            return;
        }

        $user = new User(Auth::userId());
        if (!$user->saveTheme($profil)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Farbprofil konnte nicht gespeichert werden.']);
            return;
        }

        echo json_encode(['success' => true, 'theme' => $profil]);
    }
}
