<?php

namespace CattyNeo\LaravelGenAI\Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use CattyNeo\LaravelGenAI\Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
