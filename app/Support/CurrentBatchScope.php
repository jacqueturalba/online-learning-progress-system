<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/** Attributes student activity to the student's current active batch membership. */
class CurrentBatchScope
{
    public const MEMBERSHIP = 'report_batch_membership';
    public const BATCH = 'report_batch';

    public static function join(Builder $query, string $studentColumn): Builder
    {
        return $query
            ->leftJoin('zoom_batch_memberships as '.self::MEMBERSHIP, function ($join) use ($studentColumn): void {
                $join->on(self::MEMBERSHIP.'.student_id', '=', $studentColumn)
                    ->where(self::MEMBERSHIP.'.status', '=', 'active')
                    ->whereNotExists(function (Builder $newer): void {
                        $newer->selectRaw('1')->from('zoom_batch_memberships as newer_current_batch')
                            ->whereColumn('newer_current_batch.student_id', self::MEMBERSHIP.'.student_id')
                            ->where('newer_current_batch.status', 'active')
                            ->whereColumn('newer_current_batch.id', '>', self::MEMBERSHIP.'.id');
                    });
            })
            ->leftJoin('zoom_batches as '.self::BATCH, self::BATCH.'.id', '=', self::MEMBERSHIP.'.zoom_batch_id');
    }

    public static function filter(Builder $query, ?string $batchId): Builder
    {
        if ($batchId === null || $batchId === '') return $query;

        return $batchId === 'none'
            ? $query->whereNull(self::MEMBERSHIP.'.zoom_batch_id')
            : $query->where(self::MEMBERSHIP.'.zoom_batch_id', (int) $batchId);
    }
}
