<?php

namespace FluentForms;

use Closure;
use FluentForms\Contracts\Mailer;
use FluentForms\Enums\HttpMethod;
use FluentForms\Exceptions\FormException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FormBuilder
{
	private const MESSAGES = [
		'honeypot' => 'Something went wrong',
		'delayed'  => 'Please wait before trying again.',
		'success'  => 'Thank you for your message',
		'rejected' => 'Sorry, the submission was rejected.',
	];
	private const int SUBMIT_DELAY = 3;
	private const string HONEYPOT_NAME = 'my_name';
	private const string HONEYPOT_REQUEST = 'request';
	private const string JS_PATH = '/formAjax.js';

	private array $inputList = [];
	private array $formErrors = [];
	private array $formData = [];
	private bool $withAjax = true;
	private bool $successful = false;
	private bool $rejected = false;
	private array $messages = [];
	private ?Closure $handler = null;

	function __construct(
		private string $method = HttpMethod::GET,
		private string $action = '',
		private string $id = '',
		private string $name = '',
		private bool $honey = true,
	){
		$this->setAction($action);
		$this->setFormId();
		$this->setFormName();

		!$this->honey ?: $this->honeypot(); // Opt-out if not needed.
	}

	# HANDLE RESPONSE

	public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		# EXECUTE HANDLER
		if ($this->wasSubmitted($request)) {
			$this->fill($request->getParsedBody());
			if ($this->validates()) {
				try {
					$result = ($this->handler)($this->formData, $this);

					match (true) {
						$result === false => $this->reject(),
						$result === true => $this->success(),
						default => null, # handler retured void - e.g. AuthController redirects itself
					};
				} catch(\Throwable $e) {
					$this->abort($e->getMessage());
				}
			}
		}

		# AJAX 
		if (isset($this->formData['ajax'])) {
			if ($this->wasSuccessful()) {
				$response->getBody()->write(json_encode([
					'successful' => $this->getMessage('success')
				]));

				return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
			}

			$response->getBody()->write(json_encode([
				'errors' => $this->getErrors()
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
		}

		# PHP
		$this->flash('errors', $this->getErrors());
		$this->flash('_old', $this->formData);

		return $response->withHeader('Location', $this->action)->withStatus(302);
	}

	# BUILDER METHODS

	public function handler(Closure $handler): self
	{
		$this->handler = $handler;
		return $this;
	}

	public function mailer(Mailer $mailer): self
	{
		return $this->handler(fn(array $data) => $mailer->send($data));
	}

	public function get(?string $action = ''): self
	{
		$this->method = HttpMethod::GET;
		$this->setAction($action);
		return $this;
	}

	public function post(?string $action = ''): self
	{
		$this->method = HttpMethod::POST;
		$this->setAction($action);
		return $this;
	}

	public function action(?string $action = ''): self
	{
		$this->setAction($action);
		return $this;
	}

	/**
	 * Sets the name attribute on the form element, or defaults to a hash of the action string.
	 */
	public function setFormName(string $name = ''): self
	{
		$this->name = $name ?: 'form-'. $this->hashActionString();

		return $this;
	}

	public function getFormName(): string
	{
		return $this->name;
	}

	/**
	 * Sets the id attribute on the form element, or defaults to a hash of the action string.
	 */
	public function setFormId(string $id = ''): self
	{
		$this->id = $id ?: 'id-'. $this->hashActionString();

		return $this;
	}

	public function withoutHoneypot(): self
	{
		$this->honey = false;
		return $this;
	}

	public function withoutAjax(): self
	{
		$this->withAjax = false;
		return $this;
	}

	public function withError(?string $message = null): self
	{
		if (is_string($message)) {
			$this->addError('form', $message);
		}
		$this->flash('errors', ['form' => $message]);
		return $this;
	}

	public function addError(string $key, string $message ):void
	{
		$this->formErrors[$key] = $message;
	}
	
	public function make(...$attrs): self
	{
		$input = new FormInput( ...$attrs );
		$this->addFormInput($input);
		return $this;
	}

	public function build(): string
	{
		if (is_null($this->handler)) {
			$this->abort('WARNING: No handler has been configued for this form.');
		}

		if ($this->hasFlash('errors')) {
			foreach ($this->getFlash('errors') as $key => $message) {
				$this->addError($key, $message);
			}
			$this->forget('errors');
		}

		switch(true) {
			case $this->wasAborted():
				$this->abort();
				$this->forget('aborted');
					break;
			case $this->wasSuccessful(): 
				$this->success();
				$this->forget('successful'); # PENDING::: DON'T "FORGET" THIS FLAG IN ORDER TO PREVENT USERS FROM SENDING MORE 
					break;
			case $this->wasRejected():
				$this->reject();
				$this->forget('rejected');
					break;
			default:
				if ($this->hasFlash('_old')) {
					$this->fill( $this->getFlash('_old') );
					$this->forget('_old');
				}

				$this->restoreFieldErrors();
		};

		$output  = "";
		$output .= $this->getFormStart();
		
		foreach ($this->inputList as $input) {
			$output .= $input;
		}

		$output .= '<div data-input-error="form" class="text-warning" style="color:salmon;display:block;">';
		if ( $this->hasErrors() && $this->getErrorMessage()) {
			$output .= $this->getErrorMessage();
		} 
		$output .= '</div>';
		
		if ( $this->wasSuccessful() ) {
			$output .= '<div data-form-success class="text-success">'.$this->getMessage('success').'</div>';
		}

		$output .= $this->getFormEnd();
		return $output;
	}

	# FORM ELEMENTS

	public function input(...$attrs):self 	 {return $this->make(...$attrs);}
	public function text(...$attrs):self 	 {return $this->make(...$attrs, type:'text');}
	public function button(...$attrs):self 	 {return $this->make(...$attrs, type:'button');}
	public function reset(...$attrs):self 	 {return $this->make(...$attrs, type:'reset');}
	public function submit(...$attrs):self 	 {return $this->make(...$attrs, type:'submit');}
	public function textarea(...$attrs):self {return $this->make(...$attrs, type:'textarea');}

	# OPINIONATED ELEMENT STARTERS
	public function name(...$attrs):self 	 {return $this->make(...$attrs, type:'text', label:'Name', autocomplete:'name', );}
	public function email(...$attrs):self 	 {return $this->make(...$attrs, type:'email', label:'Email', autocomplete:'email',);}
	public function message(...$attrs):self  {return $this->make(...$attrs, type:'textarea', label:'Message',); }
	public function username(...$attrs):self {return $this->make(...$attrs, type:'text', label:'Username',); }
	public function password(...$attrs):self {return $this->make(...$attrs, type:'password', label:'Password',);}
	
	# SPAM PROTECTION
	private function honeypot(...$attrs):self { 
		return $this
			->make(...$attrs, type:'hidden', name:self::HONEYPOT_NAME )
			->make(...$attrs, type:'hidden', name:self::HONEYPOT_REQUEST, value:time() );
	}

	# RECONSTRUCT WITH OLD DATA

	public function fill(array $payload):self
	{
		# USER INPUT FROM THE REQUEST MAY CONTAIN MANIPULATED FIELDS
		$this->formData = $payload; 
		foreach ($this->inputList as $input) {
			$newValue = $payload[$input->getName()] ?? null;
			if (!is_null($newValue)) $input->setValue($newValue);
		}
		return $this;
	}

	# VALIDATE
	
	public function isValid():bool
	{
		return !$this->hasErrors() && !$this->wasRejected();
	}

	public function validates(): bool
	{
		if ($this->wasRejected()) {
			return false;
		}

		return $this->validateInputList()
			&& $this->validateHoneypot();
	}

	# VALIDATE INPUTLIST

	/**
	 * Triggers validation on each input
	 */
	public function validateInputList(): bool
	{
		foreach ($this->inputList as $input) {
			$input->validate();
			if ($input->hasError()) {
				$this->formErrors[$input->getName()] = $input->getError();
			}
		}
		
		return $this->hasErrors() ? false : true;
	}

	/**
	 * Writes errors from $formErrors back onto inputs
	 */
	private function restoreFieldErrors(): void
	{
		if ($this->hasErrors()) {
			foreach($this->inputList as $input) {
				if (isset($this->formErrors[$input->getName()])) {
					$input->withError($this->formErrors[$input->getName()]);
				}
			}
		}
	}
	
	# CHECK FOR ANY ERRORS // AFTER VALIDATION

	public function hasErrors():bool
	{
		return !empty($this->formErrors);
	}

	public function hasError(string $key)
	{
		return $this->hasErrors() && array_key_exists($key, $this->getErrors());
	}

	public function getErrors()
	{
		return $this->formErrors;	
	}

	# STATUS

	/**
	 * @todo check that this is working when GET instead of POST
	 */
	private function wasSubmitted(RequestInterface $request)
	{
		return strtoupper($request->getMethod()) === strtoupper($this->method);
	}

	private function wasSuccessful(): bool 
	{
		if ($this->hasFlash('successful')) {
			$this->success($this->getFlash('successful'));
		}
		return $this->successful; 
	}

	private function wasRejected(): bool
	{ 
		if ($this->hasFlash('rejected')) {
			$this->reject($this->getFlash('rejected'));
		}
		return $this->rejected; 
	}

	private function wasAborted(): bool
	{
		if ($this->hasFlash('aborted')) {
			$this->abort($this->getFlash('aborted'));
			return true;
		}
		return false;
	}

	# ACTIONS

	public function success(string $message = ''): self
	{
		$this->successful = true;
		$this->forget('errors');
		$this->forget('_old');
		$this->setMessage('success', $message);
		$this->flash('successful', $this->getMessage('success'));
		$this->disableAllInputs();
		$this->removeAllInputs();
		return $this;
	}

	public function reject(string $message = '')
	{
		$this->rejected = true;
		$this->setMessage('rejected', $message);
		$this->flash('rejected', $this->getMessage('rejected'));
		$this->addError('form', $this->getMessage('rejected'));
		$this->forget('_old');
		return $this;
	}

	public function abort(string $message = '')
	{
		$this->action = '';
		$this->method = '';
		$this->setMessage('aborted', $message);
		$this->flash('aborted', $this->getMessage('aborted'));
		$this->addError('form', $this->getMessage('aborted'));
		$this->forget('_old');
		$this->disableAllInputs();
		// $this->removeAllInputs();
		return $this;
	}

	# SESSION

	private function getFlash(string $key)
	{
		return $_SESSION['FluentForms'][$this->name][$key];
	}

	private function flash(string $key, array|string $value): void
	{
		$_SESSION['FluentForms'][$this->name][$key] = $value;
	}

	private function hasFlash(string $key):bool
	{
		return isset($_SESSION['FluentForms'][$this->name][$key]);
	}

	private function forget(string $key):void
	{
		unset($_SESSION['FluentForms'][$this->name][$key]);
	}

	# MESSAGES

	public function withMessages(array $messages): self
	{
		$this->messages = array_merge($this->messages, $message);

		return $this;
	}

	private function setMessage(string $key, string $message): void
	{
		if (empty($message)) {
			$message = $this->getMessage($key);
		}

		$this->messages[$key] = $message;
	}

	private function getMessage(string $key): string
	{
		return $this->messages[$key] ?? self::MESSAGES[$key] ?? '';
	}

	/**
	 * @todo Follow up on getErrorMessage if the new stuff made it redundant or not.
	 */
	public function getErrorMessage():?string
	{
		if ($this->hasError('form')) {
			return $this->getErrors()['form'];
		}

		return null;
	}

	# SPAM VALIDATION
	public function validateHoneypot(): bool
	{
		# USER SUBMITTED request data is stored in property ParsedBody.
		try {
			match(true) {
				is_null($this->formData)
					=> throw new FormException('Data to validate might be missing.'),

				!array_key_exists(self::HONEYPOT_NAME, $this->formData ) || 
				!array_key_exists(self::HONEYPOT_REQUEST, $this->formData )
					=> throw new FormException('Data to validate might be modified.'),

				is_null($this->formData[self::HONEYPOT_NAME]) || 
				!empty ($this->formData[self::HONEYPOT_NAME]) 
					=> throw new FormException($this->getMessage('honeypot')),
				
				($_SERVER['REQUEST_TIME'] - $this->formData[self::HONEYPOT_REQUEST]) <= self::SUBMIT_DELAY 
					=> throw new FormException($this->getMessage('delayed')),

				default => null, 
			};
			return true;
		}
		catch(FormException $e) {
			$this->withError($e->getMessage());
			return false;
		}
	}
	

	private function disableAllInputs():void
	{
		foreach ($this->inputList as $key => $input) {
			# Unset Submit Button
			if ($input->isSubmit()) {
				unset($this->inputList[$key]); 
			}
			$input->disable();
		}
	}

	private function removeAllInputs():void
	{
		$this->inputList = [];
	}

	# PRIVATE METHODS

	private function addFormInput(FormInput $forminput): void
	{
		$this->inputList[] = $forminput;
	}

	private function setAction(?string $action = ''):void
	{
		$this->action = match(true){
			is_null($action)   => null,
			empty($action) 	   => htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')),
			is_string($action) => htmlspecialchars($action),
		};
	}

	private function hashActionString()
	{
		return substr(md5($this->action ?? uniqid()), 0, 8);
	}

	private function getFormStart(): string
	{
		$form = array_filter([
			'id' => htmlspecialchars($this->id),
			'name' => $this->name,
			'method' => htmlspecialchars($this->method),
			'action' => htmlspecialchars($this->action),
		]);
		
		$attrs = join(' ', array_map(function($key) use ($form) {
			return $key.'="'.$form[$key].'"';
		}, array_keys($form)));

		return '<form '.$attrs.' data-form>';
	}

	private function getFormEnd(): string
	{
		$output = '';
		if ( $this->withAjax ) {
			$output .= self::JS_PATH ? '<script type="text/javascript">'.file_get_contents(__DIR__.self::JS_PATH).'</script>' : '';
		}
		return $output .= '</form>';
	}
}