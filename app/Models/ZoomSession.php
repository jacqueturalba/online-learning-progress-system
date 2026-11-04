<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class ZoomSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'lesson_id', 'zoom_batch_id', 'title', 'meeting_url', 'scheduled_at', 'description', 'is_active',
        'zoom_meeting_id', 'zoom_meeting_uuid', 'zoom_host_id', 'zoom_meeting_type', 'registration_type',
        'recurrence_config', 'registration_url', 'meeting_status', 'last_synced_at',
    ];
    protected function casts(): array { return ['scheduled_at' => 'datetime', 'last_synced_at' => 'datetime', 'recurrence_config' => 'array', 'is_active' => 'boolean']; }

    protected static function booted(): void
    {
        static::saving(function (ZoomSession $session): void {
            $statuses = ['pending', 'active', 'ended', 'cancelled', 'failed'];
            if ($session->meeting_status !== null && ! in_array($session->meeting_status, $statuses, true)) {
                throw new InvalidArgumentException('The Zoom meeting status is invalid.');
            }

            if ($session->zoom_meeting_type !== null && ! in_array((int) $session->zoom_meeting_type, [1, 2, 3, 8, 10], true)) {
                throw new InvalidArgumentException('The Zoom meeting type is invalid.');
            }

            if ($session->registration_type !== null && ! in_array((int) $session->registration_type, [1, 2, 3], true)) {
                throw new InvalidArgumentException('The Zoom registration type is invalid.');
            }
        });
    }

    public function category() { return $this->belongsTo(Category::class)->withTrashed(); }
    public function lesson() { return $this->belongsTo(Lesson::class)->withTrashed(); }
    public function zoomBatch() { return $this->belongsTo(ZoomBatch::class)->withTrashed(); }
    public function registrations() { return $this->hasMany(StudentZoomAssignment::class, 'zoom_session_id'); }
    public function attendanceRecords() { return $this->hasMany(ZoomAttendanceRecord::class); }
}
