<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use CattyNeo\LaravelGenAI\Actions\RequestAction;
use CattyNeo\LaravelGenAI\Data\GenAIRequestData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenAIController extends Controller
{
    public function __construct(
        private RequestAction $requestAction
    ) {
    }

    /**
     * GenAI API動作確認エンドポイント
     */
    public function test(Request $request): JsonResponse
    {
        try {
            $genaiRequest = GenAIRequestData::from([
                'prompt' => $request->input('prompt', 'Hello, how are you?'),
                'systemPrompt' => $request->input('systemPrompt'),
                'model' => $request->input('model'),
                'options' => $request->input('options', []),
                'vars' => $request->input('vars', []),
            ]);

            $response = ($this->requestAction)($genaiRequest);

            return response()->json([
                'success' => true,
                'data' => $response->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
