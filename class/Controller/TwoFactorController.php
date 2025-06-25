<?php
namespace App\Controller;

use App\Helper\ViewHelper;
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
        $userId = $_SESSION['user']['user_id'] ?? null;
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
            error_log("// DEBUG: Neues Secret erzeugt: [$secret]");
        } else {
            $secret = $_SESSION['2fa_temp_secret'];
            $totp = TOTP::create($secret, 30, 'sha1', 6, 0, $clock);
            error_log("// DEBUG: Temporäres Secret aus Session: [$secret]");
        }

        $totp->setLabel($user->getEmail());
        $totp->setIssuer('WebRTC-Projekt');

        $qrCode = new QrCode($totp->getProvisioningUri());
        $writer = new PngWriter();
        $qrCodeData = $writer->write($qrCode)->getString();
        $qrBase64 = 'data:image/png;base64,' . base64_encode($qrCodeData);

        $html = <<<HTML
                    <div class="container d-flex justify-content-center align-items-center">
                        <div class="card shadow-sm p-4" style="max-width: 400px; width: 100%;">
                            <h2 class="mb-3 text-center">2-Faktor-Authentifizierung einrichten</h2>
                            <p>Scanne den QR-Code mit deiner Authenticator-App und gib den aktuellen 6-stelligen Code unten ein:</p>
                                <div class="d-flex justify-content-center my-3">
                                <img src="$qrBase64" alt="QR-Code" style="max-width:200px;">
                            </div>
                            <form action="index.php?act=2fa_activate" method="post" autocomplete="off">
                            <div class="mb-3">
                                <label for="2fa_code" class="form-label">Code:</label>
                                <input type="text" name="2fa_code" id="2fa_code" class="form-control" pattern="[0-9]{6}" required>
                            </div>
                            <button type="submit" class="btn btn-success w-100">2FA aktivieren</button>
                            </form>
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
        $userId = $_SESSION['user']['user_id'] ?? null;
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $user = new User($userId);
        $secret = $_SESSION['2fa_temp_secret'] ?? null;
        $code = Request::g('2fa_code');
        $clock = new NativeClock(new \DateTimeZone('Europe/Berlin'));

        error_log("// DEBUG: Eingabecode: [$code]");
        error_log("// DEBUG: Setup-Secret (raw): [$secret]");

        if (!$secret || !$code) {
            $this->outputError("Fehler: Bitte QR-Code erneut scannen.");
            return;
        }

        $totp = TOTP::create($secret, 30, 'sha1', 6, 0, $clock);
        $isValid = $totp->verify($code);

        error_log("// DEBUG: Setup-Code Verification Result: [" . ($isValid ? 'OK' : 'FAIL') . "]");

        if ($isValid) {
            error_log("// DEBUG: Vor dem Verschlüsseln (Setup): [$secret]");
            $encSecret = $this->encryptTotpSecret($secret);
            error_log("// DEBUG: Nach dem Verschlüsseln (Setup): [$encSecret]");
            $user->setTotpSecret($encSecret);
            $user->setTotpEnabled(1);
            $user->save();
            // Test-Entschlüsselung direkt hier!
            $decTest = $this->decryptTotpSecret($encSecret);
            error_log("// DEBUG: Direkt wieder entschlüsselt: [$decTest]");
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
                    <div class="container d-flex justify-content-center align-items-center">
                        <div class="card shadow-sm p-4" style="max-width: 350px; width: 100%;">
                            <h2 class="mb-3 text-center">2FA-Code eingeben</h2>
                            <form action="index.php?act=2fa_verify" method="post" autocomplete="off">
                            <div class="mb-3">
                                <label for="2fa_code" class="form-label">Authenticator-Code:</label>
                                <input type="text" name="2fa_code" id="2fa_code" class="form-control" pattern="[0-9]{6}" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Anmelden</button>
                            </form>
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

        error_log("// DEBUG: Entschlüsseltes Secret vor Trim: [$secret]");
        $secret = trim($secret, " \t\n\r\0\x0B");
        error_log("// DEBUG: Entschlüsseltes Secret nach Trim: [$secret]");
        error_log("// DEBUG: Login-Code: [$code]");

        $totp = TOTP::create($secret, 30, 'sha1', 6, 0, $clock);
        $isValid = $totp->verify($code);

        error_log("// DEBUG: Login-Code Verification Result: [" . ($isValid ? 'OK' : 'FAIL') . "]");

        if ($isValid) {
            $_SESSION['user'] = $user->getUserDetails();
            unset($_SESSION['2fa_userid']);
            header("Location: index.php?act=home");
            exit;
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
        $userId = $_SESSION['user']['user_id'] ?? null;
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
                <div class="alert alert-danger text-center my-4" role="alert" style="max-width:400px; margin:0 auto;">
                    ' . htmlspecialchars($msg) . '
                    <div class="mt-3">
                        <a href="index.php?act=home" class="btn btn-outline-primary btn-sm">Zurück</a>
                    </div>
                </div>
                ';
        ViewHelper::output($html);
    }


}
