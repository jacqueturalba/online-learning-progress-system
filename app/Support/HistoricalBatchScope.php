<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

class HistoricalBatchScope
{
    public const MEMBERSHIP = 'report_batch_membership';
    public const BATCH = 'report_batch';

    /** Attribute a timestamped record to the membership interval that contained it. */
    public static function join(Builder $query, string $studentColumn, string $timestampExpression): Builder
    {
        return $query
            ->leftJoin('zoom_batch_memberships as '.self::MEMBERSHIP, function ($join) use ($studentColumn, $timestampExpression): void {
                $join->on(self::MEMBERSHIP.'.student_id', '=', $studentColumn)
                    ->whereRaw(self::MEMBERSHIP.'.joined_at <= '.$timestampExpression)
                    ->where(fn ($ended) => $ended->whereNull(self::MEMBERSHIP.'.ended_at')
                        ->orWhereRaw(self::MEMBERSHIP.'.ended_at > '.$timestampExpression));
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

    public static function select(Builder $query): Builder
    {
        return $query->addSelect([
            self::MEMBERSHIP.'.zoom_batch_id as batch_id',
        ])->selectRaw("COALESCE(".self::BATCH.".name, 'No batch assigned') as batch_name");
    }
}
