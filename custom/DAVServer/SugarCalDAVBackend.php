<?php

namespace Custom\DAVServer;

use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

/**
 * PDO CalDAV backend
 *
 * This backend is used to store calendar-data in a PDO database, such as
 * sqlite or MySQL
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class SugarCalDAVBackend extends \Sabre\CalDAV\Backend\AbstractBackend implements \Sabre\CalDAV\Backend\SyncSupport, \Sabre\CalDAV\Backend\SubscriptionSupport, \Sabre\CalDAV\Backend\SchedulingSupport {

    private $db;
    private $caldav_calls_as_event;

    /**
     * We need to specify a max date, because we need to stop *somewhere*
     *
     * On 32 bit system the maximum for a signed integer is 2147483647, so
     * MAX_DATE cannot be higher than date('Y-m-d', 2147483647) which results
     * in 2038-01-19 to avoid problems when the date is converted
     * to a unix timestamp.
     */
    const MAX_DATE = '2038-01-01';

    /**
     * pdo
     *
     * @var \PDO
     */
    protected $pdo;

    /**
     * The table name that will be used for calendars
     *
     * @var string
     */
    public $calendarTableName = 'calendars';

    /**
     * The table name that will be used for calendar objects
     *
     * @var string
     */
    public $calendarObjectTableName = 'calendarobjects';

    /**
     * The table name that will be used for tracking changes in calendars.
     *
     * @var string
     */
    public $calendarChangesTableName = 'calendarchanges';

    /**
     * The table name that will be used inbox items.
     *
     * @var string
     */
    public $schedulingObjectTableName = 'schedulingobjects';

    /**
     * The table name that will be used for calendar subscriptions.
     *
     * @var string
     */
    public $calendarSubscriptionsTableName = 'calendarsubscriptions';

    /**
     * List of CalDAV properties, and how they map to database fieldnames
     * Add your own properties by simply adding on to this array.
     *
     * Note that only string-based properties are supported here.
     *
     * @var array
     */
    public $propertyMap = [
        '{DAV:}displayname' => 'displayname',
        //'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        //'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'timezone',
        //'{http://apple.com/ns/ical/}calendar-order' => 'calendarorder',
        '{http://apple.com/ns/ical/}calendar-color' => 'calendarcolor',
    ];

    /**
     * List of subscription properties, and how they map to database fieldnames.
     *
     * @var array
     */
    public $subscriptionPropertyMap = [
        '{DAV:}displayname' => 'displayname',
        //'{http://apple.com/ns/ical/}refreshrate' => 'refreshrate',
        //'{http://apple.com/ns/ical/}calendar-order' => 'calendarorder',
        '{http://apple.com/ns/ical/}calendar-color' => 'calendarcolor',
            //'{http://calendarserver.org/ns/}subscribed-strip-todos' => 'striptodos',
            //'{http://calendarserver.org/ns/}subscribed-strip-alarms' => 'stripalarms',
            //'{http://calendarserver.org/ns/}subscribed-strip-attachments' => 'stripattachments',
    ];

    /**
     * Creates the backend
     *
     * @param \PDO $pdo
     */
    function __construct($sugar_config) {
        $this->caldav_calls_as_event = isset($sugar_config["caldav_calls_as_event"]) ? $sugar_config["caldav_calls_as_event"] : false;
        $this->db = new \mysqli($sugar_config["dbconfig"]['db_host_name'], $sugar_config["dbconfig"]['db_user_name'], $sugar_config["dbconfig"]['db_password'], $sugar_config["dbconfig"]['db_name']);
    }

    /**
     * Returns a list of calendars for a principal.
     *
     * Every project is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    calendar. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the calendar.
     *  * principaluri. The owner of the calendar. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore it can contain webdav properties in clark notation. A very
     * common one is '{DAV:}displayname'.
     *
     * Many clients also require:
     * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     * For this property, you can just return an instance of
     * Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet.
     *
     * If you return {http://sabredav.org/ns}read-only and set the value to 1,
     * ACL will automatically be put in read-only mode.
     *
     * @param string $principalUri
     * @return array
     */
    function getCalendarsForUser($principalUri) {
        //ACLController::checkAccess('Contacts', 'view', true);
        $paramprincipalUri = $this->db->real_escape_string($principalUri);
        $stmt = "SELECT id, user_name, '#bbff00ff' as calendarcolor FROM users WHERE deleted=0 AND CONCAT('principals/',user_name)='{$paramprincipalUri}';";
        try {
            $sqlresult = $this->db->query($stmt);
            while ($row = $sqlresult->fetch_assoc()) {
                $components = ["VEVENT", "VTODO"];
                $row['transparent'] = "";
                // Default Calendar
                $row['displayname']="default";
                $calendar = [
                    'id' => "default|" . $row['id'],
                    'uri' => "default", //"calendars/{$row['user_name']}/default",
                    'principaluri' => $principalUri,
                    //'{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ? $row['synctoken'] : '0'),
                    '{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . '0',
                    //'{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
                    '{http://sabredav.org/ns}sync-token' => '0',
                    '{http://sabredav.org/ns}read-only' => '1',
                    '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                    '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new CalDAV\Xml\Property\ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
                ];

                foreach ($this->propertyMap as $xmlName => $dbName) {
                    $calendar[$xmlName] = $row[$dbName];
                }
                $calendar["{urn:ietf:params:xml:ns:caldav}calendar-timezone"] = "UTC";
                $calendars[] = $calendar;
                // Meetings Calendar
                $row['displayname']="Meetings";
                $calendar = [
                    'id' => "Meetings|" . $row['id'],
                    'uri' => "Meetings", //"calendars/{$row['user_name']}/default",
                    'principaluri' => $principalUri,
                    //'{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ? $row['synctoken'] : '0'),
                    '{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . '0',
                    //'{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
                    '{http://sabredav.org/ns}sync-token' => '0',
                    '{http://sabredav.org/ns}read-only' => '1',
                    '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                    '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new CalDAV\Xml\Property\ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
                ];

                foreach ($this->propertyMap as $xmlName => $dbName) {
                    $calendar[$xmlName] = $row[$dbName];
                }
                $calendar["{urn:ietf:params:xml:ns:caldav}calendar-timezone"] = "UTC";
                $calendars[] = $calendar;
                // Calls Calendar
                $row['displayname']="Calls";
                $calendar = [
                    'id' => "Calls|" . $row['id'],
                    'uri' => "Calls", //"calendars/{$row['user_name']}/default",
                    'principaluri' => $principalUri,
                    //'{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ? $row['synctoken'] : '0'),
                    '{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . '0',
                    //'{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
                    '{http://sabredav.org/ns}sync-token' => '0',
                    '{http://sabredav.org/ns}read-only' => '1',
                    '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                    '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new CalDAV\Xml\Property\ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
                ];

                foreach ($this->propertyMap as $xmlName => $dbName) {
                    $calendar[$xmlName] = $row[$dbName];
                }
                $calendar["{urn:ietf:params:xml:ns:caldav}calendar-timezone"] = "UTC";
                $calendars[] = $calendar;
                // Event Calendar
                $row['displayname']="Events";
                $calendar = [
                    'id' => "Events|" . $row['id'],
                    'uri' => "Events", //"calendars/{$row['user_name']}/default",
                    'principaluri' => $principalUri,
                    //'{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ? $row['synctoken'] : '0'),
                    '{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . '0',
                    //'{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
                    '{http://sabredav.org/ns}sync-token' => '0',
                    '{http://sabredav.org/ns}read-only' => '1',
                    '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                    '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new CalDAV\Xml\Property\ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
                ];

                foreach ($this->propertyMap as $xmlName => $dbName) {
                    $calendar[$xmlName] = $row[$dbName];
                }
                $calendar["{urn:ietf:params:xml:ns:caldav}calendar-timezone"] = "UTC";
                $calendars[] = $calendar;
            }
            return $calendars;
        } catch (Exception $e) {
            //echo 'Exception: ',  $e->getMessage(), "\n";
            $GLOBALS['log']->error($e->getMessage());
            throw e;
        }
    }

    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * calendardata - The iCalendar-compatible calendar data
     *   * uri - a unique key which will be used to construct the uri. This can
     *     be any arbitrary string, but making sure it ends with '.ics' is a
     *     good idea. This is only the basename, or filename, not the full
     *     path.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
     *   '  "abcdef"')
     *   * size - The size of the calendar objects, in bytes.
     *   * component - optional, a string containing the type of object, such
     *     as 'vevent' or 'vtodo'. If specified, this will be used to populate
     *     the Content-Type header.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * The calendardata is also optional. If it's not returned
     * 'getCalendarObject' will be called later, which *is* expected to return
     * calendardata.
     *
     * If neither etag or size are specified, the calendardata will be
     * used/fetched to determine these numbers. If both are specified the
     * amount of times this is needed is reduced by a great degree.
     *
     * @param string $calendarId
     * @return array
     */
    function getCalendarObjects($calendarId) {
        $calendarParams = explode("|", $calendarId);
        $assigned_user_id = $this->db->real_escape_string($calendarParams[1]);
        
        $stmt = $this->getQueryForUser($calendarParams[0], $assigned_user_id);
        if ($calendarParams[0]=="default" && $this->caldav_calls_as_event == true) {
            $stmt .= " UNION " . $this->getQueryForUser("Calls", $assigned_user_id);
        }
        try {
            $sqlresult = $this->db->query($stmt);
            while ($row = $sqlresult->fetch_assoc()) {
                $result[] = $this->createCalendarItemFromRow($row, $calendarId);
            }

            return $result;
        } catch (Exception $e) {
            //echo 'Exception: ',  $e->getMessage(), "\n";
            $GLOBALS['log']->error($e->getMessage());
            throw e;
        }
    }

    /**
     * Returns information from a single calendar object, based on it's object
     * uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * The returned array must have the same keys as getCalendarObjects. The
     * 'calendardata' object is required here though, while it's not required
     * for getCalendarObjects.
     *
     * This method must return null if the object did not exist.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return array|null
     */
    function getCalendarObject($calendarId, $objectUri) {
        $calendarParams = explode("|", $calendarId);
        $assigned_user_id = $this->db->real_escape_string($calendarParams[1]);
        $paramobjectUri = $this->db->real_escape_string($objectUri);
        $idfield=$calendarParams[0]=="Events"?"fp_events.id":"id";
        $stmt = $this->getQueryForUser($calendarParams[0], $assigned_user_id) . " AND CONCAT({$idfield},'.ics')='{$paramobjectUri}' ";
        if ($calendarParams[0]=="default" && $this->caldav_calls_as_event == true) {
            $stmt .= " UNION " . $this->getQueryForUser("Calls", $assigned_user_id) . " AND CONCAT(id,'.ics')='{$paramobjectUri}' ";
        }
        try {
            $sqlresult = $this->db->query($stmt);

            if ($row = $sqlresult->fetch_assoc()) {

                return $this->createCalendarItemFromRow($row, $calendarId);
            }
            return null;
        } catch (Exception $e) {
            //echo 'Exception: ',  $e->getMessage(), "\n";
            $GLOBALS['log']->error($e->getMessage());
            throw e;
        }
    }

    function createCalendarItemFromRow(&$row, $calendarId) {
        $vcalendar = new VObject\Component\VCalendar([
            'VEVENT' => [
                'SUMMARY' => $row['name'],
                'DESCRIPTION' => $row['description'],
                'LOCATION' => $row['location'],
                'DTSTART' => new \DateTime($row['date_start'], new \DateTimeZone("UTC")),
                'DTEND' => new \DateTime($row['date_end'], new \DateTimeZone("UTC"))
            ]
        ]);
        $calendardata = $vcalendar->serialize();
        return [
            'id' => $row['id'],
            'uri' => $row['id'] . ".ics",
            'lastmodified' => new \DateTime($row['date_modified']),
            'etag' => '"' . md5($row['id'] . $row['date_modified']) . '"',
            'calendarid' => $calendarId, //$row['assigned_user_id'],
            'size' => (int) strlen($calendardata), // $row['size'],
            'calendardata' => $calendardata,
            'component' => "vevent", //strtolower($row['componenttype']),
        ];
    }

    function getQueryForUser($type, $assigned_user_id) {
        switch ($type) {
            Case "Calls":
                return " SELECT id, date_modified, date_start, date_end, name, description, 'Call' AS location FROM calls WHERE deleted=0 AND assigned_user_id='{$assigned_user_id}' ";
            Case "Events":
                return " SELECT fp_events.id, fp_events.date_modified, fp_events.date_start, fp_events.date_end, fp_events.name, fp_events.description, fp_event_locations.name AS location FROM fp_events "
                     . " LEFT JOIN fp_events_fp_event_locations_1_c ON fp_events.id=fp_events_fp_event_locations_1_c.fp_events_fp_event_locations_1fp_events_ida AND fp_events.id=fp_events_fp_event_locations_1_c.deleted=0 "
                     . " LEFT JOIN fp_event_locations ON fp_events_fp_event_locations_1_c.fp_events_fp_event_locations_1fp_event_locations_idb=fp_event_locations.id AND fp_event_locations.deleted=0 "
                     . " WHERE fp_events.deleted = 0 AND fp_events.assigned_user_id='{$assigned_user_id}' ";
            case "Meetings":
            default:
                return " SELECT id, date_modified, date_start, date_end, name, description, location FROM meetings WHERE deleted=0 AND assigned_user_id='{$assigned_user_id}' ";
        }
    }

    /**
     * Performs a calendar-query on the contents of this calendar.
     *
     * The calendar-query is defined in RFC4791 : CalDAV. Using the
     * calendar-query it is possible for a client to request a specific set of
     * object, based on contents of iCalendar properties, date-ranges and
     * iCalendar component types (VTODO, VEVENT).
     *
     * This method should just return a list of (relative) urls that match this
     * query.
     *
     * The list of filters are specified as an array. The exact array is
     * documented by \Sabre\CalDAV\CalendarQueryParser.
     *
     * Note that it is extremely likely that getCalendarObject for every path
     * returned from this method will be called almost immediately after. You
     * may want to anticipate this to speed up these requests.
     *
     * This method provides a default implementation, which parses *all* the
     * iCalendar objects in the specified calendar.
     *
     * This default may well be good enough for personal use, and calendars
     * that aren't very large. But if you anticipate high usage, big calendars
     * or high loads, you are strongly adviced to optimize certain paths.
     *
     * The best way to do so is override this method and to optimize
     * specifically for 'common filters'.
     *
     * Requests that are extremely common are:
     *   * requests for just VEVENTS
     *   * requests for just VTODO
     *   * requests with a time-range-filter on a VEVENT.
     *
     * ..and combinations of these requests. It may not be worth it to try to
     * handle every possible situation and just rely on the (relatively
     * easy to use) CalendarQueryValidator to handle the rest.
     *
     * Note that especially time-range-filters may be difficult to parse. A
     * time-range filter specified on a VEVENT must for instance also handle
     * recurrence rules correctly.
     * A good example of how to interprete all these filters can also simply
     * be found in \Sabre\CalDAV\CalendarQueryFilter. This class is as correct
     * as possible, so it gives you a good idea on what type of stuff you need
     * to think of.
     *
     * This specific implementation (for the PDO) backend optimizes filters on
     * specific components, and VEVENT time-ranges.
     *
     * @param string $calendarId
     * @param array $filters
     * @return array
     */
    function calendarQuery($calendarId, array $filters) {
        // TODO: $filters und altes raus
        $calendarParams = explode("|", $calendarId);
        try {
            $componentType = null;
            $requirePostFilter = true;
            $timeRange = null;

            // if no filters were specified, we don't need to filter after a query
            if (!$filters['prop-filters'] && !$filters['comp-filters']) {
                $requirePostFilter = false;
            }

            // Figuring out if there's a component filter
            if (count($filters['comp-filters']) > 0 && !$filters['comp-filters'][0]['is-not-defined']) {
                $componentType = $filters['comp-filters'][0]['name'];

                // Checking if we need post-filters
                if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['time-range'] && !$filters['comp-filters'][0]['prop-filters']) {
                    $requirePostFilter = false;
                }
                // There was a time-range filter
                if ($componentType == 'VEVENT' && isset($filters['comp-filters'][0]['time-range'])) {
                    $timeRange = $filters['comp-filters'][0]['time-range'];

                    // If start time OR the end time is not specified, we can do a
                    // 100% accurate mysql query.
                    if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['prop-filters'] && (!$timeRange['start'] || !$timeRange['end'])) {
                        $requirePostFilter = false;
                    }
                }
            }
            $assigned_user_id = $this->db->real_escape_string($calendarParams[1]);
            $query = $this->getQueryForUser($calendarParams[0], $assigned_user_id);
            if ($calendarParams[0]=="default" && $this->caldav_calls_as_event == true) {
                $query .= " UNION " . $this->getQueryForUser("Calls", $assigned_user_id);
            }
            if ($timeRange && $timeRange['start']) {

                $values['startdate'] = $timeRange['start']->format('Y-m-d H:i:s');
                $query .= " AND date_start > '{$values['startdate']}'";
            }
            if ($timeRange && $timeRange['end']) {

                $values['enddate'] = $timeRange['end']->format('Y-m-d H:i:s');
                $query .= " AND date_start < '{$values['enddate']}'";
            }

            $sqlresult = $this->db->query($query);

            $result = [];
            while ($row = $sqlresult->fetch_assoc()) {
                $result[] = $row['id'] . ".ics";
            }

            return $result;
        } catch (Exception $e) {
            //echo 'Exception: ',  $e->getMessage(), "\n";
            $GLOBALS['log']->error($e->getMessage());
            throw e;
        }
    }

    /**
     * Returns a list of calendar objects.
     *
     * This method should work identical to getCalendarObject, but instead
     * return all the calendar objects in the list as an array.
     *
     * If the backend supports this, it may allow for some speed-ups.
     *
     * @param mixed $calendarId
     * @param array $uris
     * @return array
     */
    function getMultipleCalendarObjects($calendarId, array $uris) {

        return array_map(function($uri) use ($calendarId) {
            return $this->getCalendarObject($calendarId, $uri);
        }, $uris);

        /* $assigned_user_id = $calendarId; //==1?1:"2e4b870d-5079-db56-e3b1-53f3452f63c3";
          $objectUri = implode('","', $uris);
          $stmt = "SELECT id, date_modified, date_start, date_end, name, description, location FROM meetings WHERE deleted=0 AND assigned_user_id='{$assigned_user_id}' AND CONCAT(id,'.ics') IN (\"{$objectUri}\") ";
          if ($this->caldav_calls_as_event == true) {
          $stmt .= " UNION SELECT id, date_modified, date_start, date_end, name, description, 'Call' AS location FROM calls WHERE deleted=0 AND assigned_user_id='{$assigned_user_id}' AND CONCAT(id,'.ics') IN (\"{$objectUri}\")  ";
          }
          $sqlresult = $this->db->query($stmt);
          while ($row = $sqlresult->fetch_assoc()) {
          $vcalendar = new VObject\Component\VCalendar([
          'VEVENT' => [
          'SUMMARY' => $row['name'],
          'DESCRIPTION' => $row['description'],
          'LOCATION' => $row['location'],
          'DTSTART' => new \DateTime($row['date_start'], new \DateTimeZone("UTC")),
          'DTEND' => new \DateTime($row['date_end'], new \DateTimeZone("UTC"))
          ]
          ]);
          $row['calendardata'] = $vcalendar->serialize();
          $result[] = [
          'id' => $row['id'],
          'uri' => $row['id'] . ".ics",
          'lastmodified' => new \DateTime($row['date_modified']),
          'etag' => '"' . md5($row['id'] . $row['date_modified']) . '"',
          'calendarid' => $calendarId, //$row['calendarid'],
          'size' => (int) strlen($row['calendardata']), //$row['size'],
          'calendardata' => $row['calendardata'],
          'component' => "vevent", //strtolower($row['componenttype']),
          ];
          }

          return $result;
         */
    }

// **********************************************************************************************************************************    
// FROM HERE DOWNWARD ONLY STUBS, COPIED FROM THE SabreDAV EXAMPLE
// **********************************************************************************************************************************    

    /**
     * Creates a new calendar for a principal.
     *
     * If the creation was a success, an id must be returned that can be used
     * to reference this calendar in other methods, such as updateCalendar.
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return string
     */
    function createCalendar($principalUri, $calendarUri, array $properties) {
        return null; // STUB
        $fieldNames = [
            'principaluri',
            'uri',
            'synctoken',
            'transparent',
        ];
        $values = [
            ':principaluri' => $principalUri,
            ':uri' => $calendarUri,
            ':synctoken' => 1,
            ':transparent' => 0,
        ];

        // Default value
        $sccs = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
        $fieldNames[] = 'components';
        if (!isset($properties[$sccs])) {
            $values[':components'] = 'VEVENT,VTODO';
        } else {
            if (!($properties[$sccs] instanceof CalDAV\Xml\Property\SupportedCalendarComponentSet)) {
                throw new DAV\Exception('The ' . $sccs . ' property must be of type: \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet');
            }
            $values[':components'] = implode(',', $properties[$sccs]->getValue());
        }
        $transp = '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';
        if (isset($properties[$transp])) {
            $values[':transparent'] = $properties[$transp]->getValue() === 'transparent';
        }

        foreach ($this->propertyMap as $xmlName => $dbName) {
            if (isset($properties[$xmlName])) {

                $values[':' . $dbName] = $properties[$xmlName];
                $fieldNames[] = $dbName;
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO " . $this->calendarTableName . " (" . implode(', ', $fieldNames) . ") VALUES (" . implode(', ', array_keys($values)) . ")");
        $stmt->execute($values);

        return $this->pdo->lastInsertId();
    }

    /**
     * Updates properties for a calendar.
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documenation for more info and examples.
     *
     * @param string $calendarId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @return void
     */
    function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {
        return null; // STUB
        $supportedProperties = array_keys($this->propertyMap);
        $supportedProperties[] = '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';

        $propPatch->handle($supportedProperties, function($mutations) use ($calendarId) {
            $newValues = [];
            foreach ($mutations as $propertyName => $propertyValue) {

                switch ($propertyName) {
                    case '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' :
                        $fieldName = 'transparent';
                        $newValues[$fieldName] = $propertyValue->getValue() === 'transparent';
                        break;
                    default :
                        $fieldName = $this->propertyMap[$propertyName];
                        $newValues[$fieldName] = $propertyValue;
                        break;
                }
            }
            $valuesSql = [];
            foreach ($newValues as $fieldName => $value) {
                $valuesSql[] = $fieldName . ' = ?';
            }

            $stmt = $this->pdo->prepare("UPDATE " . $this->calendarTableName . " SET " . implode(', ', $valuesSql) . " WHERE id = ?");
            $newValues['id'] = $calendarId;
            $stmt->execute(array_values($newValues));

            $this->addChange($calendarId, "", 2);

            return true;
        });
    }

    /**
     * Delete a calendar and all it's objects
     *
     * @param string $calendarId
     * @return void
     */
    function deleteCalendar($calendarId) {
        return null; // STUB
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarObjectTableName . ' WHERE calendarid = ?');
        $stmt->execute([$calendarId]);

        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarTableName . ' WHERE id = ?');
        $stmt->execute([$calendarId]);

        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarChangesTableName . ' WHERE calendarid = ?');
        $stmt->execute([$calendarId]);
    }

    /**
     * Creates a new calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    function createCalendarObject($calendarId, $objectUri, $calendarData) {
        return null; // STUB
        $extraData = $this->getDenormalizedData($calendarData);

        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->calendarObjectTableName . ' (calendarid, uri, calendardata, lastmodified, etag, size, componenttype, firstoccurence, lastoccurence, uid) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $calendarId,
            $objectUri,
            $calendarData,
            time(),
            $extraData['etag'],
            $extraData['size'],
            $extraData['componentType'],
            $extraData['firstOccurence'],
            $extraData['lastOccurence'],
            $extraData['uid'],
        ]);
        $this->addChange($calendarId, $objectUri, 1);

        return '"' . $extraData['etag'] . '"';
    }

    /**
     * Updates an existing calendarobject, based on it's uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    function updateCalendarObject($calendarId, $objectUri, $calendarData) {
        return null; // STUB
        $extraData = $this->getDenormalizedData($calendarData);

        $stmt = $this->pdo->prepare('UPDATE ' . $this->calendarObjectTableName . ' SET calendardata = ?, lastmodified = ?, etag = ?, size = ?, componenttype = ?, firstoccurence = ?, lastoccurence = ?, uid = ? WHERE calendarid = ? AND uri = ?');
        $stmt->execute([$calendarData, time(), $extraData['etag'], $extraData['size'], $extraData['componentType'], $extraData['firstOccurence'], $extraData['lastOccurence'], $extraData['uid'], $calendarId, $objectUri]);

        $this->addChange($calendarId, $objectUri, 2);

        return '"' . $extraData['etag'] . '"';
    }

    /**
     * Parses some information from calendar objects, used for optimized
     * calendar-queries.
     *
     * Returns an array with the following keys:
     *   * etag - An md5 checksum of the object without the quotes.
     *   * size - Size of the object in bytes
     *   * componentType - VEVENT, VTODO or VJOURNAL
     *   * firstOccurence
     *   * lastOccurence
     *   * uid - value of the UID property
     *
     * @param string $calendarData
     * @return array
     */
    protected function getDenormalizedData($calendarData) {
        return null; // STUB
        $vObject = VObject\Reader::read($calendarData);
        $componentType = null;
        $component = null;
        $firstOccurence = null;
        $lastOccurence = null;
        $uid = null;
        foreach ($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $componentType = $component->name;
                $uid = (string) $component->UID;
                break;
            }
        }
        if (!$componentType) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
        }
        if ($componentType === 'VEVENT') {
            $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
            // Finding the last occurence is a bit harder
            if (!isset($component->RRULE)) {
                if (isset($component->DTEND)) {
                    $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
                } elseif (isset($component->DURATION)) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate = $endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
                    $lastOccurence = $endDate->getTimeStamp();
                } elseif (!$component->DTSTART->hasTime()) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate = $endDate->modify('+1 day');
                    $lastOccurence = $endDate->getTimeStamp();
                } else {
                    $lastOccurence = $firstOccurence;
                }
            } else {
                $it = new VObject\Recur\EventIterator($vObject, (string) $component->UID);
                $maxDate = new \DateTime(self::MAX_DATE);
                if ($it->isInfinite()) {
                    $lastOccurence = $maxDate->getTimeStamp();
                } else {
                    $end = $it->getDtEnd();
                    while ($it->valid() && $end < $maxDate) {
                        $end = $it->getDtEnd();
                        $it->next();
                    }
                    $lastOccurence = $end->getTimeStamp();
                }
            }
        }

        // Destroy circular references to PHP will GC the object.
        $vObject->destroy();

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => $firstOccurence,
            'lastOccurence' => $lastOccurence,
            'uid' => $uid,
        ];
    }

    /**
     * Deletes an existing calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return void
     */
    function deleteCalendarObject($calendarId, $objectUri) {
        return null; // STUB
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarObjectTableName . ' WHERE calendarid = ? AND uri = ?');
        $stmt->execute([$calendarId, $objectUri]);

        $this->addChange($calendarId, $objectUri, 3);
    }

    /**
     * Searches through all of a users calendars and calendar objects to find
     * an object with a specific UID.
     *
     * This method should return the path to this object, relative to the
     * calendar home, so this path usually only contains two parts:
     *
     * calendarpath/objectpath.ics
     *
     * If the uid is not found, return null.
     *
     * This method should only consider * objects that the principal owns, so
     * any calendars owned by other principals that also appear in this
     * collection should be ignored.
     *
     * @param string $principalUri
     * @param string $uid
     * @return string|null
     */
    function getCalendarObjectByUID($principalUri, $uid) {
        return null; // STUB
        $query = <<<SQL
SELECT
    calendars.uri AS calendaruri, calendarobjects.uri as objecturi
FROM
    $this->calendarObjectTableName AS calendarobjects
LEFT JOIN
    $this->calendarTableName AS calendars
    ON calendarobjects.calendarid = calendars.id
WHERE
    calendars.principaluri = ?
    AND
    calendarobjects.uid = ?
SQL;

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$principalUri, $uid]);

        if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            return $row['calendaruri'] . '/' . $row['objecturi'];
        }
    }

    /**
     * The getChanges method returns all the changes that have happened, since
     * the specified syncToken in the specified calendar.
     *
     * This function should return an array, such as the following:
     *
     * [
     *   'syncToken' => 'The current synctoken',
     *   'added'   => [
     *      'new.txt',
     *   ],
     *   'modified'   => [
     *      'modified.txt',
     *   ],
     *   'deleted' => [
     *      'foo.php.bak',
     *      'old.txt'
     *   ]
     * ];
     *
     * The returned syncToken property should reflect the *current* syncToken
     * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
     * property this is needed here too, to ensure the operation is atomic.
     *
     * If the $syncToken argument is specified as null, this is an initial
     * sync, and all members should be reported.
     *
     * The modified property is an array of nodenames that have changed since
     * the last token.
     *
     * The deleted property is an array with nodenames, that have been deleted
     * from collection.
     *
     * The $syncLevel argument is basically the 'depth' of the report. If it's
     * 1, you only have to report changes that happened only directly in
     * immediate descendants. If it's 2, it should also include changes from
     * the nodes below the child collections. (grandchildren)
     *
     * The $limit argument allows a client to specify how many results should
     * be returned at most. If the limit is not specified, it should be treated
     * as infinite.
     *
     * If the limit (infinite or not) is higher than you're willing to return,
     * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
     *
     * If the syncToken is expired (due to data cleanup) or unknown, you must
     * return null.
     *
     * The limit is 'suggestive'. You are free to ignore it.
     *
     * @param string $calendarId
     * @param string $syncToken
     * @param int $syncLevel
     * @param int $limit
     * @return array
     */
    function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {
        return []; // STUB
        // Current synctoken
        $stmt = $this->pdo->prepare('SELECT synctoken FROM ' . $this->calendarTableName . ' WHERE id = ?');
        $stmt->execute([$calendarId]);
        $currentToken = $stmt->fetchColumn(0);

        if (is_null($currentToken))
            return null;

        $result = [
            'syncToken' => $currentToken,
            'added' => [],
            'modified' => [],
            'deleted' => [],
        ];

        if ($syncToken) {

            $query = "SELECT uri, operation FROM " . $this->calendarChangesTableName . " WHERE synctoken >= ? AND synctoken < ? AND calendarid = ? ORDER BY synctoken";
            if ($limit > 0)
                $query .= " LIMIT " . (int) $limit;

            // Fetching all changes
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$syncToken, $currentToken, $calendarId]);

            $changes = [];

            // This loop ensures that any duplicates are overwritten, only the
            // last change on a node is relevant.
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $changes[$row['uri']] = $row['operation'];
            }

            foreach ($changes as $uri => $operation) {

                switch ($operation) {
                    case 1 :
                        $result['added'][] = $uri;
                        break;
                    case 2 :
                        $result['modified'][] = $uri;
                        break;
                    case 3 :
                        $result['deleted'][] = $uri;
                        break;
                }
            }
        } else {
            // No synctoken supplied, this is the initial sync.
            $query = "SELECT uri FROM " . $this->calendarObjectTableName . " WHERE calendarid = ?";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$calendarId]);

            $result['added'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $result;
    }

    /**
     * Adds a change record to the calendarchanges table.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param int $operation 1 = add, 2 = modify, 3 = delete.
     * @return void
     */
    protected function addChange($calendarId, $objectUri, $operation) {
        return null; // STUB
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->calendarChangesTableName . ' (uri, synctoken, calendarid, operation) SELECT ?, synctoken, ?, ? FROM ' . $this->calendarTableName . ' WHERE id = ?');
        $stmt->execute([
            $objectUri,
            $calendarId,
            $operation,
            $calendarId
        ]);
        $stmt = $this->pdo->prepare('UPDATE ' . $this->calendarTableName . ' SET synctoken = synctoken + 1 WHERE id = ?');
        $stmt->execute([
            $calendarId
        ]);
    }

    /**
     * Returns a list of subscriptions for a principal.
     *
     * Every subscription is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    subscription. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the subscription.
     *  * principaluri. The owner of the subscription. Almost always the same as
     *    principalUri passed to this method.
     *  * source. Url to the actual feed
     *
     * Furthermore, all the subscription info must be returned too:
     *
     * 1. {DAV:}displayname
     * 2. {http://apple.com/ns/ical/}refreshrate
     * 3. {http://calendarserver.org/ns/}subscribed-strip-todos (omit if todos
     *    should not be stripped).
     * 4. {http://calendarserver.org/ns/}subscribed-strip-alarms (omit if alarms
     *    should not be stripped).
     * 5. {http://calendarserver.org/ns/}subscribed-strip-attachments (omit if
     *    attachments should not be stripped).
     * 7. {http://apple.com/ns/ical/}calendar-color
     * 8. {http://apple.com/ns/ical/}calendar-order
     * 9. {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     *    (should just be an instance of
     *    Sabre\CalDAV\Property\SupportedCalendarComponentSet, with a bunch of
     *    default components).
     *
     * @param string $principalUri
     * @return array
     */
    function getSubscriptionsForUser($principalUri) {
        return null; // STUB
        $fields = array_values($this->subscriptionPropertyMap);
        $fields[] = 'id';
        $fields[] = 'uri';
        $fields[] = 'source';
        $fields[] = 'principaluri';
        $fields[] = 'lastmodified';

        // Making fields a comma-delimited list
        $fields = implode(', ', $fields);
        $stmt = $this->pdo->prepare("SELECT " . $fields . " FROM " . $this->calendarSubscriptionsTableName . " WHERE principaluri = ? ORDER BY calendarorder ASC");
        $stmt->execute([$principalUri]);

        $subscriptions = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

            $subscription = [
                'id' => $row['id'],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                'source' => $row['source'],
                'lastmodified' => $row['lastmodified'],
                '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet(['VTODO', 'VEVENT']),
            ];

            foreach ($this->subscriptionPropertyMap as $xmlName => $dbName) {
                if (!is_null($row[$dbName])) {
                    $subscription[$xmlName] = $row[$dbName];
                }
            }

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    /**
     * Creates a new subscription for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this subscription in other methods, such as updateSubscription.
     *
     * @param string $principalUri
     * @param string $uri
     * @param array $properties
     * @return mixed
     */
    function createSubscription($principalUri, $uri, array $properties) {
        return null; // STUB
        $fieldNames = [
            'principaluri',
            'uri',
            'source',
            'lastmodified',
        ];

        if (!isset($properties['{http://calendarserver.org/ns/}source'])) {
            throw new Forbidden('The {http://calendarserver.org/ns/}source property is required when creating subscriptions');
        }

        $values = [
            ':principaluri' => $principalUri,
            ':uri' => $uri,
            ':source' => $properties['{http://calendarserver.org/ns/}source']->getHref(),
            ':lastmodified' => time(),
        ];

        foreach ($this->subscriptionPropertyMap as $xmlName => $dbName) {
            if (isset($properties[$xmlName])) {

                $values[':' . $dbName] = $properties[$xmlName];
                $fieldNames[] = $dbName;
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO " . $this->calendarSubscriptionsTableName . " (" . implode(', ', $fieldNames) . ") VALUES (" . implode(', ', array_keys($values)) . ")");
        $stmt->execute($values);

        return $this->pdo->lastInsertId();
    }

    /**
     * Updates a subscription
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documenation for more info and examples.
     *
     * @param mixed $subscriptionId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @return void
     */
    function updateSubscription($subscriptionId, DAV\PropPatch $propPatch) {
        return null; // STUB
        $supportedProperties = array_keys($this->subscriptionPropertyMap);
        $supportedProperties[] = '{http://calendarserver.org/ns/}source';

        $propPatch->handle($supportedProperties, function($mutations) use ($subscriptionId) {

            $newValues = [];

            foreach ($mutations as $propertyName => $propertyValue) {

                if ($propertyName === '{http://calendarserver.org/ns/}source') {
                    $newValues['source'] = $propertyValue->getHref();
                } else {
                    $fieldName = $this->subscriptionPropertyMap[$propertyName];
                    $newValues[$fieldName] = $propertyValue;
                }
            }

            // Now we're generating the sql query.
            $valuesSql = [];
            foreach ($newValues as $fieldName => $value) {
                $valuesSql[] = $fieldName . ' = ?';
            }

            $stmt = $this->pdo->prepare("UPDATE " . $this->calendarSubscriptionsTableName . " SET " . implode(', ', $valuesSql) . ", lastmodified = ? WHERE id = ?");
            $newValues['lastmodified'] = time();
            $newValues['id'] = $subscriptionId;
            $stmt->execute(array_values($newValues));

            return true;
        });
    }

    /**
     * Deletes a subscription
     *
     * @param mixed $subscriptionId
     * @return void
     */
    function deleteSubscription($subscriptionId) {
        return null; // STUB
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarSubscriptionsTableName . ' WHERE id = ?');
        $stmt->execute([$subscriptionId]);
    }

    /**
     * Returns a single scheduling object.
     *
     * The returned array should contain the following elements:
     *   * uri - A unique basename for the object. This will be used to
     *           construct a full uri.
     *   * calendardata - The iCalendar object
     *   * lastmodified - The last modification date. Can be an int for a unix
     *                    timestamp, or a PHP DateTime object.
     *   * etag - A unique token that must change if the object changed.
     *   * size - The size of the object, in bytes.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return array
     */
    function getSchedulingObject($principalUri, $objectUri) {
        return null; // STUB
        $stmt = $this->pdo->prepare('SELECT uri, calendardata, lastmodified, etag, size FROM ' . $this->schedulingObjectTableName . ' WHERE principaluri = ? AND uri = ?');
        $stmt->execute([$principalUri, $objectUri]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row)
            return null;

        return [
            'uri' => $row['uri'],
            'calendardata' => $row['calendardata'],
            'lastmodified' => $row['lastmodified'],
            'etag' => '"' . $row['etag'] . '"',
            'size' => (int) $row['size'],
        ];
    }

    /**
     * Returns all scheduling objects for the inbox collection.
     *
     * These objects should be returned as an array. Every item in the array
     * should follow the same structure as returned from getSchedulingObject.
     *
     * The main difference is that 'calendardata' is optional.
     *
     * @param string $principalUri
     * @return array
     */
    function getSchedulingObjects($principalUri) {
        return null; // STUB
        $stmt = $this->pdo->prepare('SELECT id, calendardata, uri, lastmodified, etag, size FROM ' . $this->schedulingObjectTableName . ' WHERE principaluri = ?');
        $stmt->execute([$principalUri]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'calendardata' => $row['calendardata'],
                'uri' => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag' => '"' . $row['etag'] . '"',
                'size' => (int) $row['size'],
            ];
        }

        return $result;
    }

    /**
     * Deletes a scheduling object
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return void
     */
    function deleteSchedulingObject($principalUri, $objectUri) {
        return null; // STUB
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->schedulingObjectTableName . ' WHERE principaluri = ? AND uri = ?');
        $stmt->execute([$principalUri, $objectUri]);
    }

    /**
     * Creates a new scheduling object. This should land in a users' inbox.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @param string $objectData
     * @return void
     */
    function createSchedulingObject($principalUri, $objectUri, $objectData) {
        return null; // STUB
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->schedulingObjectTableName . ' (principaluri, calendardata, uri, lastmodified, etag, size) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$principalUri, $objectData, $objectUri, time(), md5($objectData), strlen($objectData)]);
    }

}
