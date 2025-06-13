<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Exceptions;

use Exception;

class GenAIException extends Exception
{
    protected $message = 'GenAI error occurred';
}

class RateLimitException extends GenAIException
{
    protected $message = 'Rate limit exceeded';
}

class TimeoutException extends GenAIException
{
    protected $message = 'Request timeout';
}

class ConnectionException extends GenAIException
{
    protected $message = 'Connection error';
}
