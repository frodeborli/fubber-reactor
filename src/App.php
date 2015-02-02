<?php
namespace Fubber\Reactor;

abstract class App {
	public $hits = 0;


	protected $lookupCache = array();

	protected $routes = NULL;
	protected $weightedRoutes = array();
	protected $rawRoutes = array();

	protected $endpoints = array();

	public function listen($request, $response) {

		if($this->routes === NULL)
			$this->buildRoutes();

		$this->hits++;

		$path = '/'.ltrim($request->getPath(), '/');

		$endpoint = NULL;

		if(isset($this->routes[$path])) {
			// Direct lookup
			$endpoint = $this->routes[$path];
		} else {
			if(isset($this->lookupCache[$path])) {
				$endpoint = $this->lookupCache[$path];
			} else {
				foreach($this->weightedRoutes as $weight => $routes) {
					foreach($routes as $pattern => $endpoint) {
						if(fnmatch($pattern, $path))
							break 2;
					}
				}

				if($endpoint) {
					$this->lookupCache[$path] = $endpoint;
					while(sizeof($this->lookupCache)>1000)
						array_shift($this->lookupCache);
				}
			}
		}

		if($endpoint) {
			// We identified an endpoint
			return $endpoint->listen($request, $response);
		} else {
			// We failed to find an endpoint, so we redirect to /errors/404
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

	public function buildRoutes() {
		$this->routes = array();
		$this->weightedRoutes = array();

		$this->rawRoutes = array();
		$this->scanRoutes();

		// Direct match routes added here, for fastest possible lookup
		$this->routes = $this->rawRoutes[0];

		// Weighted routes added here, for semi fast lookup using fnmatch()
		$index = 0;
		for($i = 1; $i < 200; $i++) {
			if(isset($this->rawRoutes[$i])) {
				$this->routes[$index] = array();
				foreach($this->rawRoutes[$i] as $route => $endpoint) {
					$this->routes[$index][$route] = $endpoint;
				}
			}
		}
	}

	public function scanRoutes($root = NULL) {
		if($root===NULL)
			$root = Host::$config['app']['root'];

		$all = glob($root.'/*'); /* */
		foreach($all as $path) {
			if(!is_dir($path)) {
				// This is an endpoint
				$this->scanRouteAdd($path);
			} else {
				$this->scanRoutes($path);
			}
		}
	}

	public function scanRouteAdd($path) {
		$path = realpath($path);
		$root = realpath(Host::$config['app']['root']);

		if(strpos($path, $root)!==0) {
			Host::$instance->logError("Routes: Not adding $path. Must reside inside $root.");
			return FALSE;
		}

		$pi = pathinfo($path);
		$pi['rel_dirname'] = substr($pi['dirname'], strlen($root));
//		print_r($pi);

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
				case 'ini' :
					$endpoint = $this->createEndpointFromIniFile($path);
					break;
				case 'php' :
					$endpoint = $this->createEndpointFromPhpFile($path);
					break;
				default :
					Host::$instance->logError("Routes: Not adding $path. Unknown extension ".$pi['extension']);
					break;
			}
		}

		if($endpoint) {
			$weight = substr_count($pattern, '/*/'); /* */
			if(rtrim($pattern, '*')!=$pattern) $weight += 100;
			if(!isset($this->rawRoutes[$weight]))
				$this->rawRoutes[$weight] = array();
			$this->rawRoutes[$weight][$pattern] = $endpoint;
		}
	}

	public function createEndpointFromPhpFile($path) {
		return TRUE;
	}

	public function createEndpointFromIniFile($path) {
		if(strpos(realpath($path), realpath(Host::$config['app']['root']))!==0)
			return NULL;
		$ini = parse_ini_file($path, TRUE, INI_SCANNER_RAW);

		if(!isset($ini['general']) || !isset($ini['general']['class'])) {
			Host::$instance->logError('Parsing "'.$path.'": In section [general], class not declared.');
			return NULL;
		}

		$className = $ini['general']['class'];

		if(!class_exists($className)) {
			Host::$instance->logError('Parsing "'.$path.'": In section [general], class "'.$className.'" not found.');
			return NULL;
		}

		if(!is_subclass_of($className, '\Fubber\Reactor\Controller')) {
			Host::$instance->logError('Parsing "'.$path.'": In section [general], class "'.$className.'" does not extend the class Controller.');
			return NULL;
		}

		$instance = new $className($ini);
		return $instance;
	}
}
