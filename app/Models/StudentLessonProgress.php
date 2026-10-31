<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentLessonProgress extends Model
{
    protected $table = 'student_lesson_progress';
    protected $fillable = ['student_id', 'lesson_id', 'last_position_seconds', 'watched_seconds', 'progress_percentage', 'started_at', 'last_watched_at', 'is_playing', 'is_completed', 'completed_at'];
    protected function casts(): array { return ['started_at' => 'datetime', 'last_watched_at' => 'datetime', 'is_playing' => 'boolean', 'completed_at' => 'datetime', 'is_completed' => 'boolean', 'progress_percentage' => 'decimal:2']; }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function lesson() { return $this->belongsTo(Lesson::class)->withTrashed(); }
}
