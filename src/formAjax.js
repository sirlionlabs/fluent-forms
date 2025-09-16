const form = document.querySelector('[data-form]');
const submitter = form.querySelector('button[type=submit]');
submitter.addEventListener('click', function(e) {
    e.preventDefault();
    ajaxify();
})

    async function ajaxify() {
        
        const formData = new FormData(form, submitter);
        formData.append('ajax', 'ajax');
        const inputErrors = document.querySelectorAll('[data-input-error]');
        
        const response = await fetch(form.getAttribute('action') ?? window.location, {
            method: 'POST',
            body: formData,
        })
        .then(async response => {
            return response.json()
                .then((json) => {
                    if (response.status === 200 ) { 
                        return { "data": json };
                    }
                    if (response.status === 400 ) { 
                        return { "errors": json };
                    }
                    return json;
                })
        })
        .then((json) => {
            if (json.errors !== undefined ) {
                inputErrors.forEach((field) => field.innerHTML = '' );
                if (json.errors.mailer !== undefined ) {
                    form.querySelectorAll(['input, textarea, button']).forEach((el) => {
                        el.setAttribute('disabled', '');
                        el.value = '';
                        el.style.opacity = 0.5,
                        el.nextSibling.innerHTML = '';
                    });
                    document.querySelector('[data-input-error="form"]').innerHTML = json.errors.mailer;
                    submitter.remove();
                } else {
                    for( property in json.errors ) {
                        document.querySelector("[data-input-error="+property+"]" ).innerHTML = json.errors[property];
                    }
                }
            }
            return json;
        })
        .then((json) => {
            
            if (json.data !== undefined ) {
                form.querySelectorAll(['input, textarea, button']).forEach((el) => {
                    el.setAttribute('disabled', '');
                    el.value = '';
                    el.nextSibling.innerHTML = '';
                });
                const successdiv = document.createElement("div");
                successdiv.classList.add('text-success');
                successdiv.innerHTML = json.data.successful;
                form.after( successdiv );
                form.style.opacity = 0.5;
                submitter.remove();
            }
            return json;

        })
        .catch((error) => {
            console.log(error);
            document.querySelector('[data-input-error="form"]').innerHTML = error
        });

        console.log(response);
    }