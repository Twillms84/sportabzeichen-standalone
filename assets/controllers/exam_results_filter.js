import $ from 'jquery';

document.addEventListener('DOMContentLoaded', function() {
    
    // --- CONTAINER DEFINITIONEN ---
    // 1. Für Ansicht (Spalten ein/ausblenden)
    const viewContainer = document.getElementById('view-checkbox-container');
    
    // 2. Für Ergebnisseiten (Forensik-Modus: liest Tabelle aus)
    const resultGroupContainer = document.getElementById('group-checkbox-container');
    
    // 3. Für Bearbeitenseite (Edit-Modus: einfache Liste filtern)
    const editGroupList = document.getElementById('available-groups-list');
    const editSearchInput = document.getElementById('group-dropdown-search');

    // =========================================================
    // MODUS A: ANSICHT FILTER (Spalten) - Bleibt unverändert
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

        viewContainer.addEventListener('change', updateViewFilter);

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

        updateViewFilter();
    }

    // =========================================================
    // MODUS B: EDIT-SEITE (Einfache Suche in vorhandener Liste)
    // =========================================================
    if (editGroupList && editSearchInput) {
        console.log('✅ Edit-Modus erkannt: Aktiviere Suchfunktion für Gruppen.');

        // Event Listener für das Suchfeld
        editSearchInput.addEventListener('keyup', function() {
            const filterValue = this.value.toLowerCase();
            const items = editGroupList.querySelectorAll('li'); // Alle Listen-Elemente

            items.forEach(function(item) {
                // Wir suchen im Text oder im data-search Attribut
                const text = item.textContent || item.innerText;
                const searchAttr = item.getAttribute('data-search') || '';
                
                if (text.toLowerCase().indexOf(filterValue) > -1 || searchAttr.indexOf(filterValue) > -1) {
                    item.style.display = ""; // Anzeigen
                } else {
                    item.style.display = "none"; // Verstecken
                }
            });
        });

        // Verhindern, dass Klick ins Suchfeld das Dropdown schließt
        editSearchInput.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // =========================================================
    // MODUS C: ERGEBNIS-SEITE (Forensik / Tabellen-Scan)
    // =========================================================    
    // Wir führen das nur aus, wenn der Container da ist UND wir NICHT im Edit-Modus sind
    if (resultGroupContainer && !editGroupList) {
        
        const $rows = $('.participant-row');

        // Nur starten, wenn auch wirklich Schüler-Zeilen da sind
        if ($rows.length > 0) {
            console.log('✅ Ergebnis-Modus: Starte Gruppen-Analyse aus Tabelle...');
            
            const groups = new Set();

            $rows.each(function(index) {
                const $row = $(this);
                // Werte auslesen
                let rawClass = $row.attr('data-class');
                let rawGroup = $row.attr('data-group');
                let val = (rawClass || rawGroup || '').trim();

                if (val !== '') {
                    groups.add(val);
                }
            });

            const sortedGroups = Array.from(groups).sort();
            console.log('🏁 Gefundene Gruppen:', sortedGroups);

            // HTML neu bauen (Container leeren und neu befüllen)
            resultGroupContainer.innerHTML = '';
            
            if (sortedGroups.length === 0) {
                resultGroupContainer.innerHTML = '<div class="text-danger p-2 small">Keine Gruppen-Daten gefunden.<br>(Attribute data-class/group fehlen)</div>';
            } else {
                $('.js-group-all, .js-group-none').show();

                sortedGroups.forEach(grp => {
                    const safeId = 'chk_grp_' + grp.replace(/[^a-zA-Z0-9-_]/g, '_');
                    
                    const html = `
                        <div class="form-check mb-1">
                            <input class="form-check-input group-checkbox" type="checkbox" value="${grp}" id="${safeId}" checked>
                            <label class="form-check-label w-100 text-break" for="${safeId}" style="cursor:pointer;">
                                ${grp}
                            </label>
                        </div>`;
                    resultGroupContainer.insertAdjacentHTML('beforeend', html);
                });
            }

            // --- Filter Logik für Tabelle ---
            const updateParticipantFilter = () => {
                const $checkboxes = $(resultGroupContainer).find('.group-checkbox');
                const checkedGroups = $checkboxes.filter(':checked').map((_, el) => el.value).get();
                const allGroupsChecked = checkedGroups.length === $checkboxes.length;
                const searchTerm = $('#client-search-input').val()?.toLowerCase().trim() || '';

                $rows.each(function() {
                    const $row = $(this);
                    let rowVal = ($row.attr('data-class') || $row.attr('data-group') || '').trim();
                    
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
            $(resultGroupContainer).on('change', updateParticipantFilter);
            $('#client-search-input').on('keyup input', updateParticipantFilter);

            $('.js-group-all').on('click', function(e) { 
                e.preventDefault(); e.stopPropagation();
                $(resultGroupContainer).find('input').prop('checked', true); 
                updateParticipantFilter(); 
            });
            $('.js-group-none').on('click', function(e) { 
                e.preventDefault(); e.stopPropagation();
                $(resultGroupContainer).find('input').prop('checked', false); 
                updateParticipantFilter(); 
            });
            
            // Init
            updateParticipantFilter();
        } else {
            console.log('ℹ️ Keine Teilnehmer-Zeilen gefunden. Gruppen-Filter inaktiv.');
        }
    }

    // =========================================================
    // 4. UX HELPER (Dropdown offen halten)
    // =========================================================
    $('.dropdown-menu').on('click', function(e) {
        e.stopPropagation();
    });
});