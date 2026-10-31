<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentCategoryProgress extends Model
{
    protected $table = 'student_category_progress';
    protected $fillable = ['student_id', 'category_id', 'started_at', 'completed_at'];
    protected function casts(): array { return ['started_at' => 'datetime', 'completed_at' => 'datetime']; }
}
