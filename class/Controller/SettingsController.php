<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\Role;
use App\Helper\Theme;
use App\Helper\ViewHelper;
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
        $isGuide     = Role::isGuide(Auth::roleId());
        $guideStatus = $isGuide ? 'Aktiv' : 'Nicht aktiv';
        $guideBtn    = '';
        if (Auth::can(Permission::USER_GUIDE_ROLE)) {
            $guideLabel = $isGuide ? 'Guide-Rolle ändern' : 'Guide werden';
            $guideBtn   = "<a href='index.php?act=guide_role_page' class='btn btn-outline-primary btn-sm'>"
                        . $guideLabel . '</a>';
        }

        // E-Mail-Bestätigungsstatus (optional)
        $mailConfirmed = method_exists($user, 'getEmailVerified') ? ($user->getEmailVerified() ? 'Bestätigt' : 'Nicht bestätigt') : '';

        $mailConfirm = '';
        $out = ViewHelper::template('assets/html/settings.html');
        /*
         * Deaktiviert lassen solange kein eigener SMTP
         * 
         *  if ($mailConfirmed !== '') {
         *      $mailConfirm = "<dt>E-Mail bestätigt</dt><dd>$mailConfirmed</dd>";
         *  }
         *
         * Die Angaben stehen seit dem Umbau der Oberflaeche in einer
         * Beschreibungsliste (assets/html/settings.html), nicht mehr in einer
         * Tabelle - deshalb <dt>/<dd> statt <tr>/<td>.
         * 
         *
        */
        $out = str_replace('###USERNAME###', $user->getUsername(), $out);
        $out = str_replace('###EMAIL###', $user->getEmail(), $out);
        $out = str_replace('###TWOFASTATUS###', $status2fa, $out);
        $out = str_replace('###TWOFABTN###', $twofaBtn, $out);
        $out = str_replace('###GUIDESTATUS###', $guideStatus, $out);
        $out = str_replace('###GUIDEBTN###', $guideBtn, $out);
        $out = str_replace('###MAILCONFIRM###', $mailConfirm, $out);
        $out = str_replace('###THEMES###', self::themeChoices($user->getTheme()), $out);

        ViewHelper::output($out);
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
