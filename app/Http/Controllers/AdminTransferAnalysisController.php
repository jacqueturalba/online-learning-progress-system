<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\StudentCategoryTransfer;
use App\Models\StudentCategoryProgress;
use App\Models\User;
use App\Support\TransferEligibility;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTransferAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'pace_group' => ['nullable', Rule::exists('pace_groups', 'code')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->validateDateRange($filters);

        if (isset($filters['lesson_id']) && isset($filters['category_id']) &&
            DB::table('lessons')->where('id', $filters['lesson_id'])->where('category_id', $filters['category_id'])->doesntExist()) {
            throw ValidationException::withMessages(['lesson_id' => 'Choose a lesson in the selected category.']);
        }

        $base = $this->eligibleRows($filters);
        $summary = [
            'eligible_students' => (clone $base)->distinct()->count('u.id'),
            'transferred_students' => (clone $base)->whereNotNull('transfers.id')->distinct()->count('u.id'),
        ];
        $summary['not_transferred_students'] = max(0, $summary['eligible_students'] - $summary['transferred_students']);
        $summary['transfer_rate'] = $summary['eligible_students']
            ? round($summary['transferred_students'] / $summary['eligible_students'] * 100, 1)
            : 0;

        $rows = (clone $base)
            ->orderBy('c.sort_order')->orderBy('c.id')->orderBy('u.name')->orderBy('u.id')
            ->select([
                'scp.id', 'u.id as student_id', 'u.name as student_name', 'u.pseudocode', 'u.pace_group',
                'pg.weekly_target', 'c.id as category_id', 'c.name as category_name',
                'scp.completed_at as category_completed_at', 'transfers.id as transfer_id',
                'transfers.transferred_at', 'to_category.name as transferred_to_category',
            ])
            ->selectRaw('1 as is_transfer_eligible')
            ->selectRaw('CASE WHEN transfers.id IS NULL THEN 0 ELSE 1 END as is_transferred')
            ->paginate(25)->withQueryString();

        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $lessons = empty($filters['category_id']) ? collect() : DB::table('lessons')
            ->where('category_id', $filters['category_id'])->where('is_published', true)->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'title']);

        return response()->json([
            'rows' => $rows,
            'transfer_summary' => $summary,
            'filters' => [
                'categories' => $categories,
                'lessons' => $lessons,
                'pace_groups' => app(AdminPaceGroupController::class)->options(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'student')],
            'from_category_id' => ['required', 'integer', 'exists:categories,id'],
            'to_category_id' => ['required', 'integer', 'different:from_category_id', 'exists:categories,id'],
        ]);

        $transfer = DB::transaction(function () use ($request, $data): StudentCategoryTransfer {
            // Serialize transfer actions for this student; the unique key remains a database backstop.
            DB::table('users')->where('id', $data['student_id'])->lockForUpdate()->first();
            Category::query()->whereKey($data['to_category_id'])->where('is_active', true)->firstOrFail();

            $sourceIsEligible = DB::table('users as u')->where('u.id', $data['student_id'])
                ->whereExists(TransferEligibility::eligibleCategoriesFor('u.id')
                    ->where('eligible_categories.id', $data['from_category_id']))
                ->exists();
            abort_unless($sourceIsEligible, 422, 'The student is not currently eligible to transfer from this category.');

            $duplicate = StudentCategoryTransfer::query()
                ->where('student_id', $data['student_id'])
                ->where('from_category_id', $data['from_category_id'])->exists();
            abort_if($duplicate, 409, 'A transfer has already been recorded from this category for this student.');

            $now = now();
            $destinationProgress = StudentCategoryProgress::query()->firstOrNew([
                'student_id' => $data['student_id'], 'category_id' => $data['to_category_id'],
            ]);
            $destinationProgress->started_at ??= $now;
            $destinationProgress->save();

            return StudentCategoryTransfer::query()->create([
                'student_id' => $data['student_id'],
                'from_category_id' => $data['from_category_id'],
                'to_category_id' => $data['to_category_id'],
                'transferred_by' => $request->user()->id,
                'transferred_at' => $now,
            ]);
        });

        return response()->json([
            'transfer' => $transfer->load(['fromCategory:id,name', 'toCategory:id,name']),
        ], 201);
    }

    private function eligibleRows(array $filters): Builder
    {
        $query = DB::table('student_category_progress as scp')
            ->join('users as u', 'u.id', '=', 'scp.student_id')
            ->join('categories as c', 'c.id', '=', 'scp.category_id')
            ->join('pace_groups as pg', 'pg.code', '=', 'u.pace_group')
            ->where('u.role', 'student')
            ->whereExists(TransferEligibility::eligibleCategoriesFor('u.id')
                ->whereColumn('eligible_categories.id', 'scp.category_id'))
            ->leftJoin('student_category_transfers as transfers', fn ($join) => $join
                ->on('transfers.student_id', '=', 'scp.student_id')
                ->on('transfers.from_category_id', '=', 'scp.category_id'))
            ->leftJoin('categories as to_category', 'to_category.id', '=', 'transfers.to_category_id')
            ->when($filters['category_id'] ?? null, fn (Builder $query, $id) => $query->where('scp.category_id', $id))
            ->when($filters['lesson_id'] ?? null, function (Builder $query, $id): void {
                $query->whereExists(DB::table('student_lesson_progress as lesson_filter')
                    ->whereColumn('lesson_filter.student_id', 'scp.student_id')
                    ->where('lesson_filter.lesson_id', $id)
                    ->where('lesson_filter.is_completed', true));
            })
            ->when($filters['pace_group'] ?? null, fn (Builder $query, $code) => $query->where('u.pace_group', $code));

        if (isset($filters['date_from'])) {
            $query->where('scp.completed_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (isset($filters['date_to'])) {
            $query->where('scp.completed_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        return $query;
    }

    private function validateDateRange(array $filters): void
    {
        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }
    }
}
