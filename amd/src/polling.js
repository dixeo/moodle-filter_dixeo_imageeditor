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
    'core/ajax',
    'core/notification',
    'core/str',
    'filter_dixeo_imageeditor/image_sync',
    'filter_dixeo_imageeditor/toast',
], function(Ajax, Notification, Str, imageSync, toast) {
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
        Ajax.call([{
            methodname: 'filter_dixeo_imageeditor_get_location_status',
            args: Object.assign({}, imageSync.getLocationArgs(wrap), {acknowledge: true}),
        }])[0].catch(() => {
            // Acknowledge is best-effort cleanup.
        });
    };

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
     * @param {HTMLElement} wrap
     * @param {Object} [callbacks]
     * @param {Function} [callbacks.onApplied]
     * @param {Function} [callbacks.onFailed]
     * @param {Function} [callbacks.onTimeout]
     */
    const startStatusPolling = (wrap, callbacks = {}) => {
        const key = imageSync.getLocationKey(wrap);
        stopPolling(key);
        pollStartedAt.set(key, Date.now());

        const poll = async() => {
            const started = pollStartedAt.get(key) || Date.now();
            if (Date.now() - started > POLL_TIMEOUT_MS) {
                stopPolling(key);
                setGeneratingOverlay(wrap, false);
                if (callbacks.onTimeout) {
                    callbacks.onTimeout();
                }
                try {
                    const message = await Str.getString('error_job_failed', 'filter_dixeo_imageeditor');
                    await notifyJobResult(wrap, 'error', message);
                } catch (error) {
                    Notification.exception(error);
                }
                return;
            }

            try {
                const status = await Ajax.call([{
                    methodname: 'filter_dixeo_imageeditor_get_location_status',
                    args: imageSync.getLocationArgs(wrap),
                }])[0];

                if (!status?.status) {
                    return;
                }

                if (status.status === 'pending' || status.status === 'processing') {
                    return;
                }

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

                if (status.status === 'failed') {
                    const message = (status.errormessage || '').trim()
                        || await Str.getString('error_job_failed', 'filter_dixeo_imageeditor');
                    applyTerminalStatus(wrap, status, message);
                    setGeneratingOverlay(wrap, false);
                    await notifyJobResult(wrap, 'error', message, {modalOnly: true});
                    if (callbacks.onFailed) {
                        callbacks.onFailed(status);
                    }
                    acknowledgeTerminalStatus(wrap);
                }
            } catch {
                // Transient webservice/API errors: keep polling; do not open N error modals.
            }
        };

        pollTimers.set(key, window.setInterval(() => {
            poll().catch(() => {
                // Keep interval alive on unexpected rejections.
            });
        }, POLL_INTERVAL_MS));
        poll().catch(() => {
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
                    const status = await Ajax.call([{
                        methodname: 'filter_dixeo_imageeditor_get_location_status',
                        args: imageSync.getLocationArgs(wrap),
                    }])[0];

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
