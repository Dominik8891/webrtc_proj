<?php
namespace App\Controller;

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
        $userId = $_SESSION['user']['user_id'] ?? null;
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $user = new User($userId);

        // Status für 2FA
        $is2fa = $user->getTotpEnabled();
        $status2fa = $is2fa ? 'Aktiviert' : 'Nicht aktiviert';

        // Button für 2FA
        if ($is2fa) {
            $twofaBtn = "<form action='index.php?act=2fa_disable' method='post'><button type='submit'>2FA deaktivieren</button></form>";
        } else {
            $twofaBtn = "<a href='index.php?act=2fa_setup'>2FA einrichten</a>";
        }

        // E-Mail-Bestätigungsstatus (optional)
        $mailConfirmed = method_exists($user, 'getEmailVerified') ? ($user->getEmailVerified() ? 'Bestätigt' : 'Nicht bestätigt') : '';

        $mailConfirm = '';
        $out = file_get_contents('assets/html/settings.html');
        /*
         * Deaktiviert lassen solange kein eigener SMTP
         * 
         *  if ($mailConfirmed !== '') {
         *      $mailConfirm = "<tr><td>E-Mail bestätigt:</td><td>$mailConfirmed</td></tr>";
         *  }
         * 
         *
        */
        $out = str_replace('###USERNAME###', $user->getUsername(), $out);
        $out = str_replace('###EMAIL###', $user->getEmail(), $out);
        $out = str_replace('###TWOFASTATUS###', $status2fa, $out);
        $out = str_replace('###TWOFABTN###', $twofaBtn, $out);
        $out = str_replace('###MAILCONFIRM###', $mailConfirm, $out);

        ViewHelper::output($out);
    }
}
