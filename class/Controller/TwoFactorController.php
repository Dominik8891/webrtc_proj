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
    // 1. QR-Code und Formular anzeigen (2FA Setup)
    public function show2FASetup(): void
    {
        $userId = $_SESSION['user']['user_id'] ?? null;
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $user = new User($userId);

        if ($user->getTotpEnabled()) {
            $html = "<h3>2FA ist bereits aktiviert!</h3>";
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
<h2>2-Faktor-Authentifizierung einrichten</h2>
<p>Scanne den QR-Code mit deiner Authenticator-App und gib den aktuellen 6-stelligen Code unten ein:</p>
<img src="$qrBase64" alt="QR-Code">
<form action="index.php?act=2fa_activate" method="post">
    <label for="2fa_code">Code:</label>
    <input type="text" name="2fa_code" pattern="[0-9]{6}" required>
    <button type="submit">2FA aktivieren</button>
</form>
HTML;
        ViewHelper::output($html);
    }

    // 2. User bestätigt Code (2FA Setup abschließen)
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
            $html = "<h3>2FA erfolgreich aktiviert!</h3><a href='index.php?act=home'>Zurück</a>";
            ViewHelper::output($html);
        } else {
            $this->outputError("Ungültiger Code. Versuche es erneut.");
        }
    }

    // 3. Nach Passwort-Login: 2FA-Code abfragen
    public function show2FAVerifyForm(): void
    {
        $userId = $_SESSION['2fa_userid'] ?? null;
        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }
        $html = <<<HTML
<h2>2FA-Code eingeben</h2>
<form action="index.php?act=2fa_verify" method="post">
    <label for="2fa_code">Authenticator-Code:</label>
    <input type="text" name="2fa_code" pattern="[0-9]{6}" required>
    <button type="submit">Anmelden</button>
</form>
HTML;
        ViewHelper::output($html);
    }

    // 4. 2FA-Code prüfen und Login abschließen
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

    // Verschlüsselung für Secret
    private function encryptTotpSecret($secret)
    {
        $key = getenv('PEPPER');
        return openssl_encrypt($secret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
    }
    private function decryptTotpSecret($encSecret)
    {
        $key = getenv('PEPPER');
        return openssl_decrypt($encSecret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
    }

    private function outputError($msg)
    {
        $html = "<div style='color:red;'>$msg</div><a href='index.php?act=home'>Zurück</a>";
        ViewHelper::output($html);
    }

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

        $html = "<h3>2FA wurde deaktiviert.</h3><a href='index.php?act=settings'>Zurück zu den Einstellungen</a>";
        ViewHelper::output($html);
    }

}
