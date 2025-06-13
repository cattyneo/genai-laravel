<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GenAIRequest extends Model
{
    use HasFactory;

    protected $table = 'genai_requests';

    protected $fillable = [
        'provider',
        'model',
        'prompt',
        'system_prompt',
        'options',
        'vars',
        'response',
        'response_usage',
        'cost',
        'response_meta',
        'status',
        'error_message',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'duration_ms',
        'user_id',
        'session_id',
        'request_id',
    ];

    protected $casts = [
        'options' => 'array',
        'vars' => 'array',
        'response_usage' => 'array',
        'response_meta' => 'array',
        'cost' => 'decimal:6',
        'duration_ms' => 'decimal:2',
    ];

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'error');
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}
