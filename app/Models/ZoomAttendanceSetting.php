<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoomAttendanceSetting extends Model
{
    protected $table = 'zoom_attendance_settings';
    protected $fillable = ['present_threshold', 'late_threshold'];
}
