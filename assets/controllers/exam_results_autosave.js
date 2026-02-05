import $ from 'jquery';

/**
 * Sportabzeichen Manager
 * Modularer Aufbau für bessere Wartbarkeit und Performance.
 */
const SportabzeichenApp = {
    csrfToken: null,
    routes: {},

    init() {
        const form = document.getElementById('autosave-form');
        if (!form) return;

        // Globale Konfiguration laden
        this.csrfToken = form.dataset.globalToken;
        this.routes = {
            discipline: form.dataset.disciplineRoute,
            result: form.dataset.resultRoute,
            swimming: form.dataset.swimmingRoute,
            swimmingDelete: form.dataset.swimmingDeleteRoute
        };

        // Module initialisieren
        this.Filters.init();
        this.FormHandler.init(form);
        this.UI.init();
    },

    // =========================================================
    // MODUL: API & NETZWERK
    // =========================================================
    API: {
        async post(url, data) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });
                return await response.json();
            } catch (error) {
                console.error('API Error:', error);
                throw new Error('Kommunikationsfehler mit dem Server.');
            }
        }
    },

    // =========================================================
    // MODUL: FILTER & ANSICHT
    // =========================================================
    Filters: {
        $viewSelector: null,
        $classFilter: null,
        searchInput: null,
        printBtn: null,
        debounceTimer: null,

        init() {
            this.$viewSelector = $('#viewSelector');
            this.$classFilter = $('#client-class-filter');
            this.searchInput = document.getElementById('client-search-input');
            this.printBtn = document.getElementById('btn-print-groupcard');

            this.initViewSelector();
            this.initParticipantFilter();
        },

        initViewSelector() {
            if (!this.$viewSelector.length) return;

            // Event Handler
            this.$viewSelector.on('changed.bs.select', (e) => {
                const selectedCategories = this.$viewSelector.val() || [];
                const showAll = selectedCategories.length === 0;

                // Performance: Nutzung von CSS Klassen statt Loop über alle Zellen
                const allCells = document.querySelectorAll('[class*="col-cat-"]');
                allCells.forEach(cell => cell.classList.add('col-hidden'));

                if (showAll) {
                    allCells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    selectedCategories.forEach(cat => {
                        const cells = document.querySelectorAll('.col-cat-' + cat);
                        cells.forEach(cell => cell.classList.remove('col-hidden'));
                    });
                }
                localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(selectedCategories));
            });

            // Restore State
            const saved = localStorage.getItem('sportabzeichen_view_selection');
            if (saved) {
                try {
                    const parsed = JSON.parse(saved);
                    if (parsed && parsed.length) {
                        this.$viewSelector.selectpicker('val', parsed);
                        this.$viewSelector.trigger('changed.bs.select');
                    }
                } catch (e) { console.error('Storage Error', e); }
            }
        },

        initParticipantFilter() {
            if (!this.$classFilter.length || !this.searchInput) return;

            const allRows = document.querySelectorAll('.participant-row');
            const groups = new Set();

            // Gruppen extrahieren und Dropdown füllen
            allRows.forEach(row => {
                const val = (row.dataset.class || row.dataset.group || '').trim();
                if (val) groups.add(val);
            });

            [...groups].sort().forEach(val => {
                this.$classFilter.append(`<option value="${val}">${val}</option>`);
            });

            if ($.fn.selectpicker) this.$classFilter.selectpicker('refresh');

            // Event Listeners (mit Debounce für Performance)
            this.$classFilter.on('change changed.bs.select', () => this.applyFilters());
            
            this.searchInput.addEventListener('input', () => {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => this.applyFilters(), 300); // 300ms warten
            });
        },

        applyFilters() {
            let selectedGroups = this.$classFilter.val() || [];
            if (!Array.isArray(selectedGroups)) selectedGroups = [selectedGroups];
            
            const searchTerm = this.searchInput.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.participant-row');

            rows.forEach(row => {
                const rowGroup = (row.dataset.class || row.dataset.group || '').trim();
                const nameText = (row.querySelector('.name-main')?.textContent || '').toLowerCase();

                const matchGroup = selectedGroups.length === 0 || selectedGroups.includes(rowGroup);
                const matchSearch = searchTerm === '' || nameText.includes(searchTerm);

                row.style.display = (matchGroup && matchSearch) ? '' : 'none';
            });

            this.updatePrintButtonUrl(selectedGroups[0] || '', searchTerm);
        },

        updatePrintButtonUrl(group, search) {
            if (!this.printBtn) return;
            
            // Basis URL cachen
            if (!this.printBtn.dataset.baseHref) {
                this.printBtn.dataset.baseHref = this.printBtn.getAttribute('href');
            }

            const url = new URL(this.printBtn.dataset.baseHref, window.location.origin);
            if (group) url.searchParams.set('class_filter', group);
            if (search) url.searchParams.set('search_query', search);

            this.printBtn.href = url.toString();
        }
    },

    // =========================================================
    // MODUL: FORMULAR LOGIK (AUTOSAVE)
    // =========================================================
    FormHandler: {
        init(form) {
            // Change Listener (Delegation)
            form.addEventListener('change', (e) => this.handleChange(e));
            
            // Delete Click Listener (Delegation)
            document.addEventListener('click', (e) => this.handleDelete(e));

            // Initiale UI Checks für Selects
            document.querySelectorAll('.js-discipline-select').forEach(select => {
                SportabzeichenApp.UI.updateRequirementHints(select);
                SportabzeichenApp.UI.checkVerbandInput(select);
            });
        },

        async handleChange(event) {
            const el = event.target;
            // Nur Elemente mit data-save verarbeiten
            if (!el.dataset.save) return;

            const { epId, type, kategorie: kat } = el.dataset;
            const cell = el.closest('td');
            const row = el.closest('tr');
            
            // Input sperren (UX Feedback)
            const inputEl = cell?.querySelector('input[type="text"]');
            if (inputEl && type === 'leistung') {
                this.toggleInputLock(inputEl, true);
            }

            // Payload vorbereiten
            const payload = { 
                ep_id: epId, 
                _token: SportabzeichenApp.csrfToken 
            };
            
            let route = '';

            if (type === 'swimming_select') {
                route = SportabzeichenApp.routes.swimming;
                payload.discipline_id = el.value;
            } else {
                const selectEl = cell?.querySelector('select');
                if (!selectEl?.value || !epId) {
                    if(inputEl) this.toggleInputLock(inputEl, false);
                    return;
                }

                route = (el.tagName === 'SELECT') ? SportabzeichenApp.routes.discipline : SportabzeichenApp.routes.result;
                
                // UI sofort aktualisieren bei Select-Wechsel
                if (el.tagName === 'SELECT') {
                    SportabzeichenApp.UI.updateRequirementHints(el);
                    SportabzeichenApp.UI.checkVerbandInput(el);
                }

                payload.discipline_id = selectEl.value;
                payload.leistung = inputEl ? inputEl.value : '';
            }

            try {
                const data = await SportabzeichenApp.API.post(route, payload);

                if (data.status === 'ok' || data.success) {
                    if (inputEl) this.toggleInputLock(inputEl, false);
                    
                    // UI Updates
                    if (type !== 'swimming_select' && cell) {
                        SportabzeichenApp.UI.handleDisciplineColors(data, cell, row, kat, el);
                        if (data.new_requirements) {
                            SportabzeichenApp.UI.updateRequirementsBadges(cell, data.new_requirements);
                        }
                    }
                    SportabzeichenApp.UI.updateWidgets(epId, row, data);
                } else {
                    throw new Error(data.message || 'Serverfehler');
                }
            } catch (error) {
                if (inputEl) {
                    this.toggleInputLock(inputEl, false);
                    this.showError(inputEl);
                }
                console.error(error);
            }
        },

        async handleDelete(event) {
            const btn = event.target.closest('.btn-delete-swimming');
            if (!btn) return;

            event.preventDefault();

            // Validierung
            const { epId, year: proofYear, currentYear, source } = btn.dataset;
            
            if (proofYear && currentYear && proofYear !== currentYear) {
                alert(`Jahr ${proofYear} kann hier nicht gelöscht werden.`);
                return;
            }
            if (source && /AUSDAUER|SCHNELLIGKEIT|ENDURANCE|SPEED/i.test(source)) {
                alert(`Automatisch durch "${source}" erbracht. Bitte dort löschen.`);
                return;
            }
            if (!confirm('Wirklich löschen?')) return;

            // UI Feedback: Button deaktivieren
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.style.opacity = '0.5';

            try {
                const data = await SportabzeichenApp.API.post(SportabzeichenApp.routes.swimmingDelete, {
                    ep_id: epId,
                    _token: SportabzeichenApp.csrfToken
                });

                if (data.status === 'ok' || data.success) {
                    const row = btn.closest('tr');
                    SportabzeichenApp.UI.updateWidgets(epId, row, data);
                    SportabzeichenApp.UI.resetSwimmingWrapper(epId);
                } else {
                    alert('Fehler: ' + (data.message || 'Konnte nicht gelöscht werden.'));
                }
            } catch (e) {
                alert('Kommunikationsfehler.');
            } finally {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        },

        toggleInputLock(input, lock) {
            if(!input) return;
            input.disabled = lock;
            input.style.opacity = lock ? '0.6' : '1';
            if (!lock) input.focus();
        },

        showError(input) {
            input.classList.add('bg-danger', 'bg-opacity-25');
            setTimeout(() => input.classList.remove('bg-danger', 'bg-opacity-25'), 3000);
        }
    },

    // =========================================================
    // MODUL: UI HELFER (Badges, Colors, Widgets)
    // =========================================================
    UI: {
        init() {
            // Optional: Tooltips o.ä. hier initialisieren
        },

        updateWidgets(epId, row, data) {
            // 1. Gesamtpunkte
            const points = data.total ?? data.total_points;
            const totalBadge = document.getElementById(`total-points-${epId}`);
            if (totalBadge && points !== undefined) {
                totalBadge.textContent = points;
                this.triggerPulse(totalBadge);
            }

            // 2. Medaille
            const medalBadge = document.getElementById(`final-medal-${epId}`);
            if (medalBadge) {
                const medal = (data.final_medal || data.medal || 'none').toLowerCase();
                const labelMap = { 'gold': 'Gold', 'silver': 'Silber', 'silber': 'Silber', 'bronze': 'Bronze' };
                const colorClass = labelMap[medal] ? `medal-${medal === 'silver' ? 'silber' : medal}` : 'bg-light text-muted';
                
                medalBadge.className = `result-badge-box ${colorClass}`;
                
                const labelSpan = medalBadge.querySelector('.js-medal-label');
                const text = labelMap[medal] || '-';
                
                if (labelSpan) labelSpan.textContent = text;
                else medalBadge.textContent = text;
                
                this.triggerPulse(medalBadge);
            }

            // 3. Schwimmen (Wrapper Logik)
            const hasSwimming = !!(data.has_swimming);
            const wrapper = document.getElementById(`swimming-wrapper-${epId}`);
            
            if (wrapper) {
                const badgeCont = wrapper.querySelector('.swim-badge-container');
                const dropCont = wrapper.querySelector('.swim-dropdown-container');
                const textEl = wrapper.querySelector('.swim-info-text');

                if (hasSwimming) {
                    if (textEl) {
                        let txt = data.swimming_name;
                        // Fallback auf Select Text
                        if (!txt || txt.includes('DISCIPLINE')) {
                            const sel = wrapper.querySelector('select');
                            if (sel && sel.selectedIndex > -1 && sel.value) txt = sel.options[sel.selectedIndex].text;
                        }
                        textEl.textContent = txt || 'Nachweis erbracht';
                    }
                    badgeCont?.classList.remove('d-none');
                    dropCont?.classList.add('d-none');
                } else {
                    this.resetSwimmingWrapper(epId);
                }
            }
        },

        resetSwimmingWrapper(epId) {
            const wrapper = document.getElementById(`swimming-wrapper-${epId}`);
            if (!wrapper) return;

            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont = wrapper.querySelector('.swim-dropdown-container');
            const textEl = wrapper.querySelector('.swim-info-text');
            const select = wrapper.querySelector('select');

            badgeCont?.classList.add('d-none');
            dropCont?.classList.remove('d-none');
            if(textEl) textEl.textContent = '';
            if(select) select.value = "";
        },

        updateRequirementsBadges(cell, req) {
            const setTxt = (sel, txt) => {
                const el = cell.querySelector(sel);
                if(el) el.textContent = txt;
            };
            setTxt('.req-val-b', req.bronze);
            setTxt('.req-val-s', req.silber);
            setTxt('.req-val-g', req.gold);
        },

        updateRequirementHints(select) {
            const cell = select.closest('td');
            if (!cell) return;

            const opt = select.selectedOptions[0];
            const hasValue = opt && opt.value;
            const input = cell.querySelector('input[data-type="leistung"]');

            const setText = (cls, txt) => {
                const el = cell.querySelector(cls);
                if (el) el.textContent = txt;
            };

            if (!hasValue) {
                ['.req-val-b', '.req-val-s', '.req-val-g', '.req-unit'].forEach(c => setText(c, ''));
                if (input) input.disabled = true;
                return;
            }

            const unitRaw = opt.dataset.unit;
            const implicitPoints = parseInt(opt.dataset.implicitPoints || 0);
            const isVerband = (unitRaw === 'NONE' || unitRaw === 'UNIT_NONE' || implicitPoints > 0);

            if (isVerband) {
                ['.req-val-b', '.req-val-s', '.req-val-g', '.req-unit'].forEach(c => setText(c, ''));
            } else {
                setText('.req-val-b', opt.dataset.bronze || '-');
                setText('.req-val-s', opt.dataset.silber || '-');
                setText('.req-val-g', opt.dataset.gold || '-');
                setText('.req-unit', opt.dataset.unitLabel || '');
            }
        },

        checkVerbandInput(select) {
            const cell = select.closest('td');
            const input = cell?.querySelector('input[type="text"]');
            if (!input) return;

            const opt = select.selectedOptions[0];
            if (!opt || !opt.value) {
                input.disabled = true;
                input.value = '';
                this.removeMedalClasses(input);
                this.removeMedalClasses(select);
                return;
            }

            const unit = opt.dataset.unit;
            const implicitPoints = parseInt(opt.dataset.implicitPoints || 0);
            const isVerband = (unit === 'NONE' || unit === 'UNIT_NONE' || implicitPoints > 0);

            if (isVerband) {
                input.value = '';
                input.placeholder = '✓';
                input.disabled = true;
                input.classList.add('bg-light', 'medal-gold');
                this.removeMedalClasses(input, ['medal-gold']); // Alle außer Gold entfernen
                select.classList.add('medal-gold');
            } else {
                input.disabled = false;
                input.placeholder = '';
                input.classList.remove('bg-light');
                
                if (input.value === '') {
                    this.removeMedalClasses(input);
                    input.classList.add('medal-none');
                    select.classList.add('medal-none');
                }
            }
        },

        handleDisciplineColors(data, cell, row, kat, triggeredElement) {
            const select = cell.querySelector('select');
            const input = cell.querySelector('input[type="text"]');
            const isSelect = triggeredElement.tagName === 'SELECT';

            const resultColor = (data.stufe || 'none').toLowerCase();
            const cssClass = `medal-${resultColor === 'silver' ? 'silber' : resultColor}`;

            [select, input].forEach(el => {
                if (!el) return;
                this.removeMedalClasses(el);
                el.classList.add(cssClass);
            });

            // Sonderfall: Verbandsabzeichen (Unit None)
            if (data.discipline_unit === 'NONE' || data.discipline_unit === 'UNIT_NONE') {
                if (input) {
                    input.value = '';
                    input.disabled = true;
                    input.placeholder = '✓';
                    input.classList.add('bg-light');
                }
                // Bei Verband immer Gold anzeigen
                if(input) { this.removeMedalClasses(input); input.classList.add('medal-gold'); }
                if(select) { this.removeMedalClasses(select); select.classList.add('medal-gold'); }
            } else if (isSelect) {
                this.checkVerbandInput(select);
            }

            // Exklusive Auswahl in der Kategorie (Radio-Verhalten für Zeilen)
            if (isSelect && kat) {
                row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(other => {
                    if (other.closest('td') === cell) return; // Mich selbst ignorieren
                    
                    if (other.tagName === 'INPUT') {
                        other.value = '';
                        other.disabled = true;
                        other.classList.remove('bg-light');
                        this.removeMedalClasses(other);
                        other.classList.add('medal-none');
                    }
                    if (other.tagName === 'SELECT') {
                        other.value = '';
                        this.removeMedalClasses(other);
                        other.classList.add('medal-none');
                        this.updateRequirementHints(other);
                    }
                });
            }
        },

        removeMedalClasses(el, exceptions = []) {
            ['medal-gold', 'medal-silber', 'medal-bronze', 'medal-none'].forEach(cls => {
                if (!exceptions.includes(cls)) el.classList.remove(cls);
            });
        },

        triggerPulse(el) {
            el.style.transition = "transform 0.2s ease-in-out";
            el.style.transform = "scale(1.1)";
            setTimeout(() => el.style.transform = "scale(1)", 200);
        }
    }
};

// Start
document.addEventListener('DOMContentLoaded', () => SportabzeichenApp.init());