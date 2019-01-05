<?php

require_once 'config.php';
require_once "ApiService.php";
require_once 'Database.php';


if($_GET['call_function'] == 'pushfleetio') {
    echo "<a href='".path('index.php')."'>Back To Home Page</a>";
    $processData = new ProcessData();
    $processData->checkforChangeWithinLastHour();
}
else if($_GET['call_function']  == 'pullGalooli') {
    echo "<a href='".path('index.php')."'>Back To Home Page</a>";
    $processData = new ProcessData();
    $processData->pullDataFromGalooli(false);
}

class ProcessData {
    public $returnedData;
    public $apiURL;
    public $_apiService;
    public $response;
    public $errors;
    private $currentDateTime;
    private $isInitialization;
    private $fleetioUpdate = false;
    private $pushUpdated = 0;

    function  __construct() {
        $this->_apiService = new ApiService();
    }

    //CRON JOB: this function should run every ten minutes
    function pullDataFromGalooli($isInitialization)
    {

        $this->isInitialization = $isInitialization;
        //get last update time
        $query = "SELECT value from configuration where name = 'last_gmt_update_time'";
        $tableRow = Database::getSingleRow($query);
        $lastPullTime = $tableRow["value"];
        // echo "lastPullTime : ".$lastPullTime;
        $this->apiURL = "https://sdk.galooli-systems.com/galooliSDKService.svc/json/Assets_Report?userName=matrixvtrack&password=matv123?&requestedPropertiesStr=u.id,u.name,ac.status,ac.latitude,ac.longitude,ac.distance,ac.main_fuel_tank_level&lastGmtUpdateTime=".str_replace(" ","%20",$lastPullTime);
        $get_data = $this->_apiService->callAPI('GET', $this->apiURL, false, 'galooli');
        $this->currentDateTime = date("Y-m-d h:i:s");
        $this->returnedData = json_decode($get_data, true);

        //var_dump($this->returnedData['CommonResult']['DataSet']);
        echo "<br><br>";
        if($this->returnedData != null && count($this->returnedData) != 0) {
            
            //update last update time
            $query = "UPDATE configuration SET value='".$this->currentDateTime."' where name = 'last_gmt_update_time'";
            if (Database::updateOrInsert($query)) {
                echo "LastGMTupdate Time Record updated successfully<br/><br/>";
            } else {
                echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
            }
            $this->currentDateTime = date("Y-m-d H:i:s");
            //update fetch status

            $this->updateErrorData('pull_error_time', 0);

            foreach($this->returnedData['CommonResult']['DataSet'] as $returnedData) {
                if ($this->isInitialization) {
                    $updateRecordQuery = "INSERT INTO pull_report(unit_id, unit_name, active_status, latitude, longitude, distance, fuel_report, engine_hours, created_at) 
                            VALUES('".$returnedData['0']."','".$returnedData['1']."','".$returnedData['2']."','".$returnedData['3']."'
                            ,'".$returnedData['4']."','".$returnedData['5']."','".$returnedData['6']."','".$returnedData['7']."', NOW())";

                    $saveIDQuery = "INSERT INTO id_mapping(id_galooli, plate_number) 
                            VALUES('".$returnedData['0']."','".$returnedData['1']."')";
                    if (Database::updateOrInsert($saveIDQuery)) {
                        echo "IDs inserted into query  ";
                    } else {
                        echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
                    }
                    $this->saveToFleetioTable($returnedData);
               
                } else {
                    $updateRecordQuery = "UPDATE pull_report SET active_status='".$returnedData['2']."', latitude = '".$returnedData['3']."', 
                        longitude = '".$returnedData['4']."', distance = '".$returnedData['5']."', 
                        fuel_report = '".$returnedData['6']."', modified_at = '{$this->currentDateTime}' where unit_id = '".$returnedData['0']."'";
                }
                $pullUpdated = 0;
                if (Database::updateOrInsert($updateRecordQuery)) {
                    $pullUpdated++;
                } else {
                    echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
                }
            }
            if ($pullUpdated > 0) {
                echo "<p style='color: green'>Data From Galooli Saved to Databse</p><br/>";
            } else {
                $this->logError("Error Saving Galooli Data To Database");
            }
            if ($this->isInitialization) 
                $this->pullDataFromFleetio();
            else {
                $this->checkforOdometerChange($this->currentDateTime);
            }
                
        } else {
            echo "Data returned is Null<br/><br/>";
            $this->logError("Error Fetching Data From Galooli Servers, Network or Server Error");
            $this->currentDateTime = date("Y-m-d H:i:s");
            $this->updateErrorData('pull_error_time', $this->currentDateTime);
            $this->sendErrorNotificationMail();
        }
        return $this->returnedData;
    }

    function sendErrorNotificationMail()
    {
        $to = "dokafor@matrixvtrack.com.ng";
        $subject = "Error Encountered While Fetching Data From Galooli";
        
        $message = "<h3>A Network Error Occured during a get request from galooli server</h3>";
        $message .= "<b><a href='https://project.matrixvtrack.com/app'>Login</a> 
                        into the web interface to know the integration status
                    </b>";
        
        $header = "From: tech@ecagon.com \r\n";
        $header .= "Cc: cekpunobi@matrixvtrack.com.ng  \r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-type: text/html\r\n";
        
        $mailStatus = mail ($to, $subject, $message, $header);
        
        if( $mailStatus == true ) {
        echo "Message sent successfully...";
        }else {
        echo "Message could not be sent...";
        }
    }

    //NB: this is used for initialization
    function pullDataFromFleetio()
    {
        $this->apiURL = "https://secure.fleetio.com/api/v1/vehicles";
        $get_data = $this->_apiService->callAPI('GET', $this->apiURL, false, 'fleetio');
        $this->returnedData = json_decode($get_data, true);
        foreach($this->returnedData as $returnedData) {
            echo $returnedData['id']. ' ' . $returnedData['name'];
            $this->mapCorrespondingIds($returnedData['id'], $returnedData['name']);
        }
        $this->checkforChangeWithinLastHour();
    }

    function updateErrorData($name, $value)
    {
        if ($value == NULL) {
            $query = "UPDATE configuration SET value = '".$value."'  where name = '".$name."'";
        } else {
            $query = "UPDATE configuration SET value = value + 1  where name = '".$name."'";
        }

        if (Database::updateOrInsert($query)) {
            echo "Log Record updated successfully<br/><br/>";
        } else {
            echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
        }
    }

    //NB: this is used for initialization
    function mapCorrespondingIds($vehicle_id, $vehicle_name)
    {
        $mapIDQuery = "UPDATE id_mapping SET id_fleetio='{$vehicle_id}' where plate_number = '{$vehicle_name}'";
        if (Database::updateOrInsert($mapIDQuery)) {
            echo "Id Mapped<br/><br/>";
        } else {
            echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
        }
    }

    /*
    first pull data from galooli every ten minutes, since maxGMTupdatetime doesn't show up, store last pulled data
    currenttime as lastGMTupdate time, and use this data for next pull request

    For each data pulled compare it with last data sent to fleetio, if any vehicles odometer has changed over 200 km
    update the vehicle data on fleetio

    */
    function checkforOdometerChange($currentModifiedDateTime) {
        $query = "SELECT * from push_report";
        $fleetioTableRows = Database::selectFromTable($query);
        $query = "SELECT * from pull_report where modified_at = '{$currentModifiedDateTime}'";
        $galooliTableRows = Database::selectFromTable($query);

        //Check if distance/odometer reading since last push is above threshold value
        if($galooliTableRows && $fleetioTableRows) {
            $query = "SELECT value from configuration where name = 'difference_in_odometer'";
            $tableRow = Database::getSingleRow($query);
            $odometerDifference = $tableRow["value"];
            $query = "SELECT value from configuration where name = 'difference_in_fuel'";
            $tableRow = Database::getSingleRow($query);
            $fuelDifference = $tableRow["value"];
            for($i = 0; $i < count($galooliTableRows);  $i++) {
                $distanceTest = $galooliTableRows[$i]['distance'] - $fleetioTableRows[$i]['distance'];
                $fuelTest = $galooliTableRows[$i]['fuel_report'] - $fleetioTableRows[$i]['fuel_report'];
                // if($galooliTableRows[$i]['fuel_report'] == 0)  {
                //     echo "Error in Galooli Data: fuel report is Zero";
                //     continue; 
                // }
                echo "<br><br>Status: ".$galooliTableRows[$i]['unit_name'];
                echo "<br>Status: ".$galooliTableRows[$i]['active_status'];
                echo "<br>Difference in odometer: ".$distanceTest;
                if($distanceTest >= $odometerDifference &&
                    strcasecmp($galooliTableRows[$i]['active_status'], "Off") == 0)  {
                    echo "<br>Condition Met<br>";
                    //save to fleetio table
                    $this->saveToFleetioTable($galooliTableRows[$i]);
                    $this->processDataBeforePush($galooliTableRows[$i]);
                } 
            }
            if ($this->fleetioUpdate) {
                $this->currentDateTime = date("Y-m-d H:i:s");
                $query = "UPDATE configuration SET value='".$this->currentDateTime."' where name = 'last_fleetio_push_time'";
                if (Database::updateOrInsert($query)) {
                    echo "Last Update time for fleetio Saved successfully<br/><br/>";
                } else {
                    echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
                }
                $this->fleetioUpdate = false;
            } else {
                $this->logError("Error Saving New Data To Fleetio Table, No Data Available to be Pushed");
                echo "<br/><p style='color: red'>No Data To Update to Fleetio Found </p><br/>";
            }
        } else {
            $this->logError("Galooli Data Table or Fleetio Data Table is Empty, or Could not be fetched");
        }
    }  
    

    // CRON JOB: this function should run every one hour, and can be changed from user interface
    // to be anything of 30 mins interval
    function checkforChangeWithinLastHour() {
        //NB: if decided that only changed rows should be pushed then use push_report table
        $query = "SELECT * from pull_report where modified_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) OR 
                    created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        $galooliTableRows = Database::selectFromTable($query);
        if($galooliTableRows) {
            echo "<br/><br/><p style='color: green'>Change has occured within last hour </p><br/>";
            foreach($galooliTableRows as $galooliRow) {
                //save to fleetio table
                $this->saveToFleetioTable($galooliRow);
                $this->processDataBeforePush($galooliRow);
            }
            if ($this->fleetioUpdate || $this->pushUpdated > 0) {
                $this->currentDateTime = date("Y-m-d H:i:s");
                $query = "UPDATE configuration SET value='".$this->currentDateTime."' where name = 'last_fleetio_push_time'";
                if (Database::updateOrInsert($query)) {
                    echo "LastGMTupdate time for fleetio updated successfully<br/><br/>";
                } else {
                    echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
                }
                $this->fleetioUpdate = false;
                echo "Fleetio Table Data updated successfully<br/><br/>"; // this can be like logged
            }  else {
                $this->logError("Error Saving New Data To Fleetio Table");
                echo "<br/><p style='color: red'>No Change In Data to Update</p><br/>";
            }
        } else {
            echo "<br/><br/><p style='color: red'>No Change has occured within last hour </p><br/>";
        }
    } 

    function saveToFleetioTable($galooliRow) {
        if ($this->isInitialization) {
            $updateRecordQuery = "INSERT INTO push_report(unit_id, unit_name, active_status, latitude, longitude, distance, fuel_report, engine_hours, created_at) 
                    VALUES('".$galooliRow['0']."','".$galooliRow['1']."','".$galooliRow['2']."','".$galooliRow['3']."'
                    ,'".$galooliRow['4']."','".$galooliRow['5']."','".$galooliRow['6']."','".$galooliRow['7']."', NOW())";
       
        } else {
            $updateRecordQuery = "UPDATE push_report SET active_status='".$galooliRow['active_status']."', latitude = '".$galooliRow['latitude']."', 
                longitude = '".$galooliRow['longitude']."', distance = '".$galooliRow['distance']."', 
                engine_hours = '".$galooliRow['engine_hours']."', fuel_report = '".$galooliRow['fuel_report']."', modified_at = NOW() where unit_id = '".$galooliRow['unit_id']."'";
        }
        $this->pushUpdated = 0;
        if (Database::updateOrInsert($updateRecordQuery)) {
            $this->pushUpdated++;
        } else {
            echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
        }
    }

    function processDataBeforePush($dataToPush) {
        $query = "SELECT id_fleetio from id_mapping where id_galooli = '".$dataToPush['unit_id']."'";
        $tableRow = Database::getSingleRow($query);
        $fleetioID = $tableRow["id_fleetio"];
        $this->pushDataToFeetio($dataToPush, $fleetioID);
    } 

    function pushDataToFeetio($data_array, $fleetioID)
    {
        /*
        TODO: push latitude and longitude to /location_entries
            push odometer and engine hours to /meter_entries
            push fuel_report to /fuel_entries
        */
        if ($fleetioID != 0) {
            $this->fleetioUpdate = true;
            $this->currentDateTime = date("Y-m-d");
            //PUSH Odometer
            $post_data_array = array('vehicle_id' => $fleetioID,
                                'date' => $this->currentDateTime,
                                'value' => $data_array['distance']);
            $jsonDataArray = json_encode($post_data_array);
            $this->apiURL = "https://secure.fleetio.com/api/v1/meter_entries";
            $return_data = $this->_apiService->callAPI('POST', $this->apiURL, $jsonDataArray, 'fleetio');
            $response = json_decode($return_data, true);
            if($return_data) {
                echo 'Odometer Data for '.$data_array['unit_name'].' updated successfully<br/><br/>';
            }

            //PUSH Engine hours
            // $post_data_array = array('vehicle_id' => $fleetioID,
            //                     'date' => $this->currentDateTime,
            //                     'meter_type' => "secondary",
            //                     'value' => $data_array['engine_hours']);
            // $jsonDataArray = json_encode($post_data_array);
            // echo "<br><br>Encoded Json for engine hours: ";
            // var_dump($jsonDataArray);
            // $this->apiURL = "https://secure.fleetio.com/api/v1/meter_entries";
            // $return_data = $this->_apiService->callAPI('POST', $this->apiURL, $jsonDataArray, 'fleetio');
            // $response = json_decode($return_data, true);
            // echo "<br><br>Response From meter entries: ";
            // var_dump($response);
            // echo '<br>';

            //PUSH LOCATION DATA
            $post_data_array = array('vehicle_id' => $fleetioID,
                                'contact_id' => "",
                                'date' => $this->currentDateTime,
                                'latitude' => $data_array['latitude'],
                                'longitude' => $data_array['longitude']);
            $jsonDataArray = json_encode($post_data_array);
            $this->apiURL = "https://secure.fleetio.com/api/v1/location_entries";
            $return_data = $this->_apiService->callAPI('POST', $this->apiURL, $jsonDataArray, 'fleetio');
            $response = json_decode($return_data, true);

            if ($return_data == NULL) {
                $this->updateErrorData('push_error_time', $this->currentDateTime);
            } else {
                echo 'Location Data for '.$data_array['unit_name'].' updated successfully<br/><br/>';
                $this->updateErrorData('push_error_time', 0);
            }

        }
        

        //PUSH FUEL ENTRIES DATA
        // $latitude = $data_array['latitude'];
        // $longitude = $data_array['longitude'];
        // $jsonDataArray = '{"vehicle_id": {$fleetioID}, "contact_id": "","meter_type": "",
        //                     "date": {$this->currentDateTime}, "latitude": {$latitude}, "longitude": {$longitude}}';
        // $this->apiURL = "https://secure.fleetio.com/api/v1/meter_entries";
        // $return_data = $this->_apiService->callAPI('POST', $this->apiURL, $jsonDataArray, 'fleetio');
        // $response = json_decode($return_data, true);
        // var_dump($response);
    }

    function logError($errorData)
    {
        $updateErrorLog = "INSERT INTO error_log(message) VALUES('{$errorData}')";
        if (Database::updateOrInsert($updateErrorLog)) {
            echo "<br>Error Log Updated<br><br>";
        } else {
            echo "Error updating record: " . mysqli_error($GLOBALS['db_server'])."<br/>";
        }
    }
}
