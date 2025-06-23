<?php
// Datei: class/Request.php

namespace App\Helper;

/**
 * Hilfsklasse für den Zugriff auf Request-Parameter.
 * Kapselt den Zugriff auf $_REQUEST und ermöglicht einen Default-Wert.
 */
class Request
{
    /**
     * Holt einen Wert aus $_REQUEST (GET/POST), falls nicht vorhanden wird ein Default zurückgegeben.
     * 
     * @param string $key     Der Name des Parameters (z.B. 'username').
     * @param mixed  $default Wert, der zurückgegeben wird, wenn der Key nicht existiert (Standard: null).
     * @return mixed          Der Wert aus $_REQUEST oder der Default-Wert.
     */
    public static function g($key, $default = null)
    {
        // Holt den Wert aus $_REQUEST oder gibt den Default-Wert zurück
        return $_REQUEST[$key] ?? $default;
    }
}
