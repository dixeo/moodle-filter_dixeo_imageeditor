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
 * Auto-dismissing result toasts for embedded and modal image previews.
 *
 * @module     filter_dixeo_imageeditor/toast
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    const DEFAULT_DURATION_MS = 4000;
    const TOAST_CLASS = 'dixeo-imageeditor-toast';
    const VISIBLE_CLASS = 'is-visible';

    /** @type {WeakMap<HTMLElement, number>} */
    const dismissTimers = new WeakMap();

    /** @type {WeakMap<HTMLElement, HTMLElement>} */
    const openModalRoots = new WeakMap();

    /**
     * Remember the open editor modal for a wrapped image.
     *
     * @param {HTMLElement} wrap
     * @param {HTMLElement} modalRoot
     */
    const registerOpenModal = (wrap, modalRoot) => {
        if (wrap instanceof HTMLElement && modalRoot instanceof HTMLElement) {
            openModalRoots.set(wrap, modalRoot);
        }
    };

    /**
     * Clear the open editor modal reference.
     *
     * @param {HTMLElement} wrap
     */
    const unregisterOpenModal = (wrap) => {
        if (wrap instanceof HTMLElement) {
            openModalRoots.delete(wrap);
        }
    };

    /**
     * @param {HTMLElement} container
     * @returns {HTMLElement}
     */
    const getOrCreateToast = (container) => {
        let toast = container.querySelector('[data-region="result-toast"]');
        if (!(toast instanceof HTMLElement)) {
            toast = document.createElement('div');
            toast.className = TOAST_CLASS;
            toast.setAttribute('data-region', 'result-toast');
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            container.appendChild(toast);
        }
        return toast;
    };

    /**
     * @param {HTMLElement} toast
     */
    const clearDismissTimer = (toast) => {
        const timer = dismissTimers.get(toast);
        if (timer) {
            window.clearTimeout(timer);
            dismissTimers.delete(toast);
        }
    };

    /**
     * Show a toast inside one positioned container.
     *
     * @param {HTMLElement} container
     * @param {string} message
     * @param {'success'|'error'} [type]
     * @param {number} [durationMs]
     */
    const showInContainer = (container, message, type = 'success', durationMs = DEFAULT_DURATION_MS) => {
        if (!(container instanceof HTMLElement) || !message) {
            return;
        }

        const toast = getOrCreateToast(container);
        clearDismissTimer(toast);

        toast.textContent = message;
        toast.classList.remove('is-success', 'is-error', VISIBLE_CLASS);
        toast.classList.add(type === 'error' ? 'is-error' : 'is-success');

        window.requestAnimationFrame(() => {
            toast.classList.add(VISIBLE_CLASS);
        });

        const timer = window.setTimeout(() => {
            toast.classList.remove(VISIBLE_CLASS);
            dismissTimers.delete(toast);
        }, durationMs);
        dismissTimers.set(toast, timer);
    };

    /**
     * Show a toast above the embedded image wrapper.
     *
     * @param {HTMLElement} wrap
     * @param {string} message
     * @param {'success'|'error'} [type]
     * @param {number} [durationMs]
     */
    const showForWrap = (wrap, message, type = 'success', durationMs = DEFAULT_DURATION_MS) => {
        if (!(wrap instanceof HTMLElement)) {
            return;
        }
        showInContainer(wrap, message, type, durationMs);
    };

    /**
     * Show the same toast on the embedded image and in the open modal preview.
     *
     * @param {HTMLElement} wrap
     * @param {HTMLElement|null} modalRoot
     * @param {string} message
     * @param {'success'|'error'} [type]
     * @param {number} [durationMs]
     */
    const showResult = (wrap, modalRoot, message, type = 'success', durationMs = DEFAULT_DURATION_MS) => {
        showForWrap(wrap, message, type, durationMs);
        if (!(modalRoot instanceof HTMLElement)) {
            return;
        }
        const previewPanel = modalRoot.querySelector('[data-region="preview-panel"]');
        if (previewPanel instanceof HTMLElement) {
            showInContainer(previewPanel, message, type, durationMs);
        }
    };

    /**
     * Show a job result toast, including the open modal when registered.
     *
     * @param {HTMLElement} wrap
     * @param {string} message
     * @param {'success'|'error'} [type]
     * @param {number} [durationMs]
     */
    const showJobResult = (wrap, message, type = 'success', durationMs = DEFAULT_DURATION_MS) => {
        const modalRoot = wrap instanceof HTMLElement ? openModalRoots.get(wrap) : null;
        showResult(wrap, modalRoot || null, message, type, durationMs);
    };

    /**
     * Show the fading reason in the open editor only.
     *
     * The content image has its own top-centre notice, so a second copy on the wrap
     * would stack the same sentence.
     *
     * @param {HTMLElement} wrap
     * @param {string} message
     * @param {'success'|'error'} [type]
     * @param {number} [durationMs]
     */
    const showModalOnly = (wrap, message, type = 'error', durationMs = DEFAULT_DURATION_MS) => {
        const modalRoot = wrap instanceof HTMLElement ? openModalRoots.get(wrap) : null;
        if (!(modalRoot instanceof HTMLElement)) {
            return;
        }
        const previewPanel = modalRoot.querySelector('[data-region="preview-panel"]');
        if (previewPanel instanceof HTMLElement) {
            showInContainer(previewPanel, message, type, durationMs);
        }
    };

    return {
        DEFAULT_DURATION_MS,
        registerOpenModal,
        unregisterOpenModal,
        showInContainer,
        showForWrap,
        showResult,
        showJobResult,
        showModalOnly,
    };
});
