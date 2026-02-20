import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    // Die connect-Funktion wird automatisch aufgerufen, wenn Stimulus das Element im DOM findet
    connect() {
        console.log('✅ Exam Overview Controller verbunden');
        
        // Initialisiere die Original-Werte für alle Selects
        this.element.querySelectorAll('.js-change-examiner').forEach(select => {
            select.dataset.original = select.value;
        });
    }

    /**
     * Diese Methode wird durch data-action="change->exam-overview#changeExaminer" getriggert
     */
    async changeExaminer(event) {
        const select = event.currentTarget;
        const examId = select.dataset.examId;
        const newExaminerId = select.value;
        const originalValue = select.dataset.original;

        // UI Feedback: Loading Status
        select.disabled = true;
        select.classList.add('opacity-50');

        try {
            const response = await fetch(`/admin/api/exam/${examId}/set-examiner`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ examiner_id: newExaminerId })
            });

            if (!response.ok) {
                throw new Error('Netzwerk Fehler');
            }

            const data = await response.json();

            // Erfolg: Grün aufleuchten
            select.classList.remove('is-invalid');
            select.classList.add('is-valid');
            
            // Originalwert aktualisieren
            select.dataset.original = newExaminerId;

            // Nach 2 Sekunden Feedback entfernen
            setTimeout(() => {
                select.classList.remove('is-valid');
            }, 2000);

        } catch (error) {
            console.error('Error:', error);
            
            // Fehler: Rot markieren und zurücksetzen
            select.classList.add('is-invalid');
            alert('Der Prüfer konnte nicht gespeichert werden.');
            
            // Optional: Auf alten Wert zurücksetzen
            // select.value = originalValue;
        } finally {
            // UI Status wiederherstellen
            select.disabled = false;
            select.classList.remove('opacity-50');
        }
    }
}