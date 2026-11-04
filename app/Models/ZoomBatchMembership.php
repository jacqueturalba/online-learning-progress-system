<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ZoomBatchMembership extends Model
{
    public const STATUSES = ['active', 'transferred', 'removed'];

    protected $fillable = ['zoom_batch_id', 'student_id', 'status', 'joined_at', 'ended_at', 'created_by'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (ZoomBatchMembership $membership): void {
            $membership->status ??= 'active';

            if (! in_array($membership->status, self::STATUSES, true)) {
                throw new InvalidArgumentException('The Zoom batch membership status is invalid.');
            }

            if ($membership->status === 'active' && $membership->ended_at !== null) {
                throw new InvalidArgumentException('An active Zoom batch membership cannot have an end time.');
            }

            if ($membership->status !== 'active' && $membership->ended_at === null) {
                throw new InvalidArgumentException('A closed Zoom batch membership must have an end time.');
            }
        });
    }

    public function zoomBatch()
    {
        return $this->belongsTo(ZoomBatch::class)->withTrashed();
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
