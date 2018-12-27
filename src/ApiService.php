
<?php

class ApiService {
    public $returnedData;
    public $apiToken;
    private $accountToken;
    private $contactId;

    function  __construct() {
      $this->apiToken = '';
      $this->accountToken = '';
      $this->contactId = '';
    }

    //If any API calls fails, log it, and show on the user interface for every 5 retrials

    function callAPI($method, $url, $data, $appType){
       try {
         $curl = curl_init();
      
         switch ($method){
            case "POST":
               curl_setopt($curl, CURLOPT_POST, 1);
               if ($data)
                  curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
               break;
            case "PUT":
               curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
               if ($data)
                  curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
               break;
            default:
               if ($data)
                  $url = sprintf("%s?%s", $url, http_build_query($data));
         }
         
         if($appType == 'fleetio') {
            $headers = [
               'Authorization: Token 855b72c85c06431a388dbda45155651ecc2f7d0e',
               'Account-Token: 1cfafff6e0',
               'Accept: */*',
               'Accept-Encoding: gzip, deflate',
               'content-type: application/json'
            ];
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
         }
         curl_setopt($curl, CURLOPT_URL, $url);
         curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
         if(!IN_SERVER && ($appType != 'fleetio')) {
            $proxy = '159.65.88.174:12455';
            curl_setopt($curl, CURLOPT_PROXY, $proxy); // $proxy is ip of proxy server
         }
         curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
         curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
         curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
         curl_setopt($curl, CURLOPT_TIMEOUT, 10);
         $this->returnedData = curl_exec($curl);
         if ($this->returnedData === false) 
            $this->returnedData = curl_error($curl);
      } catch(Exception $exception) {
         echo "Exception Occured: ".$exception."<br/>";
      }

      if(!$this->returnedData) {
            die("Connection Failure");
      }
      curl_close($curl);
      return $this->returnedData;
   }
}