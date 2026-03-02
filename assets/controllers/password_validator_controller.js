import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["first", "second", "requirement"]

    connect() {
        this.validate();
    }

    validate() {
        const val1 = this.firstTarget.value;
        const val2 = this.secondTarget.value;

        // Anforderungen definieren (muss synchron zu deinem PHP FormType sein)
        const rules = {
            length: /.{8,}/,
            upper: /(?=.*[a-z])(?=.*[A-Z])/,
            number: /[0-9]/,
            special: /[\W_]/
        };

        // RegEx Regeln prüfen
        this.requirementTargets.forEach(el => {
            const type = el.dataset.pwRequirement;
            
            if (type === 'match') {
                this._updateStatus(el, val1 !== '' && val1 === val2);
            } else if (rules[type]) {
                this._updateStatus(el, rules[type].test(val1));
            }
        });
    }

    _updateStatus(element, isMet) {
        const icon = element.querySelector('i');
        if (isMet) {
            element.classList.add('req-met');
            if (icon) icon.classList.replace('fa-circle', 'fa-check-circle');
        } else {
            element.classList.remove('req-met');
            if (icon) icon.classList.replace('fa-check-circle', 'fa-circle');
        }
    }
}