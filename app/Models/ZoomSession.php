<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoomSession extends Model
{
    protected $fillable = ['category_id', 'lesson_id', 'title', 'meeting_url', 'scheduled_at', 'description', 'is_active'];
    protected function casts(): array { return ['scheduled_at' => 'datetime', 'is_active' => 'boolean']; }
    public function category() { return $this->belongsTo(Category::class); }
    public function lesson() { return $this->belongsTo(Lesson::class); }
}
