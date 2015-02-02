<?php
namespace Fubber\Server;

class ForkingController extends Controller {
	public function listen($request, $response) {
		// Create a stream pair which the child will use to send data back to parent
		$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$serverStream = new \React\Stream\Stream($sockets[1], Host::$instance->getLoop());

		$pid = Host::$instance->fork(30); //pcntl_fork();
		if(!$pid) {
			fclose($sockets[1]);
			// I'm the child, the server should stop listening!
			Host::$instance->getHttp()->removeAllListeners('listen');
			Host::$instance->getLoop()->removeStream(Host::$instance->getSocket()->master);
			Host::$instance->getSocket()->removeAllListeners();
			Host::$instance->getLoop()->stop(); // No event driven programming from this point onwards

			// Set up a new loop and make sure stuff works on this end
			$loop = \React\EventLoop\Factory::create();
			$clientStream = new \React\Socket\Connection($sockets[0], $loop);
			$forkedResponse = new \React\Http\Response($clientStream);

			// Replace the host loop, to trick any code running here to believing that the host loop is actually working.
			Host::$instance->setLoop($loop);

			// Actually run the controller code
			parent::listen($request, $forkedResponse);

			// Just in case we're using event driven programming inside the request
			$loop->run();
			die();
		} else {

			// Trick the response to believe the headers have been sent. We'll get the headers from the fork anyway.
			$response->fubberForkHack();

			$serverStream->on('data', function($data) use($response) {
				$response->write($data);
			});
			$serverStream->on('close', function() use ($response) {
				$response->end();
			});
		}
	}
}
