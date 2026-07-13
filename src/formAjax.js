const form = document.querySelector('[data-form]');

const submitter = form.querySelector('button[type=submit]');

submitter.addEventListener('click', function(e) {
    e.preventDefault();
    ajaxify();
})

async function ajaxify() {
    
    const formData = new FormData(form, submitter);
    formData.append('ajax', 'ajax');

    const params = new URLSearchParams();
    for (const [key, value] of formData.entries()) {
        params.append(key, value);
    }

    const inputErrors = document.querySelectorAll('[data-input-error]');
    const formError = document.querySelector('[data-input-error="form"]');
    inputErrors.forEach((field) => field.textContent = '');

    const response = await fetch(form.getAttribute('action') ?? window.location, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params,
    })
    .then(async response => {
        if (response.status !== 200 && response.status !== 400) {
            throw new Error(`Unexpected server response (status ${response.status})`);
        }
        let json;
        try {
            json = await response.json();
        } catch (e) {
            throw new Error('Unexpected response from server. Please try again later.');
        }
        return json;
    })
    .then((json) => {
        if (json.errors !== undefined ) {
            Object.entries(json.errors).forEach(([property, message]) => {
                const field = document.querySelector(`[data-input-error="${property}"]`);
                if (field) field.textContent = message;
            });
        }
        return json;
    })
    .then((json) => {
        if (json.successful !== undefined ) {
            if (json.redirect !== undefined) {
                window.location.href = json.redirect;
            } else {
                // disableFormFields(form);
                const successdiv = document.createElement("div");
                successdiv.classList.add('text-success');
                successdiv.textContent = json.successful;
                form.after( successdiv );
                form.style.opacity = 0.5;
                // submitter.remove();
            }
        }
        return json;

    })
    .catch((error) => {
        // console.log(error);
        if (formError) {
            formError.textContent = error.message ?? 'Something went wrong. Please try again later.';
        }
    });

    // console.log(response);
}

function disableFormFields(form) {
    form.querySelectorAll('input, textarea, button').forEach((el) => {
        el.setAttribute('disabled', '');
        el.value = '';
        el.style.opacity = 0.5;
        if (el.nextElementSibling) {
            el.nextElementSibling.innerHTML = '';
        }
    });
}