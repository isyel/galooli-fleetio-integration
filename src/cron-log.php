<?php 
require_once 'utils.php';
require_login();

if(DEBUG_MODE){

	$file = '/var/log/galooli-fleetio.log';
clearstatcache();
	if (file_exists($file)) {
		if( isset($_GET['reset']) ){
			exec( ' > '.$file );
			echo '<h4>Reset successful</h4>';
		}
		else{
			readfile($file);
		}
	}
}
else echo '<h3>Not enabled for now</h3>';