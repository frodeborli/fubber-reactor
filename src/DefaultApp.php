<?php
namespace Fubber\Reactor;
/**
*	This file is not suitable as a reference file. It overrides core functionality in the listen()-method. Please consult the documentation at 
*	https://github.com/frodeborli/fubber-reactor/wiki for help setting up your first Fubber Reactor application properly.
*/
class DefaultApp extends App {

	public function listen($request, $response) {
		$response->writeHead(200, array('Content-Type' => 'text/html; charset=utf-8'));
		$response->end('<!DOCTYPE html><html><head><title>Fubber Reactor</title></head><body><p>Fubber Reactor running the default app. Consult the wiki at <a href="https://github.com/frodeborli/fubber-reactor/wiki">https://github.com/frodeborli/fubber-reactor/wiki</a> for help.</p></body></html>');
	}

}
