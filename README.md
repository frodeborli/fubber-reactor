# Fubber Reactor

Fubber Reactor is an event driven and forking PHP application server based on the excellent [ReactPHP](www.reactphp.org) event driven PHP framework. 
Fubber Reactor is an invitation to contribute in developing an awesome PHP application server that combines the best of PHP with the best of node.js. 

## Event Driven Controller and Forking Controller (beans)

With Fubber Reactor you'll configure URL-paths that map to instances of controller classes (beans). There are currently two types of controller classes:

### Controller

The ordinary controller is used for page requests that should be run and handled within the standard ReactPHP event loop. The code running inside this
controller should be [non-blocking](en.wikipedia.org/wiki/Asynchronous_I/O). This allows you to respond very quickly to page requests, and you'll also
be able to maintain a client connection for a very long time, without incurring too much system overhead while doing so. Simply store a reference to
the Response object in memory and whenever you want to write to it, just do so.

### Forking Controller

The forking controller is special, in that it actually spawns off a fork of the main process. This allows you to perform non-blocking algorithms and
requests, in a very similar manner to traditional PHP development. An average server can handle a few hundred running forking controllers, but the
server will only allow a fork to run for up to 30 seconds. After 30 seconds, the server will dispatch a SIGHUP signal telling the controller to shut 
down. This will eventually be configurable.

## Routing

Fubber Reactor has a special file based routing scheme. Every route is set up by creating an INI-file in the pages/ folder. The INI-files contains
information about which class should be handling the request. In the current version, Fubber Reactor will instansiate an instance of the class for
every URL you configure. Every request will then be sent to the Controller::listen($request, $response) method. That method will in turn call
Controller::get($request, $response) for GET requests, Controller::post($request, $response) for POST requests and so on.

To create a route for a URL such as /users/12345/ you'll simply create an INI-file /pages/users/_/index.ini. This will cause an instance of your
controller to get every request that maps to /pages/users/*/ according to the fnmatch() function.

### Routing Algorithm:

1. Exact matches are checked first. Exact matches will therefore be slightly faster. This means that if you create a file /pages/users/123/index.ini,
   this will be checked first.
2. If no exact match is found, the router will scan the routing tables in order of number of wildcards in the route. A route with only one wildcard
   is checked before any route with two wildcards, and so on.
3. If no routing matches are found, the request is rewritten to go to /errors/404. You should create a controller ini file at /pages/errors/404.ini
   to customize that error page. If no controller exists there, you will get a text based error page.

## TODO

Fubber Reactor is a work in progress. I have a working and scalable publisher/subscriber server that will serve as an example application, and you
can use that for sending push requests to your audience - for example to create a Twitter like experience, or something like a chat room.

These features are on my short-list:

* Implement [Spring Framework style scopes](http://www.tutorialspoint.com/spring/spring_bean_scopes.htm). Currently only the Singleton scope is
  supported, although the Forking Controller resembles the Prototype scope due to forking.
* Create a Message Bus for the controllers to interact. This Message Bus must enable forking controllers to submit messages back to the application
  server and vice versa. Look into allowing this Message Bus to be cluster wide, for example by utilizing either the publisher/subscriber server
  written in Fubber Reactor itself, or by using an enterprise scale message queue.
* Create an Apache Simulator Controller, allowing developers to create ordinary PHP pages with access to the standard global objects such as
  $_COOKIE, $_SESSION, $_GET, $_POST etc. Should also handle file uploads. This will have to use the Forking Controller.
* Make a plugin architecture, for extending the functionality of Fubber Reactor. A suitable feature to add with this plugin architecture is
  a session handler. This plugin should be able to create Session Controller type.
* Create a special Cached Content Controller. The purpose of this controller is to enable pre-generated content to be served up.


