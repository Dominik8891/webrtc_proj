<?php

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
// weitere Controller

return [
    'signup_page'           => [SignupController::class             , 'showSignupForm'          ],
    'signup'                => [SignupController::class             , 'handleSignup'            ],

    'login_page'            => [LoginController::class              , 'showLoginForm'           ],
    'login'                 => [LoginController::class              , 'handleLogin'             ],
    'logout'                => [LoginController::class              , 'handleLogout'            ],
    
    'admin'                 => [SystemController::class             , 'showAdmin'               ],
    'home'                  => [SystemController::class             , 'home'                    ],
    'start'                 => [SystemController::class             , 'showStart'               ],
    
    'manage_user'           => [UserController::class               , 'manageUser'              ],
    'list_user'             => [UserController::class               , 'listUser'                ],
    'delete_user'           => [UserController::class               , 'deleteUser'              ],
    'heartbeat'             => [UserController::class               , 'heartbeat'               ],
    'get_username'          => [UserController::class               , 'getUsername'             ],
    
    'set_location_page'     => [LocationController::class           , 'setLocationPage'         ],
    'set_location'          => [LocationController::class           , 'setLocation'             ],
    'get_country'           => [LocationController::class           , 'getCountry'              ],
    'get_locations'         => [LocationController::class           , 'getLocations'            ],
    'show_locations_page'   => [LocationController::class           , 'showLocationsPage'       ],
    
    'getSignal'             => [WebRTCController::class             , 'getSignal'               ],
    
    'get_turn_credentials'  => [TurnController::class               , 'getTurnCredentials'      ],
    
    'process_message'       => [MessageController::class            , 'processMessage'          ],
    'goto_chat'             => [MessageController::class            , 'gotoChat'                ],

    "forgot_pw"             => [PasswordController::class           , "handleForgotPassword"    ],
    "reset_pw"              => [PasswordController::class           , "handleResetPassword"     ],
    "forgot_pw_page"        => [PasswordController::class           , "showForgotPwForm"        ],
    "reset_pw_page"         => [PasswordController::class           , "showResetForm"           ],
    "change_pw_page"        => [PasswordController::class           , "showChangePwForm"        ],
    "change_pw"             => [PasswordController::class           , "handleChangePassword"    ],

    "verify_email"          => [EmailVerificationController::class  , "handleEmailVerification" ],
    "send_email_verify"     => [EmailVerificationController::class  , "sendVerification"        ],

    "2fa_setup"             => [TwoFactorController::class          , "show2FASetup"            ],
    "2fa_activate"          => [TwoFactorController::class          , "handle2FAActivate"       ],
    "2fa_verify_page"       => [TwoFactorController::class          , "show2FAVerifyForm"       ],
    "2fa_verify"            => [TwoFactorController::class          , "handle2FAVerify"         ],
    "2fa_disable"           => [TwoFactorController::class          , "disable2FA"              ],
    
    "settings"              => [SettingsController::class           , "showSettingsPage"        ],

];
