<?php
// This file is part of Moodle - http://moodle.org/
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
 * Thin cURL subclass for presigned S3 DELETE requests.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * Why this class exists (instead of using {@see \curl::delete()} directly)
 *
 * Presigned S3 URLs already carry authentication in the query string (e.g. X-Amz-Algorithm,
 * signature parameters). S3 rejects requests that also send an HTTP Authorization header
 * with a second mechanism, with an error such as: "Only one auth mechanism allowed; only the
 * X-Amz-Algorithm query parameter, Signature query string parameter or the Authorization header
 * should be specified".
 *
 * Moodle's {@see \curl::delete()} in lib/filelib.php does the following when the third-argument
 * options omit CURLOPT_USERPWD: it sets CURLOPT_USERPWD to a default "anonymous: noreply@moodle.org"
 * (HTTP Basic), which triggers that S3 error on presigned DELETEs.
 *
 * If you pass CURLOPT_USERPWD => false to avoid the default, delete() still forwards that value
 * into curl_setopt(). libcurl may then emit Authorization: Basic Og== (empty user:password),
 * which is still a second auth mechanism and S3 rejects it. By contrast, {@see \curl::put()} has
 * explicit logic to treat false as "remove CURLOPT_USERPWD entirely" via removeopt(); delete()
 * does not implement the same behaviour.
 *
 * The actual HTTP work is implemented on {@see \curl::request()}, which applies Moodle's
 * redirect handling, security helper, and other core behaviour—but request() is protected, so
 * plugin code cannot call it on a plain \curl instance from outside the class.
 *
 * This subclass exists solely to invoke protected request() with CURLOPT_CUSTOMREQUEST = DELETE
 * and timeouts, without ever setting CURLOPT_USERPWD, so no Basic Authorization header is sent
 * alongside the presigned URL.
 */
final class hosted_presigned_curl extends \curl {
    /**
     * Perform HTTP DELETE against a presigned URL (no HTTP Basic auth).
     *
     * @param string $url Full presigned URL.
     * @return mixed Response body (same contract as {@see \curl::request()}).
     */
    public function delete_presigned_url(string $url) {
        $this->setopt([
            'connecttimeout' => 30,
            'timeout' => 120,
        ]);
        return $this->request($url, [
            'CURLOPT_CUSTOMREQUEST' => 'DELETE',
        ]);
    }
}
