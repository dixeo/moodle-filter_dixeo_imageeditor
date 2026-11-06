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
 * Embedded content image editor UI.
 *
 * @module     filter_dixeo_imageeditor/editor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/ajax',
    'core/modal_cancel',
    'core/modal_events',
    'core/notification',
    'core/templates',
    'core/str',
    'filter_dixeo_imageeditor/preview_editor',
    'filter_dixeo_imageeditor/image_sync',
    'filter_dixeo_imageeditor/polling',
    'filter_dixeo_imageeditor/toast',
], function(Ajax, CancelModal, ModalEvents, Notification, Templates, Str, PreviewEditor, imageSync, polling, toast) {
    'use strict';

    /* eslint-disable camelcase -- Mustache context and webservice fields use Moodle snake_case. */
    /* eslint-disable promise/no-nesting -- Modal handlers layer sync DOM events on async Ajax flows. */
    /* eslint-disable promise/always-return -- Fire-and-forget acknowledgement calls after job completion. */

const SELECTORS = {
    wrap: '[data-dixeo-imageeditor="1"]',
    openButton: '[data-action="open-editor"]',
};

/** @type {string} */
const COMPONENT = 'filter_dixeo_imageeditor';

/** @type {Array<Object>} */
const QUALITY_OPTIONS = [
    {value: 'low', selected: false, low: true},
    {value: 'medium', selected: true, medium: true},
    {value: 'high', selected: false, high: true},
];

/** @type {Array<Object>} */
const ASPECT_OPTIONS = [
    {value: 'landscape', size: '1536x1024', icon: 'fa-mobile fa-rotate-90', selected: true, landscape: true},
    {value: 'square', size: '1024x1024', icon: 'fa-square', selected: false, square: true},
    {value: 'portrait', size: '1024x1536', icon: 'fa-mobile', selected: false, portrait: true},
];

/** @type {boolean} */
let bound = false;

/** @type {HTMLElement|null} */
let openingWrap = null;

/**
 * Clear the in-flight open guard for one wrapper.
 *
 * @param {HTMLElement} wrap
 */
const releaseOpeningWrap = (wrap) => {
    if (openingWrap === wrap) {
        openingWrap = null;
    }
};

/**
 * Whether the manual preview editor is active.
 *
 * @param {Object|null} previewEditor
 * @returns {boolean}
 */
const isManualEditing = (previewEditor) => (
    typeof previewEditor?.isEditing === 'function' && previewEditor.isEditing()
);

/**
 * Read a File as a data URL.
 *
 * @param {File} file
 * @returns {Promise<string>}
 */
const readFileAsDataUrl = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(reader.error || new Error('read failed'));
    reader.readAsDataURL(file);
});

/**
 * Whether a file matches an HTML accept attribute value.
 *
 * @param {File} file
 * @param {string} accept
 * @returns {boolean}
 */
const fileMatchesAccept = (file, accept) => {
    if (!accept) {
        return true;
    }
    const name = file.name.toLowerCase();
    const mime = (file.type || '').toLowerCase();
    return accept.split(',').some((raw) => {
        const type = raw.trim().toLowerCase();
        if (!type) {
            return false;
        }
        if (type.startsWith('.')) {
            return name.endsWith(type);
        }
        if (type.endsWith('/*')) {
            return mime.startsWith(type.slice(0, -1));
        }
        return mime === type;
    });
};

/**
 * Ask the user to confirm replacing the current image.
 *
 * @returns {Promise<boolean>}
 */
const confirmReplaceImage = async() => {
    const title = await Str.getString('upload_replace_title', 'filter_dixeo_imageeditor');
    const body = await Str.getString('upload_replace_body', 'filter_dixeo_imageeditor');
    const confirmLabel = await Str.getString('upload_replace_confirm', 'filter_dixeo_imageeditor');
    const cancelLabel = await Str.getString('cancel', 'moodle');
    return new Promise((resolve) => {
        Notification.confirm(title, body, confirmLabel, cancelLabel, () => resolve(true), () => resolve(false));
    });
};

/**
 * Show a short success toast on the embedded image and in the modal preview.
 *
 * @param {HTMLElement} wrap
 * @param {HTMLElement|null} root
 * @returns {Promise<void>}
 */
const notifyImageUpdated = async(wrap, root = null) => {
    const message = await Str.getString('image_updated', COMPONENT);
    toast.showResult(wrap, root, message, 'success');
};

/**
 * Toggle generating overlay on the modal preview panel.
 *
 * @param {HTMLElement} root
 * @param {boolean} active
 * @param {string} label
 */
const setPreviewGenerating = (root, active, label = '') => {
    const panel = root.querySelector('[data-region="preview-panel"]');
    if (!(panel instanceof HTMLElement)) {
        return;
    }
    if (active) {
        panel.classList.add(polling.GENERATING_CLASS);
        panel.dataset.dixeoImageGeneratingLabel = label;
        return;
    }
    panel.classList.remove(polling.GENERATING_CLASS);
    delete panel.dataset.dixeoImageGeneratingLabel;
};

/**
 * Enable or disable the in-panel submit button.
 *
 * @param {HTMLElement} root
 * @param {boolean} disabled
 */
const setSubmitDisabled = (root, disabled) => {
    const button = root.querySelector('[data-region="submit-job"]');
    if (button instanceof HTMLButtonElement) {
        button.disabled = disabled;
    }
};

/**
 * Enable or disable AI controls while manual editing is active.
 *
 * @param {HTMLElement} root
 * @param {boolean} manualEditing
 */
const setAiPanelDisabled = (root, manualEditing) => {
    const modalRoot = root.querySelector('[data-region="modal-root"]');
    if (modalRoot instanceof HTMLElement) {
        modalRoot.classList.toggle('is-manual-editing', manualEditing);
    }

    root.querySelectorAll(
        'textarea[data-region="prompt"],'
        + ' input[name="dixeo-imageeditor-mode"],'
        + ' input[name="dixeo-imageeditor-quality"],'
        + ' input[name="dixeo-imageeditor-aspect"]'
    ).forEach((element) => {
        if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
            element.disabled = manualEditing;
        }
    });

    setSubmitDisabled(root, manualEditing);

    root.querySelectorAll(
        '[data-region="history-wrapper"] button, [data-region="history-wrapper"] a'
    ).forEach((element) => {
        if (element instanceof HTMLButtonElement || element instanceof HTMLAnchorElement) {
            element.disabled = manualEditing;
            if (manualEditing) {
                element.setAttribute('aria-disabled', 'true');
            } else {
                element.removeAttribute('aria-disabled');
            }
        }
    });
};

/**
 * Mark the history entry that matches the live image contenthash.
 *
 * @param {Array} history
 * @param {string} currentContenthash
 * @returns {Array}
 */
const mapHistoryForCarousel = (history, currentContenthash) => (history || []).map((item) => ({
    ...item,
    is_current: !!currentContenthash && item.contenthash === currentContenthash,
}));

/**
 * Build modal template context.
 *
 * @param {Object} context
 * @param {string} mode
 * @returns {Object}
 */
const buildModalContext = (context, mode) => {
    const canGenerate = context.policy_can_generate && context.cap_can_generate;
    const canEdit = context.policy_can_edit && context.cap_can_edit;
    const showAiPanel = canGenerate || canEdit;
    const showModeToggle = canGenerate && canEdit;
    const showAiEditMode = canEdit;
    const activeMode = showAiEditMode && mode === 'edit' ? 'edit' : 'generate';
    const isGenerate = activeMode === 'generate';

    return {
        imageurl: imageSync.appendImageRev(context.imageurl, context.current_contenthash || ''),
        can_generate: canGenerate,
        can_edit: canEdit,
        can_manage_image: true,
        show_ai_panel: showAiPanel,
        show_mode_toggle: showModeToggle,
        show_ai_edit_mode: showAiEditMode,
        mode_generate_active: isGenerate,
        mode_edit_active: !isGenerate,
        qualities: QUALITY_OPTIONS,
        aspects: ASPECT_OPTIONS,
        history: mapHistoryForCarousel(context.history, context.current_contenthash || ''),
        has_history: (context.history || []).length > 0,
        upload_accept: context.upload_accept,
    };
};

/**
 * Build Mustache context for the history carousel partial.
 *
 * @param {Object} context
 * @param {Array} history
 * @param {string} currentContenthash
 * @returns {Object}
 */
const buildHistoryCarouselContext = (context, history, currentContenthash) => ({
    history: mapHistoryForCarousel(history, currentContenthash || context.current_contenthash || ''),
});

/**
 * Remove every history wrapper under the modal root (clears races/duplicates).
 *
 * @param {HTMLElement} root
 */
const removeHistoryWrappers = (root) => {
    root.querySelectorAll('[data-region="history-wrapper"]').forEach((el) => el.remove());
};

/** @type {WeakMap<HTMLElement, Promise<void>>} */
const historyRefreshTail = new WeakMap();

/**
 * Re-render or remove the history carousel after a history change.
 *
 * @param {HTMLElement} root
 * @param {Object} context
 * @param {Array} history
 * @param {string} [currentContenthash]
 * @returns {Promise<void>}
 */
const refreshHistorySection = (root, context, history, currentContenthash) => {
    const run = async() => {
        if (!history.length) {
            removeHistoryWrappers(root);
            return;
        }
        const hash = currentContenthash || context.current_contenthash || '';
        const sectionContext = buildHistoryCarouselContext(context, history, hash);
        const {html, js} = await Templates.renderForPromise('filter_dixeo_imageeditor/history_section', sectionContext);
        // Re-query after await so any wrappers added while rendering are replaced, not stacked.
        removeHistoryWrappers(root);
        const modalRoot = root.querySelector('[data-region="modal-root"]') || root;
        modalRoot.insertAdjacentHTML('beforeend', html);
        if (js) {
            Templates.runTemplateJS(js);
        }
    };

    const previous = historyRefreshTail.get(root) || Promise.resolve();
    const next = previous.catch(() => undefined).then(run);
    historyRefreshTail.set(root, next);
    return next;
};

/**
 * Fetch fresh history and current contenthash from the server.
 *
 * @param {HTMLElement} wrap
 * @returns {Promise<{history: Array, currentContenthash: string}>}
 */
const fetchEditorSnapshot = (wrap) => Ajax.call([{
    methodname: 'filter_dixeo_imageeditor_get_editor_context',
    args: imageSync.getLocationArgs(wrap),
}])[0].then((response) => ({
    history: response.history || [],
    currentContenthash: response.current_contenthash || '',
}));

/**
 * Read selected radio value from a named group.
 *
 * @param {HTMLElement} root
 * @param {string} name
 * @returns {string}
 */
const getCheckedRadioValue = (root, name) => {
    const checked = root.querySelector(`input[type="radio"][name="${name}"]:checked`);
    return checked instanceof HTMLInputElement ? checked.value : '';
};

/**
 * Read selected aspect API size from the modal.
 *
 * @param {HTMLElement} root
 * @returns {string}
 */
const getSelectedSize = (root) => {
    const checked = root.querySelector('input[type="radio"][name="dixeo-imageeditor-aspect"]:checked');
    if (checked instanceof HTMLInputElement && checked.dataset.size) {
        return checked.dataset.size;
    }
    return '1536x1024';
};

/**
 * Submit generate or edit job.
 *
 * @param {HTMLElement} wrap
 * @param {string} mode
 * @param {Object} form
 * @returns {Promise}
 */
const submitJob = (wrap, mode, form) => {
    const args = {
        ...imageSync.getLocationArgs(wrap),
        size: form.size,
        quality: form.quality,
    };

    if (mode === 'generate') {
        return Ajax.call([{
            methodname: 'filter_dixeo_imageeditor_start_generate',
            args: {...args, prompt: form.prompt},
        }])[0];
    }

    return Ajax.call([{
        methodname: 'filter_dixeo_imageeditor_start_edit',
        args: {...args, instructions: form.prompt},
    }])[0];
};

/**
 * Find index of a version in history.
 *
 * @param {Array} history
 * @param {number} versionid
 * @returns {number}
 */
const findHistoryIndex = (history, versionid) => history.findIndex((item) => parseInt(item.id, 10) === versionid);

/**
 * Open the history preview modal for one version.
 *
 * @param {Object} options
 * @returns {Promise<Object|null>}
 */
const openHistoryPreviewModal = async(options) => {
    const {
        historyState,
        startIndex,
        wrap,
        editorRoot,
        previewEditor = null,
        onHistoryChange,
    } = options;

    if (!historyState.history.length) {
        return null;
    }

    let index = Math.max(0, Math.min(startIndex, historyState.history.length - 1));
    let previewModal = null;

    const renderPreviewBody = async() => {
        const item = historyState.history[index];
        const manualEditing = isManualEditing(previewEditor);
        const previewContext = {
            previewurl: item.previewurl,
            timecreated: item.timecreated,
            versionid: item.id,
            has_prev: index > 0,
            has_next: index < historyState.history.length - 1,
            is_current: !!historyState.currentContenthash
                && item.contenthash === historyState.currentContenthash,
            manual_editing: manualEditing,
        };
        return Templates.renderForPromise('filter_dixeo_imageeditor/history_preview_modal', previewContext);
    };

    const updatePreviewContent = async(previewRoot) => {
        const {html, js} = await renderPreviewBody();
        const container = previewRoot.querySelector('[data-region="history-preview-root"]');
        if (container) {
            container.outerHTML = html;
        }
        if (js) {
            Templates.runTemplateJS(js);
        }
    };

    const {html} = await renderPreviewBody();
    previewModal = await CancelModal.create({
        title: await Str.getString('history_preview_title', COMPONENT),
        body: html,
        large: true,
    });
    previewModal.getModal().addClass('dixeo-imageeditor-history-preview-dialog');
    previewModal.setButtonText('cancel', await Str.getString('close', 'filter_dixeo_imageeditor'));

    const previewRoot = previewModal.getRoot()[0];

    const handleSetCurrent = async(versionid) => {
        if (isManualEditing(previewEditor)) {
            return;
        }
        const response = await Ajax.call([{
            methodname: 'filter_dixeo_imageeditor_revert_version',
            args: {...imageSync.getLocationArgs(wrap), versionid},
        }])[0];
        await imageSync.applyImageResponse(response, {
            wrap,
            root: editorRoot,
            previewEditor,
            syncHistory: async(history, contenthash) => {
                historyState.history = history;
                await onHistoryChange(history, contenthash || '');
            },
        });
        await notifyImageUpdated(wrap, editorRoot);
        previewModal.hide();
    };

    const handleDelete = async(versionid) => {
        if (isManualEditing(previewEditor)) {
            return;
        }
        const response = await Ajax.call([{
            methodname: 'filter_dixeo_imageeditor_delete_version',
            args: {...imageSync.getLocationArgs(wrap), versionid},
        }])[0];
        historyState.history = response.history || [];
        await onHistoryChange(historyState.history, response.current_contenthash || '');
        if (!historyState.history.length) {
            previewModal.hide();
            return;
        }
        if (index >= historyState.history.length) {
            index = historyState.history.length - 1;
        }
        await updatePreviewContent(previewRoot);
    };

    previewRoot.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const actionEl = target.closest('[data-action]');
        if (!(actionEl instanceof HTMLElement)) {
            return;
        }

        const action = actionEl.dataset.action;
        if (action === 'history-prev' && index > 0) {
            event.preventDefault();
            index -= 1;
            updatePreviewContent(previewRoot).catch(Notification.exception);
            return;
        }
        if (action === 'history-next' && index < historyState.history.length - 1) {
            event.preventDefault();
            index += 1;
            updatePreviewContent(previewRoot).catch(Notification.exception);
            return;
        }

        const versionid = parseInt(actionEl.dataset.versionid, 10);
        if (!versionid) {
            return;
        }

        if (action === 'set-current') {
            event.preventDefault();
            if (isManualEditing(previewEditor)) {
                return;
            }
            handleSetCurrent(versionid).catch(Notification.exception);
            return;
        }
        if (action === 'delete-history') {
            event.preventDefault();
            if (isManualEditing(previewEditor)) {
                return;
            }
            handleDelete(versionid).catch(Notification.exception);
        }
    });

    previewModal.getRoot().on(ModalEvents.hidden, () => {
        previewModal.destroy();
    });

    previewModal.show();
    return previewModal;
};

/**
 * Open the editor modal for one wrapped image.
 *
 * @param {HTMLElement} wrap
 */
const openEditor = (wrap) => {
    if (openingWrap === wrap) {
        return;
    }
    openingWrap = wrap;

    Ajax.call([{
        methodname: 'filter_dixeo_imageeditor_get_editor_context',
        args: imageSync.getLocationArgs(wrap),
    }])[0].then(async(context) => {
        if (context.locked) {
            releaseOpeningWrap(wrap);
            Notification.addNotification({
                message: await Str.getString('error_locked', 'filter_dixeo_imageeditor'),
                type: 'warning',
            });
            return;
        }

        if (!context.current_contenthash) {
            context.imageurl = '';
            context.policy_can_edit = false;
            context.cap_can_edit = false;
        }

        const canGenerate = context.policy_can_generate && context.cap_can_generate;
        const canEdit = context.policy_can_edit && context.cap_can_edit;

        let mode = canGenerate ? 'generate' : 'edit';
        const historyState = {
            history: context.history || [],
            currentContenthash: context.current_contenthash || '',
        };
        const modalContext = buildModalContext(context, mode);
        const {html} = await Templates.renderForPromise('filter_dixeo_imageeditor/editor_modal', modalContext);

        const modal = await CancelModal.create({
            title: await Str.getString('modal_title', 'filter_dixeo_imageeditor'),
            body: html,
        });

        modal.getModal().addClass('modal-xl dixeo-imageeditor-modal-dialog');
        modal.setButtonText('cancel', await Str.getString('close', 'filter_dixeo_imageeditor'));

        const root = modal.getRoot()[0];
        toast.registerOpenModal(wrap, root);
        const previewPanel = root.querySelector('[data-region="preview-panel"]');
        let previewEditor = null;

        const syncHistory = async(history, currentContenthash) => {
            if (currentContenthash) {
                context.current_contenthash = currentContenthash;
                historyState.currentContenthash = currentContenthash;
            }
            historyState.history = history;
            await refreshHistorySection(root, context, history, context.current_contenthash);
        };

        const imageResponseContext = () => ({wrap, root, previewEditor, syncHistory});

        if (previewPanel instanceof HTMLElement) {
            previewEditor = new PreviewEditor(previewPanel, {
                wrap,
                maxImageDimension: context.max_image_dimension,
                maxImagePixels: context.max_image_pixels,
                onStateChange: ({editing}) => {
                    setAiPanelDisabled(root, editing);
                },
                onSaveError: (error) => {
                    Notification.exception(error);
                },
                onSaveRequest: async(imageBase64) => {
                    const response = await Ajax.call([{
                        methodname: 'filter_dixeo_imageeditor_apply_manual_edit',
                        args: {
                            ...imageSync.getLocationArgs(wrap),
                            image_base64: imageBase64,
                        },
                    }])[0];
                    const result = await imageSync.applyImageResponse(response, imageResponseContext());
                    await notifyImageUpdated(wrap, root);
                    return result;
                },
            });
        }

        const confirmDiscardAndClose = async() => {
            if (!previewEditor?.isDirty()) {
                modal.hide();
                return;
            }
            const title = await Str.getString('manual_unsaved_changes_title', 'filter_dixeo_imageeditor');
            const body = await Str.getString('manual_unsaved_changes_body', 'filter_dixeo_imageeditor');
            const confirmLabel = await Str.getString('manual_discard', 'filter_dixeo_imageeditor');
            Notification.confirm(title, body, confirmLabel, null, () => {
                previewEditor?.discard();
                modal.hide();
            });
        };

        modal.getRoot().on(ModalEvents.cancel, (event) => {
            if (previewEditor?.isDirty()) {
                event.preventDefault();
                confirmDiscardAndClose().catch(Notification.exception);
            }
        });

        modal.getRoot().on(ModalEvents.outsideClick, (event) => {
            if (previewEditor?.isDirty()) {
                event.preventDefault();
                confirmDiscardAndClose().catch(Notification.exception);
            }
        });

        modal.getRoot().on(ModalEvents.hidden, () => {
            toast.unregisterOpenModal(wrap);
            releaseOpeningWrap(wrap);
            previewEditor?.destroy();
            modal.destroy();
        });

        const promptField = root.querySelector('[data-region="prompt"]');
        const promptLabel = root.querySelector('[data-region="prompt-label"]');
        const submitButton = root.querySelector('[data-region="submit-job"]');

        if (context.prefill_prompt && promptField instanceof HTMLTextAreaElement) {
            promptField.value = context.prefill_prompt;
        }
        if (context.prefill_quality) {
            const qualityInput = root.querySelector(
                'input[name="dixeo-imageeditor-quality"][value="' + context.prefill_quality + '"]'
            );
            if (qualityInput instanceof HTMLInputElement) {
                qualityInput.checked = true;
            }
        }
        if (context.prefill_mode) {
            const aspectInput = root.querySelector(
                'input[name="dixeo-imageeditor-aspect"][value="' + context.prefill_mode + '"]'
            );
            if (aspectInput instanceof HTMLInputElement) {
                aspectInput.checked = true;
            }
        }

        const updateSubmitLabel = async() => {
            if (!(submitButton instanceof HTMLButtonElement)) {
                return;
            }
            const stringid = mode === 'generate' ? 'submit_generate' : 'submit_edit';
            submitButton.textContent = await Str.getString(stringid, COMPONENT);
        };

        const setMode = async(nextMode) => {
            mode = nextMode;
            const isGenerate = mode === 'generate';

            if (promptLabel instanceof HTMLElement) {
                promptLabel.textContent = await Str.getString(
                    isGenerate ? 'prompt_label_generate' : 'prompt_label_edit',
                    COMPONENT
                );
            }
            if (promptField instanceof HTMLTextAreaElement) {
                promptField.placeholder = await Str.getString(
                    isGenerate ? 'prompt_placeholder_generate' : 'prompt_placeholder_edit',
                    COMPONENT
                );
            }

            root.querySelectorAll('input[name="dixeo-imageeditor-mode"]').forEach((input) => {
                if (!(input instanceof HTMLInputElement)) {
                    return;
                }
                const label = input.closest('label');
                if (label) {
                    label.classList.toggle('active', input.value === mode);
                }
            });

            await updateSubmitLabel();
        };

        const handleSetCurrent = (versionid) => Ajax.call([{
            methodname: 'filter_dixeo_imageeditor_revert_version',
            args: {...imageSync.getLocationArgs(wrap), versionid},
        }])[0].then(async(response) => {
            const result = await imageSync.applyImageResponse(response, imageResponseContext());
            await notifyImageUpdated(wrap, root);
            return result;
        });

        const handleDeleteHistory = (versionid) => Ajax.call([{
            methodname: 'filter_dixeo_imageeditor_delete_version',
            args: {...imageSync.getLocationArgs(wrap), versionid},
        }])[0].then(async(response) => {
            await syncHistory(response.history || [], response.current_contenthash || '');
        });

        const uploadAccept = context.upload_accept || '';
        const uploadInput = root.querySelector('[data-region="upload-input"]');
        const previewStage = root.querySelector('[data-region="preview-stage"]');

        const resetUploadInput = () => {
            if (uploadInput instanceof HTMLInputElement) {
                uploadInput.value = '';
            }
        };

        const isUploadBlocked = () => previewEditor?.isEditing()
            || previewEditor?.isSaving()
            || previewPanel?.classList.contains(polling.GENERATING_CLASS);

        const notifyInvalidUploadType = async() => {
            const acceptList = uploadAccept.replace(/\./g, '').replace(/,/g, ', ');
            Notification.addNotification({
                message: await Str.getString('upload_invalid_type', 'filter_dixeo_imageeditor', acceptList),
                type: 'error',
            });
        };

        const processUploadFile = async(file) => {
            if (!(file instanceof File) || isUploadBlocked()) {
                return;
            }
            if (!fileMatchesAccept(file, uploadAccept)) {
                await notifyInvalidUploadType();
                resetUploadInput();
                return;
            }
            const confirmed = await confirmReplaceImage();
            if (!confirmed) {
                resetUploadInput();
                return;
            }
            try {
                const dataUrl = await readFileAsDataUrl(file);
                const response = await Ajax.call([{
                    methodname: 'filter_dixeo_imageeditor_apply_upload',
                    args: {
                        ...imageSync.getLocationArgs(wrap),
                        image_base64: dataUrl,
                    },
                }])[0];
                await imageSync.applyImageResponse(response, imageResponseContext());
                await notifyImageUpdated(wrap, root);
            } catch (error) {
                Notification.exception(error);
            } finally {
                resetUploadInput();
            }
        };

        if (uploadInput instanceof HTMLInputElement) {
            uploadInput.addEventListener('change', () => {
                const file = uploadInput.files?.[0];
                if (file) {
                    processUploadFile(file).catch(Notification.exception);
                }
            });
        }

        if (previewStage instanceof HTMLElement) {
            let dragDepth = 0;

            const setDragActive = (active) => {
                previewStage.classList.toggle('is-upload-dragover', active);
            };

            previewStage.addEventListener('dragenter', (event) => {
                if (isUploadBlocked()) {
                    return;
                }
                if (!event.dataTransfer?.types?.includes('Files')) {
                    return;
                }
                event.preventDefault();
                dragDepth += 1;
                setDragActive(true);
            });

            previewStage.addEventListener('dragover', (event) => {
                if (isUploadBlocked()) {
                    return;
                }
                if (!event.dataTransfer?.types?.includes('Files')) {
                    return;
                }
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            });

            previewStage.addEventListener('dragleave', () => {
                dragDepth = Math.max(0, dragDepth - 1);
                if (dragDepth === 0) {
                    setDragActive(false);
                }
            });

            previewStage.addEventListener('drop', (event) => {
                dragDepth = 0;
                setDragActive(false);
                if (isUploadBlocked()) {
                    return;
                }
                event.preventDefault();
                const file = event.dataTransfer?.files?.[0];
                if (file) {
                    processUploadFile(file).catch(Notification.exception);
                }
            });
        }

        const handleSubmitJob = async() => {
            if (previewEditor?.isEditing()) {
                return;
            }
            const prompt = promptField instanceof HTMLTextAreaElement ? promptField.value.trim() : '';
            if (prompt === '') {
                Notification.addNotification({
                    message: await Str.getString(
                        mode === 'generate' ? 'prompt_required' : 'instructions_required',
                        'filter_dixeo_imageeditor'
                    ),
                    type: 'error',
                });
                return;
            }

            setSubmitDisabled(root, true);
            const label = await Str.getString('generating_status', 'filter_dixeo_imageeditor');
            setPreviewGenerating(root, true, label);
            polling.setGeneratingOverlay(wrap, true, label);

            submitJob(wrap, mode, {
                prompt,
                size: getSelectedSize(root),
                quality: getCheckedRadioValue(root, 'dixeo-imageeditor-quality') || 'medium',
            }).then(() => {
                polling.startStatusPolling(wrap, {
                    onApplied: async(status) => {
                        setPreviewGenerating(root, false);
                        setSubmitDisabled(root, false);
                        if (!previewEditor?.isEditing()) {
                            await imageSync.applyImageResponse({
                                imageurl: status.imageurl || '',
                                current_contenthash: status.current_contenthash || wrap.dataset.contenthash || '',
                                history: [],
                            }, {
                                wrap,
                                root,
                                previewEditor,
                                syncHistory: async() => undefined,
                            });
                        }
                        const snapshot = await fetchEditorSnapshot(wrap);
                        await syncHistory(snapshot.history, snapshot.currentContenthash);
                    },
                    onFailed: () => {
                        setPreviewGenerating(root, false);
                        setSubmitDisabled(root, false);
                    },
                });
            }).catch((error) => {
                setPreviewGenerating(root, false);
                polling.setGeneratingOverlay(wrap, false);
                setSubmitDisabled(root, false);
                Notification.exception(error);
            });
        };

        root.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }
            if (previewEditor?.isEditing()) {
                event.preventDefault();
                return;
            }
            if (target.name === 'dixeo-imageeditor-mode') {
                if (target.value === 'generate' && canGenerate) {
                    setMode('generate').catch(Notification.exception);
                }
                if (target.value === 'edit' && canEdit) {
                    setMode('edit').catch(Notification.exception);
                }
            }
        });

        root.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const actionEl = target.closest('[data-action]');
            if (!(actionEl instanceof HTMLElement)) {
                return;
            }

            const action = actionEl.dataset.action;

            if (action === 'upload-image') {
                event.preventDefault();
                if (isUploadBlocked()) {
                    return;
                }
                if (uploadInput instanceof HTMLInputElement) {
                    uploadInput.click();
                }
                return;
            }

            if (action === 'submit-job') {
                event.preventDefault();
                handleSubmitJob();
                return;
            }

            if (action === 'preview-history') {
                event.preventDefault();
                if (previewEditor?.isEditing()) {
                    return;
                }
                const versionid = parseInt(actionEl.dataset.versionid, 10);
                const startIndex = findHistoryIndex(historyState.history, versionid);
                openHistoryPreviewModal({
                    historyState,
                    startIndex: startIndex >= 0 ? startIndex : 0,
                    wrap,
                    editorRoot: root,
                    previewEditor,
                    onHistoryChange: (history, currentContenthash) => syncHistory(history, currentContenthash),
                }).catch(Notification.exception);
                return;
            }

            const versionid = parseInt(actionEl.dataset.versionid, 10);
            if (!versionid) {
                return;
            }

            if (action === 'set-current') {
                event.preventDefault();
                if (previewEditor?.isEditing()) {
                    return;
                }
                handleSetCurrent(versionid).catch(Notification.exception);
                return;
            }

            if (action === 'delete-history') {
                event.preventDefault();
                if (previewEditor?.isEditing()) {
                    return;
                }
                handleDeleteHistory(versionid).catch(Notification.exception);
            }
        });

        modal.show();
        imageSync.updatePreviewImage(root, context.imageurl, context.current_contenthash || '');
    }).catch((error) => {
        releaseOpeningWrap(wrap);
        Notification.exception(error);
    });
};

/**
 * Initialise click handlers and resume overlays.
 */
function init() {
    if (bound) {
        polling.resumePendingOverlays(SELECTORS.wrap);
        return;
    }
    bound = true;

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const button = target.closest(SELECTORS.openButton);
        if (!(button instanceof HTMLElement)) {
            return;
        }
        const wrap = button.closest(SELECTORS.wrap);
        if (!(wrap instanceof HTMLElement)) {
            return;
        }
        // Capture phase so a tile link or section popup does not also open.
        event.preventDefault();
        event.stopPropagation();
        openEditor(wrap);
    }, true);

    polling.resumePendingOverlays(SELECTORS.wrap);
}

    return {
        init: init,
    };
});
