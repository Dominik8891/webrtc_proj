<?php

    function act_set_location_page() {
        $out = file_get_contents('assets/html/set_location.html');

        output($out);
    }

    function act_set_location() {
        if($_SERVER["REQUEST_METHOD"] == "POST") {
            $country_id = g('country');
            $city = g('city');
            $longitude = g('longitude');
            $latitude = g('latitude');
            $description = g('description');

            $tmp_location = new Location();
            $tmp_location->set_country($country);
            $tmp_location->set_city($city);
            $tmp_location->set_longitude($longitude);
            $tmp_location->set_latitude($latitude);
            $tmp_location->set_description($description);
            $tmp_location->set_new_location($_SESSION['user_id'], $country_id);

            echo 'passt';
        }
       
    }

    function act_get_country() {
        $location = new Location();
        $data = $location->select_all_countries();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }