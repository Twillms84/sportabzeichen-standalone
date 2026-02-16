// assets/js/exam_dashboard.js

document.addEventListener('DOMContentLoaded', function() {
    
    const deleteButtons = document.querySelectorAll('.btn-delete-exam');

    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            // 1. Verhindern, dass Links gefolgt werden
            event.preventDefault();
            
            // 2. Verhindern, dass der Klick an Elternelemente (z.B. die Card) weitergegeben wird
            event.stopPropagation();

            const examName = button.dataset.name;
            const formId = button.dataset.formId;

            // Sicherheitsabfrage
            const message = 
                `ACHTUNG: Möchten Sie die Prüfung "${examName}" wirklich löschen?\n\n` +
                `Das löscht auch ALLE bisher eingegebenen Ergebnisse und Teilnehmerdaten dieser Prüfung.\n` +
                `Dieser Schritt kann nicht rückgängig gemacht werden!`;

            if (confirm(message)) {
                const form = document.getElementById(formId);
                if (form) {
                    form.submit();
                } else {
                    console.error('Lösch-Formular nicht gefunden: ' + formId);
                    alert('Fehler: Das Lösch-Formular wurde nicht gefunden.');
                }
            }
        });
    });
});