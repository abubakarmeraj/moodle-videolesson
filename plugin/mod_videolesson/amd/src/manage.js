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
 * Manage functionality for videolesson plugin
 *
 * @module     mod_videolesson/manage
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalCancel from 'core/modal_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {initializePlayer, getUrlParameter, getSubtitles, addSubtitleTracks} from 'mod_videolesson/script';
import Notification from "core/notification";
import {get_string as getString} from 'core/str';
import {init as initFolders, ensureFolderTreeData, loadFolderTree} from 'mod_videolesson/folders';
import 'core/inplace_editable';
import * as Toast from 'core/toast';
import * as Debug from 'mod_videolesson/debug';

let currentFolder = 0;
let currentSearch = '';
let currentPage = 0;
let perPage = 10;
let tableContainer;
let isLoading = false;

let videoPlayer; // eslint-disable-line no-unused-vars
const folderParamToId = (value) => {
    if (value === null || value === undefined || value === '' || value === 'all') {
        return 0;
    }
    if (value === 'uncategorized' || value === 'null') {
        return null;
    }
    const parsed = parseInt(value, 10);
    return Number.isNaN(parsed) ? 0 : parsed;
};

const folderIdToParam = (value) => {
    if (value === null) {
        return 'uncategorized';
    }
    if (value === 0 || value === undefined) {
        return 'all';
    }
    return String(value);
};

const stateDefaults = () => {
    tableContainer = document.getElementById('videolesson-table-container');
    if (!tableContainer) {
        return;
    }
    currentFolder = folderParamToId(tableContainer.dataset.initialFolder || 'all');
    currentSearch = tableContainer.dataset.initialSearch || '';
    currentPage = parseInt(tableContainer.dataset.initialPage || '0', 10);
    perPage = parseInt(tableContainer.dataset.perPage || '10', 10);
};

const showTableSpinner = () => {
    if (tableContainer) {
        tableContainer.classList.add('videolesson-loading');
    }
};

const hideTableSpinner = () => {
    if (tableContainer) {
        tableContainer.classList.remove('videolesson-loading');
    }
};

const updateHistory = (replace = false) => {
    const url = new URL(window.location.href);
    const folderParam = folderIdToParam(currentFolder);
    if (folderParam === 'all') {
        url.searchParams.delete('folder');
    } else {
        url.searchParams.set('folder', folderParam);
    }
    url.searchParams.delete('folderid');

    if (currentSearch) {
        url.searchParams.set('search', currentSearch);
    } else {
        url.searchParams.delete('search');
    }

    url.searchParams.set('vpage', currentPage);
    url.searchParams.set('vperpage', perPage);

    const state = {
        folder: folderParam,
        search: currentSearch,
        page: currentPage
    };

    if (replace) {
        window.history.replaceState(state, '', url.toString());
    } else {
        window.history.pushState(state, '', url.toString());
    }
};

const highlightFolder = (folder) => {
    document.dispatchEvent(new CustomEvent('videolesson:set-folder', {
        detail: {folderId: folder}
    }));
};

const loadVideos = async ({folderId = currentFolder, search = currentSearch,
        page = currentPage, pushState = true, replaceState = false} = {}) => {
    if (isLoading) {
        return;
    }

    if (!tableContainer) {
        tableContainer = document.getElementById('videolesson-table-container');
    }

    if (!tableContainer) {
        return;
    }

    isLoading = true;
    showTableSpinner();

    try {
        const folderParam = folderIdToParam(folderId);
        const response = await Ajax.call([{
            methodname: 'mod_videolesson_get_videos',
            args: {
                folderid: folderParam,
                search: search,
                page: page,
                perpage: perPage
            }
        }])[0];

        const html = await Templates.render('mod_videolesson/library_table', response);
        const wrapper = tableContainer.querySelector('#videolesson-table-wrapper');
        if (wrapper) {
            wrapper.outerHTML = html;
        } else {
            tableContainer.insertAdjacentHTML('beforeend', html);
        }

        currentFolder = folderParamToId(response.filters.folderid);
        currentSearch = response.filters.search;
        currentPage = response.pagination.page;
        perPage = response.pagination.perpage;

        highlightFolder(currentFolder);
        setupBulkActions();
        initTableSorting();
        if (pushState) {
            updateHistory(replaceState);
        }
    } catch (error) {
        Notification.exception(error);
    } finally {
        hideTableSpinner();
        isLoading = false;
    }
};

/**
 * Initialize table sorting
 */
const initTableSorting = () => {
    const table = document.getElementById('videolesson-table');
    if (!table) {
        return;
    }

    const sortableHeaders = table.querySelectorAll('th.videolesson-sortable');
    let currentSort = {
        column: null,
        direction: 'asc' // 'asc' or 'desc'
    };

    sortableHeaders.forEach(header => {
        header.style.cursor = 'pointer';
        header.style.userSelect = 'none';

        header.addEventListener('click', () => {
            const column = header.getAttribute('data-sort-column');

            // Toggle direction if clicking the same column
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }

            // Update indicators
            sortableHeaders.forEach(h => {
                const indicator = h.querySelector('.sort-indicator');
                if (indicator) {
                    indicator.textContent = '';
                }
            });

            const indicator = header.querySelector('.sort-indicator');
            if (indicator) {
                indicator.textContent = currentSort.direction === 'asc' ? ' ▲' : ' ▼';
            }

            // Sort the table
            sortTableRows(table, column, currentSort.direction);
        });
    });
};

/**
 * Sort table rows by column
 * @param {HTMLElement} table - The table element.
 * @param {string} column - The column to sort.
 * @param {string} direction - The direction to sort.
 */
const sortTableRows = (table, column, direction) => {
    const tbody = table.querySelector('tbody');
    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('tr:not(.d-none)'));

    rows.sort((a, b) => {
        const aValue = a.getAttribute(`data-sort-${column}`) || '';
        const bValue = b.getAttribute(`data-sort-${column}`) || '';

        // Handle numeric columns
        if (column === 'instances' || column === 'timecreated') {
            const aNum = parseFloat(aValue) || 0;
            const bNum = parseFloat(bValue) || 0;
            return direction === 'asc' ? aNum - bNum : bNum - aNum;
        }

        // Handle text columns
        const aText = aValue.toLowerCase();
        const bText = bValue.toLowerCase();

        if (direction === 'asc') {
            return aText.localeCompare(bText);
        } else {
            return bText.localeCompare(aText);
        }
    });

    // Clear and re-append sorted rows
    tbody.innerHTML = '';
    rows.forEach(row => tbody.appendChild(row));

    // Re-attach event listeners (if needed)
    setupBulkActions();
};
/**
 * Flatten folder options
 * @param {Array<Object>} folders - The folders to flatten.
 * @param {number} selectedId - The selected ID.
 * @param {number} depth - The depth of the folders.
 * @return {Array<Object>} The flattened folder options.
 */
const flattenFolderOptions = (folders, selectedId = null, depth = 0) => {
    let options = [];
    folders.forEach(folder => {
        // Skip folders at depth 2 or higher (max depth is 3, so depth 2 cannot be a parent)
        if (folder.depth >= 2) {
            // Don't add this folder as a parent option
            return;
        }
        const indent = depth > 0 ? `${'— '.repeat(depth)}` : '';
        options.push({
            value: folder.id,
            label: `${indent}${folder.name}`,
            selected: selectedId !== null && parseInt(folder.id, 10) === parseInt(selectedId, 10)
        });
        if (folder.children && folder.children.length) {
            options = options.concat(flattenFolderOptions(folder.children, selectedId, depth + 1));
        }
    });
    return options;
};

/**
 * Get selected video IDs from checkboxes
 * @return {Array<number>} Array of selected video IDs
 */
const getSelectedVideoIds = () => {
    const checkboxes = document.querySelectorAll('.videolesson-video-checkbox:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.dataset.videoId, 10));
};

/**
 * Update bulk actions toolbar visibility and selected count
 * @return {void}
 */
const updateBulkActions = () => {
    const selectedIds = getSelectedVideoIds();
    const bulkActions = document.getElementById('videolesson-bulk-actions');
    const selectedCount = document.getElementById('videolesson-selected-count');
    const selectAll = document.getElementById('videolesson-select-all');

    if (bulkActions && selectedCount) {
        if (selectedIds.length > 0) {
            bulkActions.classList.remove('d-none');
            getString('bulk:selected_count', 'mod_videolesson').then(str => {
                selectedCount.textContent = `${selectedIds.length} ${str}`;
            });
        } else {
            bulkActions.classList.add('d-none');
        }
    }

    // Update select all checkbox state
    if (selectAll) {
        const allCheckboxes = document.querySelectorAll('.videolesson-video-checkbox');
        const checkedCount = document.querySelectorAll('.videolesson-video-checkbox:checked').length;
        selectAll.checked = allCheckboxes.length > 0 && checkedCount === allCheckboxes.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
    }
};

/**
 * Setup bulk action event listeners
 * @return {void}
 */
const setupBulkActions = () => {
    const selectAll = document.getElementById('videolesson-select-all');
    const bulkMove = document.querySelector('.videolesson-bulk-move');
    const bulkDelete = document.querySelector('.videolesson-bulk-delete');

    // Select all checkbox
    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.videolesson-video-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = e.target.checked;
            });
            updateBulkActions();
        });
    }

    // Individual checkboxes
    document.querySelectorAll('.videolesson-video-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            updateBulkActions();
        });
    });

    // Bulk move button
    if (bulkMove) {
        bulkMove.addEventListener('click', async (e) => {
            e.preventDefault();
            const selectedIds = getSelectedVideoIds();
            if (selectedIds.length === 0) {
                Toast.add(await getString('bulk:no_selection', 'mod_videolesson'), {type: 'error'});
                return;
            }
            await handleBulkMove(selectedIds);
        });
    }

    // Bulk delete button
    if (bulkDelete) {
        bulkDelete.addEventListener('click', async (e) => {
            e.preventDefault();
            const selectedIds = getSelectedVideoIds();
            if (selectedIds.length === 0) {
                Toast.add(await getString('bulk:no_selection', 'mod_videolesson'), {type: 'error'});
                return;
            }
            await handleBulkDelete(selectedIds);
        });
    }

    // Initialize bulk actions state
    updateBulkActions();
};

/**
 * Get loading body HTML with spinner
 * @param {string} message Optional loading message
 * @return {string} HTML string for loading body
 */
const getLoadingBody = (message = 'Loading...') => {
    return '<div class="text-center p-3">' +
        '<div class="spinner-border text-primary" role="status">' +
        '<span class="sr-only">Loading...</span>' +
        '</div>' +
        (message ? `<p class="mt-2">${message}</p>` : '') +
        '</div>';
};

/**
 * Handle bulk move operation
 * @param {Array<number>} videoIds Array of video IDs to move
 */
const handleBulkMove = async (videoIds) => {
    try {
        const [title, selectLabel, uncategorizedLabel, cancelText, saveText] = await Promise.all([
            getString('bulk:move', 'mod_videolesson'),
            getString('folder:select', 'mod_videolesson'),
            getString('folder:uncategorized', 'mod_videolesson'),
            getString('cancel'),
            getString('save', 'moodle')
        ]);

        // Show modal immediately with loading state
        const loadingBody = getLoadingBody('Loading folders...');

        const modal = await ModalSaveCancel.create({
            title: title,
            body: loadingBody,
            buttons: {
                cancel: cancelText,
                save: saveText,
            },
            removeOnClose: true,
        });

        modal.show();

        // Determine which folder to pre-select (do this while modal is showing)
        // First, check if all selected videos are in the same folder
        const selectedCheckboxes = document.querySelectorAll('.videolesson-video-checkbox:checked');
        const folderIds = new Set();
        let defaultFolderId = null;

        selectedCheckboxes.forEach(checkbox => {
            const row = checkbox.closest('tr.videolesson-draggable-row');
            if (row) {
                const folderIdAttr = row.getAttribute('data-folder-id');
                if (folderIdAttr && folderIdAttr !== 'null') {
                    folderIds.add(folderIdAttr);
                }
            }
        });

        // If all selected videos are in the same folder, use that folder
        if (folderIds.size === 1) {
            const folderIdValue = Array.from(folderIds)[0];
            defaultFolderId = folderIdValue === 'null' ? null : parseInt(folderIdValue, 10);
        } else {
            // Otherwise, use the current active folder (if it's not "all videos")
            if (currentFolder !== null && currentFolder !== 0) {
                defaultFolderId = currentFolder;
            }
        }

        // Load folder tree data in background
        const tree = await ensureFolderTreeData();
        const options = [
            {value: '', label: uncategorizedLabel, selected: defaultFolderId === null},
            ...flattenFolderOptions(tree, defaultFolderId)
        ];

        const body = await Templates.render('mod_videolesson/assign_folder_modal', {
            label: selectLabel,
            options: options
        });

        // Update modal body with actual content
        modal.getRoot().find('.modal-body').html(body);

        modal.getRoot().on(ModalEvents.save, async () => {
            const selected = modal.getRoot().find('#videolesson-assign-folder-select').val();
            const folderId = selected === '' ? null : parseInt(selected, 10);

            try {
                const response = await Ajax.call([{
                    methodname: 'mod_videolesson_bulk_move_videos',
                    args: {
                        videoids: videoIds,
                        folderid: folderId
                    }
                }])[0];

                if (response.success) {
                    const msg = await getString('bulk:move_success', 'mod_videolesson');
                    Toast.add(msg);
                    modal.destroy();
                    await loadFolderTree();
                    await loadVideos({
                        folderId: currentFolder,
                        search: currentSearch,
                        page: currentPage,
                        pushState: false,
                        replaceState: true
                    });
                } else {
                    Toast.add(response.error || await getString('bulk:error', 'mod_videolesson'), {type: 'error'});
                }
            } catch (error) {
                Notification.exception(error);
            }
        });

        modal.show();
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Handle single video delete operation
 * @param {number} videoId Video ID to delete
 * @param {string} videoName Video name for confirmation message
 */
const handleSingleVideoDelete = async (videoId, videoName = '') => {
    try {
        const [confirmTitle, confirmBody, cancelText, yesText] = await Promise.all([
            getString('confirmation', 'mod_videolesson'),
            getString('manage:video:delete:confirm', 'mod_videolesson').then(msg =>
                videoName ? msg.replace('{$a}', videoName) : msg.replace('{$a}', 'this video')
            ),
            getString('cancel'),
            getString('yes', 'moodle')
        ]);

        const modal = await ModalSaveCancel.create({
            title: confirmTitle,
            body: confirmBody,
            buttons: {
                cancel: cancelText,
                save: yesText
            },
            removeOnClose: true,
        });

        // Use 'one' to handle save event only once and prevent auto-close
        modal.getRoot().one(ModalEvents.save, function(e) {
            // Prevent modal from auto-closing - must be synchronous and non-async
            e.preventDefault();
            e.stopPropagation();

            // Now handle the async logic
            (async () => {
                // Show loading state in modal
                const loadingBody = getLoadingBody('Deleting video...');
                modal.getRoot().find('.modal-body').html(loadingBody);

                // Disable buttons during loading
                modal.getRoot().find('[data-action="save"]').prop('disabled', true);
                modal.getRoot().find('[data-action="cancel"]').prop('disabled', true);

                try {
                    const response = await Ajax.call([{
                        methodname: 'mod_videolesson_bulk_delete_videos',
                        args: {
                            videoids: [videoId]
                        }
                    }])[0];

                    // Close modal immediately after response
                    modal.destroy();

                    if (response.success) {
                        const msg = await getString('success:delete', 'mod_videolesson');
                        Toast.add(msg);
                        await loadFolderTree();
                        await loadVideos({
                            folderId: currentFolder,
                            search: currentSearch,
                            page: currentPage,
                            pushState: false,
                            replaceState: true
                        });
                    } else {
                        const errorMsg = response.errors && response.errors.length > 0
                            ? response.errors.join(', ')
                            : await getString('bulk:error', 'mod_videolesson');
                        Toast.add(errorMsg, {type: 'error'});
                    }
                } catch (error) {
                    // Close modal on error
                    modal.destroy();

                    // Show error notification
                    Notification.exception(error);
                }
            })(); // Close async IIFE
        });

        modal.show();
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Handle bulk delete operation
 * @param {Array<number>} videoIds Array of video IDs to delete
 */
/**
 * Handle subtitle generation
 * @param {string} contenthash Video content hash
 * @param {string} videoName Video name for display
 */
// eslint-disable-next-line no-unused-vars
const handleSubtitleGeneration = async (contenthash, videoName = '') => {
    try {
        // Show modal immediately with loading state
        const [modalTitle, cancelText, submitText] = await Promise.all([
            getString('subtitle:modal:title', 'mod_videolesson'),
            getString('cancel'),
            getString('subtitle:modal:submit', 'mod_videolesson')
        ]);

        const loadingBody = getLoadingBody('Loading...');

        const modal = await ModalSaveCancel.create({
            title: modalTitle,
            body: loadingBody,
            buttons: {
                cancel: cancelText,
                save: submitText
            },
            removeOnClose: true,
        });

        modal.show();

        // Fetch supported languages and existing subtitles in background
        const languagesResponse = await Ajax.call([{
            methodname: 'mod_videolesson_get_subtitle_languages',
            args: {
                contenthash: contenthash
            }
        }])[0];

        const languages = languagesResponse.languages || [];
        const existing = languagesResponse.existing || [];
        const pending = languagesResponse.pending || [];
        const processing = languagesResponse.processing || [];
        const failed = languagesResponse.failed || [];

        // Count existing languages excluding 'original'
        // Also exclude pending/processing from the count since they're already requested
        // Handle both old format (string) and new format (object with code/name)
        const existingCount = existing.filter(lang => {
            const code = typeof lang === 'string' ? lang : (lang.code || '');
            return code !== 'original';
        }).length;
        // Handle both old format (string) and new format (object with code/name) for pending/processing
        const pendingProcessingCount = (pending.length + processing.length);
        const maxSelectable = 5 - existingCount - pendingProcessingCount;
        const canSelectMore = maxSelectable > 0;

        // Show all available languages (excluding already selected ones)
        // The selection limit is enforced in validation, not by limiting the list
        const selectableLanguages = canSelectMore ? languages : [];

        const body = await Templates.render('mod_videolesson/subtitle_modal', {
            languages: selectableLanguages,
            existing: existing,
            pending: pending,
            processing: processing,
            failed: failed,
            maxSelectable: maxSelectable,
            canSelectMore: canSelectMore
        });

        // Update modal body with actual content
        modal.getRoot().find('.modal-body').html(body);

        // Use 'one' to handle save event only once and prevent auto-close
        modal.getRoot().one(ModalEvents.save, function(e) {
            // Prevent modal from auto-closing - must be synchronous and non-async
            e.preventDefault();
            e.stopPropagation();

            // Now handle the async logic
            (async () => {

            if (!canSelectMore) {
                const remainingMsg = await getString('subtitle:modal:remaining', 'mod_videolesson').then(msg =>
                    msg.replace('{$a}', '0')
                );
                Toast.add(remainingMsg, {type: 'error'});
                return;
            }

            const select = modal.getRoot().find('#subtitle-language-select');
            const selectedOptions = Array.from(select[0].selectedOptions);
            const selectedLanguages = selectedOptions.map(opt => opt.value);

            if (selectedLanguages.length === 0) {
                Toast.add(await getString('subtitle:select_language', 'mod_videolesson'), {type: 'error'});
                return;
            }

            // Check if any selected languages are pending/processing (shouldn't happen, but double-check)
            // Handle both old format (string) and new format (object with code/name)
            const pendingCodes = pending.map(lang => typeof lang === 'string' ? lang : (lang.code || ''));
            const processingCodes = processing.map(lang => typeof lang === 'string' ? lang : (lang.code || ''));
            const allPendingProcessing = [...pendingCodes, ...processingCodes];
            const invalidSelections = selectedLanguages.filter(lang => allPendingProcessing.includes(lang));
            if (invalidSelections.length > 0) {
                Toast.add(await getString('error:subtitle:already_requested', 'mod_videolesson'), {type: 'error'});
                return;
            }

            // Also check against existing languages
            const existingCodes = existing.map(lang => typeof lang === 'string' ? lang : (lang.code || ''));
            const duplicateSelections = selectedLanguages.filter(lang => existingCodes.includes(lang));
            if (duplicateSelections.length > 0) {
                Toast.add(await getString('error:subtitle:already_requested', 'mod_videolesson'), {type: 'error'});
                return;
            }

            if (selectedLanguages.length > maxSelectable) {
                const remainingMsg = await getString('subtitle:modal:remaining', 'mod_videolesson').then(msg =>
                    msg.replace('{$a}', maxSelectable.toString())
                );
                Toast.add(remainingMsg, {type: 'error'});
                return;
            }

            const langString = selectedLanguages.join(',');

            // Show loading state in modal
            const loadingBody = getLoadingBody('Processing subtitle request...');
            modal.getRoot().find('.modal-body').html(loadingBody);

            // Disable buttons during loading
            modal.getRoot().find('[data-action="save"]').prop('disabled', true);
            modal.getRoot().find('[data-action="cancel"]').prop('disabled', true);

            try {
                const response = await Ajax.call([{
                    methodname: 'mod_videolesson_trigger_subtitle',
                    args: {
                        contenthash: contenthash,
                        lang: langString
                    }
                }])[0];

                // Close modal immediately after response
                modal.destroy();

                // Show toast notification with result
                const [successMsg, errorMsg] = await Promise.all([
                    getString('success:subtitle:triggered', 'mod_videolesson'),
                    getString('error:subtitle:trigger_failed', 'mod_videolesson')
                ]);
                const resultMessage = response.success
                    ? (response.message || successMsg)
                    : (response.message || errorMsg);
                const resultType = response.success ? 'success' : 'error';

                Toast.add(resultMessage, {type: resultType});
            } catch (error) {
                // Close modal on error
                modal.destroy();

                // Show error notification
                Notification.exception(error);
            }
            })(); // Close async IIFE
        });

        modal.show();
    } catch (error) {
        Notification.exception(error);
    }
};

const handleBulkDelete = async (videoIds) => {
    try {
        const [confirmTitle, confirmBody, cancelText, yesText] = await Promise.all([
            getString('confirmation', 'mod_videolesson'),
            getString('bulk:confirm_delete', 'mod_videolesson').then(msg =>
                msg.replace('{$a}', videoIds.length)
            ),
            getString('cancel'),
            getString('yes', 'moodle')
        ]);

        const modal = await ModalSaveCancel.create({
            title: confirmTitle,
            body: confirmBody,
            buttons: {
                cancel: cancelText,
                save: yesText
            },
            removeOnClose: true,
        });

        // Use 'one' to handle save event only once and prevent auto-close
        modal.getRoot().one(ModalEvents.save, function(e) {
            // Prevent modal from auto-closing - must be synchronous and non-async
            e.preventDefault();
            e.stopPropagation();

            // Now handle the async logic
            (async () => {
                // Show loading state in modal
                const loadingBody = getLoadingBody('Deleting videos...');
                modal.getRoot().find('.modal-body').html(loadingBody);

                // Disable buttons during loading
                modal.getRoot().find('[data-action="save"]').prop('disabled', true);
                modal.getRoot().find('[data-action="cancel"]').prop('disabled', true);

                try {
                    const response = await Ajax.call([{
                        methodname: 'mod_videolesson_bulk_delete_videos',
                        args: {
                            videoids: videoIds
                        }
                    }])[0];

                    // Close modal immediately after response
                    modal.destroy();

                    if (response.success) {
                        const msg = await getString('bulk:delete_success', 'mod_videolesson');
                        Toast.add(msg);
                        await loadVideos({
                            folderId: currentFolder,
                            search: currentSearch,
                            page: currentPage,
                            pushState: false,
                            replaceState: true
                        });
                        await loadFolderTree();
                    } else {
                        Toast.add(response.error || await getString('bulk:error', 'mod_videolesson'), {type: 'error'});
                    }
                } catch (error) {
                    // Close modal on error
                    modal.destroy();

                    // Show error notification
                    Notification.exception(error);
                }
            })(); // Close async IIFE
        });

        modal.show();
    } catch (error) {
        Notification.exception(error);
    }
};

const assignFolder = async (videoId, folderValue) => {
    const targetFolder = folderValue === null ? null : parseInt(folderValue, 10);
    try {
        const response = await Ajax.call([{
            methodname: 'mod_videolesson_move_video',
            args: {
                videolessonid: parseInt(videoId, 10),
                folderid: targetFolder
            }
        }])[0];

        if (response.success) {
            const msg = await getString('folder:move_video_success', 'mod_videolesson');
            Toast.add(msg);
            await loadFolderTree();
            await loadVideos({
                folderId: currentFolder,
                search: currentSearch,
                page: currentPage,
                pushState: false,
                replaceState: true
            });
        } else {
            Toast.add(response.error || await getString('folder:move_video_error', 'mod_videolesson'), {type: 'error'});
        }
    } catch (error) {
        Notification.exception(error);
    }
};

const showAssignFolderModal = async (videoId, currentFolderValue, videoTitle = '') => {
    try {
        // Show modal immediately with loading state
        const [title, selectLabel, uncategorizedLabel, cancelText, saveText] = await Promise.all([
            getString('folder:assign', 'mod_videolesson'),
            getString('folder:select', 'mod_videolesson'),
            getString('folder:uncategorized', 'mod_videolesson'),
            getString('cancel'),
            getString('save', 'moodle')
        ]);

        const loadingBody = getLoadingBody('Loading folders...');

        const modal = await ModalSaveCancel.create({
            title: title,
            body: loadingBody,
            buttons: {
                cancel: cancelText,
                save: saveText,
            },
            removeOnClose: true,
        });

        modal.show();

        // Load folder tree data in background
        const tree = await ensureFolderTreeData();
        const currentFolderId = currentFolderValue === undefined || currentFolderValue === '' || currentFolderValue === 'null'
            ? null : parseInt(currentFolderValue, 10);
        const options = [
            {value: '', label: uncategorizedLabel, selected: currentFolderId === null},
            ...flattenFolderOptions(tree, currentFolderId)
        ];

        const body = await Templates.render('mod_videolesson/assign_folder_modal', {
            label: selectLabel,
            options: options,
            videotitle: videoTitle
        });

        // Update modal body with actual content
        modal.getRoot().find('.modal-body').html(body);

        modal.getRoot().on(ModalEvents.save, async () => {
            const selected = modal.getRoot().find('#videolesson-assign-folder-select').val();
            const folderId = selected === '' ? null : parseInt(selected, 10);
            await assignFolder(videoId, folderId);
            modal.destroy();
        });

        modal.show();
    } catch (error) {
        Notification.exception(error);
    }
};

const showLoader = () => {
    document.getElementById('videolesson-modal-loading').style.display = 'flex';
};

const hideLoader = () => {
    document.getElementById('videolesson-modal-loading').style.display = 'none';
};

const viewVideoModal = async (params) => {
    showLoader();
    try {
        const renderedBody = await Templates.render('mod_videolesson/view_modal', params);

        let tracks = [];
        try {
            tracks = await getSubtitles(params.contenthash);
        } catch (error) {
            Debug.error('Error fetching subtitles', error);
            Notification.exception(error);
        }

        const modal = await ModalCancel.create({
            title: params.title,
            body: renderedBody,
            large: true,
            removeOnClose: true,
        });

        modal.getRoot().on(ModalEvents.hidden, function() {
            modal.destroy();
        });
        modal.getRoot().on(ModalEvents.shown, function() {
            let v = modal.getRoot().find('#player')[0],
            c = modal.getRoot().find('#player-container-div')[0],
            p = modal.getRoot().find('#player-placeholder')[0];

            let playerParams = {
                ishls: true,
                video: v,
            };

            addSubtitleTracks(v, tracks);
            initializePlayer(playerParams)
            .then((videoPlyr) => {
                videoPlayer = videoPlyr;
            })
            .then(() => {
                c.classList.remove('d-none');
                p.classList.add('d-none');
            });
        });
        hideLoader();
        modal.show();
        return true;
    } catch (error) {
        hideLoader();
        Notification.exception(error);
    }
};

export const init = () => {
    stateDefaults();

    window.onload = function() {
        const action = getUrlParameter('action');
        if (action === 'view') {
            const src = getUrlParameter('src');
            const title = getUrlParameter('title');
            if (src !== '') {
                viewVideoModal({sourceurl: src, title: title});
            }
        }
    };

    if (document.querySelector('.videolesson-folder-tree-container')) {
        initFolders(currentFolder);
        document.addEventListener('videolesson:folder:selected', (e) => {
            const folderId = (e.detail && typeof e.detail.folderId !== 'undefined')
                ? e.detail.folderId
                : folderParamToId(e.detail.folder);
            loadVideos({folderId: folderId, page: 0});
        });
    }

    const filterForm = document.querySelector('form[name="filter-videos"]');
    if (filterForm) {
        const searchInput = filterForm.querySelector('input[name="_text"]');
        const resetButton = filterForm.querySelector('button[value="reset"]');

        filterForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const value = searchInput ? searchInput.value : '';
            loadVideos({folderId: currentFolder, search: value, page: 0});
        });

        if (resetButton) {
            resetButton.addEventListener('click', (e) => {
                e.preventDefault();
                if (searchInput) {
                    searchInput.value = '';
                }
                loadVideos({folderId: currentFolder, search: '', page: 0});
            });
        }
    }

    document.body.addEventListener('click', function(event) {
        const target = event.target;

        const legacyLink = target.closest('.videolesson-viewmodal-href');
        if (legacyLink){
            event.preventDefault();
            const fragment = legacyLink.getAttribute("href");
            const url = new URL("http://dummy.com" + fragment);
            const params = new URLSearchParams(url.hash.substring(1));
            const src = params.get('videolesson-src');
            const hash = legacyLink.getAttribute('data-videolesson-contenthash');
            let modalparams = { sourceurl: src, title: legacyLink.textContent.trim(), contenthash: hash};
            viewVideoModal(modalparams);
        }

        const viewBtn = target.closest('.videolesson-viewmodal-data');
        if (viewBtn){
            event.preventDefault();
            const src = viewBtn.getAttribute('data-videolesson-src');
            const hash = viewBtn.getAttribute('data-videolesson-contenthash');
            const title = viewBtn.getAttribute('data-videolesson-title') || '';
            const modalparams = { sourceurl: src, title: title, contenthash: hash };
            viewVideoModal(modalparams);
        }

        // Handle thumbnail click
        const thumbnail = target.closest('.videolesson-viewmodal-thumbnail');
        if (thumbnail) {
            event.preventDefault();
            const src = thumbnail.getAttribute('data-videolesson-src');
            const hash = thumbnail.getAttribute('data-videolesson-contenthash');
            const title = thumbnail.getAttribute('data-videolesson-title') || '';
            const modalparams = { sourceurl: src, title: title, contenthash: hash };
            viewVideoModal(modalparams);
        }

        const paginate = target.closest('.videolesson-page-link');
        if (paginate) {
            event.preventDefault();
            const nextpage = parseInt(paginate.dataset.page, 10);
            if (!Number.isNaN(nextpage)) {
                loadVideos({page: nextpage});
            }
        }

        // Handle filter removal
        const removeFilterBtn = target.closest('.videolesson-remove-filter');
        if (removeFilterBtn) {
            event.preventDefault();
            event.stopPropagation();
            const badge = removeFilterBtn.closest('.videolesson-filter-badge');
            if (badge) {
                const filterType = badge.getAttribute('data-filter-type');
                if (filterType === 'search') {
                    currentSearch = '';
                    updateHistory(true);
                    loadVideos({
                        folderId: currentFolder,
                        search: '',
                        page: 0,
                        pushState: true,
                        replaceState: false
                    });
                } else if (filterType === 'folder') {
                    currentFolder = 0;
                    updateHistory(true);
                    loadVideos({
                        folderId: 0,
                        search: currentSearch,
                        page: 0,
                        pushState: true,
                        replaceState: false
                    });
                }
            }
            return;
        }

        const refreshBtn = target.closest('.videolesson-refresh-table');
        if (refreshBtn) {
            event.preventDefault();
            loadVideos({pushState: false, replaceState: true});
        }

        const assignBtn = target.closest('.videolesson-assign-folder');
        if (assignBtn) {
            event.preventDefault();
            const videoId = assignBtn.getAttribute('data-videolesson-id');
            const currentFolderValue = assignBtn.getAttribute('data-current-folder');
            const videoTitle = assignBtn.getAttribute('data-video-title') || '';
            showAssignFolderModal(videoId, currentFolderValue, videoTitle);
        }

        const deleteLink = target.closest('.videolesson-delete-link');
        if (deleteLink) {
            console.log('deleteLink', deleteLink); // eslint-disable-line no-console
            event.preventDefault();
            const videoIdAttr = deleteLink.getAttribute('data-video-id') || deleteLink.dataset.videoId;
            const videoName = deleteLink.getAttribute('data-video-name') || deleteLink.dataset.videoName || '';

            if (videoIdAttr) {
                const videoId = parseInt(videoIdAttr, 10);
                if (!isNaN(videoId)) {
                    handleSingleVideoDelete(videoId, videoName);
                }
            }
        }

    });

    // Handle subtitle generation button clicks
    document.addEventListener('click', async (e) => {
        const subtitleBtn = e.target.closest('.videolesson-trigger-subtitle');
        if (subtitleBtn) {
            e.preventDefault();
            const contenthash = subtitleBtn.getAttribute('data-contenthash') || subtitleBtn.dataset.contenthash;
            const videoName = subtitleBtn.getAttribute('data-video-name') || subtitleBtn.dataset.videoName || '';
            if (contenthash) {
                await handleSubtitleGeneration(contenthash, videoName);
            }
        }
    });

    window.addEventListener('popstate', (e) => {
        const state = e.state;
        if (state) {
            const folderId = folderParamToId(state.folder);
            loadVideos({
                folderId: folderId,
                search: state.search || '',
                page: state.page || 0,
                pushState: false,
                replaceState: true
            });
            highlightFolder(folderId);
        } else {
            const url = new URL(window.location.href);
            const folderParam = url.searchParams.get('folder') || url.searchParams.get('folderid') || 'all';
            const search = url.searchParams.get('search') || '';
            const page = parseInt(url.searchParams.get('vpage') || '0', 10);
            loadVideos({
                folderId: folderParamToId(folderParam),
                search: search,
                page: page,
                pushState: false,
                replaceState: true
            });
            highlightFolder(folderParamToId(folderParam));
        }
    });

    if (tableContainer) {
        loadVideos({folderId: currentFolder, search: currentSearch, page: currentPage, pushState: false, replaceState: true});
    }
};
