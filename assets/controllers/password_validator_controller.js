import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["first", "second", "requirement"]

    connect() {
        this.validate();
    }

    validate() {
        const val1 = this.hasFirstTarget ? this.firstTarget.value : '';
        const val2 = this.hasSecondTarget ? this.secondTarget.value : '';

        const rules = {
            length: /.{8,}/,
            upper: /(?=.*[a-z])(?=.*[A-Z])/,
            number: /[0-9]/,
            special: /[\W_]/
        };

        this.requirementTargets.forEach((el) => {
            const type = el.dataset.pwRequirement;
            let isMet = false;

            if (type === 'match') {
                isMet = (val1 !== '' && val1 === val2);
            } else if (rules[type]) {
                isMet = rules[type].test(val1);
            }

            this._updateStatus(el, isMet);
        });
    }

    _updateStatus(element, isMet) {
        const icon = element.querySelector('i');
        
        if (isMet) {
            element.classList.add('req-met');
            if (icon) {
                // Wir tauschen den leeren Kreis gegen den Check-Circle
                icon.classList.replace('fa-circle', 'fa-check-circle');
            }
        } else {
            element.classList.remove('req-met');
            if (icon) {
                // Zurück zum leeren Kreis
                icon.classList.replace('fa-check-circle', 'fa-circle');
            }
        }
    }
}