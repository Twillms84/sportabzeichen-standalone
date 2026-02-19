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


import { Application } from '@hotwired/stimulus'; // Stimulus Application importieren
const app = Application.start(); // Stimulus starten

// Deine Controller importieren
import GroupsController from './controllers/groups_controller.js';

// Controller manuell bei Stimulus registrieren
app.register('groups', GroupsController);


console.log('App started & Bootstrap loaded');
// Deine Controller
import './controllers/exam_results_autosave.js';
import './controllers/exam_results_filter.js';

import './controllers/admin_participant.js';
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