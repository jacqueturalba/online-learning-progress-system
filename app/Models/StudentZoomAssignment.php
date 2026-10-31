<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentZoomAssignment extends Model
{
    protected $fillable = ['student_id', 'zoom_url', 'is_active', 'assigned_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
