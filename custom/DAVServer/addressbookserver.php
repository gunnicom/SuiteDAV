<?php

if (!defined('sugarEntry'))
    define('sugarEntry', true);

/*register_shutdown_function('errorHandler');

function errorHandler() { 
    $error = error_get_last();
    $type = $error['type'];
    $message = $error['message'];
    if ($type == 64 && !empty($message)) {
        echo "
            <strong>
              <font color=\"red\">
              Fatal error captured:
              </font>
            </strong>
        ";
        echo "<pre>";
        print_r($error);
        echo "</pre>";
    }
}
*/

// For includes we need to change Dir to Sugar root folder
chdir("../..");
require_once 'config.php';
require_once 'include/entryPoint.php';
require_once 'modules/Users/authentication/AuthenticationController.php';

// Requires maybe not possible if everything is correctly installed with composer
require_once 'custom/DAVServer/SugarDAVACLPrincipalBackend.php';
require_once 'custom/DAVServer/SugarCardDAVBackend.php';

$authcontroller = AuthenticationController::getInstance();

/*
  This server features CardDAV support
 */

// settings
//date_default_timezone_set('Canada/Eastern');
// If you want to run the SabreDAV server in a custom location (using mod_rewrite for instance)
// You can override the baseUri here.
//$baseUri = 'SuiteCRM/custom/DAVServer/addressbookserver.php';
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

// Directory structure
$tree = [
    new Sabre\CalDAV\Principal\Collection($principalBackend),
    new Sabre\CardDAV\AddressBookRoot($principalBackend, $addressBookBackend),
];

$server = new Sabre\DAV\Server($tree);

if (isset($baseUri))
    $server->setBaseUri($baseUri);

/* Server Plugins */
$server->addPlugin(new Sabre\DAV\Auth\Plugin($authBackend));

$server->addPlugin(new Sabre\DAVACL\Plugin());

/* CardDAV support */
$server->addPlugin(new Sabre\CardDAV\Plugin());


/* WebDAV-Sync plugin */
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$server->addPlugin(new Sabre\DAV\Browser\Plugin());

// And off we go!
$server->exec();
