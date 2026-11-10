<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoomAttendanceOccurrence extends Model
{
    protected $fillable = ['zoom_session_id', 'meeting_uuid', 'started_at', 'duration_seconds', 'participants_detected', 'synced_at', 'sync_status'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'synced_at' => 'datetime'];
    }

    public function session() { return $this->belongsTo(ZoomSession::class, 'zoom_session_id'); }
    public function students() { return $this->hasMany(ZoomAttendanceOccurrenceStudent::class, 'occurrence_id'); }
}
