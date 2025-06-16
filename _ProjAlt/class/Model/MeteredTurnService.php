<?php

namespace App\Model;

class MeteredTurnService
{
    private $configPath;

    public function __construct($configPath)
    {
        $this->configPath = $configPath;
    }

    public function fetch_turn_credentials()
    {
        if (!file_exists($this->configPath)) {
            throw new Exception("config/api.php not found at $this->configPath");
        }
        $config = require $this->configPath;
        if (empty($config['metered_app_name']) || empty($config['metered_api_key'])) {
            throw new Exception("metered_app_name or metered_api_key missing in config");
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
            throw new Exception("Could not fetch TURN credentials (HTTP $httpcode): $response");
        }
        return $response;
    }
}
