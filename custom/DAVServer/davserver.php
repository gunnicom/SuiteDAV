<?php

if (!defined('sugarEntry'))
    define('sugarEntry', true);

// For includes we need to change Dir to Sugar root folder
chdir("../..");
require_once 'config.php';
require_once 'include/entryPoint.php';
require_once 'modules/Users/authentication/AuthenticationController.php';

// Requires maybe not possible if everything is correctly installed with composer
require_once 'custom/DAVServer/SugarCalDAVBackend.php';
require_once 'custom/DAVServer/SugarCardDAVBackend.php';
require_once 'custom/DAVServer/SugarDAVACLPrincipalBackend.php';

$authcontroller = AuthenticationController::getInstance();

//date_default_timezone_set('Canada/Eastern');

$baseUri = '/custom/DAVServer/davserver.php';
//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

//set_error_handler("exception_error_handler");
// Files we need
//require_once 'SabreDAV/vendor/autoload.php';
// Sugar Autoloader used
// Backends
$authBackend = new Sabre\DAV\Auth\Backend\BasicCallBack(Array($authcontroller, "login"));
$principalBackend = new Custom\DAVServer\SugarDAVACLPrincipalBackend($sugar_config);
$addressBookBackend = new Custom\DAVServer\SugarCardDAVBackend($sugar_config);
$calendarBackend = new Custom\DAVServer\SugarCalDAVBackend($sugar_config);

// Directory structure
$tree = [
    new Sabre\CalDAV\Principal\Collection($principalBackend),
    new Sabre\CardDAV\AddressBookRoot($principalBackend, $addressBookBackend),
    new Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend),
];

$server = new Sabre\DAV\Server($tree);

if (isset($baseUri)){
    $server->setBaseUri($baseUri);
}

/* Server Plugins */
$server->addPlugin(new Sabre\DAV\Auth\Plugin($authBackend));

$server->addPlugin(new Sabre\DAVACL\Plugin());

/* CardDAV support */
$server->addPlugin(new Sabre\CardDAV\Plugin());

/* CalDAV support */
$server->addPlugin(new Sabre\CalDAV\Plugin());
/* Calendar subscription support */
$server->addPlugin(new Sabre\CalDAV\Subscriptions\Plugin());

/* Calendar scheduling support */
$server->addPlugin(new Sabre\CalDAV\Schedule\Plugin());

/* WebDAV-Sync plugin */
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$server->addPlugin(new Sabre\DAV\Browser\Plugin());

// And off we go!
$server->exec();
