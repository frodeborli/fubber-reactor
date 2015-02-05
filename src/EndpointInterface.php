<?php
namespace Fubber\Reactor;

interface EndpointInterface {
	public function listen($request, $response);
}
