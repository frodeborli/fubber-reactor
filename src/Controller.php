<?php
namespace Fubber\Reactor;

class Controller {
	protected $config;

	public function __construct($config) {
		$this->config = $config;
	}

	public function listen($request, $response) {
		$methodName = strtolower($request->getMethod());

		if(method_exists($this, $methodName)) {
			return $this->$methodName($request, $response);
		} else {
			return Host::$app->respondError(405, $request, $response);
		}
	}
}
