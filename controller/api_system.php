<?php
function act_get_turn_credentials() {
    header('Content-Type: application/json');
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $configPath = __DIR__ . '/../config/api.php';
    if (!file_exists($configPath)) {
        http_response_code(500);
        echo json_encode(["error" => "config/api.php not found at $configPath"]);
        exit;
    }
    $config = require $configPath;
    if (!isset($config['metered_app_name']) || !isset($config['metered_api_key'])) {
        http_response_code(500);
        echo json_encode(["error" => "metered_app_name or metered_api_key missing in config"]);
        exit;
    }

    $appname = $config['metered_app_name'];
    $apikey = $config['metered_api_key'];
    $url = "https://$appname.metered.live/api/v1/turn/credentials?apiKey=$apikey";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode != 200 || !$response) {
        http_response_code(500);
        echo json_encode(["error" => "Could not fetch TURN credentials", "httpcode" => $httpcode, "response" => $response]);
        exit;
    }
    echo $response;
    exit;
}
