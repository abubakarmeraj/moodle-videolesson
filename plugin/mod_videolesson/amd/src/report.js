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
 * Report functionality for videolesson plugin
 *
 * @module     mod_videolesson/report
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {secondsToMinutesAndSeconds} from 'mod_videolesson/script';

const InitializeCharts = () => {
    const elements = document.querySelectorAll('.mod-videolesson-chart');
    elements.forEach(element => {
        const rangesAttribute = element.getAttribute('data-ranges');
        const rangesArray = JSON.parse(rangesAttribute.replace(/'/g, '"'));
        const vduration =  element.getAttribute('data-vduration');
        generateChart(element, rangesArray, vduration);
    });
};

const generateChart = (chart, data, vduration) => {
    let opacity = 1;
    data.forEach(function (range) {
        let div = document.createElement('div');
        let left = (range[0] / vduration) * 100;
        let width = (range[1] / vduration) * 100;
        let startPoint = (left / 100) * vduration;
        let endPoint = startPoint + (width / 100) * vduration;
        startPoint = secondsToMinutesAndSeconds(Math.abs(startPoint));
        endPoint = secondsToMinutesAndSeconds(Math.abs(endPoint));
        div.setAttribute("title", endPoint + ' - ' + endPoint);
        width -= left;
        div.style.left = Math.abs(left) + '%';
        div.style.width = Math.abs(width) + '%';
        div.style.opacity = opacity;
        chart.appendChild(div);
    });
};

export const init = () => {
    InitializeCharts();
};
