<?php


require_once 'config.php';
require_once "ProcessData.php";

$processData = new ProcessData();

echo '<div style="margin:auto; width: 800px">';
echo "<a href='/galooli-fleetio-integration/'>Back To Home Page</a>";
$responseData = $processData->pullDataFromGalooli(false);
echo "</div>";

?>