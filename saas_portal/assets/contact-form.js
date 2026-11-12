(() => {
    'use strict';

    const form = document.getElementById('publicContactForm');
    if (!form) return;

    const status = document.getElementById('contactFormStatus');
    const submit = form.querySelector('[type="submit"]');
    const inputs = [...form.querySelectorAll('input[name]')].filter(input => input.name !== 'website_check');
    const errors = new Map();

    inputs.forEach((input) => {
        const error = document.createElement('small');
        error.className = 'contact-field-error';
        error.id = `${input.id}Error`;
        error.hidden = true;
        input.closest('.contact-field').append(error);
        input.setAttribute('aria-describedby', error.id);
        errors.set(input.name, { input, error });
        input.addEventListener('input', () => {
            input.removeAttribute('aria-invalid');
            error.textContent = '';
            error.hidden = true;
        });
    });

    function showStatus(message, kind = '') {
        status.textContent = message || '';
        status.className = `contact-form-status${kind ? ` is-${kind}` : ''}`;
    }

    function showErrors(fieldErrors) {
        let firstInvalid = null;
        Object.entries(fieldErrors || {}).forEach(([name, message]) => {
            const field = errors.get(name);
            if (!field) return;
            field.input.setAttribute('aria-invalid', 'true');
            field.error.textContent = String(message);
            field.error.hidden = false;
            firstInvalid ||= field.input;
        });
        firstInvalid?.focus();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        submit.disabled = true;
        showStatus('Sender forespørselen …');
        const payload = Object.fromEntries(new FormData(form).entries());

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload)
            });
            const body = await response.json();
            if (!response.ok || body.success !== true) {
                showErrors(body.errors);
                throw new Error(body.message || 'Forespørselen kunne ikke sendes. Prøv igjen.');
            }
            form.reset();
            inputs.forEach(input => {
                input.removeAttribute('aria-invalid');
                const error = errors.get(input.name)?.error;
                if (error) {
                    error.textContent = '';
                    error.hidden = true;
                }
            });
            showStatus(body.message || 'Takk for henvendelsen. Vi tar kontakt så snart vi kan.', 'success');
        } catch (error) {
            showStatus(error.message || 'Forespørselen kunne ikke sendes. Prøv igjen.', 'error');
        } finally {
            submit.disabled = false;
        }
    });
})();
