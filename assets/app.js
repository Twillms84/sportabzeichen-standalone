/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */


import $ from 'jquery';
// jQuery global verfügbar machen (für Legacy Code und Console)
window.jQuery = window.$ = $;

import 'bootstrap';
import 'bootstrap-select'; // Lädt das JS der Library

console.log('App started & Bootstrap loaded');
// Deine Controller
import './controllers/exam_results_autosave.js';
import './controllers/admin_participant.js';
import './controllers/exam_dashboard.js';

// Styles
import './styles/app.css';
import './styles/results.css';
import './styles/dashboard_css.css';
// Falls du das CSS von Bootstrap-Select auch lokal hast, importiere es hier. 
// Wenn nicht, lass den CSS Link im HTML.

console.log('App started 🎉');

$(document).ready(function() {
    $('.app-selectpicker').each(function() {
        var $select = $(this);
        
        // 1. Initialisieren
        $select.selectpicker(); 

        // 2. Button suchen und für Bootstrap 5 patchen
        var $toggle = $select.parent().find('.dropdown-toggle');
        $toggle.removeAttr('data-toggle');
        $toggle.attr('data-bs-toggle', 'dropdown');
        
        // 3. WICHTIG: Den Crash verhindern!
        // Wir entfernen den Listener für 'show.bs.dropdown' vom Wrapper-Element.
        // Das verhindert, dass bootstrap-select versucht, sich einzumischen, wenn das Menü aufgeht.
        $select.parent().off('show.bs.dropdown');
        
        console.log('Selectpicker fixed & silenced:', $select.attr('id'));
    });
});
