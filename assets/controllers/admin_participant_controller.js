import { Controller } from '@hotwired/stimulus';

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

        // Wir nutzen das Bootstrap Event 'show.bs.modal'
        modalEl.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget; // Der Button, der geklickt wurde
            if (!button) return;

            const form = modalEl.querySelector('#genericEditForm');
            
            // Daten aus den data-Attributen des Buttons holen
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const dob = button.getAttribute('data-dob');
            const gender = button.getAttribute('data-gender');

            // UI-Elemente im Modal befüllen
            const nameEl = modalEl.querySelector('#modalUserName');
            const dobInput = modalEl.querySelector('#modalDob');
            const genderSelect = modalEl.querySelector('#modalGender');

            if (nameEl) nameEl.textContent = name;
            if (dobInput) dobInput.value = dob;
            if (genderSelect) genderSelect.value = gender;

            // Formular-Action dynamisch setzen
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
        const message = target.dataset.confirmMessage || "Möchtest du das wirklich tun?";
        const type = target.dataset.confirmType || 'warning'; // default, danger, info...

        const modalEl = document.getElementById('confirmActionModal');
        const modal = new bootstrap.Modal(modalEl);
        
        // UI Anpassungen
        const header = modalEl.querySelector('.modal-header');
        const btn = modalEl.querySelector('#confirmModalBtn');
        modalEl.querySelector('#confirmModalBody').textContent = message;

        // Klassen zurücksetzen & neu setzen für das Design
        header.className = 'modal-header border-0 text-white ' + (type === 'danger' ? 'bg-danger' : 'bg-primary');
        btn.className = 'btn px-4 shadow-sm ' + (type === 'danger' ? 'btn-danger' : 'btn-primary');
        
        // Button Logik
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        
        newBtn.addEventListener('click', () => {
            modal.hide();
            if (form) form.submit();
            else this.assignGroup(event); // Falls es kein Form-Submit ist
        });
        
        modal.show();
    }
}