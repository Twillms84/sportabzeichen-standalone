import $ from 'jquery';

document.addEventListener('DOMContentLoaded', function() {
    
    // --- KONFIGURATION ---
    const viewContainer = document.getElementById('view-checkbox-container');
    const groupContainer = document.getElementById('group-checkbox-container');
    
    // =========================================================
    // 1. ANSICHT FILTER (Spalten)
    // =========================================================
    if (viewContainer) {
        // Kategorien sammeln
        const categories = new Set();
        document.querySelectorAll('[class*="col-cat-"]').forEach(el => {
            el.classList.forEach(cls => {
                if (cls.startsWith('col-cat-')) {
                    categories.add(cls.replace('col-cat-', ''));
                }
            });
        });

        // Checkboxen bauen
        const savedViewSelection = JSON.parse(localStorage.getItem('sportabzeichen_view_selection') || '[]');
        
        // HTML leeren & neu befüllen
        viewContainer.innerHTML = '';
        Array.from(categories).sort().forEach(cat => {
            let label = cat.charAt(0).toUpperCase() + cat.slice(1);
            if (cat.toLowerCase() === 'swimming') label = 'Schwimmnachweis';

            const isChecked = savedViewSelection.length === 0 || savedViewSelection.includes(cat);
            const html = `
                <div class="form-check mb-1"> 
                    <input class="form-check-input view-checkbox" type="checkbox" value="${cat}" id="chk_view_${cat}" ${isChecked ? 'checked' : ''}>
                    <label class="form-check-label w-100" for="chk_view_${cat}">${label}</label>
                </div>`;
            viewContainer.insertAdjacentHTML('beforeend', html);
        });

        // Update Logik
        const updateViewFilter = () => {
            const checkboxes = viewContainer.querySelectorAll('.view-checkbox');
            const checkedValues = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            const allChecked = checkedValues.length === checkboxes.length;

            categories.forEach(cat => {
                const cells = document.querySelectorAll('.col-cat-' + cat);
                if (allChecked || checkedValues.includes(cat)) {
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });
            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(allChecked ? [] : checkedValues));
        };

        // Event Listener (Change)
        viewContainer.addEventListener('change', updateViewFilter);

        // Buttons Alle/Keine
        document.querySelector('.js-view-all')?.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            viewContainer.querySelectorAll('input').forEach(el => el.checked = true);
            updateViewFilter();
        });
        document.querySelector('.js-view-none')?.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            viewContainer.querySelectorAll('input').forEach(el => el.checked = false);
            updateViewFilter();
        });

        // Initialer Run
        updateViewFilter();
    }

    // =========================================================
    // 2. GRUPPEN FILTER (Korrigiert & mit Debugging)
    // =========================================================
    const groupContainer = document.getElementById('group-checkbox-container');
    
    if (groupContainer) {
        console.log('🔍 Gruppen-Filter wird initialisiert...');
        
        const groups = new Set();
        // Wir nutzen jQuery Selektoren, die sind oft fehlertoleranter
        const $rows = $('.participant-row');

        console.log('Anzahl gefundener Zeilen:', $rows.length);

        $rows.each(function() {
            // Wir versuchen beide Attribut-Namen
            let val = $(this).data('class');
            if (!val) val = $(this).data('group'); // Fallback
            
            // Debugging für die erste Zeile, damit wir sehen was passiert
            if (groups.size === 0 && val) {
                console.log('Erster gefundener Wert:', val);
            }

            if (val && String(val).trim() !== '') {
                groups.add(String(val).trim());
            }
        });

        console.log('Gefundene Gruppen (Set):', Array.from(groups));

        // HTML neu bauen
        groupContainer.innerHTML = '';
        
        if (groups.size === 0) {
            groupContainer.innerHTML = '<div class="text-muted p-2 small">Keine Gruppen gefunden.<br>Prüfe HTML Attribute (data-class).</div>';
        } else {
            Array.from(groups).sort().forEach(grp => {
                const html = `
                    <div class="form-check mb-1">
                        <input class="form-check-input group-checkbox" type="checkbox" value="${grp}" id="chk_grp_${grp.replace(/\s+/g, '_')}" checked>
                        <label class="form-check-label w-100" for="chk_grp_${grp.replace(/\s+/g, '_')}" style="cursor:pointer;">${grp}</label>
                    </div>`;
                groupContainer.insertAdjacentHTML('beforeend', html);
            });
        }

        // --- Update Logik ---
        const updateParticipantFilter = () => {
            const $checkboxes = $(groupContainer).find('.group-checkbox');
            const checkedGroups = $checkboxes.filter(':checked').map((_, el) => el.value).get();
            const allGroupsChecked = checkedGroups.length === $checkboxes.length;
            const searchTerm = $('#client-search-input').val()?.toLowerCase().trim() || '';

            $rows.each(function() {
                const $row = $(this);
                let rowGroup = $row.data('class');
                if (!rowGroup) rowGroup = $row.data('group');
                rowGroup = String(rowGroup || '').trim();

                // Namen finden (sucht in gängigen Klassen)
                const nameText = $row.find('.name-main, .col-name, td:nth-child(1), td:nth-child(2)').text().toLowerCase();

                const matchGroup = (allGroupsChecked || checkedGroups.includes(rowGroup));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                // Zeile anzeigen/verstecken
                if (matchGroup && matchSearch) {
                    $row.show(); // jQuery show
                } else {
                    $row.hide(); // jQuery hide
                }
            });

            // Update Print Button URL (Falls vorhanden)
            const $printBtn = $('#btn-print-groupcard');
            if ($printBtn.length) {
                 if (!$printBtn.data('base-href')) $printBtn.data('base-href', $printBtn.attr('href'));
                 const baseUrl = $printBtn.data('base-href');
                 // Wenn NICHT alle gewählt sind, nimm die erste gewählte Gruppe für den Link (einfache Logik)
                 const selGrp = (checkedGroups.length > 0 && !allGroupsChecked) ? checkedGroups[0] : '';
                 
                 const sep = baseUrl.includes('?') ? '&' : '?';
                 let newUrl = baseUrl + sep + 'class_filter=' + encodeURIComponent(selGrp);
                 if (searchTerm) newUrl += '&search_query=' + encodeURIComponent(searchTerm);
                 $printBtn.attr('href', newUrl);
            }
        };

        // Event Listener (jQuery Style)
        $(groupContainer).on('change', updateParticipantFilter);
        $('#client-search-input').on('keyup input', updateParticipantFilter);

        $('.js-group-all').on('click', function(e) { 
            e.preventDefault(); 
            $(groupContainer).find('input').prop('checked', true); 
            updateParticipantFilter(); 
        });
        $('.js-group-none').on('click', function(e) { 
            e.preventDefault(); 
            $(groupContainer).find('input').prop('checked', false); 
            updateParticipantFilter(); 
        });
        
        // Einmal initial ausführen
        updateParticipantFilter();
    }
});