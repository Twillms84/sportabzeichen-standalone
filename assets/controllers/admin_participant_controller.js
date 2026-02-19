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
}