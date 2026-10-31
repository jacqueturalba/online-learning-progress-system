<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

    protected $fillable = ['category_id', 'title', 'description', 'video_url', 'duration_seconds', 'sort_order', 'is_published'];
    protected function casts(): array { return ['is_published' => 'boolean']; }
    public function category() { return $this->belongsTo(Category::class)->withTrashed(); }
    public function progress() { return $this->hasMany(StudentLessonProgress::class); }
    public function realizations() { return $this->hasMany(StudentRealization::class); }
}
