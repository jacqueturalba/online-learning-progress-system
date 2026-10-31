<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'sort_order', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function lessons() { return $this->hasMany(Lesson::class)->orderBy('sort_order')->orderBy('id'); }
    public function zoomSessions() { return $this->hasMany(ZoomSession::class); }
}
