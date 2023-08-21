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
 * Shows the course engagement graph
 *
 * @module      local_ace/course_engagement
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ChartBuilder from 'core/chart_builder';
import ChartJSOutput from 'core/chart_output_chartjs';
import {init as filtersInit} from 'local_ace/chart_filters';

let COURSE_ID = 0;
// Stores the current time method, allowing us to update the graph without supplying values.
let START_TIME = null;
let END_TIME = null;

/**
 * Initialise the course engagement graph
 *
 * @param {Object} parameters Data passed from the server.
 */
export const init = (parameters) => {
    if (COURSE_ID !== 0) {
        return;
    }
    COURSE_ID = parameters.courseid;
    filtersInit(updateGraph);

    document.addEventListener(
        "local_ace-graph-reload",
        () => {
            updateGraph(START_TIME, END_TIME);
        },
        false,
    );
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
 * Update the graph display based on values fetched from a webservice.
 *
 * @param {Number|null} startDatetime
 * @param {Number|null} endDateTime
 */
const updateGraph = (startDatetime, endDateTime) => {
    if (START_TIME !== startDatetime) {
        START_TIME = startDatetime;
    }
    if (END_TIME !== endDateTime) {
        END_TIME = endDateTime;
    }

    let engagementData = getCourseEngagementData(null, startDatetime, endDateTime).then((response) => {
        if (response.error !== null || response.series.length === 0) {
            displayError(response.error);
            return null;
        }
        let data = getGraphDataPlaceholder();

        let legendContainer = document.getElementById('local_ace-graph-series-legend');
        legendContainer.innerHTML = '';

        response.series.forEach((series) => {
            let seriesData = {
                "label": series.label,
                "labels": null,
                "type": null,
                "values": series.values,
                "colors": [series.colour],
                "fill": null,
                "axes": {
                    "x": null,
                    "y": null,
                },
                "urls": [],
                "smooth": null
            };
            data.series.push(seriesData);
            let legendBox = document.createElement("span");
            legendBox.className = 'legendbox';
            legendBox.style.cssText = 'background-color: ' + series.colour;
            legendContainer.appendChild(legendBox);
            let legendText = document.createElement("span");
            legendText.className = 'legendtext';
            legendText.innerText = series.legend;
            legendContainer.appendChild(legendText);
        });

        data.labels = response.xlabels;
        data.axes.y[0].max = 100;
        data.axes.y[0].stepSize = 25;
        let yLabels = {};
        response.ylabels.forEach((element) => {
            yLabels[element.value] = element.label;
        });
        data.axes.y[0].labels = yLabels;

        return data;
    }).catch(() => {
        displayError("API Error");
    });

    engagementData.then((data) => {
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

const getGraphDataPlaceholder = () => {
    return {
        "type": "line",
        "series": [
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

/**
 * Get course analytics data
 *
 * @param {Number|null} period Display period
 * @param {Number|null} start Start time of analytics period in seconds
 * @param {Number|null} end End of analytics period in seconds
 * @returns {Promise}
 */
const getCourseEngagementData = (period, start, end) => {
    return Ajax.call([{
        methodname: 'local_ace_get_course_analytics_graph',
        args: {
            'courseid': COURSE_ID,
            'period': period,
            'start': start,
            'end': end
        },
    }])[0];
};
