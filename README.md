# SuiteDAV
CalDAV implementation for SuiteCRM
Currently only one way SuiteCRM -> Outlook

Keep in mind, this code is just a copy of an Example for SabreDAV with hackish adaption to SuiteCRM, so use it at your own risk and don't blame us if it eats your children.

To install:
- When using SuiteCRM < 7.10.14 or < 7.11.2 change your composer.json (within the SuiteCRM folder) 
	adding "sabre/dav": "*" at the end of "require" section
	adding "phpunit/phpunit": "^8.0" at the end of "require-dev" section for developers
  on the Windows/XAMPP we ran into problems with the PHP Version. Change the PHP Version in composer.json to the actual PHP Version you are running e.g.
		"platform": {
		  "php": "7.2.2"
		}
- Run "composer update" to download SabreDAV http://sabre.io/

**** IMPORTANT ****
When using SuiteCRM < 7.10.14 or < 7.11.2 SuiteCRM upgrade will overwrite your composer.json file. After the upgrade you need to insert above changes again and run composer update


- Copy the custom/DAVServer files from the this repository into <SuiteCRM>/custom/DAVServer folder. 
  (Create the DAVServer folder if it does not exist)
	

The CalDAV URL is https://YOURSUGARPATH/custom/DAVServer/calendarserver.php/calendars/USERNAME/TYPE/

The CardDAV URL is https://YOURSUGARPATH/custom/DAVServer/addressbookserver.php/addressbooks/USERNAME/TYPE/

(replace YOURSUGARPATH and USERNAME and TYPE)

possible TYPE for CalDAV
* default (Meetings and if enabled by configuration merged with Calls)
* Meetings
* Calls
* Events

possible TYPE for CardDAV:
* Contacts (or default)
* Leads
* Prospects
* Users

https://sourceforge.net/projects/outlookcaldavsynchronizer/ will have unsuccessful connection test (The specified URL does not support calendar access) but it still works

You can use 
<pre>$sugar_config["caldav_calls_as_event"]=true;</pre>
in config_override.php to include calls in your calendar sync.


- If running on a development platform on windows with an environment such as xampp add 
		
		SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1$
		
  to the httpd.conf in the <VirtualHost> or <Directory> section


Tested Enviroenments:

Linux PHP 7.3.3 - Mysql - SuiteCRM 7.8.27

Windows PHP 7.2.2 - Mysql - SuiteCRM 7.11.2

Known Issues:

There maybe problems with special chars in passwords like "€".
