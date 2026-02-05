/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */


import $ from 'jquery';
// WICHTIG: jQuery global verfügbar machen, BEVOR bootstrap-select geladen wird
window.jQuery = window.$ = $;

import 'bootstrap-select';
import './controllers/exam_results_autosave.js'; // DEIN SKRIPT
import './controllers/admin_participant.js';
import './controllers/exam_dashboard.js';

import './styles/app.css';
import './styles/results.css';
import './styles/dashboard_css.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
