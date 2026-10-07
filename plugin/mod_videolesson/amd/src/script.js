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
 * Script utilities for videolesson plugin
 *
 * @module     mod_videolesson/script
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Ajax from 'core/ajax';
import ModalCancel from 'core/modal_cancel';
import ModalEvents from 'core/modal_events';
import {get_string as getString} from 'core/str';
import * as Debug from 'mod_videolesson/debug';
import {
    HLS_INIT_TIMEOUT,
    SEEK_BLOCK_TIMEOUT,
    VIMEO_API_CHECK_INTERVAL,
    VIMEO_API_TIMEOUT,
    YOUTUBE_API_TIMEOUT,
} from 'mod_videolesson/constants';

const getTargetTime = (plyr, input) => {
    if (typeof input === "object" && (input.type === "input" || input.type === "change")) {
        const max = input.target.max;
        if (max === 0 || !max) {
            return 0;
        }
        return input.target.value / max * plyr.duration;
    } else {
        return Number(input);
    }
};

const showSeekingAlertDialog = async () => {
    const modal = await ModalCancel.create({
        title: getString('player:seeking:disabled:header', 'mod_videolesson'),
        body: getString('player:seeking:disabled:description', 'mod_videolesson'),
        buttons: {
            cancel: getString('gotit', 'mod_videolesson')
        },
        removeOnClose: true,
    });

    const cancelButton = modal.getRoot().find('[data-action="cancel"]');
    cancelButton.removeClass('btn-secondary').addClass('btn-primary');

    return new Promise((resolve) => {
        modal.getRoot().on(ModalEvents.cancel, () => {
            resolve(false);
            modal.hide();
        });

        modal.getRoot().on(ModalEvents.hidden, () => {
            modal.destroy();
        });

        modal.show();
    });
};

export const initializePlayer = (params) => {
    return new Promise((resolve, reject) => {

        let videoPlyr;
        let isSeekBlocked = false; // Track if seek is currently blocked to prevent multiple dialogs

        const playerDefaultOptions = {
            listeners: {
                // disallow moving forward
                seek: function customSeekBehavior(e) {
                    if (params.disableseek && params.student && params.video) {
                        const current_time = videoPlyr.currentTime;
                        const newTime = getTargetTime(videoPlyr, e);
                        const maxwatched = parseFloat(params.video?.dataset?.maxwatched || 0);

                        let shouldBlockSeek = false;

                        if (params.allowrewind) {
                            // Allow backward seek, block only if seeking beyond maxwatched
                            if (parseFloat(newTime) > maxwatched) {
                                shouldBlockSeek = true;
                            }
                        } else {
                            // Don't allow any seeking at all (forward or backward)
                            if (parseFloat(newTime) !== current_time) {
                                shouldBlockSeek = true;
                            }
                        }

                        if (shouldBlockSeek) {
                            e.preventDefault();
                            videoPlyr.pause();

                            if (!isSeekBlocked) {
                                isSeekBlocked = true;
                                showSeekingAlertDialog();

                                setTimeout(() => {
                                    isSeekBlocked = false;
                                }, SEEK_BLOCK_TIMEOUT);
                            }

                            // Reset to the original time
                            videoPlyr.currentTime = current_time;

                            return false;
                        }
                    }
                }
            },
            captions: { active: true, update: true, language: 'en' },
            settings: [],
            controls: []
        };

        const settingControls = [
            'play',
            'progress',
            'current-time',
            'mute',
            'volume',
            'captions',
            'settings',
            'fullscreen',
            ...(params.pip ? ['pip'] : [])
        ];

        const settingOptions = [
            'captions',
            'quality',
            'loop',
            ...(params.speed ? ['speed'] : [])
        ];

        playerDefaultOptions.settings = settingOptions;
        playerDefaultOptions.controls = settingControls;

        // Check for embedded videos (YouTube/Vimeo) - both 'embed' and 'external' providers
        if ((params.provider === 'embed' || params.provider === 'external') && params.externaltype === 'youtube') {
            Debug.log('Configuring YouTube embed player', {
                provider: params.provider,
                externaltype: params.externaltype
            });
            playerDefaultOptions.youtube = {
                noCookie: true,
                rel: 0,
                modestbranding: 1
            };
        }

        if ((params.provider === 'embed' || params.provider === 'external') && params.externaltype === 'vimeo') {
            Debug.log('Configuring Vimeo embed player', {
                provider: params.provider,
                externaltype: params.externaltype
            });
            playerDefaultOptions.vimeo = {
                dnt: true
            };
        }

        if (params.ishls && params.video) {
            const source = params.video?.dataset?.source;
            if (!source) {
                reject(new Error('HLS source not found'));
                return;
            }
            // eslint-disable-next-line no-undef
            const hls = new Hls();

            // Load the HLS source
            hls.loadSource(source);

            // Add timeout for HLS initialization
            const hlsTimeout = setTimeout(() => {
                const errorMessage = 'HLS initialization timed out.';
                Debug.error(errorMessage);
                displayErrorMessage(errorMessage, params.video);
                reject(new Error(errorMessage));
            }, HLS_INIT_TIMEOUT);

            // eslint-disable-next-line no-undef
            hls.on(Hls.Events.MANIFEST_PARSED, function () {
                clearTimeout(hlsTimeout); // Clear the timeout

                const availableQualities = hls.levels.map((l) => l.height);
                availableQualities.unshift(0); // Prepend 0 to quality array

                playerDefaultOptions.quality = {
                    default: 0, // Default - AUTO
                    options: availableQualities,
                    forced: true,
                    onChange: (e) => updateQuality(e),
                };

                playerDefaultOptions.i18n = {
                    qualityLabel: {
                        0: 'Auto',
                    },
                };

                // eslint-disable-next-line no-undef
                hls.on(Hls.Events.LEVEL_SWITCHED, function (event, data) {
                    const span = document.querySelector(".plyr__menu__container [data-plyr='quality'][value='0'] span");
                    if (span) {
                        if (hls.autoLevelEnabled) {
                            span.innerHTML = `AUTO (${hls.levels[data.level].height}p)`;
                        } else {
                            span.innerHTML = `AUTO`;
                        }
                    }
                });

                // Initialize Plyr instance
                // eslint-disable-next-line no-undef
                videoPlyr = new Plyr(params.video, playerDefaultOptions);
                // Store in namespaced global for debugging only
                if (typeof window !== 'undefined' && Debug.isDebugEnabled()) {
                    // eslint-disable-next-line no-undef
                    window.videolesson = window.videolesson || {};
                    // eslint-disable-next-line no-undef
                    window.videolesson.player = videoPlyr;
                }

                // Resolve the promise with the Plyr instance
                resolve(videoPlyr);
            });

            // Attach media to HLS
            hls.attachMedia(params.video);
            // Store in namespaced global for debugging only
            if (typeof window !== 'undefined' && Debug.isDebugEnabled()) {
                // eslint-disable-next-line no-undef
                window.videolesson = window.videolesson || {};
                // eslint-disable-next-line no-undef
                window.videolesson.hls = hls;
            }

            // Handle error events
            // eslint-disable-next-line no-undef
            hls.on(Hls.Events.ERROR, function (event, data) {
                clearTimeout(hlsTimeout); // Clear the timeout
                let errorMessage = 'An unknown error occurred.';
                // eslint-disable-next-line no-undef
                switch (data.type) {
                    // eslint-disable-next-line no-undef
                    case Hls.ErrorTypes.NETWORK_ERROR:
                        errorMessage = 'A network error occurred while fetching the media.';
                        break;
                    // eslint-disable-next-line no-undef
                    case Hls.ErrorTypes.MEDIA_ERROR:
                        errorMessage = 'An error occurred while loading the media.';
                        break;
                    // eslint-disable-next-line no-undef
                    case Hls.ErrorTypes.OTHER_ERROR:
                        errorMessage = 'An unexpected error occurred.';
                        break;
                }

                Debug.error('HLS Error', {message: errorMessage, data: data});
                displayErrorMessage(errorMessage, params.video);
                reject(new Error(errorMessage)); // Reject the promise with error details
            });
        } else {
            // Check if this is an embed that needs API loading
            const isEmbed = (params.provider === 'embed' || params.provider === 'external') &&
                           (params.externaltype === 'youtube' || params.externaltype === 'vimeo');

            if (isEmbed) {
                // Wait for YouTube/Vimeo API to be ready
                const waitForAPI = () => {
                    return new Promise((resolveAPI) => {
                        if (params.externaltype === 'youtube') {
                            if (window.YT && window.YT.Player) {
                                Debug.log('YouTube API ready');
                                resolveAPI();
                            } else {
                                Debug.log('Waiting for YouTube API...');
                                // Store existing handler if any, then set our own
                                const existingHandler = window.onYouTubeIframeAPIReady;
                                // eslint-disable-next-line no-undef
                                window.onYouTubeIframeAPIReady = () => {
                                    // Call existing handler if it exists
                                    if (existingHandler && typeof existingHandler === 'function') {
                                        existingHandler();
                                    }
                                    Debug.log('YouTube API ready (callback)');
                                    resolveAPI();
                                };
                                // Fallback timeout
                                const timeoutId = setTimeout(() => {
                                    Debug.log('YouTube API timeout, proceeding anyway');
                                    resolveAPI();
                                }, YOUTUBE_API_TIMEOUT);
                                // Store timeout ID for potential cleanup
                                if (resolveAPI.timeoutId) {
                                    clearTimeout(resolveAPI.timeoutId);
                                }
                                resolveAPI.timeoutId = timeoutId;
                            }
                        } else if (params.externaltype === 'vimeo') {
                            if (window.Vimeo && window.Vimeo.Player) {
                                Debug.log('Vimeo API ready');
                                resolveAPI();
                            } else {
                                Debug.log('Waiting for Vimeo API...');
                                // Check periodically
                                let checkInterval = setInterval(() => {
                                    if (window.Vimeo && window.Vimeo.Player) {
                                        clearInterval(checkInterval);
                                        checkInterval = null;
                                        Debug.log('Vimeo API ready');
                                        resolveAPI();
                                    }
                                }, VIMEO_API_CHECK_INTERVAL);
                                // Fallback timeout
                                const timeoutId = setTimeout(() => {
                                    if (checkInterval) {
                                        clearInterval(checkInterval);
                                        checkInterval = null;
                                    }
                                    Debug.log('Vimeo API timeout, proceeding anyway');
                                    resolveAPI();
                                }, VIMEO_API_TIMEOUT);
                                // Store references for cleanup
                                resolveAPI.cleanup = () => {
                                    if (checkInterval) {
                                        clearInterval(checkInterval);
                                        checkInterval = null;
                                    }
                                    clearTimeout(timeoutId);
                                };
                            }
                        } else {
                            resolveAPI();
                        }
                    });
                };

                waitForAPI().then(() => {
                    try {
                        Debug.log('Initializing Plyr player (embed)', {
                            provider: params.provider,
                            externaltype: params.externaltype,
                            element: params.video?.tagName,
                            className: params.video?.className,
                            hasIframe: !!params.video?.querySelector('iframe')
                        });

                        // eslint-disable-next-line no-undef
                        videoPlyr = new Plyr(params.video, playerDefaultOptions);
                        // Store in namespaced global for debugging only
                        if (typeof window !== 'undefined' && Debug.isDebugEnabled()) {
                            // eslint-disable-next-line no-undef
                            window.videolesson = window.videolesson || {};
                            // eslint-disable-next-line no-undef
                            window.videolesson.player = videoPlyr;
                        }

                        Debug.log('Plyr instance created (embed)', {
                            hasEmbed: !!videoPlyr.embed,
                            embedProvider: videoPlyr.embed?.provider,
                            duration: videoPlyr.duration
                        });

                        resolve(videoPlyr);
                    } catch (error) {
                        Debug.error('Error initializing embed player', error);
                        reject(error);
                    }
                });
            } else {
                try {
                    Debug.log('Initializing Plyr player (non-HLS)', {
                        provider: params.provider,
                        externaltype: params.externaltype,
                        element: params.video?.tagName,
                        className: params.video?.className,
                        hasIframe: !!params.video?.querySelector('iframe')
                    });

                    // eslint-disable-next-line no-undef
                    videoPlyr = new Plyr(params.video, playerDefaultOptions);
                    // Store in namespaced global for debugging only
                    if (typeof window !== 'undefined' && Debug.isDebugEnabled()) {
                        // eslint-disable-next-line no-undef
                        window.videolesson = window.videolesson || {};
                        // eslint-disable-next-line no-undef
                        window.videolesson.player = videoPlyr;
                    }

                    Debug.log('Plyr instance created', {
                        hasEmbed: !!videoPlyr.embed,
                        embedProvider: videoPlyr.embed?.provider,
                        duration: videoPlyr.duration
                    });

                    resolve(videoPlyr); // Resolve the promise with the Plyr instance
                } catch (error) {
                    Debug.error('Error initializing non-HLS player', error);
                    reject(error);
                }
            }
        }
    });
};

/**
 * Display error message in player container
 * @param {string} message - Error message to display
 * @param {HTMLElement|null} video - Video element
 */
const displayErrorMessage = async(message, video = null) => {
    if (!video) {
        Debug.error('Video element not provided for error display');
        return;
    }

    let playerContainer;
    const hash = video?.dataset?.hash;
    if (hash) {
        playerContainer = document.querySelector(`#player-placeholder-${hash}`);
    } else {
        playerContainer = document.querySelector(`#player-placeholder`);
    }

    if (!playerContainer) {
        Debug.error('Player container not found');
        return;
    }

    try {
        playerContainer.innerHTML = await Templates.render('mod_videolesson/error_player', {message: message});

        const retryButton = playerContainer.querySelector('.retry-button');
        if (retryButton) {
            retryButton.addEventListener('click', () => {
                window.location.reload();
            });
        } else {
            Debug.warn('Retry button not found inside player container');
        }
    } catch (error) {
        Debug.error('Error rendering error message template', error);
    }
};

/**
 * Update video quality for HLS playback
 * @param {number} newQuality - Quality level to set
 */
const updateQuality = newQuality => {
    // eslint-disable-next-line no-undef
    const hls = window.videolesson?.hls;
    if (!hls) {
        Debug.warn('HLS instance not available for quality update');
        return;
    }
    try {
        if (newQuality === 0) {
            hls.currentLevel = -1; // Enable AUTO quality if option.value = 0
        } else {
            hls.levels.forEach((level, levelIndex) => {
                if (level.height === newQuality) {
                    hls.currentLevel = levelIndex;
                }
            });
        }
    } catch (error) {
        Debug.error('Error updating quality', error);
    }
};

export const secondsToMinutesAndSeconds = (seconds) => {
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    const minutesString = String(minutes).padStart(2, '0');
    const secondsString = String(remainingSeconds).padStart(2, '0');
    return minutesString + ':' + secondsString;
};

export const getUrlParameter = (name) => {
    const escapedName = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    const regex = new RegExp('[\\?&]' + escapedName + '=([^&#]*)');
    const results = regex.exec(window.location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
};

export const getSubtitles = (contenthash) => {
    return new Promise((resolve, reject) => {
        Ajax.call([{
            methodname: 'mod_videolesson_getsubtitles',
            args: { contenthash: contenthash },
            done: function (response) {
                resolve(response);
            },
            fail: function (error) {
                reject(error);
            }
        }]);
    });
};

export const addSubtitleTracks = (video, tracks) => {
    tracks.forEach(track => {
        const trackEl = document.createElement('track');
        trackEl.kind = 'subtitles';
        trackEl.label = track.language;
        trackEl.srclang = track.code;
        trackEl.src = track.url;
        if (track.default) {
            trackEl.default = true;
        }
        video.appendChild(trackEl);
    });
};
