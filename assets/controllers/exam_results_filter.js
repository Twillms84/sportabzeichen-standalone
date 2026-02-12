import $ from 'jquery';

document.addEventListener('DOMContentLoaded', function() {

    // =========================================================
    // 1. ANSICHT FILTER (Spalten ein/ausblenden)
    // =========================================================
    const viewContainer = document.getElementById('view-checkbox-container');
    const viewBtn = document.getElementById('viewFilterBtn');
    
    if (viewContainer && viewBtn) {
        // 1. Kategorien automatisch aus dem DOM ermitteln
        // Wir suchen nach Elementen mit Klassen wie 'col-cat-ausdauer', 'col-cat-kraft' etc.
        const categories = new Set();
        document.querySelectorAll('[class*="col-cat-"]').forEach(el => {
            el.classList.forEach(cls => {
                if (cls.startsWith('col-cat-')) {
                    const catName = cls.replace('col-cat-', '');
                    categories.add(catName);
                }
            });
        });

        // 2. Checkboxen rendern
        const savedViewSelection = JSON.parse(localStorage.getItem('sportabzeichen_view_selection') || '[]');
        
        // Sortieren und rendern
        Array.from(categories).sort().forEach(cat => {
            // Standard-Label (erster Buchstabe groß)
            let label = cat.charAt(0).toUpperCase() + cat.slice(1);

            // SPEZIALFALL: Wenn die Klasse "swimming" heißt, nenne sie "Schwimmnachweis"
            if (cat.toLowerCase() === 'swimming') {
                label = 'Schwimmnachweis';
            }

            const isChecked = savedViewSelection.length === 0 || savedViewSelection.includes(cat);

            const html = `
                <div class="form-check"> <input class="form-check-input view-checkbox" type="checkbox" value="${cat}" id="chk_view_${cat}" ${isChecked ? 'checked' : ''}>
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
            const checkedValues = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);

            const allChecked = checkedValues.length === checkboxes.length;
            const noneChecked = checkedValues.length === 0;

            // Button Text Update
            viewBtn.textContent = 'Diziplin';

            // Spalten ein-/ausblenden
            categories.forEach(cat => {
                const cells = document.querySelectorAll('.col-cat-' + cat);
                // Wenn "Alle" oder Checkbox aktiv -> anzeigen
                if (allChecked || checkedValues.includes(cat)) {
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });

            // Speichern (Wenn alle ausgewählt sind, speichern wir ein leeres Array = Default)
            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(allChecked ? [] : checkedValues));
        };

        // Event Listener
        viewContainer.addEventListener('change', updateViewFilter);
   
        viewContainer.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // "Alle" / "Keine" Buttons
        document.querySelector('.js-view-all')?.addEventListener('click', () => {
            viewContainer.querySelectorAll('input').forEach(el => el.checked = true);
            updateViewFilter();
        });
        document.querySelector('.js-view-none')?.addEventListener('click', () => {
            viewContainer.querySelectorAll('input').forEach(el => el.checked = false);
            updateViewFilter();
        });

        // Initiale Ausführung
        updateViewFilter();
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
            // Prüfen auf data-class ODER data-group
            const rawVal = row.getAttribute('data-class') || row.getAttribute('data-group');
            if (rawVal && rawVal.trim() !== '') {
                groups.add(rawVal.trim());
            }
        });

        // 2. Checkboxen rendern
        groupContainer.innerHTML = ''; // Container leeren, falls doppelt geladen
        Array.from(groups).sort().forEach(grp => {
            const html = `
                <div class="form-check">
                    <input class="form-check-input group-checkbox" type="checkbox" value="${grp}" id="chk_grp_${grp}" checked>
                    <label class="form-check-label w-100 stretched-link" for="chk_grp_${grp}">
                        ${grp}
                    </label>
                </div>
            `;
            groupContainer.insertAdjacentHTML('beforeend', html);
        });

        // 3. Filter Logik
        const updateParticipantFilter = () => {
            // A. Checkbox Werte holen
            const checkboxes = groupContainer.querySelectorAll('.group-checkbox');
            const checkedGroups = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);
            
            // Wenn Anzahl der gecheckten gleich Gesamtanzahl ist -> Alles ausgewählt
            const allGroupsChecked = checkedGroups.length === checkboxes.length;
    
            // B. Button Text fixieren (falls gewünscht, sonst Zeile löschen)
            // groupBtn.textContent = 'Gruppen'; 

            // C. Suchbegriff
            const searchTerm = searchInput.value.toLowerCase().trim();

            // D. Zeilen filtern
            allRows.forEach(row => {
                const rowGroup = (row.getAttribute('data-class') || row.getAttribute('data-group') || '').trim();
                
                // Name suchen (angepasst auf deine Struktur .name-main oder .col-name)
                const nameEl = row.querySelector('.name-main') || row.querySelector('.col-name span'); 
                const nameText = nameEl ? nameEl.textContent.toLowerCase() : ''; 

                // Logik: Gruppe muss passen UND Suchbegriff muss passen
                const matchGroup = (allGroupsChecked || checkedGroups.includes(rowGroup));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                if (matchGroup && matchSearch) {
                    row.style.display = ''; // Anzeigen
                } else {
                    row.style.display = 'none'; // Ausblenden
                }
            });

            // E. Druck-Button URL aktualisieren (nur wenn Funktion existiert)
            if (typeof updatePrintButtonUrl === 'function') {
                updatePrintButtonUrl(allGroupsChecked ? [] : checkedGroups, searchInput.value.trim());
            }
        };

        // Event Listener: Änderungen an Checkboxen
        groupContainer.addEventListener('change', updateParticipantFilter);

        // Event Listener: Suche
        searchInput.addEventListener('keyup', updateParticipantFilter);
        searchInput.addEventListener('input', updateParticipantFilter);

        // Event Listener: Dropdown nicht schließen beim Klicken
        groupContainer.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // --- HIER WAR DER FEHLER ---
        // Du hattest hier .js-view-all (Disziplinen) abgefragt. 
        // Es muss .js-group-all (Gruppen) sein:

        // "Alle" Button (Gruppen)
        document.querySelector('.js-group-all')?.addEventListener('click', (e) => {
            e.preventDefault(); // Springen verhindern
            e.stopPropagation();
            groupContainer.querySelectorAll('input').forEach(el => el.checked = true);
            updateParticipantFilter();
        });

        // "Keine" Button (Gruppen)
        document.querySelector('.js-group-none')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            groupContainer.querySelectorAll('input').forEach(el => el.checked = false);
            updateParticipantFilter();
        });
        
        // Initiale Ausführung
        updateParticipantFilter();
    }

    // Helper: Print URL
    function updatePrintButtonUrl(selectedGroups, rawSearchTerm) {
        const $printBtn = $('#btn-print-groupcard'); 
        if (!$printBtn.length) return;

        if (!$printBtn.data('base-href')) {
            $printBtn.data('base-href', $printBtn.attr('href'));
        }
        const baseUrl = $printBtn.data('base-href');
        
        // Wenn Gruppen ausgewählt sind, nehmen wir die erste für den Print-Link (oder Logik anpassen)
        // Wenn "Alle" ausgewählt sind (selectedGroups ist leer oder voll), schicken wir leer
        const selectedGroupParam = (selectedGroups.length > 0) ? selectedGroups[0] : '';
        
        const separator = baseUrl.includes('?') ? '&' : '?';
        let newUrl = baseUrl + separator + 'class_filter=' + encodeURIComponent(selectedGroupParam);
        
        if (rawSearchTerm) {
            newUrl += '&search_query=' + encodeURIComponent(rawSearchTerm);
        }
        $printBtn.attr('href', newUrl);
    }
});