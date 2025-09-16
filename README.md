# Minimal Fluent Form Builder for PHP

Originally built from a Slim Framework project utilizing PSR ResponseInterface. This build is an Alpha prototype and far from complete but covers the basics of a simple contact form and optionally via Ajax. 

While the goal of this project is to remain as minimal as possible, additional updates may be added when necessary.


## Example

FormBuilder is the main class but can be called via a Form facade for better readability.

```php
$form = Form::post()
    ->name(required: 1, minLength:5)
    ->email(required: 1)
    ->textarea(minLength:10, maxLength:140, rows:4)
    ->build();
```

### Methods

Builder Methods are derrived from HTML elements and attributes. 
- `->method('post')`
- `->action('/path-to-your-action')

### Input Elements

Supported types: button, input, textarea

Methods for basic Form Elements are also included. Named arguments can be passed to the elements as html attributes:
- `->button()`{:.php}
- `->input(required: 1, maxLength: 255)`{:.php}
- `->textarea(rows: 4, placeholder: 'Your Message')`{:.php}

However, some additional methods are included preconfigured for common contact form inputs:
- `->get()` => `->method('get')`
- `->name()` => `->input(type:'text', required: 1)`
- `->email()` => `->input(type:'email', label:'Email', autocomplete:'email',)`
- `->message()` => `->make(type:'textarea', label:'Message',)'
- `->submit()` => `->button(type:'submit')`

#### Labels, Names, ID's

Form input elements require a name. The other attributes will try to automatically resolve from the name but you may override with your own named arguments for `label`, `id`, etc. 

## Sending Emails

Currently only MailerSend is included.

```php
if (!$form->wasRejected() && $_SERVER["REQUEST_METHOD"] == "POST") {
    $form->reconstructWithParsedBody( $request->getParsedBody() )->validates();
    return $response = $form->send($response);
}
```

### Recipients

The MailerSend Mailer is currently formatted to send emails from the application to the administrator with ReplyTo set to the sender's email included in the form. These are set in the .env file. Extending or creating your own Mailer class may be required to configure in your own way.

## Honeypot

Some additional spam protectiion features have been included with reference to Spatie/Honeypot such as:

- `SubmitDelay` buffer time beteen form submission and the inital server request time. 
- Hidden honeypot input field `my_name` which must rename blank.

The honeypot can be excluded on the FormBuilder `$form->withoutHoneypot()`.