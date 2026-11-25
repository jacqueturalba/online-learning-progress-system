<?php

namespace App\Http\Controllers;

use App\Models\PaceGroup;
use App\Services\StudentPaceGroupAssigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AdminPaceGroupController extends Controller
{
    public function update(Request $request, StudentPaceGroupAssigner $assigner)
    {
        $data = $request->validate([
            'groups' => ['required', 'array', 'size:4'],
            'groups.*.code' => ['required', 'string', Rule::exists('pace_groups', 'code'), 'distinct'],
            'groups.*.minimum_completed_lessons' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'measurement_period' => ['required', Rule::in(['weekly', 'monthly'])],
        ]);

        $groups = collect($data['groups'])->keyBy('code');
        abort_unless($groups->keys()->sort()->values()->all() === ['A', 'B', 'C', 'D'], 422, 'Configure each of groups A, B, C, and D exactly once.');
        $previous = null;
        foreach (['A', 'B', 'C', 'D'] as $code) {
            $target = $groups[$code]['minimum_completed_lessons'] ?? null;
            if ($target === null) continue;
            if ($previous !== null && (int) $target >= $previous) {
                throw ValidationException::withMessages(['groups' => 'Configured targets must strictly decrease from A through D so every group can be reached.']);
            }
            $previous = (int) $target;
        }

        DB::transaction(function () use ($groups, $data, $assigner): void {
            foreach ($groups as $group) {
                PaceGroup::query()->whereKey($group['code'])->update([
                    'minimum_completed_lessons' => $group['minimum_completed_lessons'] ?? null,
                    'measurement_period' => $data['measurement_period'],
                    'weekly_target' => $data['measurement_period'] === 'weekly' ? ($group['minimum_completed_lessons'] ?? 0) : 0,
                ]);
            }
            $assigner->recalculateAll();
        });

        return response()->json(['pace_groups' => $this->options()]);
    }

    public function options(): array
    {
        return PaceGroup::query()->orderBy('code')->get(['code', 'weekly_target', 'minimum_completed_lessons', 'measurement_period'])
            ->map(fn (PaceGroup $group): array => [
                'value' => $group->code,
                'label' => 'Group '.$group->code,
                'weekly_target' => $group->weekly_target,
                'minimum_completed_lessons' => $group->minimum_completed_lessons,
                'measurement_period' => $group->measurement_period,
            ])->all();
    }
}
