/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */


import $ from 'jquery';
window.jQuery = window.$ = $;

import * as bootstrap from 'bootstrap'; 
import 'bootstrap-select';

// --- STIMULUS SETUP ---
import { Application } from '@hotwired/stimulus';
const app = Application.start();

// Controller-Klassen importieren
import GroupsController from './controllers/groups_controller.js';
import ParticipantController from './controllers/admin_participant_controller.js';
import ExamOverviewController from './controllers/exam_overview_controller.js';

// (Falls du die anderen auch als Stimulus-Klassen hast, hier importieren)

// Controller manuell registrieren
app.register('groups', GroupsController);
app.register('admin-participant', ParticipantController); 
app.register('exam-overview', ExamOverviewController);
app.register('password-validator', PasswordValidatorController);

// --- ANDERE JS LOGIK (Non-Stimulus oder Legacy) ---
import './controllers/exam_results_autosave.js';
import './controllers/exam_results_filter.js';
import './controllers/exam_dashboard.js';

// Styles
import './styles/app.css';
import './styles/results.css';
import './styles/dashboard_css.css';

console.log('App started & patched 🛠️');

$(document).ready(function() {
    $('.app-selectpicker').each(function() {
        var $select = $(this);
        
        // 1. Initialisieren
        $select.selectpicker();

        // 2. Wrapper finden
        var $dropdown = $select.parent(); 
        
        // 3. Button patchen (BS4 -> BS5)
        var $toggle = $dropdown.find('.dropdown-toggle');
        $toggle.removeAttr('data-toggle');
        $toggle.attr('data-bs-toggle', 'dropdown');
        
        // 4. Störfeuer verhindern
        // Wir verhindern, dass das Plugin beim Öffnen in Panik gerät
        $dropdown.off('show.bs.dropdown');
        
        // 5. Klassen synchronisieren (für Styling)
        $dropdown.on('show.bs.dropdown', function () {
            $dropdown.addClass('open');
        });
        $dropdown.on('hide.bs.dropdown', function () {
            $dropdown.removeClass('open');
        });
        
        console.log('Dropdown ready:', $select.attr('id'));
    });
});