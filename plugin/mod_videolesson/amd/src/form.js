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
 * Form functionality for videolesson plugin
 *
 * @module     mod_videolesson/form
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from "core/notification";
import * as Str from 'core/str';
import {initTable} from 'mod_videolesson/table';
let restrict = false;
let awsdependent = ['aws', 'upload'];
export const init = (params) => {
    restrict = params.restrict;
    const contenthash = document.getElementById('id_contenthash');
    const sourceElement = document.getElementById('id_source');
    const galleryContainer = document.getElementById('video_gallery_container');
    const completionunlockedElement = document.getElementsByName('completionunlocked')[0];
    const completionunlocked = completionunlockedElement ? completionunlockedElement : null;
    const instance = document.getElementsByName('instance')[0];
    const videourl = document.getElementsByName('videourl')[0];

    let originalValueUrl = videourl.value;
    let originalValue = sourceElement.value;
    let originalValueContenthash = contenthash.value;
    let notified = false;

    initTable();

    const table = document.getElementById('videolist');
    table.addEventListener('click', function(event) {
        console.log('table click', event); // eslint-disable-line no-console
        const clickedRow = event.target.closest('tbody tr');
        console.log('clickedRow', clickedRow); // eslint-disable-line no-console
        if (clickedRow) {
            if (originalValueContenthash && originalValueContenthash != clickedRow.getAttribute('data-contenthash')) {
                changeVideoConfirm(clickedRow, changeVideo);
            } else {
                changeVideo(clickedRow);
            }
        }
    });

    const changeVideoConfirm = (clickedRowOrEvent, saveCallback, cancelCallback = null, cancelParam = null) => {
        // Determine if we received a row element or an event object
        const isRow = clickedRowOrEvent && clickedRowOrEvent.nodeType === 1; // Element node
        const isEvent = clickedRowOrEvent && clickedRowOrEvent.target;
        const row = isRow ? clickedRowOrEvent : null;
        const event = isEvent ? clickedRowOrEvent : null;

        if (event && restrict && awsdependent.includes(event.target.value) ) {
            noconfigConfirm();
            sourceElement.value = originalValue;
            return;
        }
        if (notified || !instance.value) {
            saveCallback(row || clickedRowOrEvent);
            return;
        }

        Str.get_strings([
            {key: 'modform:videochange:title', component: 'mod_videolesson'},
            {key: 'modform:videochange:content', component: 'mod_videolesson'},
            {key: 'modform:videochange:yes', component: 'mod_videolesson'},
            {key: 'modform:videochange:cancel', component: 'mod_videolesson'}
        ]).then(function(strings) {
            Notification.confirm(
                strings[0],
                strings[1],
                strings[2],
                strings[3],
                () => {
                    notified = true;
                    saveCallback(row || clickedRowOrEvent);
                },
                () => {
                    if(cancelCallback !== null) {
                        cancelCallback(cancelParam);
                    }
                }
            );
        }).catch(Notification.exception);
    };

    const noconfigConfirm = () => {

        Str.get_strings([
            {key: 'modform:noconfig:alert:title', component: 'mod_videolesson'},
            {key: 'modform:noconfig:alert:content', component: 'mod_videolesson'},
            {key: 'modform:noconfig:alert:button', component: 'mod_videolesson'}
        ]).then(function(strings) {
            Notification.alert(
                strings[0],
                strings[1],
                strings[2],
            );
        }).catch(Notification.exception);
    };

    const changeVideo = (clickedRow) => {
        console.log('changeVideo', clickedRow); // eslint-disable-line no-console
        // Handle case where clickedRow might be an event (for backward compatibility)
        const row = clickedRow && clickedRow.nodeType ? clickedRow :
                    (clickedRow && clickedRow.currentTarget ? clickedRow.currentTarget : null);

        if (!row) {
            console.error('changeVideo: Invalid row element', clickedRow); // eslint-disable-line no-console
            return;
        }

        const rowContenthash = row.getAttribute('data-contenthash');
        if (completionunlocked && originalValueContenthash != rowContenthash) {
            completionunlocked.value = 1;
        }

        contenthash.value = rowContenthash;
        var rows = document.getElementById('videolist').getElementsByTagName('tr');
        for (var i = 0; i < rows.length; i++) {
            rows[i].classList.remove('selected');
        }
        row.classList.add('selected');
    };

    const changeUrlConfirm = (event) => {
        if (originalValueUrl) {
            event.preventDefault();
            changeVideoConfirm(event,changeUrlSave, changeUrlCancel, originalValueUrl);
        }
    };

    const changeUrlSave = () => {
        // do nothing.
    };

    const changeUrlCancel = (original) => {
        videourl.value = original;
    };

    const changeSource = (event) => {
        event.preventDefault();
        changeVideoConfirm(event,toggleContainerVisibility, SetselectOriginalVale, originalValue);
    };

    const SetselectOriginalVale = (original) => {
        sourceElement.value = original;
        toggleContainerVisibility();
    };

    const toggleContainerVisibility = () => {

        const removeSpecificStyle = (element, property) => {
            element.style.removeProperty(property);
        };

        const setSpecificStyle = (element, property, value) => {
            element.style.setProperty(property,value);
        };

        let toShowElementIds = [];
        let toHideElementIds = [];
        switch (sourceElement.value) {
            case 'upload':
                toShowElementIds = ['fitem_id_newvideo', 'fitem_id_thumbnail', 'fitem_id_ffprobeerrormessage'];
                toHideElementIds = ['fitem_id_videourl', 'fitem_id_embedcode'];
                if (params.restrict) {toShowElementIds.push('fitem_id_missingconfig');}
                galleryContainer.classList.toggle('d-none', true);
                break;
            case 'aws':
                toShowElementIds = ['video_gallery_container', 'fitem_id_thumbnail'];
                toHideElementIds = [
                    'fitem_id_newvideo',
                    'fitem_id_videourl',
                    'fitem_id_embedcode',
                    'fitem_id_ffprobeerrormessage'
                ];
                if (params.restrict) {toShowElementIds.push('fitem_id_missingconfig');}
                galleryContainer.classList.toggle('d-none', false);
                break;
            case 'external':
                toShowElementIds = ['fitem_id_videourl'];
                toHideElementIds = [
                    'fitem_id_newvideo',
                    'video_gallery_container',
                    'fitem_id_thumbnail',
                    'fitem_id_embedcode',
                    'fitem_id_ffprobeerrormessage'
                ];
                if (params.restrict) {toHideElementIds.push('fitem_id_missingconfig');}
                galleryContainer.classList.toggle('d-none', true);
                break;
            case 'embed':
                toShowElementIds = ['fitem_id_embedcode'];
                toHideElementIds = [
                    'fitem_id_newvideo',
                    'video_gallery_container',
                    'fitem_id_thumbnail',
                    'fitem_id_videourl',
                    'fitem_id_ffprobeerrormessage'
                ];
                if (params.restrict) {toHideElementIds.push('fitem_id_missingconfig');}
                galleryContainer.classList.toggle('d-none', true);
                break;
            default:
                break;
        }

        toShowElementIds.forEach(function(id) {
            var element = document.getElementById(id);
            if (element) {
                removeSpecificStyle(element, 'display');
            }
        });

        toHideElementIds.forEach(function(id) {
            var element = document.getElementById(id);
            if (element) {
                setSpecificStyle(element, 'display', 'none');
            }
        });

    };

    sourceElement.addEventListener('change', changeSource);
    videourl.addEventListener('input', changeUrlConfirm);
    toggleContainerVisibility();
};
