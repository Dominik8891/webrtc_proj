<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Role;
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
        $out = file_get_contents('assets/html/settings.html');
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

        ViewHelper::output($out);
    }
}
