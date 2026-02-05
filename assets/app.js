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
    $('.electpicker').each(function() {
        var $select = $(this);
        
        // 1. Initialisieren
        $select.selectpicker();

        // 2. Button finden
        var $toggle = $select.parent().find('.dropdown-toggle');
        
        // 3. Den Crash verhindern (wie vorhin)
        $select.parent().off('show.bs.dropdown');

        // 4. BRUTE FORCE FIX
        // Wir hören manuell auf den Klick
        $toggle.on('click', function(e) {
            // Wir holen uns die "echte" Bootstrap 5 Instanz für diesen Button
            var dropdownInstance = bootstrap.Dropdown.getOrCreateInstance(this);
            
            // Wir zwingen sie zum Umschalten (Auf/Zu)
            dropdownInstance.toggle();
            
            // Verhindern, dass das Plugin dazwischenfunkt
            e.preventDefault(); 
        });
        
        console.log('Force-Fix aktiviert für:', $select.attr('id'));
    });
});