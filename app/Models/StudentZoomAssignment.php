<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class StudentZoomAssignment extends Model
{
    public const REGISTRATION_STATUSES = ['manual', 'pending', 'registered', 'failed', 'cancelled'];
    public const REGISTRATION_SOURCES = ['manual', 'zoom_api'];

    protected $fillable = [
        'student_id', 'zoom_session_id', 'zoom_url', 'is_active', 'assigned_by', 'registrant_id',
        'pseudocode_snapshot', 'registration_status', 'registered_at', 'registration_source', 'registration_error',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'registered_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (StudentZoomAssignment $assignment): void {
            $assignment->registration_status ??= 'manual';
            $assignment->registration_source ??= 'manual';

            if (! in_array($assignment->registration_status, self::REGISTRATION_STATUSES, true)) {
                throw new InvalidArgumentException('The Zoom registration status is invalid.');
            }

            if (! in_array($assignment->registration_source, self::REGISTRATION_SOURCES, true)) {
                throw new InvalidArgumentException('The Zoom registration source is invalid.');
            }

            if ($assignment->zoom_session_id === null && $assignment->registration_source !== 'manual') {
                throw new InvalidArgumentException('An API registration must be associated with a Zoom session.');
            }

            if ($assignment->zoom_session_id === null && $assignment->registration_status !== 'manual') {
                throw new InvalidArgumentException('A non-manual Zoom registration must be associated with a Zoom session.');
            }

            if ($assignment->registration_status === 'registered'
                && ($assignment->registration_source !== 'zoom_api' || blank($assignment->registrant_id) || blank($assignment->zoom_url) || $assignment->registered_at === null)) {
                throw new InvalidArgumentException('A registered Zoom student must have a session, registrant ID, join URL, and registration time.');
            }
        });
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function zoomSession()
    {
        return $this->belongsTo(ZoomSession::class, 'zoom_session_id')->withTrashed();
    }
}
