import $ from 'jquery';

document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT & FILTER (Spalten ein/ausblenden)
    // =========================================================
    const $viewSelector = $('#viewSelector'); 
    
    if ($viewSelector.length) {
        $viewSelector.on('changed.bs.select', function (e, clickedIndex, isSelected, previousValue) {
            const selectedCategories = $(this).val() || [];
            
            $('#viewSelector option').each(function() {
                const category = $(this).val(); 
                const cells = document.querySelectorAll('.col-cat-' + category);
                const showAll = selectedCategories.length === 0;
                
                if (showAll || selectedCategories.includes(category)) {
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });
            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(selectedCategories));
        });

        // Restore Selection from LocalStorage
        const savedSelection = localStorage.getItem('sportabzeichen_view_selection');
        if (savedSelection) {
            try {
                const parsed = JSON.parse(savedSelection);
                if(parsed && parsed.length > 0) {
                    $viewSelector.selectpicker('val', parsed);
                }
                $viewSelector.selectpicker('refresh');
                $viewSelector.trigger('changed.bs.select');
            } catch(e) { console.error('Storage Error', e); }
        }
    }

    // =========================================================
    // 2. TEILNEHMER FILTER & SUCHE
    // =========================================================
    const $classFilterSelect = $('#client-class-filter');
    const searchInput = document.getElementById('client-search-input');
    
    if ($classFilterSelect.length && searchInput) {
        // Wir holen die Rows einmalig, um das Dropdown zu füllen
        const allRows = document.querySelectorAll('.participant-row');
        const groups = new Set(); 

        // Dropdown füllen: Wir suchen nach Klasse ODER Gruppe
        allRows.forEach(row => {
            const rawVal = row.getAttribute('data-class') || row.getAttribute('data-group');
            if (rawVal) {
                const val = rawVal.trim();
                if (val !== '') groups.add(val);
            }
        });

        // Optionen alphabetisch sortiert einfügen
        Array.from(groups).sort().forEach(val => {
            $classFilterSelect.append(`<option value="${val}">${val}</option>`);
        });
        
        // Selectpicker aktualisieren (falls vorhanden)
        if ($.fn.selectpicker) {
            $classFilterSelect.selectpicker('refresh');
        }

        // Die Filter-Logik
        const filterRows = () => {
            let selectedValues = $classFilterSelect.val();

            // Sicherstellen, dass es immer ein Array ist
            if (!selectedValues) {
                selectedValues = [];
            } else if (!Array.isArray(selectedValues)) {
                selectedValues = [selectedValues];
            }

            const searchTerm = searchInput.value.toLowerCase().trim();
            const currentRows = document.querySelectorAll('.participant-row');

            currentRows.forEach(row => {
                const rawRowVal = row.getAttribute('data-class') || row.getAttribute('data-group') || '';
                const rowGroup = rawRowVal.trim();
                
                const nameEl = row.querySelector('.name-main');
                const nameText = nameEl ? nameEl.textContent.toLowerCase() : ''; 

                const matchGroup = (selectedValues.length === 0 || selectedValues.includes(rowGroup));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                if (matchGroup && matchSearch) {
                    row.style.display = ''; 
                } else {
                    row.style.display = 'none'; 
                }
            });

            updatePrintButtonUrl(selectedValues, searchInput.value.trim());
        };

        // Helper: Groupcard Button URL aktualisieren
        const updatePrintButtonUrl = (selectedValues, rawSearchTerm) => {
            const $printBtn = $('#btn-print-groupcard'); 
            if (!$printBtn.length) return;

            if (!$printBtn.data('base-href')) {
                $printBtn.data('base-href', $printBtn.attr('href'));
            }
            const baseUrl = $printBtn.data('base-href');
            
            // Nimmt die erste gewählte Gruppe oder leer
            const selectedGroup = (Array.isArray(selectedValues) && selectedValues.length > 0) ? selectedValues[0] : '';
            const separator = baseUrl.includes('?') ? '&' : '?';
            
            let newUrl = baseUrl + separator + 'class_filter=' + encodeURIComponent(selectedGroup);
            
            if (rawSearchTerm) {
                newUrl += '&search_query=' + encodeURIComponent(rawSearchTerm);
            }

            $printBtn.attr('href', newUrl);
        };

        // Event Listener
        $classFilterSelect.on('change changed.bs.select', filterRows);
        searchInput.addEventListener('keyup', filterRows);
        searchInput.addEventListener('input', filterRows);
    }
});