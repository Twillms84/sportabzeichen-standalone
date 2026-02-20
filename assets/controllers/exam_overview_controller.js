/**
 * Admin Exam Overview Scripts
 * Pfad: public/js/admin/exam-overview.js
 */

document.addEventListener('DOMContentLoaded', function() {
    initExaminerSelects();
});

function initExaminerSelects() {
    const selects = document.querySelectorAll('.js-change-examiner');

    selects.forEach(select => {
        select.addEventListener('change', function() {
            const examId = this.dataset.examId;
            const newExaminerId = this.value;
            const originalValue = this.dataset.original; // Falls wir zurücksetzen müssen

            // UI Feedback: Loading Status
            this.disabled = true;
            this.classList.add('opacity-50');

            fetch(`/admin/api/exam/${examId}/set-examiner`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // Falls du CSRF Protection nutzt (empfohlen):
                    // 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ examiner_id: newExaminerId })
            })
            .then(response => {
                if (response.ok) {
                    return response.json();
                }
                throw new Error('Netzwerk Fehler');
            })
            .then(data => {
                // Erfolg: Grün aufleuchten
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
                
                // Originalwert aktualisieren
                this.dataset.original = newExaminerId;

                // Nach 2 Sekunden Feedback entfernen
                setTimeout(() => {
                    this.classList.remove('is-valid');
                }, 2000);
            })
            .catch(error => {
                console.error('Error:', error);
                // Fehler: Rot markieren und zurücksetzen
                this.classList.add('is-invalid');
                alert('Der Prüfer konnte nicht gespeichert werden.');
                // Zurücksetzen (optional)
                // this.value = originalValue; 
            })
            .finally(() => {
                this.disabled = false;
                this.classList.remove('opacity-50');
            });
        });
        
        // Speichere den Initialwert für Reset-Zwecke
        select.dataset.original = select.value;
    });
}