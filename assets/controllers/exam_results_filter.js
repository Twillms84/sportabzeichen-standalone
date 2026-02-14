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
    // 2. GRUPPEN FILTER (Forensik-Modus)
    // =========================================================    
    if (!groupContainer) {
        console.error('❌ FEHLER: Container <div id="group-checkbox-container"> nicht gefunden!');
    } else {
        console.log('✅ Container gefunden. Starte Gruppen-Analyse...');
        
        const groups = new Set();
        const $rows = $('.participant-row');

        console.log(`📊 Anzahl gefundener Zeilen (.participant-row): ${$rows.length}`);

        $rows.each(function(index) {
            const $row = $(this);
            
            // WICHTIG: Wir nutzen .attr(), das liest den echten HTML-Text
            let rawClass = $row.attr('data-class');
            let rawGroup = $row.attr('data-group');
            
            // Bereinigen
            let val = (rawClass || rawGroup || '').trim();

            if (val !== '') {
                groups.add(val);
            }
        });

        const sortedGroups = Array.from(groups).sort();
        console.log('🏁 Gefundene eindeutige Gruppen:', sortedGroups);

        // HTML neu bauen
        groupContainer.innerHTML = '';
        
        if (sortedGroups.length === 0) {
            groupContainer.innerHTML = '<div class="text-danger p-2 small">Keine Gruppen-Daten gefunden.<br>(Attribute data-class/group sind leer)</div>';
        } else {
            // "Alle" / "Keine" Buttons sichtbar machen falls nötig
            $('.js-group-all, .js-group-none').show();

            sortedGroups.forEach(grp => {
                // ID sicher machen (Leerzeichen zu Unterstrichen)
                const safeId = 'chk_grp_' + grp.replace(/[^a-zA-Z0-9-_]/g, '_');
                
                const html = `
                    <div class="form-check mb-1">
                        <input class="form-check-input group-checkbox" type="checkbox" value="${grp}" id="${safeId}" checked>
                        <label class="form-check-label w-100 text-break" for="${safeId}" style="cursor:pointer;">
                            ${grp}
                        </label>
                    </div>`;
                groupContainer.insertAdjacentHTML('beforeend', html);
            });
        }

        // --- Update Logik (Filter anwenden) ---
        const updateParticipantFilter = () => {
            const $checkboxes = $(groupContainer).find('.group-checkbox');
            // Welche Werte sind angehakt?
            const checkedGroups = $checkboxes.filter(':checked').map((_, el) => el.value).get();
            const allGroupsChecked = checkedGroups.length === $checkboxes.length;
            const searchTerm = $('#client-search-input').val()?.toLowerCase().trim() || '';

            $rows.each(function() {
                const $row = $(this);
                // Auch hier attr() nutzen für Konsistenz
                let rowVal = ($row.attr('data-class') || $row.attr('data-group') || '').trim();
                
                // Namenssuche
                const nameText = $row.find('.name-main').text().toLowerCase() + ' ' + 
                                 $row.find('.col-name').text().toLowerCase();

                const matchGroup = (groups.size === 0) || (allGroupsChecked || checkedGroups.includes(rowVal));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                if (matchGroup && matchSearch) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        };

        // Event Listener
        $(groupContainer).on('change', updateParticipantFilter);
        $('#client-search-input').on('keyup input', updateParticipantFilter);

        $('.js-group-all').on('click', function(e) { 
            e.preventDefault(); 
            // WICHTIG: stopPropagation verhindert, dass der Klick das Dropdown schließt
            e.stopPropagation();
            $(groupContainer).find('input').prop('checked', true); 
            updateParticipantFilter(); 
        });
        $('.js-group-none').on('click', function(e) { 
            e.preventDefault(); 
            e.stopPropagation();
            $(groupContainer).find('input').prop('checked', false); 
            updateParticipantFilter(); 
        });
        
        // Init
        updateParticipantFilter();
    }

    // =========================================================
    // 3. UX HELPER (Damit Dropdown beim Klicken offen bleibt)
    // =========================================================
    // Verhindert, dass Klicks innerhalb des Menüs das Dropdown schließen.
    // Das arbeitet mit Bootstrap 'data-bs-auto-close="outside"' zusammen.
    $('.dropdown-menu').on('click', function(e) {
        e.stopPropagation();
    });
});