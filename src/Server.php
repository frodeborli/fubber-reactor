<?php
namespace Fubber\Reactor;

class Server {

    protected $host;
    public $config;

    public function __construct($host, $config) {
        $this->config = $config;
    }

}
