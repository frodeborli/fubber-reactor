#!/usr/bin/php
<?php
// Forks off to run the server

echo "FubberServer startup:\n";
$inotifyPid = spawn_inotify();
echo "- File monitor started\n";
$serverPid = spawn_server();
echo "- Server started\n";

while(TRUE) {
	usleep(100000);
	$result = pcntl_waitpid($serverPid, $status, WNOHANG);
	if($result == -1 || $result > 0) {
		echo "Restarting server (PID=$result)\n";
		$serverPid = spawn_server();
	}
	$result = pcntl_waitpid($inotifyPid, $status, WNOHANG);
	if($result == -1 || $result > 0) {
		echo "Restarting directory watch (PID=$result)\n";
		$inotifyPid = spawn_inotify();
		echo "Killing server (PID=$serverPid)\n";
		posix_kill($serverPid, SIGABRT);
	}
}

function spawn_inotify() {
	$inotifyPid = pcntl_fork();
	if(!$inotifyPid) {
		// Start inotify
		shell_exec("/usr/bin/inotifywait -qq -r -e 'modify,move,create,delete' ./");
		die();
	}
	return $inotifyPid;
}

function spawn_server() {
	$serverPid = pcntl_fork();
	if(!$serverPid) {
		require('server.php');
		die();
	}

	return $serverPid;
}
