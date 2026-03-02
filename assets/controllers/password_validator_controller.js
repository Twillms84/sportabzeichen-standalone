import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["first", "second", "requirement"]

    connect() {
        console.log("✅ Password-Validator: Controller verbunden!");
        console.log("🔍 Gefundene Targets:", {
            firstInput: this.hasFirstTarget,
            secondInput: this.hasSecondTarget,
            requirementsCount: this.requirementTargets.length
        });

        // Falls Targets fehlen, warnen
        if (!this.hasFirstTarget || !this.hasSecondTarget) {
            console.error("❌ Password-Validator: Eingabefelder wurden nicht gefunden! Prüfe 'data-password-validator-target'.");
        }

        this.validate();
    }

    validate() {
        console.log("⌨️ Validierung läuft...");

        // Werte sicher abgreifen
        const val1 = this.hasFirstTarget ? this.firstTarget.value : '';
        const val2 = this.hasSecondTarget ? this.secondTarget.value : '';

        const rules = {
            length: /.{8,}/,
            upper: /(?=.*[a-z])(?=.*[A-Z])/,
            number: /[0-9]/,
            special: /[\W_]/
        };

        this.requirementTargets.forEach((el, index) => {
            const type = el.dataset.pwRequirement;
            let isMet = false;

            if (type === 'match') {
                isMet = (val1 !== '' && val1 === val2);
            } else if (rules[type]) {
                isMet = rules[type].test(val1);
            }

            console.log(`📋 Regel [${type}]: ${isMet ? '✅ erfüllt' : '❌ offen'}`);
            this._updateStatus(el, isMet);
        });
    }

    _updateStatus(element, isMet) {
        const icon = element.querySelector('i');
        
        if (isMet) {
            element.classList.add('req-met');
            if (icon) {
                icon.classList.replace('fa-circle', 'fa-check-circle');
                icon.classList.replace('fa-times-circle', 'fa-check-circle');
            }
        } else {
            element.classList.remove('req-met');
            if (icon) {
                icon.classList.replace('fa-check-circle', 'fa-circle');
            }
        }
    }
}