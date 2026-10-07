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
 * Table functionality for videolesson plugin
 *
 * @module     mod_videolesson/table
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const initializedTables = {};

/**
 * Filter table rows based on input value and specified column index.
 * @param {string} tableId - The ID of the table.
 * @param {string} inputId - The ID of the input element.
 * @param {number} columnIndex - The column index to filter.
 */
const filterTable = (tableId, inputId, columnIndex) => {
    const input = document.getElementById(inputId);
    const filter = input?.value.toLowerCase();
    const table = document.getElementById(tableId);
    const rows = table?.getElementsByTagName("tr");

    if (!rows) {
        return;
    }

    for (let i = 1; i < rows.length; i++) { // Skip header row
        const cell = rows[i].getElementsByTagName("td")[columnIndex];
        if (cell) {
            const textValue = cell.textContent || cell.innerText;
            rows[i].style.display = textValue.toLowerCase().includes(filter) ? "" : "none";
        }
    }
};

/**
 * Sort table rows based on a specific column index.
 * @param {string} tableId - The ID of the table.
 * @param {number} columnIndex - The column index to sort.
 */
const sortTable = (tableId, columnIndex) => {
    const table = document.getElementById(tableId);
    const rows = Array.from(table?.rows || []).slice(1); // Get all rows except the header
    if (rows.length === 0) {
        return;
    }

    const isNumeric = !isNaN(rows[0].cells[columnIndex].innerText);
    const sortOrderAttr = `data-sort-order-${columnIndex}`;
    const ascending = table.getAttribute(sortOrderAttr) !== "asc";

    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].innerText.toLowerCase();
        const bValue = b.cells[columnIndex].innerText.toLowerCase();

        if (isNumeric) {
            return ascending
                ? parseFloat(aValue) - parseFloat(bValue)
                : parseFloat(bValue) - parseFloat(aValue);
        }
        return ascending
            ? aValue.localeCompare(bValue)
            : bValue.localeCompare(aValue);
    });

    // Toggle sort order
    table.setAttribute(sortOrderAttr, ascending ? "asc" : "desc");

    // Append sorted rows back to the table
    const tbody = table.querySelector("tbody");
    if (tbody) {
        tbody.innerHTML = "";
        rows.forEach(row => tbody.appendChild(row));
    }

    updateSortIndicators(table, columnIndex, ascending);
};

/**
 * Update sort indicators for the table headers.
 * @param {HTMLElement} table - The table element.
 * @param {number} columnIndex - The sorted column index.
 * @param {boolean} ascending - Whether the sort is ascending.
 */
const updateSortIndicators = (table, columnIndex, ascending) => {
    const headers = table.querySelectorAll("th");
    headers.forEach((header, index) => {
        header.setAttribute(
            "data-sort-indicator",
            index === columnIndex ? (ascending ? "▲" : "▼") : ""
        );
    });
};

/**
 * Initialize a table for sorting and filtering functionality.
 * @param {string} tableId - The ID of the table.
 * @param {string} inputId - The ID of the input field for filtering.
 * @param {number} filterColumnIndex - The index of the column for filtering.
 */
export const initTable = (tableId = 'videolist', inputId = 'videolistsearchinput', filterColumnIndex = 2) => {

    if (initializedTables[tableId]) {
        return;
    }

    const table = document.getElementById(tableId);
    if (!table) {
        return;
    }

    const headers = table.querySelectorAll("th");
    headers.forEach((header, index) => {
        header.addEventListener("click", () => sortTable(tableId, index));
    });

    const input = document.getElementById(inputId);
    if (input) {
        input.addEventListener("input", () => filterTable(tableId, inputId, filterColumnIndex));
    } else {
        // Input not found - this is not critical, just disable filtering
        // Silently fail as this is a non-critical feature
    }

    initializedTables[tableId] = true;
};
