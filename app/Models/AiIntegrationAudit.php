<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntegrationAudit extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_id', 'ai_integration_id', 'action', 'metadata', 'created_at'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function integration() { return $this->belongsTo(AiIntegration::class, 'ai_integration_id')->withTrashed(); }
}
