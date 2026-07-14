<?php

namespace FluentForms\Contracts;

interface Mailer
{
    public function send(array $data): bool;
}