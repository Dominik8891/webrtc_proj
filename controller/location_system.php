<?php

    function act_set_location_page() {
        if($_SESSION['user_id']) {
            $out = file_get_contents('assets/html/set_location.html');

            output($out);
        } else {
            home();
        }
        
    }

    function act_set_location() {
        if($_SERVER["REQUEST_METHOD"] == "POST") {
            $country_id = g('country');
            $city = g('city');
            $longitude = g('longitude');
            $latitude = g('latitude');
            $description = g('description');
            
            $user = new User($_SESSION['user_id']);
            if($user->get_usertype() === 'tourist') {
                try {
                    $user->set_usertype('guide');
                    $user->save();
                } catch (e) {
                    
                }
            }

            $tmp_location = new Location();
            $tmp_location->set_country($country);
            $tmp_location->set_city($city);
            $tmp_location->set_longitude($longitude);
            $tmp_location->set_latitude($latitude);
            $tmp_location->set_description($description);
            $tmp_location->set_new_location($_SESSION['user_id'], $country_id);

            home();
        }
       
    }

    function act_get_country() {
        $location = new Location();
        $data = $location->select_all_countries();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    function act_get_locations() {
        $location = new Location();
        $data = $location->select_all_locations();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    function act_show_locations() {
        if($_SESSION['user_id']) {
            $out = file_get_contents('assets/html/locations_table.html');

            output($out);
        } else {
            home();
        }
    }