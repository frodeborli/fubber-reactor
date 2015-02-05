<?php
class HitsController extends \Fubber\Reactor\Controller {

	public $hits = 0;

	public function get($request, $response) {
		$this->hits++;
		$response->writeHead(200, array('Content-Type' => 'text/plain'));
		$response->end($this->hits);
	}

}
