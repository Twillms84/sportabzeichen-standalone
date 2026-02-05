/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

import './bootstrap.js'; // Bestehender Stimulus-Import
import 'jquery';
import 'bootstrap-select';
import './results.js'; // DEIN SKRIPT
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
