<?php
require 'vendor/autoload.php';

spl_autoload_register(function($className) {
	$path = str_replace("\\", "/", $className);
	if(file_exists(__DIR__.'/classes/'.$path.'.class.php'))
		include(__DIR__.'/classes/'.$path.'.class.php');
	else if(file_exists(__DIR__.'/framework/'.$path.'.class.php'))
		include(__DIR__.'/framework/'.$path.'.class.php');
});

new \Fubber\Server\Host($config);
