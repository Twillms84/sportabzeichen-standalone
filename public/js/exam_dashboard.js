// public/js/exam_dashboard.js

document.addEventListener('DOMContentLoaded', function() {
    console.log('Sportabzeichen Dashboard JS geladen.');

    // Alle Löschen-Buttons finden
    const deleteButtons = document.querySelectorAll('.btn-delete-exam');
    console.log('Gefundene Löschen-Buttons:', deleteButtons.length);

    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault(); // Standard-Klick verhindern

            const examName = this.getAttribute('data-name');
            const formId = this.getAttribute('data-form-id');

            console.log('Löschen geklickt für:', examName);

            // Bestätigungsdialog (ganz simpel)
            if (confirm('ACHTUNG: Möchten Sie die Prüfung "' + examName + '" wirklich löschen?\nAlle Ergebnisse dieser Prüfung werden unwiderruflich entfernt!')) {
                // Formular absenden
                const form = document.getElementById(formId);
                if (form) {
                    form.submit();
                } else {
                    console.error('Formular nicht gefunden mit ID:', formId);
                }
            }
        });
    });
});