// CodeFortex additions, GPL v3 or later.
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/**
 * One player and watch session per activity page; no global Hls/debug ownership.
 * @param {Object} config Server-owned activity/player configuration.
 */
export const init = async(config) => {
    const video = document.getElementById('cf-player');
    if (!video || video.dataset.initialized) {
        return;
    }
    video.dataset.initialized = '1';
    const status = document.getElementById('cf-watch-status');
    const call = async(action, extra = {}) => {
        const response = await fetch(M.cfg.wwwroot + '/mod/videolesson/ajax.php?sesskey=' + M.cfg.sesskey, {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action, cmid: config.cmid, ...extra}),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(await getString('cfrequestfailed', 'mod_videolesson'));
        }
        return result.data;
    };
    let hls;
    let session;
    let pending = false;
    let start = 0;
    let previous = 0;
    let furthest = 0;
    let seeking = false;
    let player;
    let currentUrl = config.url;
    let renewalTimer;
    let renewing;
    let disconnected = false;
    let destroyed = false;
    let rotating = false;
    let reconnecting;
    let resumeAfterReconnect = false;
    const showFailure = (error) => {
        resumeAfterReconnect = resumeAfterReconnect || !video.paused;
        disconnected = true;
        video.pause();
        status.textContent = error.message;
    };
    const renew = async() => {
        if (renewing) {
            return renewing;
        }
        renewing = (async() => {
            const grant = await call('renew', {session: session.session});
            if (destroyed) {
                return;
            }
            currentUrl = grant.url;
            clearTimeout(renewalTimer);
            renewalTimer = setTimeout(() => { void renew().catch(showFailure); },
                Math.max(1000, (grant.expires * 1000 - Date.now()) * 0.7));
            if (!hls && video.src && video.src !== currentUrl) {
                const time = video.currentTime;
                const playing = !video.paused;
                const speed = video.playbackRate;
                rotating = true;
                video.src = currentUrl;
                video.addEventListener('loadedmetadata', () => {
                    video.currentTime = time;
                    // Changing an MP4 URL resets the media element's rate to its default.
                    // Authorization renewal must not change the learner's selected speed.
                    video.playbackRate = speed;
                    start = previous = time;
                    rotating = false;
                    if (playing) {
                        void video.play().catch(showFailure);
                    }
                }, {once: true});
            }
        })();
        try {
            return await renewing;
        } finally {
            renewing = null;
        }
    };
    const reconnect = async() => {
        if (!session || destroyed || reconnecting) {
            return;
        }
        reconnecting = true;
        try {
            const state = await call('reconnect', {session: session.session});
            // Response-lost and request-lost cases both resume at the accepted server sequence.
            // Discard unacknowledged/offline intervals instead of re-crediting uncertain playback.
            session.sequence = state.sequence;
            start = previous = video.currentTime;
            status.textContent = Math.floor(state.progress) + '%';
            await renew();
            disconnected = false;
            hls?.startLoad();
            if (resumeAfterReconnect) {
                resumeAfterReconnect = false;
                void video.play().catch(showFailure);
            }
        } catch (error) {
            showFailure(error);
        } finally {
            reconnecting = false;
        }
    };
    const flush = async() => {
        // Encoded HLS may include a small padded tail beyond the authoritative
        // source duration. Never report/credit time outside the server session.
        const end = session ? Math.min(previous, session.duration) : previous;
        if (!session || pending || disconnected || rotating || end - start < 0.001) {
            return;
        }
        pending = true;
        const from = start;
        start = end;
        try {
            const result = await call('event', {event: {
                session: session.session, sequence: session.sequence + 1,
                video: session.video, revision: session.revision,
                start: from, end, rate: video.playbackRate,
            }});
            session.sequence = result.sequence;
            status.textContent = Math.floor(result.progress) + '%';
        } catch (error) {
            showFailure(error);
            await reconnect();
        } finally {
            pending = false;
            // pause can submit just before ended. Drain the final observed tail
            // after that request completes, without concurrent sequence numbers.
            if (session && !disconnected && (video.ended || video.paused) && previous - start >= 0.001) {
                void flush();
            }
        }
    };
    const configure = (levels = []) => {
        if (player) {
            return;
        }
        player = new window.Plyr(video, {
            loadSprite: false,
            iconUrl: M.cfg.wwwroot + '/mod/videolesson/pix/codefortex-controls.svg',
            storage: {enabled: false},
            settings: ['quality', 'speed'],
            speed: {selected: 1, options: [0.5, 1, 1.25, 1.5, 1.75, 2]},
            quality: {default: 0, options: [0, ...levels], forced: true,
                onChange: (height) => {
                    if (hls) {
                        hls.currentLevel = Number(height) === 0 ? -1 : hls.levels.findIndex(level => level.height === height);
                    }
                }},
            i18n: {qualityLabel: {0: 'Auto'}},
        });
    };
    try {
        session = await call('session');
        await renew();
        if (config.kind === 'hls' && window.Hls.isSupported()) {
            hls = new window.Hls({maxBufferLength: 20, maxMaxBufferLength: 20,
                xhrSetup: (xhr, url) => {
                    // Old playlists can remain buffered. Rotate authorization at every child request.
                    const child = new URL(url, location.href);
                    const active = new URL(currentUrl, location.href);
                    if (child.origin !== active.origin || child.pathname !== active.pathname) {
                        throw new Error('Unexpected media origin');
                    }
                    child.searchParams.set('grant', active.searchParams.get('grant'));
                    xhr.open('GET', child.href, true);
                }});
            hls.on(window.Hls.Events.MANIFEST_PARSED, () => configure(hls.levels.map(level => level.height)));
            hls.on(window.Hls.Events.ERROR, (event, data) => {
                if (data.fatal) {
                    showFailure(new Error('Media unavailable'));
                    void reconnect();
                }
            });
            hls.loadSource(currentUrl);
            hls.attachMedia(video);
        } else {
            video.src = currentUrl;
            configure();
        }
        video.addEventListener('timeupdate', () => {
            if (seeking || video.paused || disconnected || rotating) {
                return;
            }
            const current = video.currentTime;
            if (current < previous || current - previous > 2.5) {
                start = current;
            }
            previous = current;
            furthest = Math.max(furthest, current);
            if (current - start >= 3) {
                void flush();
            }
        });
        video.addEventListener('seeking', () => {
            seeking = true;
            if (!rotating && config.restrictseek && video.currentTime > furthest + 0.5) {
                video.currentTime = furthest;
                getString('cfseekblocked', 'mod_videolesson').then(text => { status.textContent = text; })
                    .catch(Notification.exception);
            }
        });
        video.addEventListener('seeked', () => {
            start = previous = video.currentTime;
            seeking = false;
        });
        video.addEventListener('ratechange', () => { start = previous = video.currentTime; });
        video.addEventListener('pause', () => { void flush(); });
        video.addEventListener('ended', () => { previous = video.currentTime; void flush(); });
        const online = () => { void reconnect(); };
        window.addEventListener('online', online);
        video.addEventListener('play', () => {
            if (disconnected) {
                video.pause();
                void reconnect();
            }
        });
        window.addEventListener('pagehide', () => {
            destroyed = true;
            clearTimeout(renewalTimer);
            window.removeEventListener('online', online);
            hls?.destroy();
            player?.destroy();
        }, {once: true});
    } catch (error) {
        status.textContent = error.message;
        Notification.exception(error);
    }
};
