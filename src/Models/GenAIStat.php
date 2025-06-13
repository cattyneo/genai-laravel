<?php

declare(strict_types=1);

namespace CattyNeo\LaravelGenAI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GenAIStat extends Model
{
    use HasFactory;

    protected $table = 'genai_stats';

    protected $fillable = [
        'date',
        'provider',
        'model',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'total_input_tokens',
        'total_output_tokens',
        'total_tokens',
        'total_cost',
        'avg_duration_ms',
    ];

    protected $casts = [
        'date' => 'date',
        'total_cost' => 'decimal:6',
        'avg_duration_ms' => 'decimal:2',
    ];

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
