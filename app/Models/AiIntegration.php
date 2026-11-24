<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiIntegration extends Model
{
    use SoftDeletes;

    protected $fillable = ['provider', 'label', 'endpoint', 'credentials', 'is_enabled', 'last_tested_at', 'last_test_status'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_enabled' => 'boolean', 'last_tested_at' => 'datetime'];
    }

    public function assignments() { return $this->hasMany(AiServiceAssignment::class); }
    public function usages() { return $this->hasMany(AiOperationUsage::class); }
}
