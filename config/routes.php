<?php

// Importiert die Controller-Klassen, die für die Verarbeitung der jeweiligen Routen zuständig sind
use App\Controller\SignupController;
use App\Controller\LoginController;
use App\Controller\SystemController;
use App\Controller\UserController;
use App\Controller\LocationController;
use App\Controller\WebRTCController;
use App\Controller\TurnController;
use App\Controller\MessageController;
use App\Controller\PasswordController;
use App\Controller\EmailVerificationController;
use App\Controller\TwoFactorController;
use App\Controller\SettingsController;
use App\Controller\ChatController;

// Die Routing-Tabelle: Ordnet jedem Aktionsnamen ein Controller/Methode-Paar zu
// Format: 'aktionsname' => [ControllerClass::class, 'methodenName']
return [
    // Registrierung
    'signup_page'           => [SignupController::class             , 'showSignupForm'          ],
    'signup'                => [SignupController::class             , 'handleSignup'            ],

    // Login und Logout
    'login_page'            => [LoginController::class              , 'showLoginForm'           ],
    'login'                 => [LoginController::class              , 'handleLogin'             ],
    'logout'                => [LoginController::class              , 'handleLogout'            ],

    // Administrations- und Startseiten
    'admin'                 => [SystemController::class             , 'showAdmin'               ],
    'home'                  => [SystemController::class             , 'home'                    ],
    'start'                 => [SystemController::class             , 'showStart'               ],

    // Benutzerverwaltung
    'manage_user'           => [UserController::class               , 'manageUser'              ],
    'list_user'             => [UserController::class               , 'listUser'                ],
    'delete_user'           => [UserController::class               , 'deleteUser'              ],
    'heartbeat'             => [UserController::class               , 'heartbeat'               ],
    'get_username'          => [UserController::class               , 'getUsername'             ],
    'save_location'         => [UserController::class               , 'saveLocation'            ],

    // Standortverwaltung
    'set_location_page'     => [LocationController::class           , 'setLocationPage'         ],
    'set_location'          => [LocationController::class           , 'setLocation'             ],
    'get_country'           => [LocationController::class           , 'getCountry'              ],
    'get_locations'         => [LocationController::class           , 'getLocations'            ],
    'get_my_locations'      => [LocationController::class           , 'getMyLocations'          ],
    'show_locations_page'   => [LocationController::class           , 'showLocationsPage'       ],
    'edit_location_desc'    => [LocationController::class           , 'editLocationDesc'        ],
    'delete_location'       => [LocationController::class           , 'deleteLocation'          ],

    // WebRTC-Funktionen
    'getSignal'             => [WebRTCController::class             , 'getSignal'               ],

    // TURN-Server-Zugang
    'get_turn_credentials'  => [TurnController::class               , 'getTurnCredentials'      ],

    // Nachrichtenverarbeitung
    'process_message'       => [MessageController::class            , 'processMessage'          ],
    'goto_chat'             => [MessageController::class            , 'gotoChat'                ],

    // Passwort-Reset und -Änderung
    "forgot_pw"             => [PasswordController::class           , "handleForgotPassword"    ],
    "reset_pw"              => [PasswordController::class           , "handleResetPassword"     ],
    "forgot_pw_page"        => [PasswordController::class           , "showForgotPwForm"        ],
    "reset_pw_page"         => [PasswordController::class           , "showResetForm"           ],
    "change_pw_page"        => [PasswordController::class           , "showChangePwForm"        ],
    "change_pw"             => [PasswordController::class           , "handleChangePassword"    ],

    // E-Mail-Verifizierung
    "verify_email"          => [EmailVerificationController::class  , "handleEmailVerification" ],
    "send_email_verify"     => [EmailVerificationController::class  , "sendVerification"        ],

    // Zwei-Faktor-Authentifizierung (2FA)
    "2fa_setup"             => [TwoFactorController::class          , "show2FASetup"            ],
    "2fa_activate"          => [TwoFactorController::class          , "handle2FAActivate"       ],
    "2fa_verify_page"       => [TwoFactorController::class          , "show2FAVerifyForm"       ],
    "2fa_verify"            => [TwoFactorController::class          , "handle2FAVerify"         ],
    "2fa_disable"           => [TwoFactorController::class          , "disable2FA"              ],

    // Einstellungen
    "settings"              => [SettingsController::class           , "showSettingsPage"        ],

    // Chat-Funktionen
    'chat_start'            => [ChatController::class               , 'startChat'               ],
    'chat_accept'           => [ChatController::class               , 'acceptChat'              ],
    'chat_get_chats'        => [ChatController::class               , 'getChats'                ],
    'chat_get_messages'     => [ChatController::class               , 'getMessages'             ],
    'chat_send_message'     => [ChatController::class               , 'sendMessage'             ],
    'chat_get_invitations'  => [ChatController::class               , 'getChatInvitations'      ],
    'chat_decline'          => [ChatController::class               , 'declineChat'             ],
    'chat_set_seen'         => [ChatController::class               , 'setMessagesSeen'         ],
    'get_all_chats'         => [ChatController::class               , 'getAllChats'             ],
    'show_chat'             => [ChatController::class               , 'showChat'                ],

];
