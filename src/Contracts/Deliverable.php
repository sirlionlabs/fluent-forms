<?php

namespace FluentForms\Contracts;

use Psr\Http\Message\ResponseInterface as Response;

interface Deliverable
{
    public function send(Response $response): Response;
}