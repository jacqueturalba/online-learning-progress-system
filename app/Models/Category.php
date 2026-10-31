<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'description', 'sort_order', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function lessons() { return $this->hasMany(Lesson::class)->orderBy('sort_order'); }
    public function zoomSessions() { return $this->hasMany(ZoomSession::class); }
}
