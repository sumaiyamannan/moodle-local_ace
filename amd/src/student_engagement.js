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
 * @module      local_ace/student_engagement
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';
import ChartBuilder from 'core/chart_builder';
import ChartJSOutput from 'core/chart_output_chartjs';
import {init as filtersInit} from 'local_ace/chart_filters';

let COLOURS = {};

/**
 * Retrieves data from the local_ace webservice to populate an engagement graph
 */
export const init = (parameters) => {
    COLOURS = parameters;
    filtersInit(updateGraph);
    updateGraph(null, null);
};

/**
 * Update the graph display based on values fetched from a webservice.
 *
 * @param {Number|null} startDatetime
 * @param {Number|null} endDateTime
 */
const updateGraph = (startDatetime, endDateTime) => {
    let url = new URL(window.location.href);
    let params = new URLSearchParams(url.search);
    let userid = parseInt(params.get('id'));
    let courseid = null;
    if (params.has('course')) {
        courseid = parseInt(params.get('course'));
    }
    let engagementDataPromise = getUserEngagementData(courseid, userid, startDatetime, endDateTime).then(function(response) {
        if (response.error !== null) {
            displayError(response.error);
            return null;
        } else if (response.series.length === 0) {
            getString('noanalytics', 'local_ace').then((langString) => {
                displayError(langString);
                return;
            }).catch();
            return null;
        }

        // Populate empty fields.
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

        return graphData;
    }).catch(function() {
        displayError("API returned an error");
    });

    engagementDataPromise.then((data) => {
        if (data === null) {
            return;
        }
        let chartArea = document.querySelector('#chart-area-engagement');
        let chartImage = chartArea.querySelector('.chart-image');
        chartImage.innerHTML = "";
        ChartBuilder.make(data).then((chart) => {
            new ChartJSOutput(chartImage, chart);
            return;
        }).catch();
        return;
    }).catch();
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
 * @param {number} start Start time of analytics period in seconds
 * @param {end} end End of analytics period in seconds
 * @returns {Promise}
 */
const getUserEngagementData = (courseid, userid, start, end) => {
    return Ajax.call([{
        methodname: 'local_ace_get_user_analytics_graph',
        args: {
            'courseid': courseid,
            'userid': userid,
            'start': start,
            'end': end
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
                "colors": [COLOURS.colouruserhistory],
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
                "colors": [COLOURS.colourusercoursehistory],
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
                "colors": [COLOURS.colourusercoursehistory],
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
