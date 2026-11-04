<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class ZoomBatch extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'active', 'completed', 'archived'];

    protected $fillable = ['name', 'description', 'status', 'capacity', 'starts_at', 'ends_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (ZoomBatch $batch): void {
            $batch->status ??= 'draft';
            $batch->capacity ??= 30;

            if (! in_array($batch->status, self::STATUSES, true)) {
                throw new InvalidArgumentException('The Zoom batch status is invalid.');
            }

            if ((int) $batch->capacity < 1) {
                throw new InvalidArgumentException('A Zoom batch capacity must be at least one.');
            }
        });
    }

    public function memberships()
    {
        return $this->hasMany(ZoomBatchMembership::class);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'zoom_batch_memberships', 'zoom_batch_id', 'student_id')
            ->withPivot(['id', 'status', 'joined_at', 'ended_at', 'created_by'])
            ->withTimestamps();
    }

    public function zoomSessions()
    {
        return $this->hasMany(ZoomSession::class)->withTrashed();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
