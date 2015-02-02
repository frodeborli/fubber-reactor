<?php
require 'vendor/autoload.php';

$config = @parse_ini_file('config.ini', TRUE, INI_SCANNER_RAW);

if(!$config) die("Error: No config.ini found!\n");

spl_autoload_register(function($className) {
	$path = str_replace("\\", "/", $className);
	if(file_exists(__DIR__.'/classes/'.$path.'.class.php'))
		include(__DIR__.'/classes/'.$path.'.class.php');
	else if(file_exists(__DIR__.'/framework/'.$path.'.class.php'))
		include(__DIR__.'/framework/'.$path.'.class.php');
});

new \Fubber\Server\Host($config);
