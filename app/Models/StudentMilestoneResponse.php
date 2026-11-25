<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentMilestoneResponse extends Model
{
    protected $fillable = ['student_id', 'lesson_id', 'zoom_session_id', 'scheduled_at', 'milestone_key', 'response', 'submitted_at'];
    protected function casts(): array { return ['submitted_at' => 'datetime']; }
}
