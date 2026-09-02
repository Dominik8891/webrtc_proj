<?php
namespace App\Controller;

use App\Helper\ViewHelper;
use App\Helper\Auth;
use App\Helper\Request;
use App\Model\User;
use OTPHP\TOTP;
use Symfony\Component\Clock\NativeClock;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class TwoFactorController
{
    /**
     * Zeigt das 2FA-Setup-Formular inkl. QR-Code und Eingabefeld für Code.
     * @return void
     */
    public function show2FASetup(): void
    {
        $userId = Auth::userId();
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $user = new User($userId);

        if ($user->getTotpEnabled()) {
            $html = '<div class="alert alert-success text-center my-4" role="alert" style="max-width:400px; margin:0 auto;">
                        <h4 class="alert-heading mb-0">2FA ist bereits aktiviert!</h4>
                    </div>';
            ViewHelper::output($html);
            return;
        }

        $clock = new NativeClock(new \DateTimeZone('Europe/Berlin'));

        if (!isset($_SESSION['2fa_temp_secret'])) {
            $totp = TOTP::create(null, 30, 'sha1', 6, 0, $clock);
            $secret = $totp->getSecret();
            $_SESSION['2fa_temp_secret'] = $secret;
        } else {
            $secret = $_SESSION['2fa_temp_secret'];
            $totp = TOTP::create($secret, 30, 'sha1', 6, 0, $clock);
        }

        $totp->setLabel($user->getEmail());
        $totp->setIssuer('WebRTC-Projekt');

        $qrCode = new QrCode($totp->getProvisioningUri());
        $writer = new PngWriter();
        $qrCodeData = $writer->write($qrCode)->getString();
        $qrBase64 = 'data:image/png;base64,' . base64_encode($qrCodeData);

        $html = <<<HTML
                    <div class="app-auth">
                        <div class="app-auth__card">
                            <div class="app-panel">
                                <div class="app-panel__body">
                                    <div class="app-auth__head">
                                        <h1 class="app-auth__title">Zwei-Faktor-Anmeldung einrichten</h1>
                                        <p class="app-auth__sub">
                                            QR-Code mit der Authenticator-App scannen und den angezeigten
                                            sechsstelligen Code eintragen.
                                        </p>
                                    </div>
                                    <div class="app-qr">
                                        <img src="$qrBase64" alt="QR-Code für die Authenticator-App">
                                    </div>
                                    <form action="index.php?act=2fa_activate" method="post" autocomplete="off">
                                        <div class="app-field">
                                            <label for="2fa_code" class="form-label">Code aus der App</label>
                                            <input type="text" name="2fa_code" id="2fa_code" class="form-control app-code-input"
                                                   inputmode="numeric" autocomplete="one-time-code"
                                                   pattern="[0-9]{6}" maxlength="6" required autofocus>
                                        </div>
                                        <div class="app-actions app-actions--stretch">
                                            <button type="submit" class="btn btn-primary">Aktivieren</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                HTML;
        ViewHelper::output($html);
    }

    /**
     * Aktiviert 2FA für den angemeldeten User nach erfolgreicher Code-Eingabe.
     * @return void
     */
    public function handle2FAActivate(): void
    {
        $userId = Auth::userId();
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $user = new User($userId);
        $secret = $_SESSION['2fa_temp_secret'] ?? null;
        $code = Request::g('2fa_code');
        $clock = new NativeClock(new \DateTimeZone('Europe/Berlin'));

        // Kein Loggen von Code oder Secret - nur, ob beide Werte vorliegen.
        error_log(sprintf(
            '2FA-Setup: Secret %s, Code %s (UserID %d)',
            $secret ? 'vorhanden' : 'fehlt',
            $code    ? 'vorhanden' : 'fehlt',
            $userId
        ));

        if (!$secret || !$code) {
            $this->outputError("Fehler: Bitte QR-Code erneut scannen.");
            return;
        }

        $totp = TOTP::create($secret, 30, 'sha1', 6, 0, $clock);
        $isValid = $totp->verify($code);

        error_log("2FA-Setup: Code-Pruefung " . ($isValid ? 'OK' : 'FAIL') . " (UserID {$userId})");

        if ($isValid) {
            $encSecret = $this->encryptTotpSecret($secret);
            $user->setTotpSecret($encSecret);
            $user->setTotpEnabled(1);
            $user->save();
            error_log("2FA erfolgreich aktiviert (UserID {$userId})");
            unset($_SESSION['2fa_temp_secret']);
            $html = '
                    <div class="alert alert-success text-center my-4" role="alert" style="max-width:400px; margin:0 auto;">
                        <h4 class="alert-heading mb-3">2FA erfolgreich aktiviert!</h4>
                        <a href="index.php?act=home" class="btn btn-outline-primary btn-sm">Zurück</a>
                    </div>
                    ';
            ViewHelper::output($html);
        } else {
            $this->outputError("Ungültiger Code. Versuche es erneut.");
        }
    }

    /**
     * Zeigt das Eingabefeld für den 2FA-Code beim Login an.
     * @return void
     */
    public function show2FAVerifyForm(): void
    {
        $userId = $_SESSION['2fa_userid'] ?? null;
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $html = <<<HTML
                    <div class="app-auth">
                        <div class="app-auth__card">
                            <div class="app-panel">
                                <div class="app-panel__body">
                                    <div class="app-auth__head">
                                        <h1 class="app-auth__title">Bestätigungscode</h1>
                                        <p class="app-auth__sub">Der sechsstellige Code aus Ihrer Authenticator-App.</p>
                                    </div>
                                    <form action="index.php?act=2fa_verify" method="post" autocomplete="off">
                                        <div class="app-field">
                                            <label for="2fa_code" class="form-label">Code</label>
                                            <input type="text" name="2fa_code" id="2fa_code" class="form-control app-code-input"
                                                   inputmode="numeric" autocomplete="one-time-code"
                                                   pattern="[0-9]{6}" maxlength="6" required autofocus>
                                        </div>
                                        <div class="app-actions app-actions--stretch">
                                            <button type="submit" class="btn btn-primary">Anmelden</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                HTML;
        ViewHelper::output($html);
    }

    /**
     * Prüft den 2FA-Code nach dem Login und schließt Login ggf. ab.
     * @return void
     */
    public function handle2FAVerify(): void
    {
        $userId = $_SESSION['2fa_userid'] ?? null;
        $code = Request::g('2fa_code');
        if (!$userId || !$code) {
            $this->outputError("Fehler beim 2FA-Login.");
            return;
        }
        $user = new User($userId);

        $clock = new NativeClock(new \DateTimeZone('Europe/Berlin'));
        $encSecret = $user->getTotpSecret();
        $secret = $this->decryptTotpSecret($encSecret);

        $secret = trim($secret, " \t\n\r\0\x0B");
        // Kein Loggen von Secret oder Code - nur, ob das Secret entschluesselt werden konnte.
        error_log(sprintf(
            '2FA-Login: Secret %s (UserID %d)',
            $secret !== '' ? 'vorhanden' : 'fehlt',
            $userId
        ));

        $totp = TOTP::create($secret, 30, 'sha1', 6, 0, $clock);
        $isValid = $totp->verify($code);

        error_log("2FA-Login: Code-Pruefung " . ($isValid ? 'OK' : 'FAIL') . " (UserID {$userId})");

        if ($isValid) {
            // Ueber Auth::establish() statt ueber getUserDetails(): Nur so
            // traegt die Sitzung die normalisierte Rolle und die Kennung des
            // Sitzungsaufbaus - sonst haetten mit 2FA angemeldete Nutzer eine
            // andere Sitzungsstruktur als alle anderen.
            session_regenerate_id(true);
            Auth::establish($user);
            unset($_SESSION['2fa_userid']);
            // Derselbe Abschluss wie beim Login ohne zweiten Faktor: erst die
            // Guide-Frage, dann - fuer Guides - die Standortabfrage. Vorher
            // ging es hier direkt zur Startseite, wer 2FA benutzte, wurde
            // deshalb nie gefragt.
            LoginController::continueAfterLogin();
        } else {
            $this->outputError("Ungültiger Code. Bitte erneut versuchen.");
        }
    }
    
    /**
     * Deaktiviert 2FA für den angemeldeten User.
     * @return void
     */
    public function disable2FA(): void
    {
        $userId = Auth::userId();
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $user = new User($userId);
        $user->setTotpEnabled(0);
        $user->setTotpSecret(null);
        $user->save();

        $html = '
                <div class="alert alert-success text-center my-4" role="alert" style="max-width:400px; margin:0 auto;">
                    <h4 class="alert-heading mb-3">2FA wurde deaktiviert.</h4>
                    <a href="index.php?act=settings" class="btn btn-outline-primary btn-sm">Zurück zu den Einstellungen</a>
                </div>
                ';
        ViewHelper::output($html);
    }

    // Hilfsmethoden
    private function encryptTotpSecret($secret)
    {
        $key = $_ENV['PEPPER'];
        return openssl_encrypt($secret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
    }
    private function decryptTotpSecret($encSecret)
    {
        $key = $_ENV['PEPPER'];
        return openssl_decrypt($encSecret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
    }

    private function outputError($msg)
    {
        $html = '
                <div class="app-result">
                    <div class="app-panel">
                        <div class="app-panel__body">
                            <div class="app-result__mark app-result__mark--danger" aria-hidden="true">!</div>
                            <h1 class="app-auth__title">Anmeldung nicht abgeschlossen</h1>
                            <p class="app-result__text">' . htmlspecialchars($msg) . '</p>
                            <div class="app-actions app-actions--center">
                                <a href="index.php?act=login_page" class="btn btn-primary">Zur Anmeldung</a>
                            </div>
                        </div>
                    </div>
                </div>
                ';
        ViewHelper::output($html);
    }


}
