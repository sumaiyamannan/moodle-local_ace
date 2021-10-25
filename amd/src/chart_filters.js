// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Provides the chart filters for engagement graphs
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Litepicker from 'local_ace/litepicker';

const FILTER_ACTIVE = "active";

const Selectors = {
    chartFilterOptions: "#chart-filter-options",
    courseToDate: "#course-to-date",
    last12Days: "#last-12-days",
    dateRange: "#date-range",
};

let updateFunc = null;

/**
 * Retrieves data from the local_ace webservice to populate an engagement graph
 *
 * @param {Function} providedFunc graph function
 */
export const init = (providedFunc) => {
    // Set default filter, update display, set handlers
    let filter = getActiveFilter();
    if (filter === null) {
        // Course to date is our default filter
        filter = document.querySelector(Selectors.courseToDate);
        setActiveFilter(filter);
    }

    setupFilters();
    updateFunc = providedFunc;
};

/**
 * Set the active filter.
 *
 * @param {Element} suppliedFilter
 */
const setActiveFilter = (suppliedFilter) => {
    getFilterNodes().forEach((filter) => {
        if (filter === suppliedFilter) {
            filter.dataset.filter = FILTER_ACTIVE;
        } else {
            filter.dataset.filter = null;
        }

        updateFilterDisplay();
    });
};

/**
 * Set up the click/change listeners on the filter buttons.
 * When detected set the active filter and pass the new date through to the graph.
 */
const setupFilters = () => {
    let filtersNode = document.querySelector(Selectors.chartFilterOptions);

    let courseToDateFilter = filtersNode.querySelector(Selectors.courseToDate);
    courseToDateFilter.addEventListener("click", () => {
        setActiveFilter(courseToDateFilter);
        updateFunc(null, null);
    });

    let last12DaysFilter = filtersNode.querySelector(Selectors.last12Days);
    last12DaysFilter.addEventListener("click", () => {
        setActiveFilter(last12DaysFilter);
        var date = new Date();
        date.setDate(date.getDate() - 12);
        let val = date.getTime() / 1000;
        updateFunc(val.toFixed(0), null);
    });

    let dateRangeFilter = filtersNode.querySelector(Selectors.dateRange);
    let picker = new Litepicker({
        element: dateRangeFilter,
        singleMode: false,
        splitView: false,
        setup: (picker) => {
            picker.on('selected', (date1, date2) => {
                if (date1 === undefined || date2 === undefined) {
                    return;
                }
                setActiveFilter(dateRangeFilter);
                updateFunc(date1.timestamp() / 1000, date2.timestamp() / 1000);
            });
        }
    });
    picker.clearSelection();
};

/**
 * Update the filter colours to display which is active.
 */
const updateFilterDisplay = () => {
    let filters = getFilterNodes();
    filters.forEach((filter) => {
        if (filter.dataset.filter === FILTER_ACTIVE) {
            filter.classList.add("btn-primary");
            filter.classList.remove("btn-secondary");
        } else {
            filter.classList.add("btn-secondary");
            filter.classList.remove("btn-primary");
        }
    });
};

/**
 * Get the active filter DOM element.
 *
 * @returns {null|Element}
 */
const getActiveFilter = () => {
    let filters = getFilterNodes();
    let filter = filters.find(filter => filter.dataset.filter === FILTER_ACTIVE);
    if (filter !== undefined) {
        return filter;
    }
    return null;
};

/**
 * Get the DOM element of chart filters on the page.
 *
 * @returns {[Element]}
 */
const getFilterNodes = () => {
    let filtersNode = document.querySelector(Selectors.chartFilterOptions);
    return [
        filtersNode.querySelector(Selectors.courseToDate),
        filtersNode.querySelector(Selectors.last12Days),
        filtersNode.querySelector(Selectors.dateRange)
    ];
};
