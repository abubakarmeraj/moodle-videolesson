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
 * Constants for videolesson plugin
 *
 * @module     mod_videolesson/constants
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Constants for videolesson plugin
 *
 * @module mod_videolesson/constants
 */

/**
 * Interval for sending tracking data to server (in seconds)
 */
export const SEND_INTERVAL = 10;

/**
 * Timeout for YouTube API loading (in milliseconds)
 */
export const YOUTUBE_API_TIMEOUT = 5000;

/**
 * Timeout for Vimeo API loading (in milliseconds)
 */
export const VIMEO_API_TIMEOUT = 5000;

/**
 * Interval for checking Vimeo API availability (in milliseconds)
 */
export const VIMEO_API_CHECK_INTERVAL = 100;

/**
 * Timeout for HLS initialization (in milliseconds)
 */
export const HLS_INIT_TIMEOUT = 10000;

/**
 * Maximum number of playback ranges to keep in memory
 */
export const MAX_RANGE_HISTORY = 1000;

/**
 * Debounce delay for chart updates (in milliseconds)
 */
export const CHART_UPDATE_DEBOUNCE = 100;

/**
 * Seek blocking dialog timeout (in milliseconds)
 */
export const SEEK_BLOCK_TIMEOUT = 1000;

/**
 * Platform type codes stored in watch analytics (aligned with Bowser platform types).
 */
export const PLATFORMS_MAP = {
    bot: 'bot',
    desktop: 'desktop',
    mobile: 'mobile',
    tablet: 'tablet',
    tv: 'tv',
};

/**
 * Browser display names stored in watch analytics.
 */
export const BROWSERS_MAP = {
    chrome: 'Chrome',
    edge: 'Microsoft Edge',
    firefox: 'Firefox',
    opera: 'Opera',
    safari: 'Safari',
    unknown: 'Unknown',
};

/**
 * Operating system display names stored in watch analytics.
 */
export const OS_MAP = {
    android: 'Android',
    chromeos: 'Chrome OS',
    ios: 'iOS',
    linux: 'Linux',
    macos: 'macOS',
    unknown: 'Unknown',
    windows: 'Windows',
};