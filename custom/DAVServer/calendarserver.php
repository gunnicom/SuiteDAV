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
require_once 'custom/DAVServer/SugarDAVACLPrincipalBackend.php';

$authcontroller = AuthenticationController::getInstance();

/*

  CalendarServer example

  This server features CalDAV support

 */

// settings
//date_default_timezone_set('Canada/Eastern');
// If you want to run the SabreDAV server in a custom location (using mod_rewrite for instance)
// You can override the baseUri here.
//$baseUri = 'SuiteCRM/custom/DAVServer/calendarserver.php';
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
$calendarBackend = new Custom\DAVServer\SugarCalDAVBackend($sugar_config);
$principalBackend = new Custom\DAVServer\SugarDAVACLPrincipalBackend($sugar_config);

// Directory structure
$tree = [
    new Sabre\CalDAV\Principal\Collection($principalBackend),
    new Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend),
];

$server = new Sabre\DAV\Server($tree);

if (isset($baseUri))
    $server->setBaseUri($baseUri);

/* Server Plugins */
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend);
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$server->addPlugin($aclPlugin);

/* CalDAV support */
$caldavPlugin = new Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

/* Calendar subscription support */
$server->addPlugin(
        new Sabre\CalDAV\Subscriptions\Plugin()
);

/* Calendar scheduling support */
$server->addPlugin(
        new Sabre\CalDAV\Schedule\Plugin()
);

/* WebDAV-Sync plugin */
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$browser = new Sabre\DAV\Browser\Plugin();
$server->addPlugin($browser);

// And off we go!
$server->exec();
