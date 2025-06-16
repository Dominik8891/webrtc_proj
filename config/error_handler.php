<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php-error.log');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    error_log("[$severity] $message in $file on line $line");
    http_response_code(500);
    echo "Interner Serverfehler.";
    exit;
});

set_exception_handler(function ($exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo "Interner Serverfehler.";
    exit;
});
