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
 * Uses the local_ace webservices to create engagement charts
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';
import ChartBuilder from 'core/chart_builder';
import ChartJSOutput from 'core/chart_output_chartjs';

const FILTER_ACTIVE = "active";

const Selectors = {
    chartFilterOptions: "#chart-filter-options",
    courseToDate: "#course-to-date",
    last12Days: "#last-12-days",
    startDate: "#start-date",
};

/**
 * Retrieves data from the local_ace webservice to populate an engagement graph
 */
export const init = () => {
    // Set default filter, update display, set handlers
    let filter = getActiveFilter();
    if (filter === null) {
        // Course to date is our default filter
        filter = document.querySelector(Selectors.courseToDate);
        setActiveFilter(filter);
    }

    setupFilters();
    updateGraph();
};

/**
 * Update the graph display based on values fetched from a webservice.
 *
 * @param {Number|null} startDatetime
 */
const updateGraph = (startDatetime) => {
    startDatetime = isNaN(startDatetime) ? null : startDatetime;
    let url = new URL(window.location.href);
    let params = new URLSearchParams(url.search);
    let userid = parseInt(params.get('id'));
    let courseid = null;
    if (params.has('course')) {
        courseid = parseInt(params.get('course'));
    }
    getUserEngagementData(courseid, userid, startDatetime).then(function(response) {
        let chartArea = document.querySelector('#chart-area-engagement');
        let chartImage = chartArea.querySelector('.chart-image');
        chartImage.innerHTML = "";

        if (response.error !== null) {
            displayError(response.error);
            return;
        } else if (response.series.length === 0) {
            getString('noanalytics', 'local_ace').then((langString) => {
                displayError(langString);
            });
            return;
        }

        let graphData = getGraphDataPlaceholder();
        graphData.series[0].values = response.series;
        graphData.series[1].values = response.average1;
        graphData.series[2].values = response.average2;
        graphData.labels = response.labels;
        graphData.axes.y[0].max = response.max;
        graphData.axes.y[0].stepSize = response.stepsize;
        let yLabels = {};
        response.ylabels.forEach((element) => {
            yLabels[element.value] = element.label;
        });
        graphData.axes.y[0].labels = yLabels;

        ChartBuilder.make(graphData).then((chart) => {
            new ChartJSOutput(chartImage, chart);
        });
    }).catch(function() {
        displayError("API returned an error");
    });
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
    getFilterNodes().forEach((filter) => {
        if (filter.type === "date") {
            filter.addEventListener("change", () => {
                setActiveFilter(filter);
                let val = new Date(filter.value).getTime() / 1000;
                val = val.toFixed(0);
                if (isNaN(val)) {
                    val = null;
                }
                updateGraph(val);
            });
        } else {
            filter.addEventListener("click", () => {
                setActiveFilter(filter);

                let daysSelector = Selectors.last12Days.substr(1, Selectors.last12Days.length);
                if (filter.id === daysSelector) {
                    var date = new Date();
                    date.setDate(date.getDate() - 12);
                    let val = date.getTime() / 1000;
                    updateGraph(val.toFixed(0));
                } else {
                    updateGraph(null);
                }
            });
        }
    });
};

/**
 * Update the filter colours to display which is active.
 */
const updateFilterDisplay = () => {
    let filters = getFilterNodes();
    filters.forEach((filter) => {
        if (filter.dataset.filter === FILTER_ACTIVE) {
            if (filter.type === "date") {
                filter.classList.add("border-primary");
            } else {
                filter.classList.add("btn-primary");
                filter.classList.remove("btn-secondary");
            }
        } else {
            filter.classList.add("btn-secondary");
            filter.classList.remove("border-primary");
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
        filtersNode.querySelector(Selectors.startDate)
    ];
};

/**
 * Display an error on the page, which replaces the engagement graphs on the page.
 *
 * @param {string} langString Text displayed on the page
 */
const displayError = (langString) => {
    let chartArea = document.querySelector('#chart-area-engagement');
    let chartImage = chartArea.querySelector('.chart-image');
    chartImage.innerHTML = langString;
};

/**
 * Get analytics data for specific user and course, within a certain period and after a starting time.
 *
 * @param {number|null} courseid Course ID
 * @param {number} userid User ID
 * @param {number} period User history period
 * @param {number} start Start time of analytics in seconds
 * @returns {Promise}
 */
const getUserEngagementData = (courseid, userid, start) => {
    return Ajax.call([{
        methodname: 'local_ace_get_user_analytics_graph',
        args: {
            'courseid': courseid,
            'userid': userid,
            'start': start
        },
    }])[0];
};

/**
 * Get a graph.js data object filled out with the values we need for a student engagement graph.
 * TODO: Pull graph colours from plugin settings.
 *
 * @returns {Object}
 */
const getGraphDataPlaceholder = () => {
    return {
        "type": "line",
        "series": [
            {
                "label": " Your engagement",
                "labels": null,
                "type": null,
                "values": null,
                "colors": ["#5cb85c"],
                "fill": null,
                "axes": {
                    "x": null,
                    "y": null
                },
                "urls": [],
                "smooth": null
            },
            {
                "label": "Average course engagement",
                "labels": null,
                "type": null,
                "values": null,
                "colors": ["#CEE9CE"],
                "fill": null,
                "axes": {
                    "x": null,
                    "y": null
                },
                "urls": [],
                "smooth": null
            },
            {
                "label": "Average course engagement",
                "labels": null,
                "type": null,
                "values": null,
                "colors": ["#CEE9CE"],
                "fill": 1,
                "axes": {
                    "x": null,
                    "y": null
                },
                "urls": [],
                "smooth": null
            }
        ],
        "labels": null,
        "title": null,
        "axes": {
            "x": [],
            "y": [
                {
                    "label": null,
                    "labels": {},
                    "max": null,
                    "min": 0,
                    "position": null,
                    "stepSize": null
                }
            ]
        },
        "legend_options": {
            "display": false
        },
        "config_colorset": null,
        "smooth": true
    };
};
