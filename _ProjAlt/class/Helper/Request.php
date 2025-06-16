<?php
// Datei: class/Request.php
namespace App\Helper;

class Request
{
    public static function g($key, $default = null)
    {
        //file_put_contents('getUsername_debug.txt', date('c').' dada:'.$key .PHP_EOL, FILE_APPEND);
        //file_put_contents('getUsername_debug.txt', date('c').' inhalt:'.$_REQUEST[$key] .PHP_EOL, FILE_APPEND);
        return $_REQUEST[$key] ?? $default;
    }
}
