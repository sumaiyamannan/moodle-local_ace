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
import Notification from 'core/notification';
import ChartJS from 'core/chartjs-lazy';

// Stores randomised colours against course shortnames.
let COURSE_COLOUR_MATCH = [];
// List of course shortnames hidden on the graph.
let HIDDEN_COURSES = [];

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
            if (!COURSE_COLOUR_MATCH[series.label]) {
                COURSE_COLOUR_MATCH[series.label] = parseInt(Math.random() * 0xffffff).toString(16);
            }
            let seriesData = getSeriesPlaceholder();
            seriesData.label = series.label;
            seriesData.values = series.values;
            seriesData.colors = ['#' + COURSE_COLOUR_MATCH[series.label]];
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

            // Hides courses based on user preferences.
            getHiddenCourses().then(response => {
                if (response.error) {
                    return;
                }
                let courseList = response.preferences[0].value;
                if (courseList === null) {
                    return;
                }
                HIDDEN_COURSES = courseList.split(",");
                ChartJS.helpers.each(ChartJS.instances, function(instance) {
                    let chart = instance.chart;
                    for (let dataset in chart.data.datasets) {
                        let datasetObject = chart.data.datasets[dataset];
                        if (HIDDEN_COURSES.includes(datasetObject.label)) {
                            datasetObject.hidden = true;
                        }
                    }
                    chart.update();
                });
                return;
            }).fail(Notification.exception);
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
            "display": true,
            "position": 'right',
            "onClick": legendClickHandler
        },
        "config_colorset": null,
        "smooth": true
    };
};

/**
 * Handles clicks on dataset legends.
 *
 * @param {Object} e Event
 * @param {Object} legendItem Chart.JS Legend item
 */
const legendClickHandler = function(e, legendItem) {
    let index = legendItem.datasetIndex;
    let ci = this.chart;
    let meta = ci.getDatasetMeta(index);

    if (meta.hidden === null) {
        if (ci.data.datasets[index].hidden) {
            HIDDEN_COURSES = HIDDEN_COURSES.filter(course => course !== ci.data.datasets[index].label);
            updateHiddenCourses();
            ci.data.datasets[index].hidden = false;
        } else {
            HIDDEN_COURSES.push(ci.data.datasets[index].label);
            updateHiddenCourses();
            ci.data.datasets[index].hidden = true;
        }
    }

    ci.update();
};

/**
 * Updates the hidden courses user preference, based on the HIDDEN_COURSES array.
 */
const updateHiddenCourses = function() {
    let request = {
        methodname: 'core_user_update_user_preferences',
        args: {
            preferences: [
                {
                    type: 'local_ace_teacher_hidden_courses',
                    value: HIDDEN_COURSES.join(",")
                }
            ]
        }
    };

    Ajax.call([request])[0]
        .fail(Notification.exception);
};

/**
 * Gets the hidden courses from the user preferences.
 *
 * @returns {Promise}
 */
const getHiddenCourses = function() {
    let request = {
        methodname: 'core_user_get_user_preferences',
        args: {
            name: 'local_ace_teacher_hidden_courses'
        }
    };
    return Ajax.call([request])[0];
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
