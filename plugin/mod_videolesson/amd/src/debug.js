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
 * Debug utility for videolesson plugin
 *
 * @module     mod_videolesson/debug
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Debug flag - set to true to enable console logging
 * Can be controlled via URL parameter: ?videolesson_debug=1
 */
let debugEnabled = false;

// Check URL parameter for debug flag
if (typeof window !== 'undefined') {
    const urlParams = new URLSearchParams(window.location.search);
    debugEnabled = urlParams.get('videolesson_debug') === '1' || urlParams.get('videolesson_debug') === 'true';
}

/**
 * Log a debug message
 * @param {string} message - The message to log
 * @param {*} data - Optional data to log
 */
export const log = (message, data = null) => {
    if (debugEnabled) {
        // eslint-disable-next-line no-console
        console.log(`[videolesson] ${message}`, data || '');
    }
};

/**
 * Log an error message
 * @param {string} message - The error message to log
 * @param {Error|*} error - Optional error object or data
 */
export const error = (message, error = null) => {
    if (debugEnabled) {
        // eslint-disable-next-line no-console
        console.error(`[videolesson] ${message}`, error || '');
    }
};

/**
 * Log a warning message
 * @param {string} message - The warning message to log
 * @param {*} data - Optional data to log
 */
export const warn = (message, data = null) => {
    if (debugEnabled) {
        // eslint-disable-next-line no-console
        console.warn(`[videolesson] ${message}`, data || '');
    }
};

/**
 * Enable or disable debug logging
 * @param {boolean} enabled - Whether to enable debug logging
 */
export const setDebugEnabled = (enabled) => {
    debugEnabled = enabled;
};

/**
 * Check if debug logging is enabled
 * @returns {boolean} True if debug logging is enabled
 */
export const isDebugEnabled = () => {
    return debugEnabled;
};

