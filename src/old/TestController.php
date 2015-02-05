<?php
namespace Fubber\Reactor;

class TestController extends Controller {
	public function get($request, $response) {
		$response->writeHead(200, array('Content-Type' => 'text/plain'));
		$response->end('TestController::get($request, $response) responded successfully to this request.');
	}
	public function post($request, $response) {
		$response->writeHead(200, array('Content-Type' => 'text/plain'));
		$response->end('TestController::post($request, $response) responded successfully to this request.');
	}
	public function delete($request, $response) {
		$response->writeHead(200, array('Content-Type' => 'text/plain'));
		$response->end('TestController::delete($request, $response) responded successfully to this request.');
	}
	public function put($request, $response) {
		$response->writeHead(200, array('Content-Type' => 'text/plain'));
		$response->end('TestController::put($request, $response) responded successfully to this request.');
	}
}
