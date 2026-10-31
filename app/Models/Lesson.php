<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = ['category_id', 'title', 'description', 'video_url', 'duration_seconds', 'sort_order', 'is_published'];
    protected function casts(): array { return ['is_published' => 'boolean']; }
    public function category() { return $this->belongsTo(Category::class); }
    public function progress() { return $this->hasMany(StudentLessonProgress::class); }
}
