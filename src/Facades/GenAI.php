<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Facades;

use CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed ask(string $prompt, array $options = [])
 * @method static \CattyNeo\LaravelGenAI\Services\GenAI\PromptManager preset(string $name = 'default')
 * @method static \CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager provider(string $provider)
 * @method static \CattyNeo\LaravelGenAI\Services\GenAI\GenAIManager model(string $model)
 * @method static array getProviders()
 * @method static array getModels(string $provider = null)
 * @method static void fake(array $responses = [])
 * @method static void assertRequested(string $method, ...$parameters)
 *
 * @see GenAIManager
 */
class GenAI extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'genai';
    }
}
