<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Exceptions;

class ProviderException extends GenAIException
{
    protected $message = 'Provider error occurred';
}
