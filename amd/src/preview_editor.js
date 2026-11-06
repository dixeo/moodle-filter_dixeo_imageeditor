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
 * Manual image editor for the modal preview panel (Cropper.js + canvas filters).
 *
 * @module     filter_dixeo_imageeditor/preview_editor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'filter_dixeo_imageeditor/lib/cropper',
    'core/notification',
    'core/str',
], function(Cropper, Notification, Str) {
    'use strict';

/** @typedef {{grayscale: boolean, sepia: boolean, brightness: number, contrast: number}} FilterState */

const noop = () => {
    // Default callback stub.
};

const noopAsync = () => Promise.resolve();

const createDefaultFilters = () => ({
    grayscale: false,
    sepia: false,
    brightness: 100,
    contrast: 100,
});

/**
 * Round a number for stable snapshot comparison.
 *
 * @param {number} value
 * @param {number} [precision]
 * @returns {number}
 */
const roundNumber = (value, precision = 4) => {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return value;
    }
    const factor = 10 ** precision;
    return Math.round(value * factor) / factor;
};

/**
 * Deep-normalise numeric fields so equivalent cropper states compare equal.
 *
 * @param {*} value
 * @returns {*}
 */
const normaliseSnapshotValue = (value) => {
    if (Array.isArray(value)) {
        return value.map((item) => normaliseSnapshotValue(item));
    }
    if (value && typeof value === 'object') {
        const normalised = {};
        Object.keys(value).sort().forEach((key) => {
            normalised[key] = normaliseSnapshotValue(value[key]);
        });
        return normalised;
    }
    if (typeof value === 'number') {
        return roundNumber(value);
    }
    return value;
};

/**
 * @param {Object} left
 * @param {Object} right
 * @returns {boolean}
 */
const snapshotsEqual = (left, right) => {
    if (!left || !right) {
        return false;
    }
    return JSON.stringify(normaliseSnapshotValue(left)) === JSON.stringify(normaliseSnapshotValue(right));
};

/** @type {number} */
const ZOOM_RATIO_TOLERANCE = 0.001;

/** @type {number} Fallback maximum pixels per side when the server sends none. */
const DEFAULT_MAX_IMAGE_DIMENSION = 8000;

/** @type {number} Fallback maximum total pixels when the server sends none. */
const DEFAULT_MAX_IMAGE_PIXELS = 40000000;

/**
 * Build CSS filter string for live preview.
 *
 * @param {FilterState} filters
 * @returns {string}
 */
const buildCssFilter = (filters) => {
    const parts = [];
    if (filters.grayscale) {
        parts.push('grayscale(100%)');
    }
    if (filters.sepia) {
        parts.push('sepia(100%)');
    }
    parts.push(`brightness(${filters.brightness}%)`);
    parts.push(`contrast(${filters.contrast}%)`);
    return parts.join(' ');
};

/**
 * Manual preview editor controller.
 */
class PreviewEditor {
    /**
     * @param {HTMLElement} panel Preview panel root
     * @param {Object} options
     * @param {HTMLElement} options.wrap Embedded image wrapper
     * @param {Function} [options.onStateChange]
     * @param {Function} [options.onSaveRequest] Async save handler receiving base64 payload
     * @param {Function} [options.onSaveError] Error handler for failed save
     * @param {number} [options.maxImageDimension] Maximum pixels per side of a stored image
     * @param {number} [options.maxImagePixels] Maximum total pixels of a stored image
     */
    constructor(panel, options) {
        this.panel = panel;
        this.wrap = options.wrap;
        this.maxImageDimension = options.maxImageDimension || DEFAULT_MAX_IMAGE_DIMENSION;
        this.maxImagePixels = options.maxImagePixels || DEFAULT_MAX_IMAGE_PIXELS;
        this.onStateChange = options.onStateChange || noop;
        this.onSaveRequest = options.onSaveRequest || noopAsync;
        this.onSaveError = options.onSaveError || noop;

        this.image = panel.querySelector('[data-region="preview-image"]');
        this.toolbars = panel.querySelector('[data-region="preview-toolbars"]');
        this.startButton = panel.querySelector('[data-action="manual-edit-start"]');
        this.saveDiscard = panel.querySelector('[data-region="manual-save-discard"]');
        this.brightnessInput = panel.querySelector('[data-region="manual-brightness"]');
        this.contrastInput = panel.querySelector('[data-region="manual-contrast"]');

        /** @type {Cropper|null} */
        this.cropper = null;
        this.editing = false;
        this.saving = false;
        this.flipH = false;
        this.flipV = false;
        this.brightnessSliderVisible = false;
        this.contrastSliderVisible = false;
        /** @type {string} */
        this.baselineSrc = '';
        /** @type {string} */
        this.workingImageSrc = '';
        /** @type {FilterState} */
        this.filters = createDefaultFilters();
        /** @type {Array<Object>} */
        this.history = [];
        /** @type {number} */
        this.historyIndex = -1;
        /** @type {number|null} */
        this.baseZoomRatio = null;
        /** @type {boolean} */
        this.suppressCropHistory = false;

        this.handlePanelClick = this.handlePanelClick.bind(this);
        this.handleSliderInput = this.handleSliderInput.bind(this);
        this.handleSliderChange = this.handleSliderChange.bind(this);
        panel.addEventListener('click', this.handlePanelClick);
        panel.addEventListener('input', this.handleSliderInput);
        panel.addEventListener('change', this.handleSliderChange);
    }

    /**
     * @returns {boolean}
     */
    isEditing() {
        return this.editing;
    }

    /**
     * @returns {boolean}
     */
    isSaving() {
        return this.saving;
    }

    /**
     * @returns {boolean}
     */
    isDirty() {
        if (!this.editing || this.historyIndex < 0 || !this.history[0]) {
            return false;
        }
        return !snapshotsEqual(this.createSnapshot(), this.history[0]);
    }

    /**
     * @returns {boolean} True when manual edit cannot start (AI generating).
     */
    isBlocked() {
        return this.panel.classList.contains('is-generating');
    }

    /**
     * Update preview image URL when not editing.
     *
     * @param {string} url
     */
    setImageUrl(url) {
        if (this.editing || !(this.image instanceof HTMLImageElement) || !url) {
            return;
        }
        this.image.src = url;
    }

    /**
     * Commit a saved image URL as the new baseline (after server apply or manual save).
     *
     * @param {string} url Cache-busted image URL
     */
    commitSavedImage(url) {
        if (!url || !(this.image instanceof HTMLImageElement)) {
            return;
        }
        this.baselineSrc = url;
        if (!this.editing) {
            this.image.src = url;
        }
    }

    /**
     * Report a failed manual editor action to the host.
     *
     * @param {Error|*} error
     */
    handleActionError(error) {
        this.onSaveError(error);
    }

    /**
     * Factor that brings a canvas of this size within the stored image bounds.
     *
     * @param {number} width
     * @param {number} height
     * @returns {number} 1 when the size is already within bounds, else a factor below 1.
     */
    boundedScale(width, height) {
        if (!width || !height) {
            return 1;
        }
        return Math.min(
            1,
            this.maxImageDimension / width,
            this.maxImageDimension / height,
            Math.sqrt(this.maxImagePixels / (width * height))
        );
    }

    /**
     * Cropped canvas options sized within the stored image bounds.
     *
     * @returns {Object}
     */
    getBoundedCropOptions() {
        const data = this.cropper.getData(true);
        const scale = this.boundedScale(data.width, data.height);
        return {
            width: Math.max(1, Math.round(data.width * scale)),
            height: Math.max(1, Math.round(data.height * scale)),
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high',
        };
    }

    /**
     * Show the image size limit message.
     *
     * @returns {Promise<void>}
     */
    async showImageTooLarge() {
        const [title, message] = await Str.get_strings([
            {key: 'error', component: 'moodle'},
            {
                key: 'error:image_dimensions',
                component: 'local_dixeo',
                param: {
                    dimension: this.maxImageDimension,
                    megapixels: Math.floor(this.maxImagePixels / 1000000),
                },
            },
        ]);
        await Notification.alert(title, message);
    }

    /**
     * @returns {FilterState}
     */
    getFilterState() {
        return {...this.filters};
    }

    /**
     * Capture a restorable editor state snapshot.
     *
     * @returns {Object}
     */
    createSnapshot() {
        const snapshot = {
            imageSrc: this.workingImageSrc || (this.image instanceof HTMLImageElement ? this.image.src : ''),
            filters: this.getFilterState(),
            flipH: this.flipH,
            flipV: this.flipV,
        };
        if (!this.cropper) {
            return snapshot;
        }
        snapshot.canvasData = this.cropper.getCanvasData();
        snapshot.data = this.cropper.getData(true);
        return snapshot;
    }

    /**
     * Restore editor state from a snapshot, re-mounting the image when pixels changed.
     *
     * @param {Object} snapshot
     * @returns {Promise<void>}
     */
    async applySnapshot(snapshot) {
        if (!snapshot) {
            return;
        }

        const targetSrc = snapshot.imageSrc || '';
        const currentSrc = this.workingImageSrc || (this.image instanceof HTMLImageElement ? this.image.src : '');

        if (targetSrc && targetSrc !== currentSrc) {
            await this.mountCropper(snapshot);
            return;
        }

        if (!this.cropper) {
            if (targetSrc) {
                await this.mountCropper(snapshot);
            }
            return;
        }

        if (snapshot.canvasData) {
            this.cropper.setCanvasData(snapshot.canvasData);
        }
        if (snapshot.data) {
            this.cropper.setData(snapshot.data);
        }

        this.filters = {...createDefaultFilters(), ...(snapshot.filters || {})};
        this.flipH = !!snapshot.flipH;
        this.flipV = !!snapshot.flipV;

        this.syncFilterUi();
        this.applyFilterPreview();
    }

    /**
     * @returns {boolean}
     */
    hasPendingCropTransform() {
        if (!this.cropper) {
            return false;
        }

        // The crop box in natural pixels is the size the cropped canvas would have.
        const data = this.cropper.getData(true);
        const imageData = this.cropper.getImageData();
        const cropped = {width: data.width, height: data.height};
        if (!cropped.width || !cropped.height) {
            return false;
        }

        const tolerance = 1;
        const rotated = Math.abs(data.rotate % 180) === 90;
        const expectedWidth = rotated ? imageData.naturalHeight : imageData.naturalWidth;
        const expectedHeight = rotated ? imageData.naturalWidth : imageData.naturalHeight;
        const hasTransform = data.rotate !== 0 || this.flipH || this.flipV;
        const hasCropResize = (
            Math.abs(cropped.width - expectedWidth) > tolerance
            || Math.abs(cropped.height - expectedHeight) > tolerance
        );
        const hasCropOffset = Math.abs(data.x) > tolerance || Math.abs(data.y) > tolerance;

        return hasTransform || hasCropResize || hasCropOffset;
    }

    /**
     * @returns {number|null}
     */
    getZoomRatio() {
        if (!this.cropper) {
            return null;
        }
        const canvasData = this.cropper.getCanvasData();
        if (!canvasData.naturalWidth) {
            return null;
        }
        return canvasData.width / canvasData.naturalWidth;
    }

    /**
     * Smallest zoom ratio that still fits the image inside the preview container.
     *
     * @returns {number|null}
     */
    getMinZoomRatio() {
        if (!this.cropper) {
            return null;
        }
        const containerData = this.cropper.getContainerData();
        const canvasData = this.cropper.getCanvasData();
        if (!containerData.width || !containerData.height || !canvasData.naturalWidth) {
            return null;
        }
        return Math.min(
            containerData.width / canvasData.naturalWidth,
            containerData.height / canvasData.naturalHeight
        );
    }

    /**
     * @returns {boolean}
     */
    isAtMinZoom() {
        const current = this.getZoomRatio();
        const minRatio = this.getMinZoomRatio();
        if (current === null || minRatio === null) {
            return true;
        }
        return current <= minRatio + ZOOM_RATIO_TOLERANCE;
    }

    /**
     * @returns {boolean}
     */
    isAtBaseZoom() {
        const current = this.getZoomRatio();
        if (current === null || this.baseZoomRatio === null) {
            return true;
        }
        return Math.abs(current - this.baseZoomRatio) <= ZOOM_RATIO_TOLERANCE;
    }

    /**
     * Bake the current crop selection into the working image and remount Cropper.
     *
     * @returns {Promise<void>}
     */
    async applyCropSelection() {
        if (!this.cropper || !this.hasPendingCropTransform()) {
            return;
        }

        const before = this.createSnapshot();
        const canvas = this.cropper.getCroppedCanvas(this.getBoundedCropOptions());
        if (!canvas || canvas.width === 0 || canvas.height === 0) {
            return;
        }

        const dataUrl = canvas.toDataURL('image/png');
        await this.mountCropper({
            imageSrc: dataUrl,
            filters: this.getFilterState(),
            flipH: false,
            flipV: false,
            fitPreview: true,
        });

        const after = this.createSnapshot();
        if (!snapshotsEqual(before, after)) {
            this.pushHistory();
        }
        this.notifyState();
    }

    /**
     * Draw the working image rotated on a canvas (no Cropper crop/export).
     *
     * @param {number} degrees Clockwise degrees (currently 90 only).
     * @returns {HTMLCanvasElement|null}
     */
    renderRotatedWorkingImage(degrees) {
        if (!(this.image instanceof HTMLImageElement)) {
            return null;
        }

        const scale = this.boundedScale(this.image.naturalWidth, this.image.naturalHeight);
        const width = Math.max(1, Math.round(this.image.naturalWidth * scale));
        const height = Math.max(1, Math.round(this.image.naturalHeight * scale));
        if (!this.image.naturalWidth || !this.image.naturalHeight) {
            return null;
        }

        const scaleX = this.flipH ? -1 : 1;
        const scaleY = this.flipV ? -1 : 1;
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return null;
        }

        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';

        if (degrees === 90 || degrees === -270) {
            canvas.width = height;
            canvas.height = width;
            ctx.translate(height / 2, width / 2);
            ctx.rotate(Math.PI / 2);
        } else if (degrees === -90 || degrees === 270) {
            canvas.width = height;
            canvas.height = width;
            ctx.translate(height / 2, width / 2);
            ctx.rotate(-Math.PI / 2);
        } else {
            return null;
        }

        ctx.scale(scaleX, scaleY);
        ctx.drawImage(this.image, -width / 2, -height / 2, width, height);
        return canvas;
    }

    /**
     * Fit the cropper canvas and crop box to the preview container after baking pixels.
     */
    ensureCanvasFitsPreview() {
        if (!this.cropper) {
            return;
        }

        const containerData = this.cropper.getContainerData();
        const canvasData = this.cropper.getCanvasData();
        if (!containerData.width || !containerData.height || !canvasData.naturalWidth) {
            return;
        }

        const targetRatio = Math.min(
            containerData.width / canvasData.naturalWidth,
            containerData.height / canvasData.naturalHeight
        );
        const currentRatio = canvasData.width / canvasData.naturalWidth;

        if (Math.abs(currentRatio - targetRatio) > 0.001) {
            this.cropper.zoomTo(targetRatio);
        }

        const fittedCanvas = this.cropper.getCanvasData();
        this.cropper.setCanvasData({
            left: (containerData.width - fittedCanvas.width) / 2,
            top: (containerData.height - fittedCanvas.height) / 2,
        });

        const alignedCanvas = this.cropper.getCanvasData();
        this.cropper.setCropBoxData({
            left: alignedCanvas.left,
            top: alignedCanvas.top,
            width: alignedCanvas.width,
            height: alignedCanvas.height,
        });

        const finalCanvas = this.cropper.getCanvasData();
        this.baseZoomRatio = finalCanvas.width / finalCanvas.naturalWidth;
    }

    /**
     * Rotate 90° clockwise, bake pixels into the working image, and remount to fit the preview.
     *
     * @returns {Promise<void>}
     */
    async applyRotateClockwise() {
        if (!this.cropper) {
            return;
        }

        const before = this.createSnapshot();
        const preservedFilters = this.getFilterState();
        const canvas = this.renderRotatedWorkingImage(90);
        if (!canvas) {
            return;
        }

        const dataUrl = canvas.toDataURL('image/png');
        await this.mountCropper({
            imageSrc: dataUrl,
            filters: preservedFilters,
            flipH: false,
            flipV: false,
            fitPreview: true,
        });

        const after = this.createSnapshot();
        if (!snapshotsEqual(before, after)) {
            this.pushHistory();
        }
        this.notifyState();
    }

    /**
     * @param {Function} onReady
     * @returns {Object}
     */
    getCropperConfig(onReady) {
        return {
            viewMode: 1,
            autoCropArea: 1,
            responsive: true,
            restore: false,
            checkOrientation: false,
            zoomOnWheel: false,
            zoomOnTouch: false,
            cropend: () => {
                this.handleCropGestureEnd();
            },
            ready: onReady,
        };
    }

    /**
     * Load an image source into Cropper and optionally restore a snapshot.
     *
     * @param {Object|null} [snapshot]
     * @returns {Promise<void>}
     */
    async mountCropper(snapshot = null) {
        if (!(this.image instanceof HTMLImageElement)) {
            throw new Error('Preview image is not available');
        }

        this.suppressCropHistory = true;
        if (this.cropper) {
            this.cropper.destroy();
            this.cropper = null;
        }

        const targetSrc = snapshot?.imageSrc || this.workingImageSrc || this.image.src;
        if (this.image.src !== targetSrc) {
            this.image.style.filter = '';
            this.image.src = targetSrc;
        }
        this.workingImageSrc = targetSrc;

        await this.waitForImage(this.image);

        return new Promise((resolve, reject) => {
            const handleReady = () => {
                if (!this.cropper) {
                    reject(new Error('Cropper failed to initialise'));
                    return;
                }

                if (snapshot?.canvasData) {
                    this.cropper.setCanvasData(snapshot.canvasData);
                }
                if (snapshot?.data) {
                    this.cropper.setData(snapshot.data);
                }

                if (snapshot?.fitPreview) {
                    this.ensureCanvasFitsPreview();
                }

                const canvasData = this.cropper.getCanvasData();
                if (!snapshot?.fitPreview) {
                    this.baseZoomRatio = canvasData.width / canvasData.naturalWidth;
                }

                if (snapshot) {
                    this.filters = {...createDefaultFilters(), ...(snapshot.filters || {})};
                    this.flipH = !!snapshot.flipH;
                    this.flipV = !!snapshot.flipV;
                }

                this.syncFilterUi();
                this.applyFilterPreview();
                this.updateActionButtons();
                window.requestAnimationFrame(() => {
                    this.suppressCropHistory = false;
                    resolve();
                });
            };

            this.cropper = new Cropper(this.image, this.getCropperConfig(handleReady));
        });
    }

    /**
     * Record the current state in history when it differs from the present index.
     */
    pushHistory() {
        const snapshot = this.createSnapshot();
        const current = this.history[this.historyIndex];
        if (current && snapshotsEqual(snapshot, current)) {
            this.updateActionButtons();
            return;
        }

        this.history = this.history.slice(0, this.historyIndex + 1);
        this.history.push(snapshot);
        this.historyIndex = this.history.length - 1;
        this.updateHistoryButtons();
        this.updateActionButtons();
        this.notifyState();
    }

    /**
     * Run an edit action and append history only when the image state changes.
     *
     * @param {Function} actionFn
     */
    commitIfChanged(actionFn) {
        const before = this.createSnapshot();
        actionFn();
        this.syncFilterUi();
        this.applyFilterPreview();
        const after = this.createSnapshot();
        if (!snapshotsEqual(before, after)) {
            this.pushHistory();
        } else {
            this.updateActionButtons();
        }
    }

    /**
     * Record crop-box or canvas drag gestures when they change the image state.
     */
    handleCropGestureEnd() {
        if (!this.editing || !this.cropper || this.suppressCropHistory) {
            return;
        }
        this.pushHistory();
    }

    /**
     * Apply horizontal and vertical flip from toggle state.
     */
    applyFlipState() {
        if (!this.cropper) {
            return;
        }
        const imageData = this.cropper.getImageData();
        const absX = Math.abs(imageData.scaleX) || 1;
        const absY = Math.abs(imageData.scaleY) || 1;
        this.cropper.scaleX(this.flipH ? -absX : absX);
        this.cropper.scaleY(this.flipV ? -absY : absY);
    }

    /**
     * Show or hide inline brightness/contrast sliders without changing values.
     */
    syncToneControlsUi() {
        if (this.brightnessInput instanceof HTMLInputElement) {
            this.brightnessInput.classList.toggle('d-none', !this.brightnessSliderVisible);
        }
        if (this.contrastInput instanceof HTMLInputElement) {
            this.contrastInput.classList.toggle('d-none', !this.contrastSliderVisible);
        }
        const brightnessToggle = this.panel.querySelector('[data-action="manual-toggle-brightness"]');
        const contrastToggle = this.panel.querySelector('[data-action="manual-toggle-contrast"]');
        if (brightnessToggle instanceof HTMLElement) {
            brightnessToggle.classList.toggle('active', this.brightnessSliderVisible);
        }
        if (contrastToggle instanceof HTMLElement) {
            contrastToggle.classList.toggle('active', this.contrastSliderVisible);
        }
    }

    /**
     * @returns {void}
     */
    updateHistoryButtons() {
        const undoBtn = this.panel.querySelector('[data-action="manual-undo"]');
        const redoBtn = this.panel.querySelector('[data-action="manual-redo"]');
        const canUndo = this.historyIndex > 0;
        const canRedo = this.historyIndex >= 0 && this.historyIndex < this.history.length - 1;

        if (undoBtn instanceof HTMLButtonElement) {
            undoBtn.disabled = !canUndo;
        }
        if (redoBtn instanceof HTMLButtonElement) {
            redoBtn.disabled = !canRedo;
        }
    }

    /**
     * Enable or disable crop/zoom actions that would be no-ops in the current state.
     */
    updateActionButtons() {
        const cropBtn = this.panel.querySelector('[data-action="manual-apply-crop"]');
        const zoomOutBtn = this.panel.querySelector('[data-action="manual-zoom-out"]');
        const zoomResetBtn = this.panel.querySelector('[data-action="manual-zoom-reset"]');

        if (!this.editing || !this.cropper) {
            if (cropBtn instanceof HTMLButtonElement) {
                cropBtn.disabled = true;
            }
            if (zoomOutBtn instanceof HTMLButtonElement) {
                zoomOutBtn.disabled = true;
            }
            if (zoomResetBtn instanceof HTMLButtonElement) {
                zoomResetBtn.disabled = true;
            }
            return;
        }

        if (cropBtn instanceof HTMLButtonElement) {
            cropBtn.disabled = !this.hasPendingCropTransform();
        }
        if (zoomOutBtn instanceof HTMLButtonElement) {
            zoomOutBtn.disabled = this.isAtMinZoom();
        }
        if (zoomResetBtn instanceof HTMLButtonElement) {
            zoomResetBtn.disabled = this.isAtBaseZoom();
        }
    }

    /**
     * @returns {Promise<void>}
     */
    async undo() {
        if (this.historyIndex <= 0) {
            return;
        }
        this.historyIndex -= 1;
        await this.applySnapshot(this.history[this.historyIndex]);
        this.updateHistoryButtons();
        this.updateActionButtons();
        this.notifyState();
    }

    /**
     * @returns {Promise<void>}
     */
    async redo() {
        if (this.historyIndex < 0 || this.historyIndex >= this.history.length - 1) {
            return;
        }
        this.historyIndex += 1;
        await this.applySnapshot(this.history[this.historyIndex]);
        this.updateHistoryButtons();
        this.updateActionButtons();
        this.notifyState();
    }

    /**
     * @returns {void}
     */
    syncFilterUi() {
        if (this.brightnessInput instanceof HTMLInputElement) {
            this.brightnessInput.value = String(this.filters.brightness);
        }
        if (this.contrastInput instanceof HTMLInputElement) {
            this.contrastInput.value = String(this.filters.contrast);
        }
        this.panel.querySelectorAll(
            '[data-action="manual-filter-grayscale"], [data-action="manual-filter-sepia"],'
            + ' [data-action="manual-flip-h"], [data-action="manual-flip-v"]'
        ).forEach((button) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }
            const action = button.dataset.action || '';
            const active = (
                (action === 'manual-filter-grayscale' && this.filters.grayscale)
                || (action === 'manual-filter-sepia' && this.filters.sepia)
                || (action === 'manual-flip-h' && this.flipH)
                || (action === 'manual-flip-v' && this.flipV)
            );
            button.classList.toggle('active', active);
        });
        this.syncToneControlsUi();
    }

    /**
     * Apply CSS filters to cropper image for live preview.
     */
    applyFilterPreview() {
        const filterValue = buildCssFilter(this.filters);
        const targets = this.panel.querySelectorAll('.cropper-canvas, .cropper-view-box img');
        targets.forEach((node) => {
            if (node instanceof HTMLElement) {
                node.style.filter = filterValue;
            }
        });
        if (this.image instanceof HTMLImageElement) {
            this.image.style.filter = filterValue;
        }
    }

    /**
     * @returns {Promise<void>}
     */
    async enterEditMode() {
        if (this.editing || this.isBlocked() || !(this.image instanceof HTMLImageElement)) {
            return;
        }

        await this.waitForImage(this.image);
        if (this.boundedScale(this.image.naturalWidth, this.image.naturalHeight) < 1) {
            // No canvas is built for an image above the stored image bounds.
            await this.showImageTooLarge();
            return;
        }

        this.baselineSrc = this.image.src;
        this.workingImageSrc = this.image.src;
        this.filters = createDefaultFilters();
        this.flipH = false;
        this.flipV = false;
        this.brightnessSliderVisible = false;
        this.contrastSliderVisible = false;
        this.history = [];
        this.historyIndex = -1;
        this.suppressCropHistory = true;

        this.editing = true;
        this.panel.classList.add('is-manual-editing');
        this.toolbars?.classList.remove('d-none');
        this.syncToneControlsUi();
        this.startButton?.classList.add('d-none');
        this.saveDiscard?.classList.remove('d-none');

        await this.mountCropper();
        this.history = [this.createSnapshot()];
        this.historyIndex = 0;
        this.updateHistoryButtons();
        this.updateActionButtons();
        this.notifyState();
    }

    /**
     * @param {HTMLImageElement} img
     * @returns {Promise<void>}
     */
    waitForImage(img) {
        if (img.complete && img.naturalWidth > 0) {
            return Promise.resolve();
        }
        return new Promise((resolve, reject) => {
            img.addEventListener('load', () => resolve(), {once: true});
            img.addEventListener('error', () => reject(new Error('Image failed to load')), {once: true});
        });
    }

    /**
     * Exit edit mode and optionally restore the original preview URL.
     *
     * @param {boolean} restoreBaseline
     */
    exitEditMode(restoreBaseline = true) {
        if (this.cropper) {
            this.cropper.destroy();
            this.cropper = null;
        }

        if (this.image instanceof HTMLImageElement) {
            this.image.style.filter = '';
            if (restoreBaseline && this.baselineSrc) {
                this.image.src = this.baselineSrc;
            }
        }

        this.editing = false;
        this.saving = false;
        this.filters = createDefaultFilters();
        this.flipH = false;
        this.flipV = false;
        this.brightnessSliderVisible = false;
        this.contrastSliderVisible = false;
        this.history = [];
        this.historyIndex = -1;
        this.baseZoomRatio = null;
        this.workingImageSrc = '';

        const undoBtn = this.panel.querySelector('[data-action="manual-undo"]');
        const redoBtn = this.panel.querySelector('[data-action="manual-redo"]');
        if (undoBtn instanceof HTMLButtonElement) {
            undoBtn.disabled = true;
        }
        if (redoBtn instanceof HTMLButtonElement) {
            redoBtn.disabled = true;
        }

        this.updateActionButtons();

        this.panel.classList.remove('is-manual-editing');
        this.toolbars?.classList.add('d-none');
        this.startButton?.classList.remove('d-none');
        this.saveDiscard?.classList.add('d-none');

        const saveBtn = this.panel.querySelector('[data-action="manual-save"]');
        if (saveBtn instanceof HTMLButtonElement) {
            saveBtn.disabled = false;
        }

        this.notifyState();
    }

    /**
     * @returns {HTMLCanvasElement|null}
     */
    exportCanvas() {
        if (!this.cropper) {
            return null;
        }
        const canvas = this.cropper.getCroppedCanvas(this.getBoundedCropOptions());
        if (!canvas) {
            return null;
        }

        const filterCss = buildCssFilter(this.filters);
        if (filterCss === buildCssFilter(createDefaultFilters())) {
            return canvas;
        }

        const filtered = document.createElement('canvas');
        filtered.width = canvas.width;
        filtered.height = canvas.height;
        const ctx = filtered.getContext('2d');
        if (!ctx) {
            return canvas;
        }
        ctx.filter = filterCss;
        ctx.drawImage(canvas, 0, 0);
        return filtered;
    }

    /**
     * @param {string} [filename]
     * @returns {Promise<void>}
     */
    async download(filename = 'image.png') {
        const canvas = this.exportCanvas();
        if (!canvas) {
            return;
        }
        const blob = await new Promise((resolve) => {
            canvas.toBlob((result) => resolve(result), 'image/png');
        });
        if (!blob) {
            return;
        }
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
    }

    /**
     * @returns {Promise<string>}
     */
    async exportBase64() {
        const canvas = this.exportCanvas();
        if (!canvas) {
            throw new Error('No canvas to export');
        }
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((result) => {
                if (!result) {
                    reject(new Error('Export failed'));
                    return;
                }
                resolve(result);
            }, 'image/png');
        });
        const buffer = await blob.arrayBuffer();
        const bytes = new Uint8Array(buffer);
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary);
    }

    /**
     * @returns {Promise<void>}
     */
    async save() {
        if (!this.editing || this.saving) {
            return;
        }
        if (!this.isDirty()) {
            this.exitEditMode(false);
            return;
        }

        const saveBtn = this.panel.querySelector('[data-action="manual-save"]');
        if (saveBtn instanceof HTMLButtonElement) {
            saveBtn.disabled = true;
        }
        this.saving = true;
        this.notifyState();

        try {
            const base64 = await this.exportBase64();
            await this.onSaveRequest(base64);
            this.exitEditMode(false);
        } catch (error) {
            this.onSaveError(error);
        } finally {
            this.saving = false;
            if (saveBtn instanceof HTMLButtonElement) {
                saveBtn.disabled = false;
            }
            this.notifyState();
        }
    }

    /**
     * Discard manual edits.
     */
    discard() {
        if (!this.editing) {
            return;
        }
        this.exitEditMode(true);
    }

    /**
     * Live preview while dragging tone sliders.
     *
     * @param {Event} event
     */
    handleSliderInput(event) {
        if (!this.editing || !(event.target instanceof HTMLInputElement)) {
            return;
        }
        const region = event.target.dataset.region;
        if (region === 'manual-brightness') {
            this.filters.brightness = parseInt(event.target.value, 10) || 100;
        } else if (region === 'manual-contrast') {
            this.filters.contrast = parseInt(event.target.value, 10) || 100;
        } else {
            return;
        }
        this.applyFilterPreview();
    }

    /**
     * Commit tone slider changes to history when the control is released.
     *
     * @param {Event} event
     */
    handleSliderChange(event) {
        if (!this.editing || !(event.target instanceof HTMLInputElement)) {
            return;
        }
        const region = event.target.dataset.region;
        if (region !== 'manual-brightness' && region !== 'manual-contrast') {
            return;
        }

        const before = this.history[this.historyIndex];
        if (region === 'manual-brightness') {
            this.filters.brightness = parseInt(event.target.value, 10) || 100;
        } else {
            this.filters.contrast = parseInt(event.target.value, 10) || 100;
        }
        this.applyFilterPreview();
        this.syncFilterUi();

        const after = this.createSnapshot();
        if (before && snapshotsEqual(before, after)) {
            return;
        }
        this.pushHistory();
    }

    /**
     * @param {string} action
     * @param {Event} event
     * @returns {boolean} True when the action was handled.
     */
    handleManualToolbarAction(action, event) {
        if (action === 'manual-save') {
            event.preventDefault();
            this.save().catch((error) => this.handleActionError(error));
            return true;
        }
        if (action === 'manual-discard') {
            event.preventDefault();
            this.discard();
            return true;
        }
        if (action === 'manual-download') {
            event.preventDefault();
            const filename = this.wrap?.dataset.filename || 'image.png';
            this.download(filename).catch((error) => this.handleActionError(error));
            return true;
        }
        if (action === 'manual-toggle-brightness') {
            event.preventDefault();
            this.brightnessSliderVisible = !this.brightnessSliderVisible;
            this.syncToneControlsUi();
            return true;
        }
        if (action === 'manual-toggle-contrast') {
            event.preventDefault();
            this.contrastSliderVisible = !this.contrastSliderVisible;
            this.syncToneControlsUi();
            return true;
        }
        if (action === 'manual-undo') {
            event.preventDefault();
            this.undo().catch((error) => this.handleActionError(error));
            return true;
        }
        if (action === 'manual-redo') {
            event.preventDefault();
            this.redo().catch((error) => this.handleActionError(error));
            return true;
        }
        return false;
    }

    /**
     * @param {string} action
     * @param {Event} event
     * @returns {void}
     */
    handleCropperToolbarAction(action, event) {
        if (!this.cropper) {
            return;
        }

        event.preventDefault();
        switch (action) {
            case 'manual-apply-crop':
                this.applyCropSelection().catch((error) => this.handleActionError(error));
                return;
            case 'manual-rotate-cw':
                this.applyRotateClockwise().catch((error) => this.handleActionError(error));
                return;
            case 'manual-flip-h':
                this.commitIfChanged(() => {
                    this.flipH = !this.flipH;
                    this.applyFlipState();
                });
                return;
            case 'manual-flip-v':
                this.commitIfChanged(() => {
                    this.flipV = !this.flipV;
                    this.applyFlipState();
                });
                return;
            case 'manual-zoom-in':
                this.commitIfChanged(() => {
                    this.cropper.zoom(0.1);
                });
                return;
            case 'manual-zoom-out':
                this.commitIfChanged(() => {
                    this.cropper.zoom(-0.1);
                });
                return;
            case 'manual-zoom-reset':
                this.commitIfChanged(() => {
                    if (this.baseZoomRatio !== null) {
                        this.cropper.zoomTo(this.baseZoomRatio);
                    }
                });
                return;
            case 'manual-filter-grayscale':
                this.commitIfChanged(() => {
                    this.filters.grayscale = !this.filters.grayscale;
                });
                return;
            case 'manual-filter-sepia':
                this.commitIfChanged(() => {
                    this.filters.sepia = !this.filters.sepia;
                });
                return;
            default:
                return;
        }
    }

    /**
     * @param {Event} event
     */
    handlePanelClick(event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (!this.editing) {
            if (target.closest('[data-action="manual-edit-start"]')) {
                event.preventDefault();
                this.enterEditMode().catch((error) => this.handleActionError(error));
            }
            return;
        }

        const actionEl = target.closest('[data-action]');
        if (!(actionEl instanceof HTMLElement)) {
            return;
        }

        const action = actionEl.dataset.action;
        if (!action || !action.startsWith('manual-')) {
            return;
        }

        if (this.handleManualToolbarAction(action, event)) {
            return;
        }

        this.handleCropperToolbarAction(action, event);
    }

    /**
     * @returns {void}
     */
    notifyState() {
        this.onStateChange({
            editing: this.editing,
            dirty: this.isDirty(),
            saving: this.saving,
        });
    }

    /**
     * @returns {void}
     */
    destroy() {
        this.panel.removeEventListener('click', this.handlePanelClick);
        this.panel.removeEventListener('input', this.handleSliderInput);
        this.panel.removeEventListener('change', this.handleSliderChange);
        if (this.editing) {
            this.exitEditMode(true);
        }
    }
}

    return PreviewEditor;
});
