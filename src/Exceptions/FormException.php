<?php

namespace FluentForms\Exceptions;

class FormException extends \Exception
{
    public function __construct($message)
    {
        $this->message = $message ?? 'Something went wrong.';
    }
}
