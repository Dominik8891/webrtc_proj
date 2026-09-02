<?php

// Importiert die Controller-Klassen, die für die Verarbeitung der jeweiligen Routen zuständig sind
use App\Controller\SignupController;
use App\Controller\LoginController;
use App\Controller\SystemController;
use App\Controller\UserController;
use App\Controller\LocationController;
use App\Controller\WebRTCController;
use App\Controller\TurnController;
use App\Controller\PasswordController;
use App\Controller\EmailVerificationController;
use App\Controller\TwoFactorController;
use App\Controller\SettingsController;
use App\Controller\GuideController;
use App\Controller\ChatController;
use App\Helper\Permission;

/**
 * Die Routing-Tabelle.
 *
 * Format je Eintrag:
 *   'aktionsname' => [ControllerClass::class, 'methodenName', 'recht', 'antwortart']
 *
 *   [0] Controller-Klasse
 *   [1] Methode
 *   [2] PFLICHT: das Recht aus App\Helper\Permission, das der Aufrufer
 *       braucht. index.php lehnt eine Route ohne Recht ab und ruft den
 *       Controller gar nicht erst auf - eine Route ohne Rechteangabe ist ein
 *       Konfigurationsfehler, kein offener Zugang. Auch die Startseite und
 *       das Loginformular haben eines; wer nicht angemeldet ist, hat die
 *       Rechte der Rolle "Gast" (Permission::GUEST).
 *   [3] PFLICHT: 'json' für Schnittstellen, die der Browser per fetch/AJAX
 *       aufruft, 'html' für Seiten. Davon hängt nur ab, wie eine Ablehnung
 *       aussieht: JSON-Fehlerobjekt mit Statuscode 401/403 gegenüber
 *       Weiterleitung zur Anmeldung bzw. Hinweisseite. Ein Redirect auf eine
 *       AJAX-Anfrage kam vorher als HTML im JSON-Parser an.
 *
 * Beim Ergänzen einer Route: Recht eintragen, nicht erfinden. Ein Name, den
 * keine Rolle in Permission kennt, lässt tests/server_test.php und index.php
 * fehlschlagen.
 */
return [
    // Registrierung
    'signup_page'           => [SignupController::class             , 'showSignupForm'          , Permission::AUTH_SIGNUP            , 'html'],
    'signup'                => [SignupController::class             , 'handleSignup'            , Permission::AUTH_SIGNUP            , 'html'],

    // Login und Logout
    'login_page'            => [LoginController::class              , 'showLoginForm'           , Permission::AUTH_LOGIN             , 'html'],
    'login'                 => [LoginController::class              , 'handleLogin'             , Permission::AUTH_LOGIN             , 'html'],
    'logout'                => [LoginController::class              , 'handleLogout'            , Permission::AUTH_LOGOUT            , 'html'],

    // Administrations- und Startseiten
    'admin'                 => [SystemController::class             , 'showAdmin'               , Permission::SYSTEM_ADMIN           , 'html'],
    'home'                  => [SystemController::class             , 'home'                    , Permission::SYSTEM_HOME            , 'html'],
    'start'                 => [SystemController::class             , 'showStart'               , Permission::SYSTEM_HOME            , 'html'],

    // Benutzerverwaltung
    'manage_user'           => [UserController::class               , 'manageUser'              , Permission::USER_MANAGE            , 'html'],
    'list_user'             => [UserController::class               , 'listUser'                , Permission::USER_LIST              , 'html'],
    'delete_user'           => [UserController::class               , 'deleteUser'              , Permission::USER_DELETE            , 'html'],
    'heartbeat'             => [UserController::class               , 'heartbeat'               , Permission::USER_PRESENCE          , 'json'],
    'get_username'          => [UserController::class               , 'getUsername'             , Permission::USER_READ_NAME         , 'json'],
    'save_location'         => [UserController::class               , 'saveLocation'            , Permission::USER_POSITION          , 'json'],

    // Standortverwaltung
    'set_location_page'     => [LocationController::class           , 'setLocationPage'         , Permission::LOCATION_CREATE        , 'html'],
    'set_location'          => [LocationController::class           , 'setLocation'             , Permission::LOCATION_CREATE        , 'html'],
    'get_country'           => [LocationController::class           , 'getCountry'              , Permission::LOCATION_COUNTRY_LIST  , 'json'],
    'get_locations'         => [LocationController::class           , 'getLocations'            , Permission::LOCATION_LIST          , 'json'],
    // Die Karte der Startseite. Einzige Standortroute, die ein Gast
    // aufrufen darf - sie gibt weder Benutzernamen noch IDs heraus.
    'get_map_locations'     => [LocationController::class           , 'getMapLocations'         , Permission::LOCATION_MAP_PUBLIC    , 'json'],
    'get_my_locations'      => [LocationController::class           , 'getMyLocations'          , Permission::LOCATION_LIST_OWN      , 'json'],
    'show_locations_page'   => [LocationController::class           , 'showLocationsPage'       , Permission::LOCATION_PAGE          , 'html'],
    'edit_location_desc'    => [LocationController::class           , 'editLocationDesc'        , Permission::LOCATION_EDIT_OWN      , 'json'],
    'delete_location'       => [LocationController::class           , 'deleteLocation'          , Permission::LOCATION_DELETE_OWN    , 'json'],
    'block_location'        => [LocationController::class           , 'blockLocation'           , Permission::LOCATION_BLOCK         , 'json'],
    'unblock_location'      => [LocationController::class           , 'unblockLocation'         , Permission::LOCATION_BLOCK         , 'json'],

    // WebRTC-Funktionen
    'getSignal'             => [WebRTCController::class             , 'getSignal'               , Permission::RTC_SIGNAL             , 'json'],

    // TURN-Server-Zugang
    'get_turn_credentials'  => [TurnController::class               , 'getTurnCredentials'      , Permission::RTC_TURN               , 'json'],

    // Passwort-Reset und -Änderung
    "forgot_pw"             => [PasswordController::class           , "handleForgotPassword"    , Permission::AUTH_PASSWORD_RESET    , 'html'],
    "reset_pw"              => [PasswordController::class           , "handleResetPassword"     , Permission::AUTH_PASSWORD_RESET    , 'html'],
    "forgot_pw_page"        => [PasswordController::class           , "showForgotPwForm"        , Permission::AUTH_PASSWORD_RESET    , 'html'],
    "reset_pw_page"         => [PasswordController::class           , "showResetForm"           , Permission::AUTH_PASSWORD_RESET    , 'html'],
    "change_pw_page"        => [PasswordController::class           , "showChangePwForm"        , Permission::AUTH_PASSWORD_CHANGE   , 'html'],
    "change_pw"             => [PasswordController::class           , "handleChangePassword"    , Permission::AUTH_PASSWORD_CHANGE   , 'html'],

    // E-Mail-Verifizierung
    "verify_email"          => [EmailVerificationController::class  , "handleEmailVerification" , Permission::AUTH_EMAIL_VERIFY      , 'html'],
    "send_email_verify"     => [EmailVerificationController::class  , "sendVerification"        , Permission::AUTH_EMAIL_VERIFY_SEND , 'html'],

    // Zwei-Faktor-Authentifizierung (2FA)
    //
    // Einrichten und Abschalten setzt eine bestehende Anmeldung voraus. Das
    // Eingeben des zweiten Faktors dagegen findet zwischen Passwortprüfung
    // und Session statt - dort ist der Aufrufer formal noch ein Gast.
    "2fa_setup"             => [TwoFactorController::class          , "show2FASetup"            , Permission::AUTH_TWOFACTOR_MANAGE  , 'html'],
    "2fa_activate"          => [TwoFactorController::class          , "handle2FAActivate"       , Permission::AUTH_TWOFACTOR_MANAGE  , 'html'],
    "2fa_verify_page"       => [TwoFactorController::class          , "show2FAVerifyForm"       , Permission::AUTH_TWOFACTOR_VERIFY  , 'html'],
    "2fa_verify"            => [TwoFactorController::class          , "handle2FAVerify"         , Permission::AUTH_TWOFACTOR_VERIFY  , 'html'],
    "2fa_disable"           => [TwoFactorController::class          , "disable2FA"              , Permission::AUTH_TWOFACTOR_MANAGE  , 'html'],

    // Einstellungen
    "settings"              => [SettingsController::class           , "showSettingsPage"        , Permission::USER_SETTINGS          , 'html'],

    // Guide-Rolle: die Frage stellen und beantworten.
    //
    // Zwei Routen mit demselben Recht: Die Seite zeigt die Frage, die zweite
    // nimmt die Antwort entgegen und aendert dabei die Rolle des Kontos -
    // deshalb laesst der Controller dort nur POST zu.
    'guide_role_page'       => [GuideController::class              , 'showGuideRolePage'       , Permission::USER_GUIDE_ROLE        , 'html'],
    'guide_role'            => [GuideController::class              , 'handleGuideRole'         , Permission::USER_GUIDE_ROLE        , 'html'],

    // Chat-Funktionen
    'chat_start'            => [ChatController::class               , 'startChat'               , Permission::CHAT_START             , 'json'],
    'chat_accept'           => [ChatController::class               , 'acceptChat'              , Permission::CHAT_ANSWER            , 'json'],
    'chat_get_chats'        => [ChatController::class               , 'getChats'                , Permission::CHAT_LIST              , 'json'],
    'chat_get_messages'     => [ChatController::class               , 'getMessages'             , Permission::CHAT_READ              , 'json'],
    'chat_send_message'     => [ChatController::class               , 'sendMessage'             , Permission::CHAT_WRITE             , 'json'],
    'chat_get_invitations'  => [ChatController::class               , 'getChatInvitations'      , Permission::CHAT_LIST              , 'json'],
    'chat_decline'          => [ChatController::class               , 'declineChat'             , Permission::CHAT_ANSWER            , 'json'],
    'chat_set_seen'         => [ChatController::class               , 'setMessagesSeen'         , Permission::CHAT_READ              , 'json'],
    'get_all_chats'         => [ChatController::class               , 'getAllChats'             , Permission::CHAT_LIST              , 'html'],
    'show_chat'             => [ChatController::class               , 'showChat'                , Permission::CHAT_READ              , 'html'],

];
