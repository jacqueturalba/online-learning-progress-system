<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoomAttendanceOccurrenceStudent extends Model
{
    protected $fillable = ['occurrence_id', 'student_id', 'duration_seconds', 'attendance_code', 'is_manual', 'updated_by', 'manually_updated_at'];

    protected function casts(): array
    {
        return ['is_manual' => 'boolean', 'manually_updated_at' => 'datetime'];
    }

    public function occurrence() { return $this->belongsTo(ZoomAttendanceOccurrence::class, 'occurrence_id'); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
}
