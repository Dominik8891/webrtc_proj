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
     * Holt einen Wert aus $_REQUEST (GET/POST).
     *
     * Der Default ist ein LEERSTRING, nicht null. Grund: der Rückgabewert
     * landet an vielen Stellen direkt in String-Funktionen wie trim(),
     * strlen(), str_replace() oder hash_hmac(). Seit PHP 8.1 loest null dort
     * ein E_DEPRECATED aus, das der Error-Handler in config/error_handler.php
     * abfaengt und in einen HTTP 500 verwandelt. Ein fehlendes Formularfeld
     * fuehrte dadurch zu einem Serverfehler statt zu einer Fehlermeldung.
     *
     * Wer bewusst zwischen "Feld fehlt" und "Feld ist leer" unterscheiden
     * muss, uebergibt null als zweiten Parameter - siehe
     * UserController::manageUser().
     *
     * Nicht-skalare Werte (etwa ein Array bei "?name[]=x") werden ebenfalls
     * auf den Default abgebildet. Ohne diese Pruefung wuerde trim($array)
     * einen TypeError und damit einen echten Fatal Error ausloesen.
     *
     * @param  string      $key     Der Name des Parameters (z.B. 'username').
     * @param  string|null $default Rueckgabe, wenn der Key fehlt oder der Wert
     *                              nicht skalar ist (Standard: Leerstring).
     * @return string|null          Wert als String, sonst der Default.
     */
    public static function g(string $key, ?string $default = ''): ?string
    {
        $wert = $_REQUEST[$key] ?? $default;

        // Arrays, Objekte und null auf den Default abbilden. is_scalar()
        // deckt string, int, float und bool ab - alles, was sich
        // verlustfrei in einen String wandeln laesst.
        return is_scalar($wert) ? (string)$wert : $default;
    }
}
