<?php

namespace App\Ai\Exceptions;

/** AI-слой выключен или не сконфигурирован (нет ключа/провайдера). */
class AiDisabledException extends AiException
{
    public function __construct(string $message = 'AI-слой выключен или не сконфигурирован.')
    {
        parent::__construct($message);
    }
}
