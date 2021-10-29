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
 * Shows the teacher course engagement graph
 *
 * @module      local_ace/teacher_course_engagement
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ChartBuilder from 'core/chart_builder';
import ChartJSOutput from 'core/chart_output_chartjs';
import {init as filtersInit} from 'local_ace/chart_filters';

let COURSE_COLOUR_MATCH = [];

export const init = () => {
    filtersInit(updateGraph);
    updateGraph(null, null);
};

const updateGraph = (startDate, endDate) => {
    let engagementData = getTeacherCourseEngagementData(startDate, endDate).then((response) => {
        if (response.error !== null || response.series.length === 0) {
            displayError(response.error);
            return null;
        }
        let data = getGraphDataPlaceholder();
        response.series.forEach((series) => {
            // Store colours against courses so when switching history they stay the same colour.
            if (!COURSE_COLOUR_MATCH[series.title]) {
                COURSE_COLOUR_MATCH[series.title] = parseInt(Math.random() * 0xffffff).toString(16);
            }
            let seriesData = getSeriesPlaceholder();
            seriesData.label = series.title;
            seriesData.values = series.values;
            seriesData.colors = ['#' + COURSE_COLOUR_MATCH[series.title]];
            data.series.push(seriesData);
        });
        data.labels = response.xlabels;
        data.axes.y[0].max = 100;
        data.axes.y[0].stepSize = 20;
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

const getSeriesPlaceholder = () => {
    return {
        "label": "",
        "labels": null,
        "type": null,
        "values": [],
        "colors": [],
        "fill": null,
        "axes": {
            "x": null,
            "y": null
        },
        "urls": [],
        "smooth": null
    };
};

const getGraphDataPlaceholder = () => {
    return {
        "type": "line",
        "series": [],
        "labels": [],
        "title": "Course Engagement",
        "axes": {
            "x": [],
            "y": [
                {
                    "label": null,
                    "labels": {},
                    "max": 100,
                    "min": 0,
                    "position": null,
                    "stepSize": null
                }
            ]
        },
        "legend_options": {
            "display": true
        },
        "config_colorset": null,
        "smooth": true
    };
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
 * Get teacher course analytics data
 *
 * @param {Number|null} start Start time of analytics period in seconds
 * @param {Number|null} end End of analytics period in seconds
 * @returns {Promise}
 */
const getTeacherCourseEngagementData = (start, end) => {
    return Ajax.call([{
        methodname: 'local_ace_get_teacher_course_analytics_graph',
        args: {
            'start': start,
            'end': end
        },
    }])[0];
};
