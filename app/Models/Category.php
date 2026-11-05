<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'sort_order', 'is_active', 'thumbnail_path', 'background_color', 'enable_first_lesson_access', 'unlock_all_lessons'];
    protected $hidden = ['thumbnail_path'];
    protected function casts(): array { return ['is_active' => 'boolean', 'enable_first_lesson_access' => 'boolean', 'unlock_all_lessons' => 'boolean']; }
    public function lessons() { return $this->hasMany(Lesson::class)->orderBy('sort_order')->orderBy('id'); }
    public function zoomSessions() { return $this->hasMany(ZoomSession::class); }
}
