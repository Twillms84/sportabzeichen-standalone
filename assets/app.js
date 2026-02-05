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

// --- HIER DIE INITIALISIERUNG EINFÜGEN ---
$(document).ready(function() {
    $('.app-selectpicker').each(function() {
        var $select = $(this);
        
        // 1. Initialisieren (OHNE Optionen, da diese im HTML stehen!)
        $select.selectpicker(); 

        // 2. Button suchen
        // bootstrap-select erstellt einen Button direkt neben dem (versteckten) Select
        // oder wrappt es in ein div. Wir suchen den Button im direkten Umfeld.
        var $toggle = $select.parent().find('.dropdown-toggle');
        
        // 3. FIX: Bootstrap 5 Attribut setzen
        // Wir entfernen das alte (falsche) und setzen das neue.
        $toggle.removeAttr('data-toggle');
        $toggle.attr('data-bs-toggle', 'dropdown');
        
        // WICHTIG: KEIN .selectpicker('refresh') hiernach aufrufen!
        // Ein Refresh würde den Button neu bauen und unser Attribut wieder löschen.
        
        console.log('Selectpicker gepatcht:', $select.attr('id'));
    });
});
