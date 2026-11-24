<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiOperationUsage extends Model
{
    protected $fillable = ['ai_integration_id', 'service', 'model', 'lesson_id', 'timeline_offset_seconds', 'status', 'processing_duration_ms', 'audio_minutes', 'input_tokens', 'output_tokens', 'estimated_cost', 'error_code', 'error_message', 'request_reference', 'idempotency_key', 'request_fingerprint', 'result'];
    protected function casts(): array { return ['timeline_offset_seconds' => 'decimal:3', 'audio_minutes' => 'decimal:4', 'estimated_cost' => 'decimal:8', 'result' => 'encrypted:array']; }
    public function integration() { return $this->belongsTo(AiIntegration::class, 'ai_integration_id')->withTrashed(); }
    public function lesson() { return $this->belongsTo(Lesson::class)->withTrashed(); }
}
