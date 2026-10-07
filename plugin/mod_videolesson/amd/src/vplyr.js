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
 * Plyr player functionality for videolesson plugin
 *
 * @module     mod_videolesson/vplyr
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import * as Toast from 'core/toast';
import {get_string as getString} from 'core/str';
import {addSubtitleTracks, initializePlayer } from 'mod_videolesson/script';
import * as Debug from 'mod_videolesson/debug';
import {
    BROWSERS_MAP,
    CHART_UPDATE_DEBOUNCE,
    MAX_RANGE_HISTORY,
    OS_MAP,
    PLATFORMS_MAP,
    SEND_INTERVAL
} from 'mod_videolesson/constants';

let video,
    mediaElement,
    videoPlayer,
    browserData,
    videoData,
    tracking = true,
    percentageEl,
    playerContainer,
    playerPlaceholder,
    completiondetails,
    previousTime = 0,
    lastSendTime = 0,
    seekStart = null,
    playbackRanges = [],
    existingRanges = [],
    fromSeek = false,
    fromResume = false,
    isPlaying = false,
    visibilityChangeHandler = null,
    plyrEventHandlers = [],
    chartUpdateTimeout = null,
    lastChartDataHash = null,
    sendDataRetryCount = 0,
    sendDataQueue = [],
    storedInitParams = null; // eslint-disable-line no-unused-vars

/**
 * Lightweight UA parsing for watch analytics.
 *
 * @returns {{platform: {type: string}, browser: {name: string, version: string}, os: {name: string}}}
 */
const parseClientInfo = () => {
    const ua = navigator.userAgent || '';

    let platformtype = PLATFORMS_MAP.desktop;
    if (/bot|spider|crawl|slurp|mediapartners|Googlebot|bingbot/i.test(ua)) {
        platformtype = PLATFORMS_MAP.bot;
    } else if (/SmartTV|Smart-TV|GoogleTV|AppleTV|TV Safari|HbbTV|NetCast|webOS|Tizen/i.test(ua)) {
        platformtype = PLATFORMS_MAP.tv;
    } else if (/iPad|Tablet|PlayBook|Silk/i.test(ua) || (navigator.maxTouchPoints > 1 && /Macintosh/i.test(ua))) {
        platformtype = PLATFORMS_MAP.tablet;
    } else if (/Mobile|Android|iPhone|iPod|IEMobile|Opera Mini/i.test(ua)) {
        platformtype = PLATFORMS_MAP.mobile;
    }

    let osname = OS_MAP.unknown;
    if (/Windows NT/i.test(ua)) {
        osname = OS_MAP.windows;
    } else if (/CrOS/i.test(ua)) {
        osname = OS_MAP.chromeos;
    } else if (/Android/i.test(ua)) {
        osname = OS_MAP.android;
    } else if (/iPhone|iPad|iPod/i.test(ua)) {
        osname = OS_MAP.ios;
    } else if (/Mac OS X/i.test(ua)) {
        osname = OS_MAP.macos;
    } else if (/Linux/i.test(ua)) {
        osname = OS_MAP.linux;
    }

    let browsername = BROWSERS_MAP.unknown;
    let browserversion = '';
    const edgematch = ua.match(/Edg(?:e|A|iOS)?\/(\d+[\d.]*)/);
    const chromematch = ua.match(/(?:Chrome|CriOS)\/(\d+[\d.]*)/);
    const firefoxmatch = ua.match(/(?:Firefox|FxiOS)\/(\d+[\d.]*)/);
    const operamatch = ua.match(/(?:OPR|Opera)\/(\d+[\d.]*)/);
    const safarimatch = ua.match(/Version\/(\d+[\d.]*)/);

    if (edgematch) {
        browsername = BROWSERS_MAP.edge;
        browserversion = edgematch[1];
    } else if (operamatch) {
        browsername = BROWSERS_MAP.opera;
        browserversion = operamatch[1];
    } else if (chromematch && !/Edg/i.test(ua)) {
        browsername = BROWSERS_MAP.chrome;
        browserversion = chromematch[1];
    } else if (firefoxmatch) {
        browsername = BROWSERS_MAP.firefox;
        browserversion = firefoxmatch[1];
    } else if (/Safari/i.test(ua) && safarimatch && !chromematch) {
        browsername = BROWSERS_MAP.safari;
        browserversion = safarimatch[1];
    }

    return {
        platform: {type: platformtype},
        browser: {name: browsername, version: browserversion},
        os: {name: osname},
    };
};

/**
 * Merge overlapping ranges into non-overlapping ranges
 * @param {Array<Array<number>>} ranges - Array of range pairs [start, end]
 * @returns {Array<Array<number>>} Merged non-overlapping ranges
 */
const mergeRanges = (ranges) => {
    if (!ranges || !ranges.length) {
        return [];
    }
    const sorted = ranges.slice().sort((a, b) => a[0] - b[0]);
    const merged = [sorted[0].slice()];
    for (let i = 1; i < sorted.length; i++) {
        const current = sorted[i];
        const last = merged[merged.length - 1];
        if (current[0] <= last[1]) {
            last[1] = Math.max(last[1], current[1]);
        } else {
            merged.push(current.slice());
        }
    }
    return merged;
};

/**
 * Add a playback range to track watched segments
 * @param {number} start - Start time in seconds
 * @param {number} end - End time in seconds
 */
const addPlaybackRange = (start, end) => {
    // Validate inputs
    if (!videoData || !videoPlayer) {
        return;
    }
    if (Number.isNaN(start) || Number.isNaN(end) || start < 0 || end < 0) {
        return;
    }

    let rangeStart = Math.min(start, end);
    let rangeEnd = Math.max(start, end);
    if (rangeEnd <= rangeStart) {
        return;
    }

    const duration = videoData.duration || videoPlayer?.duration || 0;
    if (duration > 0) {
        rangeStart = Math.max(0, Math.min(rangeStart, duration));
        rangeEnd = Math.max(0, Math.min(rangeEnd, duration));
        if (rangeEnd <= rangeStart) {
            return;
        }
    }

    playbackRanges.push([rangeStart, rangeEnd]);
    playbackRanges = mergeRanges(playbackRanges);

    // Limit range history to prevent memory issues
    if (playbackRanges.length > MAX_RANGE_HISTORY) {
        // Keep only the most recent ranges
        playbackRanges = playbackRanges.slice(-MAX_RANGE_HISTORY);
    }
};

/**
 * Copy TimeRanges object to array format
 * @param {TimeRanges} timeRanges - Browser TimeRanges object
 * @returns {Array<Array<number>>} Array of range pairs [start, end]
 */
const copyRanges = (timeRanges) => {
    if (!timeRanges || timeRanges.length === 0) {
        return [];
    }
    const copy = [];
    for (let i = 0; i < timeRanges.length; i++) {
        copy.push([timeRanges.start(i), timeRanges.end(i)]);
    }
    return copy;
};

/**
 * Get a snapshot of current playback ranges
 * @returns {Array<Array<number>>} Array of range pairs [start, end]
 */
const getPlaybackRangesSnapshot = () => playbackRanges.map((range) => range.slice());

const initializeChart = () => {
    if (!tracking) {
        return false;
    }

    const initChart = () => {
        if (!video) {
            return;
        }

        let watched = [];
        try {
            watched = JSON.parse(video.dataset?.watchdata || '[]');
        } catch (error) {
            watched = [];
        }
        if (watched.length !== 0) {
            existingRanges = [watched.slice()];
            updateChart(existingRanges, []);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initChart);
    } else {
        initChart();
    }
};

/**
 * Debounced chart update handler to prevent excessive updates
 */
const handleTimeUpdate = () => {
    // Clear existing timeout
    if (chartUpdateTimeout) {
        clearTimeout(chartUpdateTimeout);
    }

    // Debounce chart updates
    chartUpdateTimeout = setTimeout(() => {
        let currentRanges;

        // Use native video.played API if available (more accurate, handles seeks automatically)
        if (mediaElement && mediaElement.played && mediaElement.played.length > 0) {
            currentRanges = copyRanges(mediaElement.played);
        } else {
            // Fallback to manual tracking for embedded videos (YouTube/Vimeo)
            currentRanges = getPlaybackRangesSnapshot();
        }

        updateChart([currentRanges], existingRanges);
        chartUpdateTimeout = null;
    }, CHART_UPDATE_DEBOUNCE);
};

/**
 * Update chart with change detection to avoid unnecessary DOM manipulation
 * @param {Array} data - New range data
 * @param {Array} existingRanges - Existing ranges for comparison
 */
const updateChart = (data, existingRanges) => {
    if (!videoData) {
        return;
    }

    // Use normalized duration for consistency
    const vduration = getNormalizedDuration();

    if (!vduration) {
        return;
    }

    // Create hash of current data to detect changes
    const dataHash = JSON.stringify(data) + JSON.stringify(existingRanges);
    if (dataHash === lastChartDataHash) {
        updateProgressOnly();
        return;
    }
    lastChartDataHash = dataHash;

    // Calculate watchduration from current ranges
    // Use native video.played API if available, otherwise use manual tracking
    let currentRanges;
    if (mediaElement && mediaElement.played && mediaElement.played.length > 0) {
        currentRanges = copyRanges(mediaElement.played);
    } else {
        currentRanges = getPlaybackRangesSnapshot();
    }

    videoData.watchduration = calculateProgress(vduration, [currentRanges], false);
    videoData.ranges = [currentRanges];

    Debug.log('Watchduration calculated', {
        ranges: currentRanges,
        watchduration: videoData.watchduration,
        duration: vduration,
        usingNativeAPI: Boolean(mediaElement && mediaElement.played)
    });

    updateProgressOnly();
};

/**
 * Normalize duration value - use the most accurate source available
 * @returns {number} Normalized duration in seconds
 */
const getNormalizedDuration = () => {
    // Priority: player duration > videoData duration > video element duration
    return videoPlayer?.duration || videoData?.duration || video?.duration || 0;
};

/**
 * Check if current time is at or very close to the end (accounting for floating point precision)
 * @param {number} currentTime - Current playback time
 * @param {number} duration - Video duration
 * @returns {boolean} True if at or past the end
 */
const isAtEnd = (currentTime, duration) => {
    if (!duration || duration <= 0) {
        return false;
    }
    // Use epsilon (0.1 seconds) to account for floating point precision and API discrepancies
    const EPSILON = 0.1;
    return currentTime >= duration - EPSILON;
};

/**
 * Update progress percentage without rebuilding chart
 */
const updateProgressOnly = () => {
    if (!videoData || !video) {
        return;
    }

    const normalizedDuration = getNormalizedDuration();
    if (!normalizedDuration) {
        return;
    }

    // Calculate progress from furthest point reached
    const furthestPoint = Math.max(
        videoData.max || 0, // Server-provided max from all sessions
        parseFloat(video?.dataset?.maxwatched || 0) // Current session's furthest point
    );

    // Use isAtEnd check for consistent end detection
    if (isAtEnd(furthestPoint, normalizedDuration)) {
        videoData.totalprogess = 100;
        videoData.max = normalizedDuration;
        if (percentageEl) {
            percentageEl.innerText = '100%';
        }
        return;
    }

    const progress = calculateProgressFromFurthest(furthestPoint, normalizedDuration);
    videoData.totalprogess = progress;
    videoData.max = furthestPoint; // Update max with current furthest point

    if (percentageEl) {
        percentageEl.innerText = `${progress}%`;
    }
};

/**
 * Calculate watched seconds from nested ranges using mathematical approach
 * Optimized to avoid creating large arrays for long videos
 * @param {number} end - Total duration in seconds
 * @param {Array<Array<Array<number>>>} nestedRanges - Nested array of watched ranges
 * @param {boolean} percentage - Whether to return percentage or seconds
 * @returns {string|number} Progress percentage or watched seconds
 */
const calculateProgress = (end, nestedRanges, percentage = true) => {
    if (!end || end <= 0 || !nestedRanges || nestedRanges.length === 0) {
        return percentage ? '0.00' : 0;
    }

    // Merge all ranges from all nested arrays into a single flat array
    const allRanges = [];
    nestedRanges.forEach(subRanges => {
        if (Array.isArray(subRanges)) {
            subRanges.forEach(range => {
                if (Array.isArray(range) && range.length >= 2) {
                    // Allow 0-based ranges (e.g., 0-5 seconds is valid)
                    const rangeStart = Math.max(0, Math.round(range[0]));
                    const rangeEnd = Math.min(end, Math.round(range[1]));
                    if (rangeEnd > rangeStart) {
                        allRanges.push([rangeStart, rangeEnd]);
                    }
                }
            });
        }
    });

    if (allRanges.length === 0) {
        return percentage ? '0.00' : 0;
    }

    // Merge overlapping ranges
    const mergedRanges = mergeRanges(allRanges);

    // Calculate total watched seconds by summing range lengths (exclusive end)
    let watched = 0;
    mergedRanges.forEach(range => {
        watched += (range[1] - range[0]);
    });

    // Ensure watched doesn't exceed total duration
    watched = Math.min(watched, end);

    if (!percentage) {
        return watched;
    }

    const progress = (watched * 100) / end;
    return progress.toFixed(2);
};

/**
 * Calculate progress percentage from furthest point reached
 * @param {number} furthestPoint - Furthest point reached in seconds
 * @param {number} totalDuration - Total video duration in seconds
 * @returns {number} Progress percentage (0-100)
 */
const calculateProgressFromFurthest = (furthestPoint, totalDuration) => {
    if (!totalDuration || totalDuration <= 0 || furthestPoint < 0) {
        return 0;
    }

    // Use epsilon-based comparison instead of percentage threshold
    // This is more accurate for videos of all lengths
    const EPSILON = 0.1; // 0.1 second tolerance for floating point precision
    if (furthestPoint >= totalDuration - EPSILON) {
        return 100;
    }

    const progress = Math.min(100, (furthestPoint / totalDuration) * 100);
    return parseFloat(progress.toFixed(2));
};

/**
 * Send tracking data to server with exponential backoff on errors
 * @param {Object} data - Data to send
 * @param {boolean} retry - Whether this is a retry attempt
 */
const sendDataToServer = (data, retry = false) => {
    if (!tracking) {
        return false;
    }

    // Recalculate watchduration from current playback ranges before sending
    if (videoData && videoData.duration) {
        let currentRanges;

        // Use native video.played API if available (more accurate, handles seeks automatically)
        if (mediaElement && mediaElement.played && mediaElement.played.length > 0) {
            currentRanges = copyRanges(mediaElement.played);
        } else if (playbackRanges.length > 0) {
            // Fallback to manual tracking for embedded videos (YouTube/Vimeo)
            currentRanges = getPlaybackRangesSnapshot();
        }

        if (currentRanges && currentRanges.length > 0) {
            data.watchduration = calculateProgress(videoData.duration, [currentRanges], false);
            data.ranges = [currentRanges];
        }
    }

    // Queue data if there's an ongoing request
    if (sendDataQueue.length > 0 && !retry) {
        sendDataQueue.push(data);
        return;
    }

    Ajax.call([{
        methodname: 'mod_videolesson_monitor',
        args: { data: JSON.stringify(data) },
        done: (response) => {
            // Reset retry count on success
            sendDataRetryCount = 0;

            // Process queued data
            if (sendDataQueue.length > 0) {
                const nextData = sendDataQueue.shift();
                sendDataToServer(nextData, false);
            }

            if (!videoData.notified) {
                if (response.notify) {
                    videoData.notified = true;
                    Toast.add(response.notify.message, { type: response.notify.type});
                    const badge = document.getElementById('video-progress-requirement');
                    if (badge) {
                        badge.classList.remove("d-none");
                    }
                    if (response.activity_info && completiondetails) {
                        completiondetails.innerHTML = response.activity_info;
                    }
                }
            }
        },
        fail: (error) => {
            sendDataRetryCount++;
            const maxRetries = 3;
            const baseDelay = 1000; // 1 second

            if (sendDataRetryCount <= maxRetries) {
                // Exponential backoff: 1s, 2s, 4s
                const delay = baseDelay * Math.pow(2, sendDataRetryCount - 1);
                Debug.warn(`Server call failed, retrying in ${delay}ms (attempt ${sendDataRetryCount}/${maxRetries})`, error);

                setTimeout(() => {
                    sendDataToServer(data, true);
                }, delay);
            } else {
                Debug.error('Server call failed after max retries', error);
                sendDataRetryCount = 0;
                sendDataQueue = [];
            }
        }
    }]);
};

/**
 * Retrieve media duration from player
 * Gets duration for external/embed sources
 */
const mediaData = async () => {
    if (!videoData || !videoPlayer) {
        return;
    }
    if (!videoData.duration) {
        if (videoPlayer.duration) {
            videoData.duration = videoPlayer.duration;
        } else if (videoPlayer.embed && typeof videoPlayer.embed.getDuration === 'function') {
            try {
                const duration = videoPlayer.embed.getDuration();
                // Handle Promise if getDuration returns one (e.g., Vimeo)
                videoData.duration = duration instanceof Promise ? await duration : duration;
            } catch (error) {
                Debug.warn('Error getting embed duration', error);
            }
        }
    }
};

/**
 * Show resume dialog if user has a saved position
 * @returns {Promise<Object>} Modal instance or null
 */
const resumeDialog = async() => {
    if (!videoData || !videoData.leftOff) {
        return null;
    }
    if (videoData.notified) {
        return null;
    }
    if (videoData.leftOff === videoData.duration) {
        return null;
    }

    try {
        const modal = await ModalSaveCancel.create({
            title: getString('player:resume:modal:title', 'mod_videolesson'),
            body: getString('player:resume:modal:body', 'mod_videolesson'),
            buttons: {
                cancel: getString('player:resume:modal:no', 'mod_videolesson'),
                save: getString('player:resume:modal:yes', 'mod_videolesson')
            },
            removeOnClose: true,
        });

        modal.getRoot().on(ModalEvents.save, () => {
            handleResume();
        });

        modal.show();
        return modal;
    } catch (error) {
        Debug.error('Error creating resume dialog', error);
        return null;
    }
};

/**
 * Handle resume action - seek to saved position and play
 */
const handleResume = () => {
    if (!videoData || !videoPlayer) {
        return;
    }

    videoData.playbackEvents.push({
        event_type: 'resume',
        timestamp: new Date().toISOString(),
        position: videoData.leftOff,
    });

    try {
        if (playerContainer) {
            playerContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        if (videoPlayer && videoData.leftOff >= 0) {
            // Mark as resume operation to prevent adding incorrect ranges during seek
            fromResume = true;
            // Update previousTime to resume position BEFORE seeking to prevent range tracking
            previousTime = videoData.leftOff;
            videoPlayer.currentTime = videoData.leftOff;
            videoPlayer.play().catch(error => {
                Debug.error('Error playing video on resume', error);
            });
        }
    } catch (error) {
        Debug.error('Error handling resume', error);
    }
};

/**
 * Set up ready event handler
 */
const setupReadyHandler = () => {
    const readyHandler = async () => {
        Debug.log('Player ready event fired');
        await mediaData();
        Debug.log('Media data retrieved', {
            duration: videoData.duration,
            hasEmbed: Boolean(videoPlayer.embed)
        });
        sendDataToServer(videoData);
    };
    videoPlayer.on('ready', readyHandler);
    plyrEventHandlers.push({event: 'ready', handler: readyHandler});
};

/**
 * Set up playback event handlers (play, pause, ended)
 */
const setupPlaybackHandlers = () => {
    const playHandler = () => {
        Debug.log('Play event fired', {
            currentTime: videoPlayer.currentTime,
            duration: videoPlayer.duration,
            isEmbed: Boolean(videoPlayer.embed),
            fromResume: fromResume
        });
        isPlaying = true;
        videoData.playbackEvents.push({
            event_type: 'play',
            timestamp: new Date().toISOString(),
            position: videoPlayer.currentTime,
        });

        // Only update previousTime if not resuming (resume already set it correctly)
        if (!fromResume) {
            previousTime = videoPlayer.currentTime || 0;
        }

        if (fromSeek) {
            fromSeek = false;
            return;
        }
        sendDataToServer(videoData);
    };
    videoPlayer.on('play', playHandler);
    plyrEventHandlers.push({event: 'play', handler: playHandler});

    const pauseHandler = () => {
        Debug.log('Pause event fired', {
            currentTime: videoPlayer.currentTime
        });
        isPlaying = false;
        if (fromSeek) {
            fromSeek = false;
            return;
        }
        videoData.playbackEvents.push({
            event_type: 'pause',
            timestamp: new Date().toISOString(),
            position: videoPlayer.currentTime,
        });
        sendDataToServer(videoData);
    };
    videoPlayer.on('pause', pauseHandler);
    plyrEventHandlers.push({event: 'pause', handler: pauseHandler});

    const endedHandler = () => {
        Debug.log('Ended event fired', {
            currentTime: videoPlayer.currentTime,
            duration: videoPlayer.duration,
            videoDataDuration: videoData.duration
        });
        isPlaying = false;
        lastSendTime = 0;

        // Get normalized duration - use the most accurate source available
        const normalizedDuration = getNormalizedDuration();

        // When video ends, it's definitively 100% complete
        // This is the most reliable indicator that the video finished
        if (normalizedDuration > 0 && video) {
            video.dataset.maxwatched = normalizedDuration;
            videoData.max = normalizedDuration;
            videoData.totalprogess = 100;

            // Update progress display
            if (percentageEl) {
                percentageEl.innerText = '100%';
            }
        }

        videoData.playbackEvents.push({
            event_type: 'end',
            timestamp: new Date().toISOString(),
            position: normalizedDuration, // Use normalized duration instead of currentTime
        });
        sendDataToServer(videoData);
    };
    videoPlayer.on('ended', endedHandler);
    plyrEventHandlers.push({event: 'ended', handler: endedHandler});
};

/**
 * Set up seek event handlers (seeking, seeked)
 */
const setupSeekHandlers = () => {
    const seekingHandler = () => {
        Debug.log('Seeking event fired', {
            currentTime: videoPlayer.currentTime,
            previousTime: previousTime,
            fromResume: fromResume
        });
        isPlaying = false;
        if (seekStart === null) {
            seekStart = previousTime;
        }
        fromSeek = true;
        // If this is a resume operation, don't track the seek as a watched range
        // The fromResume flag will be cleared in seeked handler
    };
    videoPlayer.on('seeking', seekingHandler);
    plyrEventHandlers.push({event: 'seeking', handler: seekingHandler});

    const seekedHandler = () => {
        Debug.log('Seeked event fired', {
            currentTime: videoPlayer.currentTime,
            seekStart: seekStart,
            fromResume: fromResume
        });
        const seekPosition = videoPlayer.currentTime || 0;
        videoData.seekEvents.push({
            timestamp: new Date().toISOString(),
            start: seekStart,
            position: seekPosition,
            progress: seekPosition - seekStart,
        });

        // Update furthest point if seeked to a new maximum
        if (video && seekPosition > parseFloat(video.dataset.maxwatched || 0)) {
            video.dataset.maxwatched = seekPosition;
            // Update progress only (no need to rebuild chart)
            updateProgressOnly();
        }

        // CRITICAL: Update previousTime BEFORE clearing flags
        // This prevents timeupdate from adding a range from seekStart to seekPosition
        previousTime = seekPosition;
        fromSeek = false;
        // Clear resume flag after seek completes
        fromResume = false;
        seekStart = null;
        isPlaying = !videoPlayer.paused;
    };
    videoPlayer.on('seeked', seekedHandler);
    plyrEventHandlers.push({event: 'seeked', handler: seekedHandler});
};

/**
 * Set up tracking event handlers (timeupdate, volumechange, qualitychange)
 */
const setupTrackingHandlers = () => {
    const timeUpdateHandler = () => {
        const newTime = videoPlayer.currentTime || 0;

        if (Math.floor(newTime) % 5 === 0 && newTime - Math.floor(newTime) < 0.1) {
            Debug.log('Timeupdate', {
                currentTime: newTime,
                duration: videoPlayer.duration,
                isPlaying: isPlaying,
                fromSeek: fromSeek,
                previousTime: previousTime,
                isEmbed: Boolean(videoPlayer.embed),
                hasMediaElement: Boolean(mediaElement)
            });
        }

        // Only manually track ranges for embedded videos (YouTube/Vimeo)
        // For native video elements, use video.played API instead (handles seeks automatically)
        // Don't track ranges during seeks or resume operations
        if (isPlaying && !fromSeek && !fromResume && !mediaElement) {
            addPlaybackRange(previousTime, newTime);
        }

        handleTimeUpdate();

        if (fromSeek) {
            fromSeek = false;
            previousTime = newTime; // Update previousTime immediately after seek to prevent incorrect ranges
            return;
        }

        // Clear resume flag if it's still set (shouldn't happen, but safety check)
        if (fromResume) {
            fromResume = false;
        }

        if (videoPlayer.playing && video) {
            const currentMax = parseFloat(video.dataset.maxwatched || 0);
            const normalizedDuration = getNormalizedDuration();

            // Use isAtEnd helper for consistent end detection
            // This handles floating point precision and API discrepancies
            if (normalizedDuration > 0 && isAtEnd(newTime, normalizedDuration)) {
                // At or past the end - force to 100%
                video.dataset.maxwatched = normalizedDuration;
                videoData.max = normalizedDuration;
                videoData.totalprogess = 100;
                if (percentageEl) {
                    percentageEl.innerText = '100%';
                }
            } else if (newTime > currentMax) {
                video.dataset.maxwatched = newTime;
                // Update progress only (no need to rebuild chart)
                updateProgressOnly();
            }
        }

        previousTime = newTime;
        if (newTime - lastSendTime >= SEND_INTERVAL) {
            Debug.log('Sending data to server (interval)', {
                currentTime: newTime,
                lastSendTime: lastSendTime,
                progress: videoData.totalprogess
            });
            lastSendTime = newTime;
            sendDataToServer(videoData);
        }
    };
    videoPlayer.on('timeupdate', timeUpdateHandler);
    plyrEventHandlers.push({event: 'timeupdate', handler: timeUpdateHandler});

    const volumeChangeHandler = () => {
        videoData.volumeChanges.push({
            timestamp: new Date().toISOString(),
            volume_level: Math.round(videoPlayer.volume * 100),
            position: videoPlayer.currentTime,
        });
    };
    videoPlayer.on('volumechange', volumeChangeHandler);
    plyrEventHandlers.push({event: 'volumechange', handler: volumeChangeHandler});

    const qualityChangeHandler = (event) => {
        videoData.qualityChanges.push({
            timestamp: new Date().toISOString(),
            quality: event.detail.quality,
            position: videoPlayer.currentTime,
        });
    };
    videoPlayer.on('qualitychange', qualityChangeHandler);
    plyrEventHandlers.push({event: 'qualitychange', handler: qualityChangeHandler});
};

/**
 * Set up error event handler
 */
const setupErrorHandler = () => {
    const errorHandler = (event) => {
        Debug.error('Player error event', event);
        const error = videoPlayer.error || event?.detail || null;
        if (error) {
            const code = error.code ?? error.detail ?? 'unknown';
            const message = error.message ?? error.note ?? 'Playback error';
            videoData.errors.push({
                timestamp: new Date().toISOString(),
                error_type: code,
                error_description: message,
            });
        }
    };
    videoPlayer.on('error', errorHandler);
    plyrEventHandlers.push({event: 'error', handler: errorHandler});
};

/**
 * Initialize tracking components (chart and visibility handlers)
 * @param {Object} params - Initialization parameters
 */
const initializeTracking = (params) => {
    initializeChart();
    resumeDialog();

    // Initialize furthest point from server (all sessions)
    if (params.max && video) {
        const serverMax = parseFloat(params.max);
        const currentMax = parseFloat(video.dataset.maxwatched || 0);
        // Use the maximum of server max and current max
        video.dataset.maxwatched = Math.max(serverMax, currentMax);
        videoData.max = Math.max(serverMax, currentMax);
    } else if (video) {
        videoData.max = parseFloat(video.dataset.maxwatched || 0);
    }

    // Set up visibility change handler after everything is initialized
    visibilityChangeHandler = function() {
        if (videoData && videoPlayer) {
            Debug.log('Visibility change', {
                active: !document.hidden,
                currentTime: videoPlayer.currentTime
            });
            videoData.visibility.push({
                timestamp: new Date().toISOString(),
                active: !document.hidden,
                position: videoPlayer.currentTime,
            });
        }
    };
    document.addEventListener('visibilitychange', visibilityChangeHandler);
    Debug.log('Visibility change handler attached');
};

/**
 * Set up all Plyr event listeners
 */
const setupEventHandlers = () => {
    Debug.log('Setting up event listeners');
    setupReadyHandler();
    setupPlaybackHandlers();
    setupSeekHandlers();
    setupTrackingHandlers();
    setupErrorHandler();
};

/**
 * Initialize video player tracking
 * @param {Object} params - Initialization parameters
 * @param {string} params.provider - Video provider type
 * @param {string} params.externaltype - External video type (youtube/vimeo)
 * @param {boolean} params.tracking - Whether tracking is enabled
 * @param {number} params.duration - Video duration
 * @param {number} params.max - Furthest point reached from all sessions
 * @param {Array} params.subtitles - Subtitle tracks
 * @param {Object} params.video - Video element
 */
export const init = (params) => {
    // Store params for potential re-initialization after bfcache restore
    storedInitParams = params;

    Debug.log('vplyr.init called', {
        params: params
    });

    tracking = params.tracking;

    // Disable tracking for unsupported embeds (no externaltype/externalvideoid)
    if (params.externaltype === null || params.externalvideoid === null) {
        // Check if it's an external source - if so and no type/id, disable tracking
        if (params.source === 'external' || params.provider === 'external') {
            tracking = false;
        }
    }

    video = document.getElementById("player");
    mediaElement = video && video.tagName === 'VIDEO' ? video : null;
    browserData = parseClientInfo();
    params.video = document.getElementById("player");

    // Read large data from data attributes instead of params to reduce js_call_amd payload
    const sourceurl = video?.dataset?.source || ''; // eslint-disable-line no-unused-vars
    const sourcedata = video?.dataset?.sourcedata || '';
    const subtitlesJson = video?.dataset?.subtitles || '[]';
    const title = video?.dataset?.title || '';

    let subtitles = [];
    try {
        subtitles = JSON.parse(subtitlesJson);
    } catch (e) {
        Debug.warn('Error parsing subtitles from data attribute', e);
    }

    // Initialize DOM element references
    percentageEl = document.getElementById('video-progress-total-percentage');
    playerContainer = document.getElementById('player-container-div');
    playerPlaceholder = document.getElementById('player-placeholder');
    completiondetails = document.querySelector('[data-region="activity-information"].activity-information');

    Debug.log('Video element found', {
        tagName: video?.tagName,
        className: video?.className,
        isVideo: Boolean(mediaElement),
        isEmbed: video?.classList?.contains('plyr__video-embed')
    });

    const tracks = subtitles.map((item, index) => ({
        kind: 'subtitles',
        language: item.language,
        code: item.code,
        url: item.url,
        ...(index === 0 && { "default": true }) // Set the first one as default
    }));

    if (mediaElement) {
        addSubtitleTracks(mediaElement, tracks);
    }

    videoData = {
        videoid: params.videoid,
        videotitle: title, // Use from data attribute
        source: params.provider,
        sourcedata: sourcedata, // Use from data attribute
        session: params.session,
        userid: params.userid,
        cm: params.cm,
        timestamp: new Date().toISOString(),
        duration: params.duration,
        leftOff: params.leftOff,
        max: params.max,
        totalprogess: params.progress, // Accumulated progress,
        watchduration: 0,
        ranges: [],
        playbackEvents: [],
        volumeChanges: [],
        qualityChanges: [],
        seekEvents: [],
        visibility: [],
        errors: [],
        city: params.city,
        country: params.country,
        ip: params.ip,
        platform: browserData.platform.type,
        browser: `${browserData.browser.name}|v${browserData.browser.version}`,
        os: browserData.os.name,
        notified: params.notified,
        student: params.student,
        externaltype: params.externaltype || null,
        externalvideoid: params.externalvideoid || null
    };

    // initializePlayer reads sourceurl from video.dataset.source, so we don't need to pass it
    // The data attribute is already set in the template
    initializePlayer(params)
        .then((videoPlyr) => {
            videoPlayer = videoPlyr;
            Debug.log('Player initialized', {
                hasPlayer: Boolean(videoPlayer),
                hasEmbed: Boolean(videoPlayer.embed),
                embedProvider: videoPlayer.embed?.provider,
                duration: videoPlayer.duration
            });
        })
        .then(() => {
            if (playerContainer) {
                playerContainer.classList.remove('d-none');
            }
            if (playerPlaceholder) {
                playerPlaceholder.classList.add('d-none');
            }

            setupEventHandlers();
        })
        .then(() => {
            initializeTracking(params);

            Debug.log('Initialization complete', {
                hasPlayer: Boolean(videoPlayer),
                tracking: tracking,
                provider: videoData.source
            });
        })
        .catch((error) => {
            Debug.error('Initialization error', error);
        });
};

/**
 * Cleanup function to remove event listeners and reset state
 * Call this when the video player is being destroyed or page is unloading
 */
export const cleanup = () => {
    // Remove all Plyr event listeners
    if (videoPlayer && plyrEventHandlers.length > 0) {
        plyrEventHandlers.forEach(({event, handler}) => {
            try {
                videoPlayer.off(event, handler);
            } catch (e) {
                Debug.warn('Error removing Plyr event listener', {event, error: e});
            }
        });
        plyrEventHandlers = [];
    }

    if (videoPlayer) {
        try {
            videoPlayer.destroy();
        } catch (e) {
            Debug.warn('Error destroying player', e);
        }
        videoPlayer = null;
    }

    if (visibilityChangeHandler) {
        document.removeEventListener('visibilitychange', visibilityChangeHandler);
        visibilityChangeHandler = null;
    }

    // Clear chart update timeout
    if (chartUpdateTimeout) {
        clearTimeout(chartUpdateTimeout);
        chartUpdateTimeout = null;
    }

    // Reset all state
    playbackRanges = [];
    existingRanges = [];
    fromSeek = false;
    isPlaying = false;
    seekStart = null;
    previousTime = 0;
    lastSendTime = 0;
    lastChartDataHash = null;

    // Clear DOM references
    video = null;
    mediaElement = null;
    percentageEl = null;
    playerContainer = null;
    playerPlaceholder = null;
    completiondetails = null;
    videoData = null;

    // Clear namespaced globals
    if (typeof window !== 'undefined' && window.videolesson) {
        delete window.videolesson.player;
        delete window.videolesson.hls;
        if (Object.keys(window.videolesson).length === 0) {
            delete window.videolesson;
        }
    }
};

// Handle page lifecycle events
if (typeof window !== 'undefined') {
    let isNavigatingAway = false;

    // Handle page restoration from bfcache (back/forward navigation)
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            // Page was restored from bfcache - JavaScript state is reset
            Debug.log('Page restored from bfcache, checking player state');

            const playerElement = document.getElementById('player');
            const playerContainer = document.getElementById('player-container-div');

            // Check if player element exists but Plyr isn't initialized
            // This happens when bfcache restores DOM but JS state is lost
            if (playerElement && !videoPlayer && playerContainer && !playerContainer.classList.contains('d-none')) {
                Debug.log('Player element exists but not initialized after bfcache restore');
                // Moodle's AMD system should re-initialize, but if it doesn't, reload
                // Give Moodle a moment to re-initialize modules
                setTimeout(() => {
                    if (!videoPlayer) {
                        Debug.log('Player still not initialized after delay, reloading page');
                        window.location.reload();
                    }
                }, 500);
            } else if (playerElement && videoPlayer) {
                // Player exists and is initialized - all good
                Debug.log('Player already initialized after bfcache restore');
            }
        }
        // Reset navigation flag when page becomes visible
        isNavigatingAway = false;
    });

    // Track when user is actually navigating away (not just clicking links)
    window.addEventListener('beforeunload', (event) => { // eslint-disable-line no-unused-vars
        // Only mark as navigating away if we're actually leaving
        // This prevents cleanup on same-page navigation or target="_blank" links
        isNavigatingAway = true;

        // Only cleanup if page is actually being hidden
        // Don't destroy on link clicks that might not navigate
        if (document.visibilityState === 'hidden') {
            cleanup();
        }
    });

    // Cleanup on actual unload as backup (for browsers that don't fire beforeunload properly)
    window.addEventListener('unload', () => {
        if (isNavigatingAway) {
            cleanup();
        }
    });
}
