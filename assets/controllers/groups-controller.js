import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap'; // Importiert das Bootstrap-Modal

export default class extends Controller {
    // Definieren der HTML-Elemente, auf die wir zugreifen wollen
    static targets = ["modal", "idInput", "nameInput", "modalTitle"];
    // URL für den AJAX-Request aus Twig übernehmen
    static values = { saveUrl: String };

    connect() {
        // Initialisiert das Bootstrap-Modal, sobald der Controller geladen ist
        this.modalInstance = new Modal(this.modalTarget);
    }

    // Wird aufgerufen, wenn man auf "Neue Gruppe" klickt
    openCreate() {
        this.idInputTarget.value = '';
        this.nameInputTarget.value = '';
        this.modalTitleTarget.innerText = 'Neue Gruppe anlegen';
        this.modalInstance.show();
    }

    // Wird aufgerufen, wenn man auf den Stift (Bearbeiten) klickt
    openEdit(event) {
        // Holt sich ID und Name aus den data-Attributen des Buttons
        const id = event.currentTarget.dataset.id;
        const name = event.currentTarget.dataset.name;
        
        this.idInputTarget.value = id;
        this.nameInputTarget.value = name;
        this.modalTitleTarget.innerText = 'Gruppe umbenennen';
        this.modalInstance.show();
    }

    // Wird beim Klick auf Speichern oder Drücken von "Enter" aufgerufen
    save(event) {
        event.preventDefault();
        
        const id = this.idInputTarget.value;
        const name = this.nameInputTarget.value.trim();

        if (!name) {
            alert('Bitte gib einen Gruppennamen ein.');
            return;
        }

        const formData = new FormData();
        formData.append('id', id);
        formData.append('name', name);

        fetch(this.saveUrlValue, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.modalInstance.hide();
                window.location.reload(); // Seite neu laden für frische Tabelle
            } else {
                alert('Fehler: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Netzwerkfehler beim Speichern der Gruppe.');
        });
    }
}