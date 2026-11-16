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
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Client-side polling for async image job status.
 *
 * @module     filter_dixeo_imageeditor/polling
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/notification',
    'core/str',
    'local_dixeo/network',
    'filter_dixeo_imageeditor/connection',
    'filter_dixeo_imageeditor/image_sync',
    'filter_dixeo_imageeditor/toast',
], function(Notification, Str, Network, Connection, imageSync, toast) {
    'use strict';

    const GENERATING_CLASS = 'is-generating';
    const POLL_INTERVAL_MS = 4000;
    /** Align with job_repository::TIMEOUT_SECONDS (1 hour). */
    const POLL_TIMEOUT_MS = 3600000;

    /** @type {Map<string, number>} */
    const pollTimers = new Map();

    /** @type {Map<string, number>} */
    const pollStartedAt = new Map();

    /**
     * Refresh pending/failed status pills after class changes on a wrap.
     *
     * @param {HTMLElement} wrap
     * @param {string} [failedMessage] Same sentence shown for this failure elsewhere.
     */
    const refreshContentImageHost = (wrap, failedMessage = '') => {
        require(['local_dixeo/content_image_pending'], (pending) => {
            pending.refresh(wrap, failedMessage);
        }, () => {
            // Optional host module may be unavailable on some pages.
        });
    };

    /**
     * Toggle generating overlay on a wrapper.
     *
     * @param {HTMLElement} wrap
     * @param {boolean} active
     * @param {string} label
     */
    const setGeneratingOverlay = (wrap, active, label = '') => {
        if (active) {
            wrap.classList.add(GENERATING_CLASS);
            wrap.dataset.dixeoImageGeneratingLabel = label;
            return;
        }
        wrap.classList.remove(GENERATING_CLASS);
        delete wrap.dataset.dixeoImageGeneratingLabel;
    };

    /**
     * Apply a terminal status payload to the wrap image.
     *
     * A failure clears the generating overlay before the content pill is painted.
     * The overlay hides that pill, so painting first would drop the message.
     *
     * @param {HTMLElement} wrap
     * @param {Object} status
     * @param {string} [failedMessage] Sentence to show on the content image.
     */
    const applyTerminalStatus = (wrap, status, failedMessage = '') => {
        const imageurl = status.imageurl || '';
        const hash = status.current_contenthash || '';
        const failed = status.status === 'failed';
        const message = failed ? (failedMessage || status.errormessage || '').trim() : '';
        if (failed) {
            setGeneratingOverlay(wrap, false);
        }
        if (!imageurl) {
            // Still clear pending class so shimmer stops even without a URL.
            const img = wrap.querySelector('img');
            if (img instanceof HTMLImageElement) {
                img.classList.remove('dixeo-img-gen-pending');
                if (failed) {
                    img.classList.add('dixeo-img-gen-failed');
                }
            }
            refreshContentImageHost(wrap, message);
            return;
        }
        imageSync.applyImageToWrap(wrap, imageurl, hash, {
            failed,
        });
        refreshContentImageHost(wrap, message);
    };

    /**
     * Stop polling for one location.
     *
     * @param {string} key
     */
    const stopPolling = (key) => {
        const timer = pollTimers.get(key);
        if (timer) {
            window.clearInterval(timer);
            pollTimers.delete(key);
        }
        pollStartedAt.delete(key);
    };

    /**
     * Best-effort acknowledgement that clears terminal lock state server-side.
     *
     * @param {HTMLElement} wrap
     * @returns {void}
     */
    const acknowledgeTerminalStatus = (wrap) => {
        Network.call({
            methodname: 'filter_dixeo_imageeditor_get_location_status',
            args: Object.assign({}, imageSync.getLocationArgs(wrap), {acknowledge: true}),
        }, {timeout: Network.TIMEOUT_ACTION_MS}).catch(() => {
            // Acknowledge is best-effort cleanup.
        });
    };

    /**
     * Read the job status of one location.
     *
     * @param {HTMLElement} wrap
     * @returns {Promise<Object>}
     */
    const fetchLocationStatus = (wrap) => Network.call({
        methodname: 'filter_dixeo_imageeditor_get_location_status',
        args: imageSync.getLocationArgs(wrap),
    }, {timeout: Network.TIMEOUT_STATUS_MS});

    /**
     * Show a success or error toast for a completed job.
     *
     * @param {HTMLElement} wrap
     * @param {'success'|'error'} type
     * @param {string} [message]
     * @param {Object} [options]
     * @param {boolean} [options.modalOnly] Skip the image toast. The content pill owns that copy.
     * @returns {Promise<void>}
     */
    const notifyJobResult = async(wrap, type, message = '', options = {}) => {
        let text = message;
        if (!text) {
            text = await Str.getString(
                type === 'success' ? 'image_updated' : 'error_job_failed',
                'filter_dixeo_imageeditor'
            );
        }
        if (type === 'error' && options.modalOnly) {
            toast.showModalOnly(wrap, text, type);
            return;
        }
        toast.showJobResult(wrap, text, type);
    };

    /**
     * Poll lock status for UX overlay only.
     *
     * One status read runs at a time for a location. Connection failures are retried within the
     * retry window; past it, polling stops and the connection message is shown.
     *
     * With lostStart, the job start request lost its answer: the poll waits for the job to show
     * up and gives up with the "may have been created anyway" message when nothing advances.
     *
     * @param {HTMLElement} wrap
     * @param {Object} [callbacks]
     * @param {Function} [callbacks.onApplied]
     * @param {Function} [callbacks.onFailed]
     * @param {Function} [callbacks.onTimeout] Called when polling stops without a terminal status.
     * @param {boolean} [callbacks.lostStart] Whether the job start request lost its answer.
     */
    const startStatusPolling = (wrap, callbacks = {}) => {
        const key = imageSync.getLocationKey(wrap);
        stopPolling(key);
        pollStartedAt.set(key, Date.now());
        const retryWindow = Network.createRetryWindow();
        const stallWatch = callbacks.lostStart ? Network.createStallWatch() : null;
        let inFlight = false;

        /**
         * Stop polling without a terminal status and give the UI back.
         */
        const giveUp = () => {
            stopPolling(key);
            setGeneratingOverlay(wrap, false);
            if (callbacks.onTimeout) {
                callbacks.onTimeout();
            }
        };

        /**
         * Apply a terminal status: applied or failed.
         *
         * @param {Object} status
         * @returns {Promise<void>}
         */
        const applyFinalStatus = async(status) => {
            stopPolling(key);

            if (status.status === 'applied') {
                applyTerminalStatus(wrap, status);
                setGeneratingOverlay(wrap, false);
                await notifyJobResult(wrap, 'success');
                if (callbacks.onApplied) {
                    callbacks.onApplied(status);
                }
                acknowledgeTerminalStatus(wrap);
                return;
            }

            const message = (status.errormessage || '').trim()
                || await Str.getString('error_job_failed', 'filter_dixeo_imageeditor');
            applyTerminalStatus(wrap, status, message);
            setGeneratingOverlay(wrap, false);
            await notifyJobResult(wrap, 'error', message, {modalOnly: true});
            if (callbacks.onFailed) {
                callbacks.onFailed(status);
            }
            acknowledgeTerminalStatus(wrap);
        };

        /**
         * Handle a status read while waiting for a job whose start lost its answer.
         *
         * @param {string} state Status of the location.
         * @param {boolean} running Whether a job is pending or processing.
         * @returns {Promise<boolean>} True when polling stopped.
         */
        const followLostStart = async(state, running) => {
            stallWatch.observe(state, running);
            if (!stallWatch.stalled()) {
                return false;
            }
            giveUp();
            const message = await Connection.message('error_connection_image');
            await notifyJobResult(wrap, 'error', message);
            return true;
        };

        /**
         * Handle the polling time limit.
         *
         * @returns {Promise<void>}
         */
        const timeOut = async() => {
            giveUp();
            try {
                const message = await Str.getString('error_job_failed', 'filter_dixeo_imageeditor');
                await notifyJobResult(wrap, 'error', message);
            } catch (error) {
                Notification.exception(error);
            }
        };

        const poll = async() => {
            const started = pollStartedAt.get(key) || Date.now();
            if (Date.now() - started > POLL_TIMEOUT_MS) {
                await timeOut();
                return;
            }

            let status;
            try {
                status = await fetchLocationStatus(wrap);
            } catch (failure) {
                if (Network.isConnectionError(failure) && !retryWindow.shouldRetry(failure)) {
                    giveUp();
                    Connection.exception(failure);
                }
                // Server errors keep polling; do not open N error modals.
                return;
            }
            retryWindow.succeeded();

            try {
                const state = status?.status || '';
                const running = state === 'pending' || state === 'processing';
                const final = state === 'applied' || state === 'failed';
                if (final) {
                    await applyFinalStatus(status);
                    return;
                }
                if (stallWatch) {
                    // The lost start has not shown up on the server yet, or is still running.
                    await followLostStart(state, running);
                    return;
                }
                if (state && !running) {
                    stopPolling(key);
                }
            } catch {
                // A failure while applying the result keeps polling; do not open N error modals.
            }
        };

        const tick = () => {
            if (inFlight) {
                return Promise.resolve();
            }
            inFlight = true;
            return poll().finally(() => {
                inFlight = false;
            });
        };

        pollTimers.set(key, window.setInterval(() => {
            tick().catch(() => {
                // Keep interval alive on unexpected rejections.
            });
        }, POLL_INTERVAL_MS));
        tick().catch(() => {
            // First tick soft-fail.
        });
    };

    /**
     * Resume overlays for in-flight jobs after page load.
     *
     * Only wrappers flagged server-side (data-dixeo-pending="1") are checked, so
     * pages full of idle images make no status requests at all.
     *
     * @param {string} wrapSelector
     */
    const resumePendingOverlays = (wrapSelector) => {
        document.querySelectorAll(wrapSelector).forEach((wrap) => {
            if (!(wrap instanceof HTMLElement)) {
                return;
            }
            if (wrap.dataset.dixeoPending !== '1'
                    && !wrap.querySelector('img.dixeo-img-gen-pending')) {
                return;
            }

            // Claim the label immediately so local_dixeo status pills never flash.
            Str.getString('generating_status', 'filter_dixeo_imageeditor').then((label) => {
                setGeneratingOverlay(wrap, true, label);
                return;
            }).catch(() => {
                setGeneratingOverlay(wrap, true, '');
            });

            const resume = async() => {
                try {
                    const status = await Network.retryRead(() => fetchLocationStatus(wrap));

                    if (status?.status === 'pending' || status?.status === 'processing') {
                        startStatusPolling(wrap);
                    } else {
                        if (status?.status === 'applied' || status?.status === 'failed') {
                            applyTerminalStatus(wrap, status);
                        }
                        setGeneratingOverlay(wrap, false);
                    }
                } catch {
                    setGeneratingOverlay(wrap, false);
                    // Ignore resume failures on pages without webservice access.
                }
            };

            resume().catch(() => {
                // Ignore resume failures on pages without webservice access.
            });
        });
    };

    return {
        GENERATING_CLASS,
        setGeneratingOverlay,
        stopPolling,
        startStatusPolling,
        resumePendingOverlays,
        notifyJobResult,
    };
});
