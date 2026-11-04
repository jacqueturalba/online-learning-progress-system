<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ZoomAttendanceRecord extends Model
{
    public const MATCH_STATUSES = ['matched', 'unmatched', 'manually_matched'];

    protected $fillable = [
        'zoom_session_id', 'zoom_meeting_uuid', 'student_id', 'registrant_id', 'participant_id',
        'participant_pseudocode', 'participant_name', 'participant_email', 'joined_at', 'left_at',
        'duration_seconds', 'match_status', 'raw_data', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ZoomAttendanceRecord $record): void {
            $record->match_status ??= 'unmatched';

            if (! in_array($record->match_status, self::MATCH_STATUSES, true)) {
                throw new InvalidArgumentException('The Zoom attendance match status is invalid.');
            }

            if (in_array($record->match_status, ['matched', 'manually_matched'], true) && $record->student_id === null) {
                throw new InvalidArgumentException('A matched Zoom attendance record must belong to a student.');
            }

            if ($record->dedupe_key === null) {
                $identity = $record->participant_id
                    ?: implode('|', array_map(static fn ($value): string => (string) ($value ?? ''), [
                        mb_strtolower((string) $record->participant_email),
                        mb_strtolower((string) $record->participant_name),
                        $record->joined_at?->toISOString(),
                        $record->left_at?->toISOString(),
                    ]));

                if ($identity === '' || $identity === '|||') {
                    $identity = Str::uuid()->toString();
                }

                $record->dedupe_key = hash('sha256', implode('|', [
                    $record->zoom_session_id,
                    $record->zoom_meeting_uuid,
                    $identity,
                    $record->joined_at?->toISOString() ?? '',
                ]));
            }
        });
    }

    public function zoomSession()
    {
        return $this->belongsTo(ZoomSession::class)->withTrashed();
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
