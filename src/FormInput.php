<?php

namespace FluentForms;

use FluentForms\Enums\ButtonType;
use FluentForms\Enums\InputType;
use Exception;

class FormInput
{
    public array $attrs;

	function __construct(
		private ?string $name = null, 
        private ?string $label = '',
		private string $type = InputType::TEXT, 
		private string $id = '', 
		private ?string $value = null,
        private ?string $placeholder = '',
		private ?string $autocomplete = null,
		private ?bool $required = false,
        private ?string $error = null,
        private ?bool $disabled = null,

        private ?int $rows = 4,
        private ?int $minLength = null,
        private ?int $maxLength = null,
	){
        $this->type = strtolower($type);

        $this->name = match(true) {
            $this->isButton() && empty($name) => null,
            default =>   $name ?? $label ?? $id ?? null,
        };

        # LABEL MANAGER
        switch(true) {
            case is_null($label) : 
                $this->label = null;
                $this->placeholder = ($this->isInput() && empty($this->placeholder) && !is_null($this->placeholder)) 
                    ? $this->name 
                    : null;
                break;
            case empty($label) && !is_null($label) : 
                $this->label = match(true) {
                    $this->isButton() => $this->value ?? $this->type ?? 'button',
                    default => $this->name ?? 'label',
                };
                break;
            default : 
                $this->label = $label;
        }
        
        # NAME REQUIRED?
        if ($this->type == InputType::TEXT && (is_null($this->name) || empty($this->name) )) {
            throw new Exception('Input needs a name');
        }

        # UNDERSCORE
        $this->name = strtolower(str_replace(' ', '_', (string) $this->name ));
		$this->id = $id ? strtolower(str_replace(' ', '_', $id )) : $this->name;
        
        $this->buildAttributes();
	}

    # VALIDATION

    public function validate()
    {
        if ($this->type == InputType::HIDDEN) return;

        $labeled = ucfirst($this->label ?? $this->name ?? $this->id);

        $error = match(true) {
            $this->failsRequiredInput() 
                => $labeled.' is required.',
            
            $this->failsEmailInput() 
                => $labeled.' must be a valid email.',

            $this->failsMaxLength()
                => $labeled.' must not exceed '.$this->maxLength .' characters',

            $this->failsMinLength()
                => $labeled.' must not be less than '.$this->minLength.' characters',
            
            default => null,
        };

        if ($error) {
            $this->withError($error);
        }
        return $this;

    }

    private function failsRequiredInput():bool
    {
        return  $this->required && (is_null($this->value) || empty($this->value)) ? true : false;
    }
    private function failsEmailInput():bool
    {
        return $this->isEmail() && !$this->emailIsValid();
    }
    private function failsMaxLength()
    {
        return isset($this->maxLength) && strlen($this->value) > $this->maxLength;
    }
    private function failsMinLength()
    {
        return isset($this->minLength) && strlen($this->value) < $this->minLength ? true : false;
    }
    private function emailIsValid():?string
    {
        return filter_var($this->value, FILTER_VALIDATE_EMAIL) ?? false;
    }

    private function buildAttributes()
    {
        # PROPERTIES AS HTML ATTRIBUTES, EXCEPT
        unset($this->attrs);
        $this->attrs = array_filter(
            get_object_vars($this), fn($key) => !in_array($key, [
                'label',
                'error',
                $this->isTextarea() ? null : 'rows',
            ]), ARRAY_FILTER_USE_KEY
        );
    }

    # GETTERS

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue()
	{
		return $this->value;
	}

    public function hasError():bool
    {
        return $this->error ? true : false;
    }

    public function getError():?string
    {
        return $this->hasError() ? $this->error : null;
    }

    # SETTERS

    public function setValue( int|string $value ):void
    {
        $this->value = $value;
        $this->buildAttributes();
    }

    public function withError(string $error ):void
    {
        $this->error = $error;
    }

    public function disable():void
    {
        $this->disabled = true;
        $this->buildAttributes();
    }

    # HTML OUTPUT

	public function __toString(): string
	{
        $attributesList = array_filter($this->attrs);

        $string = join(' ', array_map(function($item) use ($attributesList) {
            if (is_null($attributesList[$item])) return;
            if (is_bool($attributesList[$item])) return $attributesList[$item] ? $item : '';
            return $item.'="'.$attributesList[$item].'"';
        }, array_keys($attributesList)));

        $html['label'] = $this->hasLabel() ? $this->labelHTML() : '';

        $html['input'] = match(true) {
            $this->isButton() => $this->buttonHTML($string),
            $this->isTextarea() => $this->textareaHTML($string),
            $this->isInput() => $this->inputHTML($string),
            default => $this->inputHTML($string),  
        };

        $html['error'] = !$this->isSubmit() ? $this->errorHTML() : '';

        $html = implode('', $html);

		return $html;
	}

    private function labelHTML(): string
    {
        return '<label for="'.$this->id.'">'
            .ucfirst((string) $this->label) . ($this->required ? '<sup class="required text-warning">*</sup>' : '')
            .' </label>';
    }
    
    private function errorHTML():string
    {
        return '<span style="display:block;color:salmon" data-input-error="'.$this->name.'">'.$this->error.'</span>';
    }
    
    private function inputHTML(string $attributes): string
    {
        return "<input ".$attributes  ." />";
    }
    
    private function buttonHTML(string $attributes): string
    {
        $margin = $this->isSubmit() ? 'style="margin-top:1em"' : '';
        return '<button '.$attributes.$margin .'>'.$this->label.'</button>';
    }
    
    private function textareaHTML(string $attributes): string
    {
        return '<textarea rows="'.$this->rows.'"'.$attributes .' >'.$this->value.'</textarea>';
    }

    # CONDITIONS

    private function isInput():bool
    {
        return in_array($this->type, [
            InputType::TEXT,
            InputType::EMAIL,
            InputType::PASSWORD,
        ]);
    }

    private function hasLabel():bool
    {
        return match($this->type) {
            InputType::BUTTON,
            InputType::SUBMIT,
            InputType::HIDDEN,
            ButtonType::BUTTON,
            ButtonType::SUBMIT,
            ButtonType::RESET => false,
            default => true,
        };
    }

    private function isEmail():bool
    {
        return InputType::EMAIL == $this->type;
    }

    private function isButton():bool
    {
        return in_array($this->type, [
            ButtonType::BUTTON,
            ButtonType::RESET,
            ButtonType::SUBMIT,
            InputType::BUTTON,
            InputType::SUBMIT,
        ]);
    }

    public function isSubmit():bool
    {
        return InputType::SUBMIT == $this->type;
    }

    private function isTextarea():bool
    {
        return 'textarea' == $this->type;
    }
}