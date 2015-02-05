<?php
namespace Fubber\Reactor;

/*
to do

Make Application concept. Ideas:

1. Applications can access the event loop and the server socket, and add themselves to $socket->listen();
2. Move routing logic out to a "RoutingApplication", that can run alongside other applications.
3. Make a separate "ForkingApplication" that also runs alongside.
4. Make IPC logic for applications.
5. Make sure we use PSR http messages, instead of ReactPHP messages.
*/

/**
*	Master Class, responsible for handling the socket and all requests to it.
*
*	It will find the appropriate Controller to run according to ini files in the root folder.
*/
class Host implements EndpointInterface {
	use \Psr\Log\LoggerTrait;

	public $hits = 0;
	public static $instance;
	public $config;

	// Caches path lookups
	protected $lookupCache = array();
	// Count the number of items in the lookupCache (to avoid slow sizeof()-calls)
	protected $lookupCacheCount = 0;

	// Direct routes (no wildcards)
	protected $routes = NULL;
	// Routes sorted by the number of wildcards
	protected $searchRoutes = array();
	// All routes
	protected $rawRoutes = array();

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
		self::$instance = $this;

		if(!$config) $config = new \stdClass();
		$this->config = $config;
		$this->setConfigDefaults();

		$this->loop = \React\EventLoop\Factory::create();
		$this->socket = new \React\Socket\Server($this->loop);
		$this->http = new \React\Http\Server($this->socket);

		$this->socket->listen($this->config->http->port, $this->config->http->host);
		$this->notice("Listening to ".$this->config->http->host.":".$this->config->http->port);

		$this->buildRoutingTable();

		$this->http->on('request', array($this, 'listen'));
		$this->info("Starting Host Loop");
		$this->loop->run();
		$this->info("Host Loop Ended");

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

	protected function setConfigDefaults() {
		if(!isset($this->config->http)) $this->config->http = new \stdClass();
		if(!isset($this->config->http->host)) $this->config->http->host = 'localhost';
		if(!isset($this->config->http->port)) $this->config->http->port = 1337;
	}

	/**
	*	Handle an incoming request, map it to the correct endpoint using routes and call the listen method on the endpoint
	*/
	public function listen($request, $response) {
		$this->hits++;

		$path = '/'.ltrim($request->getPath(), '/');

		$endpoint = NULL;

		if(isset($this->routes[$path])) {
			// Direct lookup
			$endpoint = $this->routes[$path];
		} else {
			$endpointKey = NULL;

			if(isset($this->lookupCache[$path])) {
				$endpointKey = $this->lookupCache[$path];
				// The item was found in the lookupCache, so we move it back to the end of the array (to avoid unshifting it too soon) - LRU algorithm
				if($this->lookupCacheCount == 1000) {
					unset($this->lookupCache[$path]);
					$this->lookupCache[$path] = $endpointKey;
				}
				$endpoint = $this->searchRoutes[$endpointKey];
			} else {
				foreach($this->searchRoutes as $key => $route) {
					if(fnmatch($route[0], $path)) {
						$endpointKey = $key;
						break;
					}
				}

				if($endpointKey !== NULL) {
					// LRU algorithm
					$this->lookupCache[$path] = $endpointKey; // Added to the end of the array
					$this->lookupCacheCount++;
					while($this->lookupCacheCount-- > 1000)
						array_shift($this->lookupCache); // Remove something from the beginning of the array

					$endpoint = $this->searchRoutes[$endpointKey];
				}
			}
		}

		if($endpoint) {
			// We identified an endpoint
			return $endpoint->listen($request, $response);
		} else {
			// We failed to find an endpoint, so we redirect to /errors/404
			// This is not cached, because we don't want anybody to be able to fill the lookupCache with garbage
			return $this->respondError(404, $request, $response);
		}
	}

	public function respondError($httpCode, $request, $response) {
		if($request->getPath() == '/errors/'.$httpCode) {
			// Prevent loop, if the error handler does not exist.
			$response->writeHead(404, array('Content-Type' => 'text/plain'));
			$response->end('Page not found, and an error handler was not found either');
			return;
		}
		$headers = $request->getHeaders();
		$headers['X-Original-Path'] = $request->getPath();
		return $this->listen(new \React\Http\Request($request->getMethod(), '/errors/'.$httpCode, $request->getQuery(), $request->getHttpVersion(), $headers), $response);
	}

	public function buildRoutingTable() {
		$this->routes = array();
		$this->searchRoutes = array();

		$this->rawRoutes = array();
		if(isset($this->config->routes)) {
			$routesRoot = $this->config->routes;
		} else {
			$routesRoot = __DIR__;
			while(!is_dir($routesRoot.'/routes') && (($routesRoot = dirname($routesRoot)) != $routesRoot));
			if(is_dir($routesRoot.'/routes')) {
				$routesRoot .= '/routes';
			} else {
				$this->notice("Unable to find a folder named routes/. Won't scan for routing files.");
				return;
			}
		}

		$routes = $this->scanRoutes($routesRoot);

		usort($routes, function($a,$b) {
			$ac = substr_count($a[0], '*');
			$bc = substr_count($b[0], '*');
			if($ac == $bc) return 0;
			return ($ac < $bc) ? -1 : 1;
		});

		foreach($routes as $route) {
			if(strpos($route[0], '*')===false) {
				$this->routes[$route[0]] = $route[1];
			} else {
				$this->searchRoutes[] = $route;
			}
		}
	}

	/**
	*	Recursively find all routes within path
	*/
	protected function scanRoutes($root, $realRoot=NULL) {
		if($realRoot === NULL) $realRoot = $root;
		$result = array();

		$all = glob($root.'/*'); /* */
		foreach($all as $path) {
			if(!is_dir($path)) {
				// This is an endpoint
				$res = $this->createRouteFromFile($realRoot, $path);
				if($res)
					$result[] = $res;
			} else {
				$res = $this->scanRoutes($path, $realRoot);
				foreach($res as $route)
					$result[] = $route;
			}
		}
		return $result;
	}

	protected function createRouteFromFile($root, $path) {
		$path = realpath($path);
		$root = realpath($root);

		if(strpos($path, $root)!==0) {
			$this->notice("Routes: Not adding $path. Must reside inside $root.");
			return FALSE;
		}

		$pi = pathinfo($path);
		$pi['rel_dirname'] = substr($pi['dirname'], strlen($root));

		$relPath = substr($path, strlen($root));

		if(!empty($pi['basename'])) {
			switch($pi['filename']) {
				case 'default' :
					$patternPath = $pi['rel_dirname'].'/*'; /* */
					break;
				case 'index' :
					$patternPath = $pi['rel_dirname'].'/'; /* */
					break;
				default :
					$patternPath = $pi['rel_dirname'].'/'.$pi['filename'];
					break;
			}
		}
		$pattern = str_replace("/_/", "/*/", $patternPath); /* */

		// What path should this file be exposed as?
		if(!empty($pi['extension'])) {
			switch($pi['extension']) {
				case 'json' :
					$endpoint = $this->createEndpointFromJsonFile($path);
					break;
				case 'ini' :
					$endpoint = $this->createEndpointFromIniFile($path);
					break;
				case 'php' :
					$endpoint = $this->createEndpointFromPhpFile($path);
					break;
				default :
					$this->notice("Routes: Not adding $path. Unknown extension ".$pi['extension']);
					break;
			}
		}

		if($endpoint) {
			return array($pattern, $endpoint);
		}
	}

	protected function createEndpointFromPhpFile($path) {
		return TRUE;
	}

	protected function createEndpointFromJsonFile($path) {
		$config = json_decode(file_get_contents($path));

		if(!$config) {
			$this->notice('Parsing "'.$path.'": Unable to parse the JSON file. Did you remember to use \\\\ instead of \\?');
			return NULL;
		}

		if(!isset($config->class)) {
			$this->notice('Parsing "'.$path.'": No "class" value.');
			return NULL;
		}

		$className = $config->class;

		if(!class_exists($className)) {
			$this->notice('Parsing "'.$path.'": Class "'.$className.'" not found. Did you add it to the autoloader in composer.json?');
			return NULL;
		}

		if(!is_subclass_of($className, '\Fubber\Reactor\Controller')) {
			$this->notice('Parsing "'.$path.'": Class "'.$className.'" does not extend \Fubber\Reactor\Controller.');
			return NULL;
		}

		return new $className($config);
	}

	public function createEndpointFromIniFile($path) {
		$ini = parse_ini_file($path, TRUE, INI_SCANNER_RAW);

		if(!isset($ini['general']) || !isset($ini['general']['class'])) {
			$this->notice('Parsing "'.$path.'": In section [general], class not declared.');
			return NULL;
		}

		$className = $ini['general']['class'];

		if(!class_exists($className)) {
			$this->notice('Parsing "'.$path.'": In section [general], class "'.$className.'" not found.');
			return NULL;
		}

		if(!is_subclass_of($className, '\Fubber\Reactor\Controller')) {
			$this->notice('Parsing "'.$path.'": In section [general], class "'.$className.'" does not extend the class Controller.');
			return NULL;
		}

		$instance = new $className($ini);
		return $instance;
	}

	public function log($level, $message, array $context = array()) {
		echo "LOG ($level): $message\n";
	}
}
