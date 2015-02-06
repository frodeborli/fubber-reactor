<?php
namespace Fubber\Reactor;

class Controller implements EndpointInterface {
	protected $config;

	public function __construct($config=NULL) {
        if(!$config) $this->config = new \stdClass();
		else $this->config = $config;
	}

	public function listen($request, $response) {
		$methodName = strtolower($request->getMethod());

		if(method_exists($this, $methodName)) {
			return $this->$methodName($request, $response);
		} else {
			return Host::$instance->respondError(405, $request, $response);
		}
	}
}
