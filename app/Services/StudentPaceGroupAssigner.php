<?php

namespace App\Services;

use App\Models\PaceGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentPaceGroupAssigner
{
    public function period(): string
    {
        return (string) (PaceGroup::query()->value('measurement_period') ?: 'weekly');
    }

    /** @return array{start: Carbon, end: Carbon, label: string} */
    public function periodBounds(?Carbon $now = null): array
    {
        $now = ($now ?: Carbon::now(config('app.timezone')))->copy()->timezone(config('app.timezone'));
        if ($this->period() === 'monthly') {
            return ['start' => $now->copy()->startOfMonth(), 'end' => $now->copy()->addMonthNoOverflow()->startOfMonth(), 'label' => 'Monthly'];
        }

        return ['start' => $now->copy()->startOfWeek(Carbon::MONDAY), 'end' => $now->copy()->startOfWeek(Carbon::MONDAY)->addWeek(), 'label' => 'Weekly'];
    }

    /** @return array{pace_group: ?string, completed_count: int, measurement_period: string} */
    public function summaryFor(int $studentId, ?Carbon $now = null): array
    {
        return $this->summariesFor([$studentId], $now)->get($studentId, [
            'pace_group' => null, 'completed_count' => 0, 'measurement_period' => $this->period(),
        ]);
    }

    public function summariesFor(iterable $studentIds, ?Carbon $now = null): Collection
    {
        $ids = collect($studentIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        $period = $this->period();
        $bounds = $this->periodBounds($now);
        $counts = DB::table('student_lesson_progress')
            ->whereIn('student_id', $ids)->where('is_completed', true)->whereNotNull('completed_at')
            ->where('completed_at', '>=', $bounds['start']->toDateTimeString())
            ->where('completed_at', '<', $bounds['end']->toDateTimeString())
            ->where('completed_at', '<=', ($now ?: Carbon::now(config('app.timezone')))->toDateTimeString())
            ->groupBy('student_id')->select('student_id')->selectRaw('COUNT(DISTINCT lesson_id) as completed_count')
            ->pluck('completed_count', 'student_id');
        $targets = PaceGroup::query()->orderBy('code')->get(['code', 'minimum_completed_lessons'])->keyBy('code');

        return $ids->mapWithKeys(function (int $id) use ($counts, $targets, $period): array {
            $count = (int) ($counts[$id] ?? 0);
            $group = null;
            foreach (['A', 'B', 'C', 'D'] as $code) {
                $target = $targets->get($code)?->minimum_completed_lessons;
                if ($target !== null && (int) $target > 0 && $count >= (int) $target) {
                    $group = $code;
                    break;
                }
            }

            return [$id => ['pace_group' => $group, 'completed_count' => $count, 'measurement_period' => $period]];
        });
    }

    public function recalculateStudent(int $studentId): array
    {
        $summary = $this->summaryFor($studentId);
        $current = DB::table('users')->where('id', $studentId)->where('role', 'student')->value('pace_group');
        if ($current !== $summary['pace_group']) {
            DB::table('users')->where('id', $studentId)->where('role', 'student')->update(['pace_group' => $summary['pace_group']]);
        }
        return $summary;
    }

    public function recalculateAll(): void
    {
        DB::table('users')->where('role', 'student')->select('id', 'pace_group')->orderBy('id')->chunk(500, function ($students): void {
            $summaries = $this->summariesFor($students->pluck('id'));
            foreach ($students as $student) {
                $group = $summaries->get($student->id)['pace_group'] ?? null;
                if ($student->pace_group !== $group) {
                    DB::table('users')->where('id', $student->id)->update(['pace_group' => $group]);
                }
            }
        });
    }
}
