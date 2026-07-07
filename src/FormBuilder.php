<?php

namespace FluentForms;

use Closure;
use FluentForms\Enums\HttpMethod;
use FluentForms\Exceptions\FormException;
use Psr\Http\Message\ResponseInterface;

class FormBuilder
{
	private const MESSAGES = [
		'honeypot' => 'Something went wrong',
		'delayed' => 'Please wait before trying again.',
		'success' => 'Thank you for your message',
		'rejected' => 'Sorry, the submission was rejected.'
	];
	private const int SUBMIT_DELAY = 3;
	private const string HONEYPOT_NAME = 'my_name';
	private const string HONEYPOT_REQUEST = 'request';
	private const string JS_PATH = '/formAjax.js';

	private array $inputList = [];
	private array $formErrors = [];
	private array $data = [];
	private bool $withAjax = true;
	private bool $successful = false;
	private bool $rejected = false;
	private array $messages = [];

	function __construct(
		private string $method = HttpMethod::GET,
		private ?string $action = '',
		private bool $honey = true,
	){
		$this->setAction($action);
		!$this->honey ?: $this->honeypot(); // Opt-out if not needed.

		if ( $this->hasStatusSuccessful() || isset($_GET['success']) ) {
			$this->setStatusSuccessful();
		}
		if ( $this->hasStatusRejected() || isset($_GET['rejected'])) {
			$this->setStatusRejected();
		}
	}

	public function handle(ResponseInterface $response, ?callable $renderer = null): ResponseInterface
	{
		if (isset($this->data['ajax'])) {
			if ($this->hasStatusSuccessful()) {
				$response->getBody()->write(json_encode([
					'successful' => $this->getMessage('success')
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
			}

			$response->getBody()->write(json_encode($this->getErrors()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
		}

		if ($this->hasStatusSuccessful()) {
			return $response->withHeader('Location', $this->action.'?success')->withStatus(302);
		}

		if ($renderer) {
			$response->getBody()->write($renderer());
			return $response;
		}

		return $response->withHeader('Location', $this->action.'?invalid')->withStatus(302);

	}

	# BUILDER METHODS
	public function withoutAjax(): self
	{
		$this->withAjax = false;
		return $this;
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

	public function withoutHoneypot(): self
	{
		$this->honey = false;
		return $this;
	}

	public function withError(?string $message = null): self
	{
		if (is_string($message)) {
			$this->addError('form', $message);
		}
		$_SESSION['FluentForms']['errors']['form'] = $message;
		return $this;
	}
	
	public function make(...$attrs): self
	{
		$input = new FormInput( ...$attrs );
		$this->addFormInput($input);
		return $this;
	}

	public function build(): string
	{
		if ( $this->hasStatusSuccessful() || $this->hasStatusRejected() ) {
			$this->disableAllInputs();
		}

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
		
		if ( $this->hasStatusSuccessful() ) {
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
		$this->data = $payload; 

		foreach ($this->inputList as $input) {
			$newValue = $payload[$input->getName()] ?? null;
			if (!is_null($newValue)) $input->setValue($newValue);
		}

		return $this;
	}

	# VALIDATE
	public function validates()
	{
		$this->validateHoneypot();
		if ( !$this->isValid() ) return $this;
		$this->validateInputList();
		$this->collectFieldErrors();
		return $this;
	}

	private function collectFieldErrors(): void
	{
		foreach ($this->inputList as $input) {
			if ($input->hasError()) {
				$this->formErrors[$input->getName()] = $input->getError();
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

	# ACTIONS

	public function success(string $message = ''): self
	{
		if (!empty($message)) $this->messages['success'] = $message;
		$this->setStatusSuccessful();
		return $this;
	}

	public function reject(string $message = '')
	{
		$message = !empty($message) ? $message : $this->getMessage('rejected');
		$this->addError('form', $this->getMessage('rejected'));
		$this->setStatusRejected();
		return $this;
	}

	# MESSAGES

	public function withMessages(array $messages): self
	{
		$this->messages = array_merge($this->messages, $message);
		return $this;
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
	public function validateHoneypot()
	{
		# USER SUBMITTED request data is stored in property ParsedBody.
		try {
			match(true) {
				is_null($this->data)
					=> throw new FormException('Data to validate might be missing.'),

				!array_key_exists(self::HONEYPOT_NAME, $this->data ) || 
				!array_key_exists(self::HONEYPOT_REQUEST, $this->data )
					=> throw new FormException('Data to validate might be modified.'),

				is_null($this->data[self::HONEYPOT_NAME]) || 
				!empty ($this->data[self::HONEYPOT_NAME]) 
					=> throw new FormException($this->getMessage('honeypot')),
				
				($_SERVER['REQUEST_TIME'] - $this->data[self::HONEYPOT_REQUEST]) <= self::SUBMIT_DELAY 
					=> throw new FormException($this->getMessage('delayed')),

				default => null, 
			};
		}
		catch(FormException $e) {
			$this->withError($e->getMessage());
		}
	}

	# VALIDATE INPUTLIST
	public function validateInputList()
	{
		foreach ($this->inputList as $input) {
			$input->validate();
		}
	}

	

	# STATUS

	public function hasStatusSuccessful():bool { return $this->successful; }
	public function hasStatusRejected():bool { return $this->rejected; }

	private function setStatusSuccessful(): void
	{
		$this->successful = true;
		$this->removeAllInputs();
	}

	private function setStatusRejected(): void
	{
		$this->rejected = true;
	}

	public function disableAllInputs():void
	{
		foreach ($this->inputList as $key => $input) {
			# Unset Submit Button
			if ($input->isSubmit()) {
				unset($this->inputList[$key]); 
			}
			$input->disable();
		}
	}

	public function removeAllInputs():void
	{
		$this->inputList = [];
	}

	public function addError(string $key, string $message ):void
	{
		$this->formErrors[$key] = $message;
	}

	public function isValid():bool
	{
		return !$this->hasErrors() && !$this->hasStatusRejected();
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
			empty($action) 	   => htmlspecialchars($_SERVER['REQUEST_URI']),
			is_string($action) => htmlspecialchars($action),
		};
	}

	private function getFormStart(): string
	{
		$form = array_filter([
			'method' => $this->method,
			'action' => $this->action,
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