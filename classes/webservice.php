<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Handles API calls to Zoom REST API.
 *
 * @package   mod_zoom
 * @copyright 2015 UC Regents
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/zoom/locallib.php');
require_once($CFG->dirroot.'/lib/filelib.php');
require_once($CFG->dirroot.'/mod/zoom/jwt/JWT.php');

define('API_URL', 'https://api.zoom.us/v2/');

/**
 * Web service class.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_zoom_webservice {

    /**
     * API key
     * @var string
     */
    protected $apikey;

    /**
     * API secret
     * @var string
     */
    protected $apisecret;

    /**
     * Whether to recycle licenses.
     * @var bool
     */
    protected $recyclelicenses;

    /**
     * Maximum limit of paid users
     * @var int
     */
    protected $numlicenses;

    /**
     * List of users
     * @var array
     */
    protected static $userslist;

    /**
     * The constructor for the webservice class.
     */
    public function __construct() {
        $config = get_config('mod_zoom');
        if (!empty($config->apikey)) {
            $this->apikey = $config->apikey;
        } else {
            throw new moodle_exception('errorwebservice', 'mod_zoom', '', get_string('zoomerr_apikey_missing', 'zoom'));
        }
        if (!empty($config->apisecret)) {
            $this->apisecret = $config->apisecret;
        } else {
            throw new moodle_exception('errorwebservice', 'mod_zoom', '', get_string('zoomerr_apisecret_missing', 'zoom'));
        }
        if (!empty($config->utmost)) {
            $this->recyclelicenses = $config->utmost;
        }
        if ($this->recyclelicenses) {
            if (!empty($config->licensescount)) {
                $this->numlicenses = $config->licensescount;
            } else {
                throw new moodle_exception('errorwebservice', 'mod_zoom', '', get_string('zoomerr_licensescount_missing', 'zoom'));
            }
        }
    }

    /**
     * Makes a REST call.
     *
     * @param string $url The URL to append to the API URL
     * @param array|string $data The data to attach to the call.
     * @param string $method The HTTP method to use.
     * @return stdClass The call's result in JSON format.
     * @throws moodle_exception Moodle exception is thrown for curl errors.
     */
    protected function _make_call($url, $data = array(), $method = 'get') {
        $url = API_URL . $url;
        $method = strtolower($method);
        $curl = new curl();
        $payload = array(
            'iss' => $this->apikey,
            'exp' => time() + 40
        );
        $token = \Firebase\JWT\JWT::encode($payload, $this->apisecret);
        $curl->setHeader('Authorization: Bearer ' . $token);

        if ($method != 'get') {
            $curl->setHeader('Content-Type: application/json');
            $data = is_array($data) ? json_encode($data) : $data;
        }
        $response = call_user_func_array(array($curl, $method), array($url, $data));

        if ($curl->get_errno()) {
            throw new moodle_exception('errorwebservice', 'mod_zoom', '', $curl->error);
        }

        $response = json_decode($response);

        $httpstatus = $curl->get_info()['http_code'];
        if ($httpstatus >= 400) {
            if ($response) {
                throw new moodle_exception('errorwebservice', 'mod_zoom', '', $response->message);
            } else {
                throw new moodle_exception('errorwebservice', 'mod_zoom', '', "HTTP Status $httpstatus");
            }
        }

        return $response;
    }

    /**
     * Makes a paginated REST call.
     * Makes a call like _make_call() but specifically for GETs with paginated results.
     *
     * @param string $url The URL to append to the API URL
     * @param array|string $data The data to attach to the call.
     * @param string $datatoget The name of the array of the data to get.
     * @return array The retrieved data.
     * @see _make_call()
     * @link https://zoom.github.io/api/#list-users
     */
    protected function _make_paginated_call($url, $data = array(), $datatoget) {
        $aggregatedata = array();
        $data['page_size'] = ZOOM_MAX_RECORDS_PER_CALL;

        // The $currentpage call parameter is 1-indexed.
        for ($currentpage = $numpages = 1; $currentpage <= $numpages; $currentpage++) {
            $data['page_number'] = $currentpage;
            $callresult = $this->_make_call($url, $data);
            $aggregatedata += $callresult->$datatoget;
            // Note how continually updating $numpages accomodatess for the edge case that users are added in between calls.
            $numpages = $callresult->page_count;
        }

        return $aggregatedata;
    }

    /**
     * Autocreate a user on Zoom.
     *
     * @param stdClass $user The user to create.
     * @return bool Whether the user was succesfully created.
     * @link https://zoom.github.io/api/#create-a-user
     */
    public function autocreate_user($user) {
        $url = 'users';
        $data = array('action' => 'autocreate');
        $data['user_info'] = array(
            'email' => $user->email,
            'type' => ZOOM_USER_TYPE_PRO,
            'first_name' => $user->firstname,
            'last_name' => $user->lastname,
            'password' => base64_encode(random_bytes(16))
        );

        try {
            $this->_make_call($url, $data, 'post');
        } catch (moodle_exception $error) {
            // If the user already exists, the error will contain 'User already in the account'.
            if (strpos($error->getMessage(), 'User already in the account') === true) {
                return false;
            } else {
                throw $error;
            }
        }

        return true;
    }

    /**
     * Get users list.
     *
     * @return array An array of users.
     * @link https://zoom.github.io/api/#list-users
     */
    public function list_users() {
        if (empty(self::$userslist)) {
            self::$userslist = $this->_make_paginated_call('users', null, 'users');
        }
        return self::$userslist;
    }

    /**
     * Checks whether the paid user license limit has been reached.
     *
     * Incrementally retrieves the active paid users and compares against $numlicenses.
     * @see $numlicenses
     * @return bool Whether the paid user license limit has been reached.
     */
    protected function _paid_user_limit_reached() {
        $userslist = $this->list_users();
        $numusers = 0;
        foreach ($userslist as $user) {
            if ($user->type != ZOOM_USER_TYPE_BASIC && ++$numusers >= $this->numlicenses) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the ID of the user, of all the paid users, with the oldest last login time.
     *
     * @return string|false If user is found, returns the User ID. Otherwise, returns false.
     */
    protected function _get_least_recently_active_paid_user_id() {
        $usertimes = array();
        $userslist = $this->list_users();
        foreach ($userslist as $user) {
            if ($user->type != ZOOM_USER_TYPE_BASIC && isset($user->last_login_time)) {
                $usertimes[$user->id] = strtotime($user->last_login_time);
            }
        }

        if (!empty($usertimes)) {
            return array_search(min($usertimes), $usertimes);
        }

        return false;
    }

    /**
     * Gets a user's settings.
     *
     * @param string $userid The user's ID.
     * @return stdClass The call's result in JSON format.
     * @link https://zoom.github.io/api/#retrieve-a-users-settings
     */
    public function _get_user_settings($userid) {
        return $this->_make_call('users/' . $userid . '/settings');
    }

    /**
     * Finds a user by their email.
     *
     * @param string $email The user's email.
     * @return stdClass|false If user is found, returns the User object. Otherwise, returns false.
     * @link https://zoom.github.io/api/#users
     */
    public function get_user_by_email($email) {
        global $CFG, $USER;
        $url = 'users/' . $email;
        try {
            $user = $this->_make_call($url);
        } catch (moodle_exception $error) {
            if (zoom_is_user_not_found_error($error->getMessage())) {
                return false;
            } else {
                throw $error;
            }
        }
        return $user;
    }

    /**
     * Converts a zoom object from database format to API format.
     *
     * The database and the API use different fields and formats for the same information. This function changes the
     * database fields to the appropriate API request fields.
     *
     * @param stdClass $zoom The zoom meeting to format.
     * @return array The formatted meetings for the meeting.
     * @todo Add functionality for 'alternative_hosts' => $zoom->option_alternative_hosts in $data['settings']
     * @todo Make UCLA data fields and API data fields match?
     */
    protected function _database_to_api($zoom) {
        global $CFG;

        $data = array(
            'topic' => $zoom->name,
            'settings' => array(
                'host_video' => (bool) ($zoom->option_host_video),
                'audio' => $zoom->option_audio,
                'enforce_login' => (bool) ($zoom->enforce_login)
            )
        );
        if (isset($zoom->intro)) {
            $data['agenda'] = strip_tags($zoom->intro);
        }
        if (isset($CFG->timezone) && !empty($CFG->timezone)) {
            $data['timezone'] = $CFG->timezone;
        } else {
            $data['timezone'] = date_default_timezone_get();
        }
        if (isset($zoom->password)) {
            $data['password'] = $zoom->password;
        }

        if ($zoom->webinar) {
            $data['type'] = $zoom->recurring ? ZOOM_RECURRING_WEBINAR : ZOOM_SCHEDULED_WEBINAR;
        } else {
            $data['type'] = $zoom->recurring ? ZOOM_RECURRING_MEETING : ZOOM_SCHEDULED_MEETING;
            $data['settings']['join_before_host'] = (bool) ($zoom->option_jbh);
            $data['settings']['participant_video'] = (bool) ($zoom->option_participants_video);
        }

        if ($data['type'] == ZOOM_SCHEDULED_MEETING || $data['type'] == ZOOM_SCHEDULED_WEBINAR) {
            // Convert timestamp to ISO-8601. The API seems to insist that it end with 'Z' to indicate UTC.
            $data['start_time'] = gmdate('Y-m-d\TH:i:s\Z', $zoom->start_time);
            $data['duration'] = (int) ceil($zoom->duration / 60);
        }

        return $data;
    }

    /**
     * Create a meeting/webinar on Zoom.
     * Take a $zoom object as returned from the Moodle form and respond with an object that can be saved to the database.
     *
     * @param stdClass $zoom The meeting to create.
     * @return stdClass The call response.
     */
    public function create_meeting($zoom) {
        // Checks whether we need to recycle licenses and acts accordingly.
        if ($this->recyclelicenses && $this->_make_call("users/$zoom->host_id")->type == ZOOM_USER_TYPE_BASIC) {
            if ($this->_paid_user_limit_reached()) {
                $leastrecentlyactivepaiduserid = $this->_get_least_recently_active_paid_user_id();
                // Changes least_recently_active_user to a basic user so we can use their license.
                $this->_make_call("users/$leastrecentlyactivepaiduserid", array('type' => ZOOM_USER_TYPE_BASIC), 'patch');
            }
            // Changes current user to pro so they can make a meeting.
            $this->_make_call("users/$zoom->host_id", array('type' => ZOOM_USER_TYPE_PRO), 'patch');
        }

        $url = "users/$zoom->host_id/" . ($zoom->webinar ? 'webinars' : 'meetings');
        return $this->_make_call($url, $this->_database_to_api($zoom), 'post');
    }

    /**
     * Update a meeting/webinar on Zoom.
     *
     * @param stdClass $zoom The meeting to update.
     * @return void
     */
    public function update_meeting($zoom) {
        $url = ($zoom->webinar ? 'webinars/' : 'meetings/') . $zoom->meeting_id;
        $this->_make_call($url, $this->_database_to_api($zoom), 'patch');
    }

    /**
     * Delete a meeting/webinar on Zoom.
     *
     * @param stdClass $zoom The meeting to delete.
     * @return void
     */
    public function delete_meeting($zoom) {
        global $CFG;

        $url = ($zoom->webinar ? 'webinars/' : 'meetings/') . $zoom->meeting_id;
        $this->_make_call($url, null, 'delete');
    }

    /**
     * Get a meeting/webinar's information from Zoom.
     *
     * @param stdClass $zoom The meeting to retrieve.
     * @return stdClass The meeting's information.
     */
    public function get_meeting_info($zoom) {
        $url = ($zoom->webinar ? 'webinars/' : 'meetings/') . $zoom->meeting_id;
        return $this->_make_call($url);
    }

    /**
     * Retrieve ended meetings report for a specified user and period. Handles multiple pages.
     *
     * @param int $userid Id of user of interest
     * @param string $from Start date of period in the form YYYY-MM-DD
     * @param string $to End date of period in the form YYYY-MM-DD
     * @return array The retrieved meetings.
     * @link https://zoom.github.io/api/#retrieve-meetings-report
     */
    public function get_user_report($userid, $from, $to) {
        $url = 'report/users/' . $userid . '/meetings';
        $data = array('from' => $from, 'to' => $to, 'page_size' => ZOOM_MAX_RECORDS_PER_CALL);
        return $this->_make_paginated_call($url, $data, 'meetings');
    }

    /**
     * List the UUIDs for all the webinars of a user.
     *
     * @param string $userid The user whose webinars to retrieve.
     * @return array An array of UUIDs as strings.
     * @link https://zoom.github.io/api/#list-webinars
     */
    public function list_webinar_uuids($userid) {
        $url = 'users/' . $userid . '/webinars';
        $webinars = $this->_make_paginated_call($url, null, 'webinars');
        $uuids = array();
        foreach ($webinars as $webinar) {
            $uuids[] = $webinar->uuid;
        }

        return $uuids;
    }

    /**
     * Get attendees for a particular UUID ("session") of a webinar.
     *
     * @param string $uuid The UUID of the webinar session to retrieve.
     * @return array The attendees.
     * @link https://zoom.github.io/api/#list-a-webinars-registrants
     */
    public function list_webinar_attendees($uuid) {
        $url = 'webinars/' . $uuid . '/registrants';
        return $this->_make_paginated_call($url, null, 'registrants');
    }

    /**
     * Get details about a particular webinar UUID/session.
     *
     * @param string $uuid The uuid of the webinar to retrieve.
     * @return stdClass A JSON object with the webinar's details.
     * @link https://zoom.github.io/api/#retrieve-a-webinar
     */
    public function get_metrics_webinar_detail($uuid) {
        return $this->_make_call('webinars/' . $uuid);
    }

    /**
     * Get a user's ZAK token.
     *
     * @param int $userid The user's ID.
     * @return string The user's ZAK token.
     * @link https://zoom.github.io/api/#retrieve-a-users-token
     */
    public function get_user_token($userid) {
        return $this->_make_call('users/' . $userid . '/token')->token;
    }
}
