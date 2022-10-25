# SuiteDAV
CalDAV implementation for SuiteCRM
Currently only one way SuiteCRM -> Outlook

Keep in mind, this code is just a copy of an Example for SabreDAV with hackish adaption to SuiteCRM, so use it at your own risk and don't blame us if it eats your children.

**To install on SuiteCRM 7.12.x:**

- Copy the custom/ files from the this repository into <SuiteCRM>/custom/ folder. 
  (Create the DAVServer folder if it does not exist)
- Run "composer update --no-dev" in your SuiteCRM folder

**Installation caveats**

- When using SuiteCRM 7.10.x or 7.11.x you need to change your composer.json file (within the SuiteCRM folder)

	- adding "sabre/dav": "*" at the end of "require" section
	- adding "phpunit/phpunit": "^8.0" at the end of "require-dev" section for developers

- on the Windows/XAMPP we ran into problems with the PHP Version. Change the PHP Version in composer.json to the actual PHP Version you are running e.g.
		"platform": {
		  "php": "7.2.2"
		}

**Usage**

The CalDAV URL is https://YOURSUGARPATH/custom/DAVServer/davserver.php/calendars/USERNAME/TYPE/

The CardDAV URL is https://YOURSUGARPATH/custom/DAVServer/davserver.php/addressbooks/USERNAME/TYPE/

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

https://sourceforge.net/projects/outlookcaldavsynchronizer/ will have unsuccessful connection test (The specified URL does not support calendar access) but it still works.

OutlookCalDAVSynchronizer will show you the available resources if you just use the base URL and press "Test or Discover Settings". You can then select the TYPE in the Popup. 

DAV URL like https://YOURSUGARPATH/custom/DAVServer/davserver.php

**Configuration options**

In SuiteCRMs config_override.php add following to include calls in your calendar sync.
<pre>$sugar_config["caldav_calls_as_event"]=true;</pre>

**Tested Environments:**

- Linux PHP 7.3.3 - Mysql - SuiteCRM 7.8.27
- Linux PHP 7.4.32 - Mysql - SuiteCRM 7.12.7
- Windows PHP 7.2.2 - Mysql - SuiteCRM 7.11.2

**Known Issues:**

- When using SuiteCRM < 7.10.14 or < 7.11.2 SuiteCRM upgrade will overwrite your composer.json file. After the upgrade you need to insert above changes again and run composer update
- There maybe problems with special chars in passwords like "€".
- If running on a development platform on windows with an environment such as xampp add following line to the httpd.conf in the <VirtualHost> or <Directory> section
		
		SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1$
		
