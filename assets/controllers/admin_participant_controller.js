import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

export default class extends Controller {
    static targets = ["bulkBar", "selectedCount", "groupSelect"];

    connect() {
        // Prüfen, ob beim Laden (z.B. Zurück-Button) schon Boxen aktiv sind
        this.updateSelection();
        this._setupEditModal();
    }

    // --- 1. Bulk Action Logik ---

    toggleAll(event) {
        const isChecked = event.target.checked;
        const checkboxes = this.element.querySelectorAll('.participant-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = isChecked;
        });
        this.updateSelection();
    }

    updateSelection() {
        const selectedBoxes = this.element.querySelectorAll('.participant-checkbox:checked');
        const count = selectedBoxes.length;

        if (this.hasBulkBarTarget) {
            if (count > 0) {
                this.bulkBarTarget.classList.remove('d-none');
                this.selectedCountTarget.textContent = count;
            } else {
                this.bulkBarTarget.classList.add('d-none');
            }
        }
    }

    assignGroup(event) {
        const url = event.currentTarget.dataset.url;
        const groupId = this.groupSelectTarget.value;
        const selectedIds = Array.from(this.element.querySelectorAll('.participant-checkbox:checked'))
                                 .map(cb => cb.value);

        if (!groupId) return alert("Bitte eine Gruppe wählen!");
        if (!confirm(`${selectedIds.length} Teilnehmer der Gruppe zuweisen?`)) return;

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: selectedIds, groupId: groupId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert("Fehler: " + (data.message || "Unbekannter Fehler"));
            }
        })
        .catch(err => alert("Netzwerkfehler: " + err));
    }

    // --- 2. Edit Modal Logik (Vanilla JS) ---

    _setupEditModal() {
        const modalEl = document.getElementById('genericEditModal');
        if (!modalEl) return;

        modalEl.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) return;

            const form = modalEl.querySelector('#genericEditForm');
            
            // 1. Daten ziehen (E-Mail ergänzt)
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const email = button.getAttribute('data-email'); // NEU
            const dob = button.getAttribute('data-dob');
            const gender = button.getAttribute('data-gender');
            const groupId = button.getAttribute('data-group');

            // 2. Felder im Modal finden (E-Mail ergänzt)
            const nameEl = modalEl.querySelector('#modalUserName');
            const emailInput = modalEl.querySelector('#modalEmail'); // NEU
            const dobInput = modalEl.querySelector('#modalDob');
            const genderSelect = modalEl.querySelector('#modalGender');
            const groupSelect = modalEl.querySelector('#modalGroup');

            // 3. Werte setzen
            if (nameEl) nameEl.textContent = name;
            if (emailInput) emailInput.value = email || ""; // NEU
            if (dobInput) dobInput.value = dob;
            if (genderSelect) genderSelect.value = gender;
            
            if (groupSelect) {
                groupSelect.value = groupId || ""; 
            }

            // 4. Form-Action
            const urlTemplate = form.getAttribute('data-url-template');
            if (urlTemplate && id) {
                form.action = urlTemplate.replace('PLACEHOLDER_ID', id);
            }
        });
    }
    confirmAction(event) {
        event.preventDefault();
        const target = event.currentTarget;
        const form = target.closest('form');
        
        // Wir holen uns die Nachricht und den Typ aus den data-Attributen
        const message = target.dataset.confirmMessage;
        const type = target.dataset.confirmType || 'warning';

        const modalEl = document.getElementById('confirmActionModal');
        const modal = new bootstrap.Modal(modalEl);
        
        // Modal-Styling anpassen
        const header = modalEl.querySelector('.modal-header');
        const confirmBtn = modalEl.querySelector('#confirmModalBtn');
        modalEl.querySelector('#confirmModalBody').textContent = message;

        header.className = `modal-header border-0 ${type === 'danger' ? 'bg-danger text-white' : 'bg-primary text-white'}`;
        confirmBtn.className = `btn px-4 shadow-sm ${type === 'danger' ? 'btn-danger' : 'btn-primary'}`;
        
        // Event-Listener für den "Ja"-Button im Modal
        const newBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
        
        newBtn.addEventListener('click', () => {
            modal.hide();
            if (form) {
                // Wenn es ein Formular ist (Löschen), einfach absenden
                form.submit();
            } else {
                // Wenn es kein Formular ist (Bulk-Action), die bestehende Logik aufrufen
                this.assignGroup(event); 
            }
        });
        
        modal.show();
    }
}