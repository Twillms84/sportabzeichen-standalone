import $ from 'jquery';

document.addEventListener('DOMContentLoaded', function() {

    // =========================================================
    // HELPER: Dropdown Schutz & Cursor Fix
    // =========================================================
    // Diese Funktion verhindert das Schließen des Menüs und repariert den Mauszeiger
    function setupDropdownBehavior(containerElement, btnAllSelector, btnNoneSelector, onUpdateCallback) {
        if (!containerElement) return;

        // 1. Das gesamte Dropdown-Menü gegen Zuklappen schützen
        const dropdownMenu = containerElement.closest('.dropdown-menu');
        if (dropdownMenu) {
            dropdownMenu.addEventListener('click', function(e) {
                e.stopPropagation(); // Stoppt das Signal an Bootstrap
            });
        }

        // 2. Buttons konfigurieren (Mauszeiger + Klick)
        const btnAll = document.querySelector(btnAllSelector);
        const btnNone = document.querySelector(btnNoneSelector);

        if (btnAll) {
            btnAll.style.cursor = 'pointer'; // CSS-Notfall-Fix
            btnAll.addEventListener('click', (e) => {
                e.preventDefault();
                // Checkboxen im Container suchen und setzen
                containerElement.querySelectorAll('input[type="checkbox"]').forEach(el => el.checked = true);
                onUpdateCallback(); // Filter-Funktion aufrufen
            });
        }

        if (btnNone) {
            btnNone.style.cursor = 'pointer'; // CSS-Notfall-Fix
            btnNone.addEventListener('click', (e) => {
                e.preventDefault();
                containerElement.querySelectorAll('input[type="checkbox"]').forEach(el => el.checked = false);
                onUpdateCallback(); // Filter-Funktion aufrufen
            });
        }
    }

    // =========================================================
    // 1. ANSICHT FILTER (Spalten ein/ausblenden)
    // =========================================================
    const viewContainer = document.getElementById('view-checkbox-container');
    const viewBtn = document.getElementById('viewFilterBtn');
    
    if (viewContainer && viewBtn) {
        // 1. Kategorien automatisch aus dem DOM ermitteln
        const categories = new Set();
        document.querySelectorAll('[class*="col-cat-"]').forEach(el => {
            el.classList.forEach(cls => {
                if (cls.startsWith('col-cat-')) {
                    categories.add(cls.replace('col-cat-', ''));
                }
            });
        });

        // 2. Checkboxen rendern
        const savedViewSelection = JSON.parse(localStorage.getItem('sportabzeichen_view_selection') || '[]');
        
        Array.from(categories).sort().forEach(cat => {
            let label = cat.charAt(0).toUpperCase() + cat.slice(1);
            if (cat.toLowerCase() === 'swimming') label = 'Schwimmnachweis';

            const isChecked = savedViewSelection.length === 0 || savedViewSelection.includes(cat);

            const html = `
                <div class="form-check"> 
                    <input class="form-check-input view-checkbox" type="checkbox" value="${cat}" id="chk_view_${cat}" ${isChecked ? 'checked' : ''}>
                    <label class="form-check-label w-100" for="chk_view_${cat}" style="cursor: pointer;">
                        ${label}
                    </label>
                </div>
            `;
            viewContainer.insertAdjacentHTML('beforeend', html);
        });

        // 3. Logik Funktion
        const updateViewFilter = () => {
            const checkboxes = viewContainer.querySelectorAll('.view-checkbox');
            const checkedValues = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            const allChecked = checkedValues.length === checkboxes.length;

            viewBtn.textContent = 'Diziplin'; // Text fixieren

            // Spalten ein-/ausblenden
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

        // Event Listener für Änderungen
        viewContainer.addEventListener('change', updateViewFilter);
        
        // Initiale Ausführung
        updateViewFilter();

        // FIX: Dropdown-Schutz & Buttons aktivieren
        setupDropdownBehavior(viewContainer, '.js-view-all', '.js-view-none', updateViewFilter);
    }

    // =========================================================
    // 2. TEILNEHMER / GRUPPEN FILTER & SUCHE
    // =========================================================
    const groupContainer = document.getElementById('group-checkbox-container');
    const groupBtn = document.getElementById('groupFilterBtn');
    const searchInput = document.getElementById('client-search-input');
    
    if (groupContainer && groupBtn && searchInput) {
        const allRows = document.querySelectorAll('.participant-row');
        const groups = new Set(); 

        // 1. Gruppen sammeln
        allRows.forEach(row => {
            const rawVal = row.getAttribute('data-class') || row.getAttribute('data-group');
            if (rawVal && rawVal.trim() !== '') {
                groups.add(rawVal.trim());
            }
        });

        // 2. Checkboxen rendern
        groupContainer.innerHTML = ''; 
        Array.from(groups).sort().forEach(grp => {
            const html = `
                <div class="form-check">
                    <input class="form-check-input group-checkbox" type="checkbox" value="${grp}" id="chk_grp_${grp}" checked>
                    <label class="form-check-label w-100" for="chk_grp_${grp}" style="cursor: pointer;">
                        ${grp}
                    </label>
                </div>
            `;
            groupContainer.insertAdjacentHTML('beforeend', html);
        });

        // 3. Filter Logik
        const updateParticipantFilter = () => {
            const checkboxes = groupContainer.querySelectorAll('.group-checkbox');
            const checkedGroups = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            const allGroupsChecked = checkedGroups.length === checkboxes.length;
            const searchTerm = searchInput.value.toLowerCase().trim();

            allRows.forEach(row => {
                const rowGroup = (row.getAttribute('data-class') || row.getAttribute('data-group') || '').trim();
                const nameEl = row.querySelector('.name-main') || row.querySelector('.col-name span'); 
                const nameText = nameEl ? nameEl.textContent.toLowerCase() : ''; 

                const matchGroup = (allGroupsChecked || checkedGroups.includes(rowGroup));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                row.style.display = (matchGroup && matchSearch) ? '' : 'none';
            });

            if (typeof updatePrintButtonUrl === 'function') {
                updatePrintButtonUrl(allGroupsChecked ? [] : checkedGroups, searchInput.value.trim());
            }
        };

        // Event Listener
        groupContainer.addEventListener('change', updateParticipantFilter);
        searchInput.addEventListener('keyup', updateParticipantFilter);
        searchInput.addEventListener('input', updateParticipantFilter);

        // Initiale Ausführung
        updateParticipantFilter();

        // FIX: Dropdown-Schutz & Buttons aktivieren
        setupDropdownBehavior(groupContainer, '.js-group-all', '.js-group-none', updateParticipantFilter);
    }

    // Helper: Print URL
    function updatePrintButtonUrl(selectedGroups, rawSearchTerm) {
        const $printBtn = $('#btn-print-groupcard'); 
        if (!$printBtn.length) return;

        if (!$printBtn.data('base-href')) {
            $printBtn.data('base-href', $printBtn.attr('href'));
        }
        const baseUrl = $printBtn.data('base-href');
        const selectedGroupParam = (selectedGroups.length > 0) ? selectedGroups[0] : '';
        const separator = baseUrl.includes('?') ? '&' : '?';
        let newUrl = baseUrl + separator + 'class_filter=' + encodeURIComponent(selectedGroupParam);
        
        if (rawSearchTerm) {
            newUrl += '&search_query=' + encodeURIComponent(rawSearchTerm);
        }
        $printBtn.attr('href', newUrl);
    }
});