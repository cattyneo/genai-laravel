<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Http\Controllers;

use CattyNeo\LaravelGenAI\Services\GenAI\PresetRepository;
use CattyNeo\LaravelGenAI\Services\GenAI\PresetAutoUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * GenAI プリセット管理 API コントローラー
 */
class PresetController extends Controller
{
    public function __construct(
        private PresetRepository $presetRepository,
        private PresetAutoUpdateService $autoUpdateService
    ) {}

    /**
     * プリセット一覧取得
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $category = $request->get('category');
            $provider = $request->get('provider');
            $includeArchived = $request->boolean('include_archived', false);
            
            $presets = $this->presetRepository->getPresets([
                'category' => $category,
                'provider' => $provider,
                'include_archived' => $includeArchived,
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'presets' => $presets,
                    'count' => count($presets),
                    'filters' => [
                        'category' => $category,
                        'provider' => $provider,
                        'include_archived' => $includeArchived,
                    ],
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get presets',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * プリセット詳細取得
     */
    public function show(Request $request, string $preset): JsonResponse
    {
        try {
            $includeHistory = $request->boolean('include_history', false);
            
            $presetData = $this->presetRepository->getPreset($preset);
            
            if (!$presetData) {
                return response()->json([
                    'success' => false,
                    'error' => 'Preset not found',
                ], 404);
            }
            
            $response = [
                'success' => true,
                'data' => [
                    'preset' => $presetData,
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ]
            ];
            
            if ($includeHistory) {
                $response['data']['history'] = $this->presetRepository->getPresetHistory($preset);
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get preset',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * プリセット作成
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:100',
                'provider' => 'required|string|in:openai,gemini,claude,grok',
                'model' => 'required|string',
                'options' => 'sometimes|array',
                'options.temperature' => 'sometimes|numeric|min:0|max:2',
                'options.max_tokens' => 'sometimes|integer|min:1|max:100000',
                'options.top_p' => 'sometimes|numeric|min:0|max:1',
                'description' => 'sometimes|string|max:500',
                'category' => 'sometimes|string|max:50',
                'tags' => 'sometimes|array',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 422);
            }
            
            $presetData = $validator->validated();
            $created = $this->presetRepository->createPreset($presetData);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'preset' => $created,
                    'message' => 'Preset created successfully',
                ],
                'meta' => [
                    'created_at' => now()->toISOString(),
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create preset',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * プリセット更新
     */
    public function update(Request $request, string $preset): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:100',
                'provider' => 'sometimes|string|in:openai,gemini,claude,grok',
                'model' => 'sometimes|string',
                'options' => 'sometimes|array',
                'options.temperature' => 'sometimes|numeric|min:0|max:2',
                'options.max_tokens' => 'sometimes|integer|min:1|max:100000',
                'options.top_p' => 'sometimes|numeric|min:0|max:1',
                'description' => 'sometimes|string|max:500',
                'category' => 'sometimes|string|max:50',
                'tags' => 'sometimes|array',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 422);
            }
            
            $updateData = $validator->validated();
            $updated = $this->presetRepository->updatePreset($preset, $updateData);
            
            if (!$updated) {
                return response()->json([
                    'success' => false,
                    'error' => 'Preset not found',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'preset' => $updated,
                    'message' => 'Preset updated successfully',
                ],
                'meta' => [
                    'updated_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update preset',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * プリセット削除
     */
    public function destroy(Request $request, string $preset): JsonResponse
    {
        try {
            $forceDelete = $request->boolean('force', false);
            
            $deleted = $this->presetRepository->deletePreset($preset, $forceDelete);
            
            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'error' => 'Preset not found',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'preset_name' => $preset,
                    'message' => $forceDelete ? 'Preset permanently deleted' : 'Preset archived',
                ],
                'meta' => [
                    'deleted_at' => now()->toISOString(),
                    'force_delete' => $forceDelete,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete preset',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * プリセット自動更新状況取得
     */
    public function getAutoUpdateStatus(Request $request, string $preset): JsonResponse
    {
        try {
            $status = $this->autoUpdateService->getPresetUpdateStatus($preset);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'preset_name' => $preset,
                    'auto_update_status' => $status,
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get auto-update status',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}