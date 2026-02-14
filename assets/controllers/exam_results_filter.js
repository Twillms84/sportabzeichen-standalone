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
    // 2. TEILNEHMER / GRUPPEN FILTER
    // =========================================================
    if (groupContainer) {
        const allRows = document.querySelectorAll('.participant-row');
        const groups = new Set();
        const searchInput = document.getElementById('client-search-input');

        // Gruppen sammeln
        allRows.forEach(row => {
            const rawVal = row.getAttribute('data-class') || row.getAttribute('data-group');
            if (rawVal && rawVal.trim() !== '') groups.add(rawVal.trim());
        });

        // Checkboxen bauen
        groupContainer.innerHTML = '';
        Array.from(groups).sort().forEach(grp => {
            const html = `
                <div class="form-check mb-1">
                    <input class="form-check-input group-checkbox" type="checkbox" value="${grp}" id="chk_grp_${grp}" checked>
                    <label class="form-check-label w-100" for="chk_grp_${grp}">${grp}</label>
                </div>`;
            groupContainer.insertAdjacentHTML('beforeend', html);
        });

        // Update Logik
        const updateParticipantFilter = () => {
            const checkboxes = groupContainer.querySelectorAll('.group-checkbox');
            const checkedGroups = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            const allGroupsChecked = checkedGroups.length === checkboxes.length;
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';

            allRows.forEach(row => {
                const rowGroup = (row.getAttribute('data-class') || row.getAttribute('data-group') || '').trim();
                const nameEl = row.querySelector('.name-main') || row.querySelector('.col-name span'); 
                const nameText = nameEl ? nameEl.textContent.toLowerCase() : ''; 

                const matchGroup = (allGroupsChecked || checkedGroups.includes(rowGroup));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                row.style.display = (matchGroup && matchSearch) ? '' : 'none';
            });
            
            // Print URL Update (optional)
            if (typeof $ !== 'undefined' && $('#btn-print-groupcard').length) {
                 const $printBtn = $('#btn-print-groupcard');
                 if (!$printBtn.data('base-href')) $printBtn.data('base-href', $printBtn.attr('href'));
                 const baseUrl = $printBtn.data('base-href');
                 const selGrp = (checkedGroups.length > 0 && !allGroupsChecked) ? checkedGroups[0] : '';
                 const sep = baseUrl.includes('?') ? '&' : '?';
                 let newUrl = baseUrl + sep + 'class_filter=' + encodeURIComponent(selGrp);
                 if (searchTerm) newUrl += '&search_query=' + encodeURIComponent(searchTerm);
                 $printBtn.attr('href', newUrl);
            }
        };

        // Listener
        groupContainer.addEventListener('change', updateParticipantFilter);
        if (searchInput) {
            searchInput.addEventListener('keyup', updateParticipantFilter);
            searchInput.addEventListener('input', updateParticipantFilter);
        }

        // Buttons Alle/Keine
        document.querySelector('.js-group-all')?.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            groupContainer.querySelectorAll('input').forEach(el => el.checked = true);
            updateParticipantFilter();
        });
        document.querySelector('.js-group-none')?.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            groupContainer.querySelectorAll('input').forEach(el => el.checked = false);
            updateParticipantFilter();
        });
        
        // Initialer Run
        updateParticipantFilter();
    }
});