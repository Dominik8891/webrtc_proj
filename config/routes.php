<?php

// Importiert die Controller-Klassen, die für die Verarbeitung der jeweiligen Routen zuständig sind
use App\Controller\AdminController;
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
use App\Controller\GuideProfileController;
use App\Controller\ChatController;
use App\Controller\RequestController;
use App\Controller\ReviewController;
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

    // DER VERWALTUNGSBEREICH
    //
    // Ein eigener Bereich mit eigener Navigation, nicht mehr eine Handvoll
    // Sonderfaelle in der Kundenoberflaeche. Was vorher wo lag, steht in
    // App\Helper\AdminView; die Kurzfassung: Sperren und Freigeben hingen als
    // zwei Symbolknoepfe in der Standortliste der Kunden, das Entfernen einer
    // Bewertung klebte an jeder Bewertung der Standortseite und des
    // Guide-Profils, und die Benutzerliste stand als einziger Eintrag "nur
    // fuer den Admin" im Kontomenue.
    //
    // DREI ROUTEN, DREI RECHTE, und das ist keine Umstaendlichkeit: Die
    // Uebersicht traegt system.admin, die Standortliste location.block, die
    // Bewertungsliste review.remove - also jeweils genau das Recht, das man
    // fuer die Handlung braucht, die dort stattfindet. Heute hat alle drei
    // nur der Admin. Kaeme eine reine Moderationsrolle dazu, bekaeme sie die
    // Liste, zu der ihr Recht passt, und die Navigation zeigte ihr auch nur
    // diese (AdminView::navHtml) - ohne dass hier etwas nachzuziehen waere.
    //
    // ALLE DREI ZEIGEN NUR. Geaendert wird ueber die Routen, die es schon
    // gab: block_location / unblock_location, review_remove, manage_user /
    // delete_user. Ein zweiter Schreibweg "fuer den Adminbereich" waere die
    // Doppelung, wegen der es diesen Bereich gibt.
    'admin'                 => [AdminController::class              , 'showDashboard'           , Permission::SYSTEM_ADMIN           , 'html'],
    'admin_locations'       => [AdminController::class              , 'showLocations'           , Permission::LOCATION_BLOCK         , 'html'],
    // Die Anfragenliste der Verwaltung - die Seite, auf der die beiden
    // Arbeitsvorraete der Uebersicht abgearbeitet werden. Eigenes Recht
    // (request.list_all), weil es hier um FREMDE Vorgaenge geht: request.list
    // hat jedes Konto fuer seine eigenen. Sie zeigt nur; angenommen,
    // abgelehnt und beendet wird weiter von den Beteiligten.
    'admin_requests'        => [AdminController::class              , 'showRequests'            , Permission::REQUEST_LIST_ALL       , 'html'],
    'admin_reviews'         => [AdminController::class              , 'showReviews'             , Permission::REVIEW_REMOVE          , 'html'],

    // Startseite
    'home'                  => [SystemController::class             , 'home'                    , Permission::SYSTEM_HOME            , 'html'],
    // Die Route 'start' ist entfallen. Sie las eine Vorlage aus einem
    // Verzeichnis, das es nicht gibt (assets/html/frontend/), und rief danach
    // output_fe() - eine Funktion, die im Projekt nirgends definiert ist. Der
    // Aufruf endete also in jedem Fall mit einem Fehler. Verwiesen hat auf sie
    // nichts.


    // Benutzerverwaltung - DREI SEITEN DES VERWALTUNGSBEREICHS.
    //
    // Sie liegen weiter bei UserController, werden aber in den Rahmen des
    // Bereichs gesetzt (App\Helper\AdminView::page). Vorher waren es Seiten
    // wie jede andere und nur an ihrer Adresse als Verwaltung zu erkennen.
    'manage_user'           => [UserController::class               , 'manageUser'              , Permission::USER_MANAGE            , 'html'],
    'list_user'             => [UserController::class               , 'listUser'                , Permission::USER_LIST              , 'html'],
    'delete_user'           => [UserController::class               , 'deleteUser'              , Permission::USER_DELETE            , 'html'],
    'heartbeat'             => [UserController::class               , 'heartbeat'               , Permission::USER_PRESENCE          , 'json'],
    // Der Bereitschaftsschalter der Kopfleiste. NICHT der Heartbeat:
    // Angemeldet zu sein und fuehren zu wollen sind zwei verschiedene
    // Aussagen, und deshalb sind es zwei Routen mit zwei Rechten. Die eine
    // meldet ein laufendes Programm, die andere trifft eine Entscheidung.
    //
    // Dieselbe Route schaltet ein und aus (POST mit "ready") und wird auch
    // beim Schliessen der Seite noch einmal als Beacon aufgerufen.
    'set_availability'      => [UserController::class               , 'setAvailability'         , Permission::USER_AVAILABILITY      , 'json'],
    'get_username'          => [UserController::class               , 'getUsername'             , Permission::USER_READ_NAME         , 'json'],
    // Die Route 'save_location' ist entfallen. Sie schrieb die per
    // Browser-Geolocation gemeldete Position nach user.latitude/longitude -
    // zwei Spalten, die keine einzige Lesestelle haben. Begruendet war die
    // Abfrage mit einer Umkreissuche, die es in dieser Anwendung nicht gibt:
    // Gesucht wird ueber die Karte, und die zeigt Standorte, keine Nutzer.
    // Mit der Route sind der Dialog (assets/html/location_prompt.html),
    // sein Skript und das Recht user.position entfallen.

    // Standortverwaltung
    'set_location_page'     => [LocationController::class           , 'setLocationPage'         , Permission::LOCATION_CREATE        , 'html'],
    'set_location'          => [LocationController::class           , 'setLocation'             , Permission::LOCATION_CREATE        , 'html'],
    'get_country'           => [LocationController::class           , 'getCountry'              , Permission::LOCATION_COUNTRY_LIST  , 'json'],
    'get_locations'         => [LocationController::class           , 'getLocations'            , Permission::LOCATION_LIST          , 'json'],
    // Die Karte der Startseite. Eine von zwei Standortrouten, die ein Gast
    // aufrufen darf (die andere ist die Standortseite weiter unten) - sie
    // gibt weder Benutzernamen noch IDs heraus.
    'get_map_locations'     => [LocationController::class           , 'getMapLocations'         , Permission::LOCATION_MAP_PUBLIC    , 'json'],
    'get_my_locations'      => [LocationController::class           , 'getMyLocations'          , Permission::LOCATION_LIST_OWN      , 'json'],
    'show_locations_page'   => [LocationController::class           , 'showLocationsPage'       , Permission::LOCATION_PAGE          , 'html'],

    // Die Seite EINES Standorts - das Ziel jeder Nadel und jeder
    // Listenzeile. Sie ist die Adresse, die ein Guide weitergibt, und
    // deshalb auch fuer Gaeste erreichbar (Permission::LOCATION_VIEW).
    // Von hier aus wird die Fuehrung gestartet, und von hier aus bearbeitet
    // der Guide seinen Standort.
    'location'              => [LocationController::class           , 'showLocationPage'        , Permission::LOCATION_VIEW          , 'html'],
    // Nur die Verfuegbarkeit, im Takt nachgefragt. Damit der Knopf
    // "Fuehrung starten" nicht anbietet, was gerade nicht geht - ohne die
    // ganze Seite neu zu laden.
    'get_location_state'    => [LocationController::class           , 'getLocationState'        , Permission::LOCATION_VIEW          , 'json'],
    // Ein Bild ausliefern. Die Dateien liegen AUSSERHALB des Webroots
    // (config/uploads.php); dies ist der einzige Weg, auf dem sie einen
    // Browser erreichen - und der einzige Ort, an dem vorher geprueft wird,
    // ob der Standort gesperrt ist.
    'location_image'        => [LocationController::class           , 'serveImage'              , Permission::LOCATION_VIEW          , 'html'],

    // Bearbeiten. Alles vier setzt Eigentum am Standort voraus - das prueft
    // der Controller und noch einmal die WHERE-Klausel, denn eine
    // Rechtetabelle kann nicht wissen, welcher Datensatz wem gehoert.
    //
    // Die Route 'edit_location_desc' ist entfallen. Sie aenderte ueber einen
    // Dialog in der Standortliste genau ein Feld - die Beschreibung. Seit
    // ein Standort Titel, ausfuehrliche Beschreibung, Dauer, Sprachen und
    // Bilder hat, waere sie das Formular fuer ein Fuenftel davon; bearbeitet
    // wird auf der Standortseite selbst.
    'update_location'       => [LocationController::class           , 'updateLocation'          , Permission::LOCATION_EDIT_OWN      , 'html'],
    'upload_location_image' => [LocationController::class           , 'uploadImage'             , Permission::LOCATION_EDIT_OWN      , 'json'],
    'delete_location_image' => [LocationController::class           , 'deleteImage'             , Permission::LOCATION_EDIT_OWN      , 'json'],
    'sort_location_images'  => [LocationController::class           , 'sortImages'              , Permission::LOCATION_EDIT_OWN      , 'json'],
    // Welches der Bilder die Kopfzeile fuellt. Ein Titelbild braucht ein sehr
    // breites Format und ruhige Flaechen fuer die Schrift, ein Beispielbild
    // soll den Ort zeigen - vorher musste EIN Bild beides sein. Ausgewaehlt
    // wird es hier, geloescht wird dabei nichts.
    'set_location_cover'    => [LocationController::class           , 'setCoverImage'           , Permission::LOCATION_EDIT_OWN      , 'json'],
    'unset_location_cover'  => [LocationController::class           , 'unsetCoverImage'         , Permission::LOCATION_EDIT_OWN      , 'json'],

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
    // Farbprofil umstellen. Antwortet als JSON, weil die Seite nicht neu
    // geladen wird - das Profil wirkt sofort ueber data-theme am <html>.
    'set_theme'             => [SettingsController::class           , 'setTheme'                , Permission::USER_SETTINGS          , 'json'],

    // Guide-Rolle: die Frage stellen und beantworten.
    //
    // Zwei Routen mit demselben Recht: Die Seite zeigt die Frage, die zweite
    // nimmt die Antwort entgegen und aendert dabei die Rolle des Kontos -
    // deshalb laesst der Controller dort nur POST zu.
    'guide_role_page'       => [GuideController::class              , 'showGuideRolePage'       , Permission::USER_GUIDE_ROLE        , 'html'],
    'guide_role'            => [GuideController::class              , 'handleGuideRole'         , Permission::USER_GUIDE_ROLE        , 'html'],

    // Das Guide-PROFIL: der Mensch hinter dem Angebot.
    //
    // Ein Kunde sah vom Guide bisher einen Benutzernamen und einen farbigen
    // Punkt. Fuer "ich vertraue dieser Person und zahle ihr Geld" reicht das
    // nicht. Die Profilseite zeigt Bild, Anzeigenamen, Selbstbeschreibung,
    // Sprachen, seit wann dabei - und die Standorte, die dieser Guide
    // anbietet.
    //
    // ZWEI ROUTEN FUER GAESTE (Permission::GUIDE_VIEW), aus demselben Grund
    // wie bei der Standortseite: Die Adresse soll sich weitergeben lassen.
    // Das Bild traegt dasselbe Recht wie die Seite - was darauf zu sehen
    // ist, muss auch geladen werden duerfen. Die Dateien liegen AUSSERHALB
    // des Webroots (config/uploads.php); der Controller ist der einzige Weg
    // zu ihnen.
    //
    // ZWEI ROUTEN ZUM BEARBEITEN (Permission::GUIDE_PROFILE_EDIT), beide nur
    // per POST und beide immer fuer den Angemeldeten: Eine Benutzerkennung
    // aus der Anfrage liest der Controller nicht. Bearbeitet wird von der
    // Kontoseite aus, nicht auf dem Profil selbst - die Profilseite soll
    // fuer den Eigentuemer genauso aussehen wie fuer einen Kunden.
    'guide'                 => [GuideProfileController::class       , 'showProfilePage'         , Permission::GUIDE_VIEW             , 'html'],
    'guide_avatar'          => [GuideProfileController::class       , 'serveAvatar'             , Permission::GUIDE_VIEW             , 'html'],
    'guide_profile_save'    => [GuideProfileController::class       , 'saveProfile'             , Permission::GUIDE_PROFILE_EDIT     , 'html'],
    'guide_avatar_delete'   => [GuideProfileController::class       , 'deleteAvatar'            , Permission::GUIDE_PROFILE_EDIT     , 'html'],

    // Anfragen: der neue Anfang jeder Fuehrung.
    //
    // Vorher rief ein Kunde den Guide unmittelbar an - das verlangte, dass
    // beide zufaellig im selben Moment koennen. Jetzt stellt er von der
    // Standortseite aus eine Anfrage mit einem Wunschzeitpunkt ("jetzt
    // sofort" ist dabei nur ein Zeitpunkt unter anderen), und der Guide nimmt
    // an oder lehnt ab. Erst danach wird angerufen - ueber dieselbe Route
    // getSignal und mit derselben Rollenvergabe wie bisher.
    //
    // Vier Rechte statt eines: Anfragen darf jedes angemeldete Konto,
    // BEANTWORTEN nur, wer selbst Standorte anbietet - dieselben Rollen wie
    // bei location.offer. Ob die einzelne Anfrage an den Aufrufer gerichtet
    // ist, kann keine Rechtetabelle wissen; das steht in der WHERE-Klausel
    // (App\Model\TourRequest).
    'request_create'        => [RequestController::class            , 'create'                  , Permission::REQUEST_CREATE         , 'json'],
    'request_accept'        => [RequestController::class            , 'accept'                  , Permission::REQUEST_ANSWER         , 'json'],
    'request_decline'       => [RequestController::class            , 'decline'                 , Permission::REQUEST_ANSWER         , 'json'],
    'request_cancel'        => [RequestController::class            , 'cancel'                  , Permission::REQUEST_CANCEL         , 'json'],
    // DIE FUEHRUNG BEENDEN - nur der Guide (Recht request.finish).
    //
    // Auflegen tut das NICHT mehr, und das ist der Punkt: Auflegen ist
    // zweideutig - "wir sind fertig" oder "das Netz ist weg". Vorher galt
    // jedes Auflegen als Abschluss, der Startknopf beim Kunden blieb dabei
    // stehen, und die Fuehrung liess sich beliebig oft neu starten. Jetzt
    // gilt sie bis zu diesem Aufruf als unterbrochen: Beide Seiten koennen
    // wieder einsteigen, bis die Frist aus config/requests.php verstrichen
    // ist (rejoin_window). Erst danach verschwindet der Startknopf, und erst
    // danach wird die Bewertung faellig.
    'request_finish'        => [RequestController::class            , 'finish'                  , Permission::REQUEST_FINISH         , 'json'],
    'get_requests'          => [RequestController::class            , 'getRequests'             , Permission::REQUEST_LIST           , 'json'],
    // Die Seite, auf der beide Seiten ihren Stand sehen. Der Zaehler dorthin
    // steht in der Kopfleiste, also auf jeder Seite: Eine Anfrage, die der
    // Guide im Moment des Eintreffens nicht bemerkt hat, muss er spaeter an
    // einer Stelle wiederfinden, die er ohnehin ansteuert.
    'requests_page'         => [RequestController::class            , 'showRequestsPage'        , Permission::REQUEST_LIST           , 'html'],

    // Bewertungen: was der Kunde nach der Fuehrung sagt.
    //
    // NUR DIESE RICHTUNG - Kunden bewerten Guides. Eine Route fuer den
    // umgekehrten Weg gibt es nicht, und im Schema auch keine Spalte dafuer
    // (migrations/016).
    //
    // Zwei Routen und zwei Rechte, und der Unterschied ist der Punkt:
    // Bewerten darf jedes angemeldete Konto (review.create), ENTFERNEN nur
    // die Moderation (review.remove). Der Guide kann seine Bewertungen
    // weder aendern noch loeschen - sonst waeren sie keine Auskunft mehr
    // ueber ihn, sondern eine von ihm.
    //
    // WELCHE Fuehrung jemand bewerten darf, kann keine Rechtetabelle wissen:
    // Das steht in der WHERE-Klausel (App\Model\TourReview::create). Eine
    // Leseroute gibt es nicht - die Bewertungen stehen auf der Standortseite
    // und auf dem Guide-Profil, und beide baut der Server.
    'review_create'         => [ReviewController::class             , 'create'                  , Permission::REVIEW_CREATE          , 'json'],
    'review_remove'         => [ReviewController::class             , 'remove'                  , Permission::REVIEW_REMOVE          , 'json'],

    // Chat-Funktionen
    //
    // EIN CHAT ENTSTEHT UEBER EINEN STANDORT. Das ist die Antwort auf Befund
    // N-12: chat_start nimmt eine STANDORTKENNUNG entgegen und holt sich den
    // Guide selbst dazu (App\Model\Location::guideIdOf). Vorher nahm die
    // Route eine beliebige Kontokennung - wer sie kannte, konnte jedem Konto
    // der Plattform schreiben, und die Kennungen sind fortlaufend.
    //
    // Damit ist der Chat zugleich ERREICHBAR geworden: Er hing vorher an der
    // Benutzerliste, die nur der Admin sieht - ein Kunde hatte gar keinen Weg
    // zu seinem Guide.
    //
    // ZWEI EINSTIEGE, ZWEI RECHTE, und der Unterschied ist der Punkt:
    // chat.start hat jedes angemeldete Konto, chat.start_direct nur der
    // Admin. Der Direktzugang gehoert zur Benutzerliste und ist deshalb eine
    // eigene Route: Ueber den Zugang entscheidet index.php anhand der
    // Rechtetabelle, und ein "wenn Admin, dann anders" mitten im Controller
    // waere eine zweite Rechteentscheidung an einer Stelle, an der niemand
    // sie sucht.
    //
    // ENTFALLEN SIND chat_accept und chat_decline. Ein Chat war vorher erst
    // eine Einladung, die der Angeschriebene annehmen musste, bevor der
    // andere ueberhaupt schreiben durfte - der Guide entschied also ueber
    // einen blossen Namen. Die Begruendung im Ganzen steht in
    // migrations/019_standort_chat.sql.
    'chat_start'            => [ChatController::class               , 'startChat'               , Permission::CHAT_START             , 'json'],
    'chat_start_direct'     => [ChatController::class               , 'startDirectChat'         , Permission::CHAT_START_DIRECT      , 'json'],
    'chat_get_chats'        => [ChatController::class               , 'getChats'                , Permission::CHAT_LIST              , 'json'],
    'chat_get_messages'     => [ChatController::class               , 'getMessages'             , Permission::CHAT_READ              , 'json'],
    'chat_send_message'     => [ChatController::class               , 'sendMessage'             , Permission::CHAT_WRITE             , 'json'],
    'chat_set_seen'         => [ChatController::class               , 'setMessagesSeen'         , Permission::CHAT_READ              , 'json'],
    'get_all_chats'         => [ChatController::class               , 'getAllChats'             , Permission::CHAT_LIST              , 'html'],
    'show_chat'             => [ChatController::class               , 'showChat'                , Permission::CHAT_READ              , 'html'],

];
