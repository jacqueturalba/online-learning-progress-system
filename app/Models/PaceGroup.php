<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaceGroup extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['code', 'weekly_target', 'minimum_completed_lessons', 'measurement_period'];

    protected function casts(): array
    {
        return ['weekly_target' => 'integer', 'minimum_completed_lessons' => 'integer'];
    }

    public function students()
    {
        return $this->hasMany(User::class, 'pace_group', 'code');
    }
}
