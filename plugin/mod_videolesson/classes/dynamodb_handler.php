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
 * AWS DynamoDB handler class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_videolesson;

/**
 * DynamoDB handler class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamodb_handler {
    /** @var string $hostingtype The license type, determining hosted or SDK-based operations */
    private $hostingtype;

    /** @var dynamodb_hosted_handler|dynamodb_sdk_handler $handler The handler instance */
    private $handler;

    /**
     * Constructor for the dynamodb_handler class.
     *
     * @throws \Exception If handler cannot be instantiated.
     */
    public function __construct() {
        $this->hostingtype = get_config('mod_videolesson', 'hosting_type');
        // Instantiate the appropriate handler based on the license type.
        $this->handler = $this->hostingtype === 'hosted'
            ? new dynamodb_hosted_handler()
            : new dynamodb_sdk_handler();
    }

    /**
     * Get transcoding status for a contenthash.
     *
     * @param string $contenthash The video content hash
     * @return array|null Status data or null if not found
     */
    public function get_status(string $contenthash): ?array {
        return $this->handler->get_status($contenthash);
    }
}
