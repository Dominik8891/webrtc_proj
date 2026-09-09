<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\GuideView;
use App\Helper\I18n;
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
        $status2fa = ViewHelper::esc(I18n::t($is2fa ? 'konto.2fa.aktiv' : 'konto.2fa.inaktiv'));

        // Button für 2FA
        if ($is2fa) {
            $twofaBtn = '<form action="index.php?act=2fa_disable" method="post" class="d-inline">'
                      . '<button type="submit" class="btn btn-outline-danger btn-sm">'
                      . ViewHelper::esc(I18n::t('konto.2fa.deaktivieren')) . '</button>'
                      . '</form>';
        } else {
            $twofaBtn = "<a href='index.php?act=2fa_setup' class='btn btn-outline-primary btn-sm'>"
                      . ViewHelper::esc(I18n::t('konto.2fa.einrichten')) . "</a>";
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
        $guideStatus = ViewHelper::esc(I18n::t($isGuide ? 'konto.guide.aktiv' : 'konto.guide.inaktiv'));
        if ($termsOpen) {
            // Der Zusatz steht MITTEN im Satz und traegt eine Auszeichnung -
            // deshalb der ganze Satz aus dem Katalog und das <span> als
            // Platzhalter (ViewHelper::tHtml).
            $guideStatus = ViewHelper::tHtml('konto.guide.aktiv_offen', [
                'zusatz' => '<span class="text-warning">'
                          . ViewHelper::esc(I18n::t('konto.guide.offen_hinweis')) . '</span>',
            ]);
        }
        $guideBtn    = '';
        if (Auth::can(Permission::USER_GUIDE_ROLE)) {
            if ($termsOpen) {
                $guideLabel = I18n::t('konto.guide.knopf_bestaetigen');
            } else {
                $guideLabel = I18n::t($isGuide
                    ? 'konto.guide.knopf_aendern' : 'konto.guide.knopf_werden');
            }
            // Der offene Punkt traegt den Akzent, der Normalfall nicht: Ein
            // Knopf, der etwas erledigen soll, muss sich von einem
            // unterscheiden, der nur eine Seite oeffnet.
            $guideCss   = $termsOpen ? 'btn-primary' : 'btn-outline-primary';
            $guideBtn   = "<a href='index.php?act=guide_role_page' class='btn $guideCss btn-sm'>"
                        . ViewHelper::esc($guideLabel) . '</a>';
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
            $mailLabel   = '<dt>' . ViewHelper::esc(I18n::t('konto.mail.label')) . '</dt>';
            $mailConfirm = $user->getEmailVerified()
                ? $mailLabel . '<dd>' . ViewHelper::esc(I18n::t('konto.mail.ja')) . '</dd>'
                : $mailLabel . '<dd>' . ViewHelper::esc(I18n::t('konto.mail.nein')) . ' '
                  . '<a href="index.php?act=send_email_verify" '
                  . 'class="btn btn-outline-primary btn-sm">'
                  . ViewHelper::esc(I18n::t('konto.mail.senden')) . '</a></dd>';
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
        $out = str_replace('###LANGS###', self::languageChoices($user->getLang()), $out);

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
             .   '<h2 class="app-page-head__title">'
             .     ViewHelper::esc(I18n::t('konto.profil.titel')) . '</h2>'
             .   '<a class="btn btn-secondary btn-sm" href="index.php?act=guide&id=' . $user_id . '">'
             .     ViewHelper::esc(I18n::t('konto.profil.ansehen')) . '</a>'
             . '</div>'
             . '<div class="app-panel__body">'
             .   '<p class="app-page-head__sub" style="margin-top:0;">'
             .     ViewHelper::esc(I18n::t('konto.profil.hinweis'))
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
     * Baut die Sprachauswahl der Kontoseite.
     *
     * SIE SIEHT AUS WIE DIE FARBPROFILE DANEBEN, ARBEITET ABER ANDERS - und
     * genau das ist hier die Entscheidung:
     *
     *   Das Farbprofil sind Radioknoepfe, die ein Skript abfaengt. Es wirkt
     *   sofort, weil der Browser nur ein Attribut am <html> setzen muss.
     *
     *   Die Sprache sind VERWEISE. Den Text hat der Server gesetzt; er steht
     *   fertig in der Seite. Umstellen heisst also in jedem Fall neu laden -
     *   und dann ist ein Verweis das ehrlichere Element als ein Knopf, der
     *   so tut, als geschaehe es an Ort und Stelle. Nebenbei funktioniert es
     *   damit auch ohne JavaScript, und der Umschalter der Fusszeile
     *   (App\Helper\ViewHelper::languageSwitch) ist derselbe Mechanismus.
     *
     * DIE AKTIVE SPRACHE IST KEIN VERWEIS, sondern Text - ein Verweis, der
     * beim Anklicken nichts tut, saehe genauso aus wie einer, der etwas tut.
     *
     * WAS MARKIERT WIRD: die Sprache, in der diese Seite gerade dasteht -
     * nicht der Wert aus user.lang. Beides faellt zusammen, sobald einmal
     * gewaehlt wurde; davor gilt Cookie oder Browser, und markiert gehoert
     * das, was der Nutzer vor sich sieht.
     *
     * DER UNTERSCHIED WIRD TROTZDEM GESAGT: Solange das Konto nichts
     * festhaelt, steht unter der markierten Sprache der Hinweis, dass sie
     * aus dem Browser kommt. Sonst hielte jemand fuer gespeichert, was beim
     * naechsten Geraet wieder anders ist.
     *
     * @param string|null $in_gewaehlt Roher Wert aus user.lang
     * @return string HTML
     */
    private static function languageChoices($in_gewaehlt): string
    {
        $aktiv = I18n::aktiv();
        // Haelt das Konto ueberhaupt etwas fest? Der rohe Wert und nicht
        // I18n::normalize() - dessen Rueckfall auf die Vorgabe wuerde
        // "nichts gewaehlt" und "Englisch gewaehlt" ununterscheidbar machen.
        $imKonto = I18n::isValid($in_gewaehlt);
        $html    = '';

        foreach (I18n::SPRACHEN as $kuerzel => $name) {
            $eigen = ($kuerzel === $aktiv);
            $klasse = 'app-swatch app-swatch--lang' . ($eigen ? ' app-swatch--on' : '');

            // Zurueck auf die Kontoseite - dorthin, wo umgestellt wurde.
            $ziel = 'index.php?act=set_lang&lang=' . rawurlencode($kuerzel)
                  . '&back=' . rawurlencode('act=settings');

            // Der Satz unter dem Namen sagt, was ein Klick bewirkt bzw. was
            // gerade gilt - und die markierte Sprache eines Kontos ohne
            // eigene Wahl sagt, dass sie nur aus diesem Browser stammt.
            $satz = ($eigen && !$imKonto)
                  ? I18n::t('sprache.gast')
                  : I18n::t('sprache.konto');

            $innen = '<span class="app-swatch__text">'
                   .   '<span class="app-swatch__name">' . ViewHelper::esc($name) . '</span>'
                   .   '<span class="app-swatch__desc">' . ViewHelper::esc($satz) . '</span>'
                   . '</span>';

            $html .= $eigen
                ? '<span class="' . $klasse . '" aria-current="true">' . $innen . '</span>'
                : '<a class="' . $klasse . '" href="' . ViewHelper::esc($ziel) . '"'
                  . ' hreflang="' . ViewHelper::esc($kuerzel) . '">' . $innen . '</a>';
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
            echo json_encode(['success' => false, 'error' => I18n::t('konto.farbprofil.unbekannt')]);
            return;
        }

        $user = new User(Auth::userId());
        if (!$user->saveTheme($profil)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => I18n::t('konto.farbprofil.fehler')]);
            return;
        }

        echo json_encode(['success' => true, 'theme' => $profil]);
    }
}
