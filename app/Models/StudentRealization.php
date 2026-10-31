<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRealization extends Model
{
    protected $fillable = ['student_id', 'lesson_id', 'realization', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class)->withTrashed();
    }
}
