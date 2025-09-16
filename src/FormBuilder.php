<?php

namespace FluentForms;

use FluentForms\Contracts\Deliverable;
use FluentForms\Enums\FormStatus;
use FluentForms\Enums\HttpMethod;
use FluentForms\Exceptions\FormException;
use FluentForms\Mailers\MailerSend;

class FormBuilder implements Deliverable
{
	private const MESSAGES = [
		'honeypot' => 'Something went wrong',
		'delayed' => 'Please wait before trying again.',
		'success' => 'Thank you for your message',
		'failed' => 'Sorry, mailer failed to send your message.',
	];
	private const int SUBMIT_DELAY = 3;
	private const string HONEYPOT_NAME = 'my_name';
	private const string HONEYPOT_REQUEST = 'request';
	private const string JS_PATH = '/formAjax.js';

	private array $inputList = [];
	private string $errorMessage;
	private ?array $formErrors;
	private array $parsedBody;
	private string $status = FormStatus::INVALID;

	use MailerSend;

	function __construct(
		private string $method = HttpMethod::GET,
		private ?string $action = '',
		private bool $honey = true,
	){
		$this->setAction($action);
		!$this->honey ?: $this->honeypot(); // Opt-out if not needed.
		
		if ( $this->wasSuccessful() || isset($_GET['success']) ) {
			$this->setSuccessful();
		}
		if ( $this->wasRejected() || isset($_GET['rejected'])) {
			$this->setRejected();
		}
	}

	# BUILDER METHODS

	public function get(?string $action = '')
	{
		$this->method = HttpMethod::GET;
		$this->setAction($action);
		return $this;
	}

	public function post(?string $action = '')
	{
		$this->method = HttpMethod::POST;
		$this->setAction($action);
		return $this;
	}

	public function action(?string $action = '')
	{
		$this->setAction($action);
		return $this;
	}

	public function withoutHoneypot()
	{
		$this->honey = false;
		return $this;
	}

	public function withError(?string $message = null): self
	{
		if (is_string($message)) {
			// $this->setErrorMessage($message);
			$this->addError('form', $message);
		}
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
		if ( $this->wasSuccessful() || $this->wasRejected() ) {
			$this->disableAllInputs();
		}

		$output  = "";
		$output .= $this->getFormStart();
		
		foreach ($this->inputList as $input) {
			$output .= $input;
		}

		$output .= '<div data-input-error="form" class="text-warning" style="color:salmon;display:block;">';
		if ( $this->hasErrors() && strlen($this->getErrorMessage()) > 1) {
			$output .= $this->getErrorMessage();
		} 
		$output .= '</div>';
		
		if ( $this->wasSuccessful() && strlen($this->getSuccessMessage()) > 1 ) {
			$output .= '<div data-form-success class="text-success">'.$this->getSuccessMessage().'</div>';
		}

		$output .= $this->getFormEnd();
		return $output;
	}

	# FORM ELEMENTS

	public function input(...$attrs):self {	return $this->make(...$attrs); }
	public function text(...$attrs):self {	return $this->make(...$attrs, type:'text'); }
	public function button(...$attrs):self { return $this->make(...$attrs, type:'button'); }
	public function reset(...$attrs):self { return $this->make(...$attrs, type:'reset'); }
	public function submit(...$attrs):self { return $this->make(...$attrs, type:'submit'); }
	public function textarea(...$attrs):self { return $this->make(...$attrs, type:'textarea'); }

	# OPINIONATED ELEMENT STARTERS
	public function name(...$attrs):self { return $this->make(...$attrs, type:'text', label:'Name', autocomplete:'name', );}
	public function email(...$attrs):self { return $this->make(...$attrs, type:'email', label:'Email', autocomplete:'email',);}
	public function message(...$attrs):self { return $this->make(...$attrs, type:'textarea', label:'Message',); }
	
	# SPAM PROTECTION
	private function honeypot(...$attrs):self { 
		return $this
			->make(...$attrs, type:'hidden', name:self::HONEYPOT_NAME )
			->make(...$attrs, type:'hidden', name:self::HONEYPOT_REQUEST, value:time() );
	}

	# RECONSTRUCT WITH OLD DATA
	public function reconstructWithParsedBody(array $payload):self
	{
		# USER INPUT FROM THE REQUEST MAY CONTAIN MANIPULATED FIELDS
		$this->parsedBody = $payload; 

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
		$this->isValid();
		return $this;
	}

	# SENT SUCCESSFULLY
	public function success()
	{
		$this->setSuccessful();
		return $this;
	}
	public function reject()
	{
		$this->setRejected();
		return $this;
	}

	# SPAM VALIDATION
	public function validateHoneypot()
	{
		# USER SUBMITTED request data is stored in property ParsedBody.
		try {
			match(true) {
				is_null($this->parsedBody)
					=> throw new FormException('Data to validate might be missing.'),

				!array_key_exists(self::HONEYPOT_NAME, $this->parsedBody ) || 
				!array_key_exists(self::HONEYPOT_REQUEST, $this->parsedBody )
					=> throw new FormException('Data to validate might be modified.'),

				is_null($this->parsedBody[self::HONEYPOT_NAME]) || 
				!empty ($this->parsedBody[self::HONEYPOT_NAME]) 
					=> throw new FormException(self::MESSAGES['honeypot']),
				
				($_SERVER['REQUEST_TIME'] - $this->parsedBody[self::HONEYPOT_REQUEST]) <= self::SUBMIT_DELAY 
					=> throw new FormException(self::MESSAGES['delayed']),

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

	# CHECK FOR ANY ERRORS // AFTER VALIDATION
	private function hasErrors():?array
	{
		if ($this->wasRejected()) {
			$errors['mailer'] = FormStatus::REJECTED; // Declare general form error
		}

		foreach ($this->inputList as $input) {
			if ( $input->hasError() ) {
				$errors[$input->getName()] = $input->getError();
			}
		}
		if (!empty($this->formErrors)) {
			foreach($this->formErrors as $key => $message ){
				$errors[$key] = $message;
			}
		}
		return $this->formErrors = $errors ?? null;
		
	}
	public function hasError(string $key)
	{
		return $this->hasErrors() && array_key_exists($key, $this->getErrors());
	}

	public function getErrors()
	{
		return $this->formErrors;	
	}

	# GETTERS

	public function wasSuccessful():bool
	{
		return $this->status === FormStatus::SUCCESS;
	}
	public function wasRejected():bool
	{
		return $this->status === FormStatus::REJECTED;
	}

	public function getSuccessMessage()
	{
		return $this->wasSuccessful() ? self::MESSAGES['success'] : null;
	}

	public function getErrorMessage():?string
	{
		return match(!empty($this->hasErrors())) {
			$this->hasError('mailer') => self::MESSAGES['failed'],
			$this->hasError('form') => $this->getErrors()['form'],
			default => '',
		};
	}

	// private function getHoneypot()
	// {
	// 	return array_find($this->inputList, function($formInput) {
	// 		return self::HONEYPOT_NAME === $formInput->getName();
	// 	});
	// }

	// private function getHoneypotRequest()
	// {
	// 	return array_find($this->inputList, function($formInput) {
	// 		return self::HONEYPOT_REQUEST === $formInput->getName();
	// 	});
	// }

	# SETTERS

	private function setSuccessful():void
	{
		$this->status = FormStatus::SUCCESS;
		$this->removeAllInputs();
	}

	private function setRejected()
	{
		$this->status = FormStatus::REJECTED;
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

	// public function setErrorMessage(string $message): void
	// {
	// 	$this->errorMessage = $message;
	// }

	public function addError(string $key, string $message ):void
	{
		$this->formErrors[$key] = $message;
	}

	public function isValid():bool
	{
		if ( $this->hasErrors() || $this->wasRejected() ) {
			$this->status = FormStatus::INVALID;
		}
		else {
			$this->status = FormStatus::VALID;
		}
		return $this->status === FormStatus::VALID;
	}

	# PRIVATE METHODS

	private function addFormInput(FormInput $forminput): void
	{
		$this->inputList[] = $forminput;
	}

	private function setAction(?string $action = ''):void
	{
		$this->action = match(true){
			is_null($action) => null,
			empty($action) => htmlspecialchars($_SERVER['REQUEST_URI']),
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
		$output = self::JS_PATH ? '<script type="text/javascript">'.file_get_contents(__DIR__.self::JS_PATH).'</script>' : '';
		return $output .= '</form>';
	}
}