import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        console.log('✅ Exam Overview Controller ist bereit!');
    }

    /**
     * PRÜFERWECHSEL (Per Select-Box)
     */
    async changeExaminer(event) {
        const select = event.currentTarget;
        const examId = select.dataset.examId;
        const newExaminerId = select.value;

        // UI Feedback: Sperren während des Speicherns
        select.disabled = true;
        select.classList.add('opacity-50');

        try {
            const response = await fetch(`/admin/api/exam/${examId}/set-examiner`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ examiner_id: newExaminerId })
            });

            if (response.ok) {
                // Erfolg: Kurz grün leuchten lassen
                select.classList.remove('is-invalid');
                select.classList.add('is-valid');
                setTimeout(() => select.classList.remove('is-valid'), 2000);
            } else {
                throw new Error('Speichern fehlgeschlagen');
            }
        } catch (error) {
            console.error('Fehler beim Prüfer-Update:', error);
            select.classList.add('is-invalid');
            alert('Der Prüfer konnte nicht aktualisiert werden.');
        } finally {
            select.disabled = false;
            select.classList.remove('opacity-50');
        }
    }

    /**
     * LÖSCHEN (Mit Sicherheitsabfrage)
     */
    confirmDelete(event) {
        event.preventDefault();
        const link = event.currentTarget;
        
        // Findet den Container, in dem das versteckte Formular liegt
        const container = link.closest('.delete-action-container');
        const form = container ? container.querySelector('form') : null;

        if (!form) {
            console.error("Lösch-Formular wurde nicht gefunden!");
            return;
        }

        const message = link.dataset.confirmMessage || 'Möchtest du diese Prüfung wirklich löschen?';

        // Sicherheitsabfrage (Browser-Native)
        if (window.confirm(message)) {
            form.submit();
        }
    }
}