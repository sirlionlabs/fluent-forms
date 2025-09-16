<?php

namespace FluentForms;

use FluentForms\FormBuilder;

class Form
{
    public static function __callStatic($method, $args)
    {
        return (new FormBuilder())->$method(...$args);
    }

}