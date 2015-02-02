<?php
namespace Fubber\Reactor;

/**
*	Master Class, responsible for handling the socket and all requests to it.
*
*	It will find the appropriate Controller to run according to ini files in the root folder.
*/
class Host {

	public static $isForked = FALSE;

	public static $instance;

	protected $endpoints = array();

	public static $app;
	public static $config;

	protected $loop;
	protected $socket;
	protected $http;

	protected static $forkMonitors = array();
	protected static $forkId = 0;

	public function fork($ttl = 10) {
		$pid = pcntl_fork();
		if($pid == -1) {
			// Could not fork
		} else if($pid) {
			// We are the parent, and we should start monitoring this child
			$forkId = self::$forkId;
			self::$forkMonitors[self::$forkId++] = array(
				'startTime' => microtime(TRUE),
				'killTime' => microtime(TRUE) + $ttl,
				'timer' => $this->loop->addPeriodicTimer(1, function() use ($pid, $forkId) {
					$result = pcntl_waitpid($pid, $status, WNOHANG);
					if($result == -1) {
						// The child has exited, so stop watching out for it!
						Host::$forkMonitors[$forkId]['timer']->cancel();
						unset(Host::$forkMonitors[$forkId]);
					} else if(Host::$forkMonitors[$forkId]['killTime'] <= microtime(TRUE)) {
						posix_kill($pid, SIGHUP);
					}
				})
			);
		} else {
			// We are the fork
		}

		return $pid;
	}

	public function __construct($config = NULL) {

		if(!$config) $config = new \stdClass();

		self::$instance = $this;
		self::$config = $config;

		$this->initializeConfig();

		$this->loop = \React\EventLoop\Factory::create();
		$this->socket = new \React\Socket\Server($this->loop);
		$this->http = new \React\Http\Server($this->socket);

		$this->socket->listen(self::$config->http->port, self::$config->http->host);
		$this->logNotice("Listening to ".self::$config->http->host.":".self::$config->http->port);


		// Note: Forking here seems to work, except we get a few warnings from time to time... Probably because the child also tries to fetch at the same time.

		$appClass = self::$config->app->class;

		if(!class_exists($appClass)) {
			$appClass = '\Fubber\Reactor\DefaultApp';
		}

		self::$app = new $appClass();
		$this->http->on('request', array(self::$app, 'listen'));


		echo "Starting Host Loop\n";
		$this->loop->run();
		echo "Host Loop Done\n";

	}

	public function setLoop($loop) {
		$this->loop = $loop;
	}

	public function getLoop() {
		return $this->loop;
	}

	public function getSocket() {
		return $this->socket;
	}

	public function getHttp() {
		return $this->http;
	}

	public function logError($message) {
		echo "ERROR: ".$message."\n";
	}

	public function logNotice($notice) {
		echo "NOTICE: ".$notice."\n";
	}

	public function initializeConfig() {
		if(!isset(self::$config->app)) self::$config->app = new \stdClass();
		if(!isset(self::$config->app->routes)) self::$config->app->routes = './routes';
		if(!isset(self::$config->app->class)) self::$config->app->class = '\Fubber\Reactor\App';

		if(!isset(self::$config->http)) self::$config->http = new \stdClass();
		if(!isset(self::$config->http->host)) self::$config->http->host = 'localhost';
		if(!isset(self::$config->http->port)) self::$config->http->port = 1337;
	}
}
