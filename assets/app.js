/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */


import $ from 'jquery';
// jQuery global verfügbar machen (für Legacy Code und Console)
window.jQuery = window.$ = $;

import 'bootstrap-select'; // Lädt das JS der Library

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

// --- HIER DIE INITIALISIERUNG EINFÜGEN ---
$(document).ready(function() {
    // Wir nutzen hier eine eigene Klasse '.app-selectpicker', 
    // damit das Plugin nicht automatisch (doppelt) startet.
    
    $('.app-selectpicker').each(function() {
        var $select = $(this);
        
        // Verhindert Doppel-Init, falls doch mal was schief geht
        if ($select.data('selectpicker')) { return; } 

        $select.selectpicker({
            style: 'btn-iserv',
            size: 6,
            liveSearch: true,
            container: 'body',
            noneSelectedText: 'Bitte wählen...',
            actionsBox: true,
            selectAllText: 'Alle',
            deselectAllText: 'Keine'
        });
    });
});
