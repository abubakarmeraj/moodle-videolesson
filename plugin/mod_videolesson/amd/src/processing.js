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
 * Processing functionality for videolesson plugin
 *
 * @module     mod_videolesson/processing
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from "core/notification";
import * as Toast from 'core/toast';

/** @type {string|null} Gallery content hash being polled. */
let contenthash = null;
/** @type {ReturnType<typeof setInterval>|null} Polling timer handle. */
let interval = null;
/** @type {boolean} Whether the ready notification has already been shown. */
let notified = false;
/** @type {number|null} Course module id for the videocheck web service. */
let cmid = null;

const checkStatus = () => {
    Ajax.call([{
        methodname: 'mod_videolesson_videocheck',
        args: { contenthash: contenthash, cmid: cmid },
        done: function (response) {
            if (response.status && !notified){
                notified = true;
                Toast.add(response.message, { type: response.type});
                window.location.reload(true);
                clearInterval(interval);
            }
        },
        fail: Notification.exception
    }]);
};

export const init = (params) => {
    contenthash = params.contenthash;
    cmid = params.cmid;
    checkStatus();
    interval = setInterval(checkStatus, 10000);
};
