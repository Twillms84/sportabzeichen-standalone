/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */


import $ from 'jquery';
// jQuery global verfügbar machen (für Legacy Code und Console)
window.jQuery = window.$ = $;

import * as bootstrap from 'bootstrap'; 
import 'bootstrap-select';

console.log('App started & Bootstrap loaded');
// Deine Controller
import './controllers/exam_results_autosave.js';
import './controllers/admin_participant.js';
import './controllers/exam_dashboard.js';

// Styles
import './styles/app.css';
import './styles/results.css';
import './styles/dashboard_css.css';

console.log('App started & patched 🛠️');

$(document).ready(function() {
    $('.selectpicker').each(function() {
        var $select = $(this);
        
        // 1. Initialisieren
        $select.selectpicker();

        // 2. Wrapper finden (das Div drumherum)
        var $dropdown = $select.parent(); 
        
        // 3. Button finden und patchen
        var $toggle = $dropdown.find('.dropdown-toggle');
        $toggle.removeAttr('data-toggle');
        $toggle.attr('data-bs-toggle', 'dropdown');
        
        // 4. Den Crash verhindern (alle alten Listener löschen)
        $dropdown.off('show.bs.dropdown');

        // 5. WICHTIG: Klassen synchronisieren (Show <-> Open)
        // Wir fügen eigene Listener hinzu, die das machen, was das Plugin vergessen hat.
        $dropdown.on('show.bs.dropdown', function () {
            $dropdown.addClass('open');
        });
        $dropdown.on('hide.bs.dropdown', function () {
            $dropdown.removeClass('open');
        });

        // 6. Manueller Refresh, falls Inhalte fehlen
        $select.selectpicker('refresh');
        
        console.log('Dropdown fixed:', $select.attr('id'));
    });
});