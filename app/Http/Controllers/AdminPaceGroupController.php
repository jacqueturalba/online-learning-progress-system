<?php

namespace App\Http\Controllers;

use App\Models\PaceGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPaceGroupController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'groups' => ['required', 'array', 'size:4'],
            'groups.*.code' => ['required', 'string', Rule::exists('pace_groups', 'code'), 'distinct'],
            'groups.*.weekly_target' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        DB::transaction(function () use ($data): void {
            foreach ($data['groups'] as $group) {
                PaceGroup::query()->whereKey($group['code'])->update(['weekly_target' => $group['weekly_target']]);
            }
        });

        return response()->json(['pace_groups' => $this->options()]);
    }

    public function options(): array
    {
        return PaceGroup::query()->orderBy('code')->get(['code', 'weekly_target'])
            ->map(fn (PaceGroup $group): array => [
                'value' => $group->code,
                'label' => 'Group '.$group->code,
                'weekly_target' => $group->weekly_target,
            ])->all();
    }
}
