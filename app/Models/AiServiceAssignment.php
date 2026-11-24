<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiServiceAssignment extends Model
{
    protected $fillable = ['service', 'ai_integration_id', 'model', 'target_language'];
    public function integration() { return $this->belongsTo(AiIntegration::class, 'ai_integration_id'); }
}
