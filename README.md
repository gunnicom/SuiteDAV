# SuiteDAV
CalDAV implementation for SuiteCRM
Currently only one way Sugar -> Outlook
Keep in mind, this code is just a copy of an Example for SabreDAV with hackish adaption to SuiteCRM, so use it at your own risk and don't blame us if it eats your children.

To install:
- copy files from folder custom/DAVServer to your SuiteCRM installation (Folder custom/DAVServer)
- Change your composer.json (within the SuiteCRM folder) 
	adding "sabre/dav": "*" at the end of "require" section
	adding "phpunit/phpunit": "^8.0" at the end of "require-dev" section for developers
  on the Windows/XAMPP we ran into problems with the PHP Version. Change the PHP Version in composer.json to the actual PHP Version you are running e.g.
		"platform": {
		  "php": "7.2.2"
		}
- Run "composer update" to download SabreDAV http://sabre.io/

The CalDAV URL is https://YOURSUGARPATH/custom/DAVServer/calendarserver.php/calendars/USERNAME/default/
(replace YOURSUGARPATH and USERNAME)

https://sourceforge.net/projects/outlookcaldavsynchronizer/ will have unsuccessful connection test (The specified URL does not support calendar access) but it still works

You can use 
<pre>'caldav_calls_as_event' => true</pre>
in config.php to include calls in your calendar sync.


- If running on a development platform on windows with an environment such as xampp add 
		SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1$
  to the httpd.conf in the <VirtualHost> or <Directory> section

