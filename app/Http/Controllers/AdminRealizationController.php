<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentRealization;
use App\Models\ZoomBatch;
use App\Support\HistoricalBatchScope;
use App\Services\ReportXlsxExporter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class AdminRealizationController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'student' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'batch_id' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'in:xlsx'],
        ]);

        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }

        $query = StudentRealization::query()
            ->join('users', 'users.id', '=', 'student_realizations.student_id')
            ->join('lessons', 'lessons.id', '=', 'student_realizations.lesson_id')
            ->join('categories', 'categories.id', '=', 'lessons.category_id')
            ->where('users.role', 'student')
            ->when(trim($filters['student'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $term = trim($filters['student']);
                $query->where(fn (Builder $students) => $students
                    ->where('users.name', 'like', '%'.$term.'%')
                    ->orWhere('users.email', 'like', '%'.$term.'%')
                    ->orWhere('users.pseudocode', 'like', '%'.$term.'%'));
            })
            ->when($filters['category_id'] ?? null, fn (Builder $query, $id) => $query->where('lessons.category_id', $id))
            ->when($filters['lesson_id'] ?? null, fn (Builder $query, $id) => $query->where('student_realizations.lesson_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->where('student_realizations.submitted_at', '>=', $date.' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->where('student_realizations.submitted_at', '<', Carbon::parse($date)->addDay()->startOfDay()));

        HistoricalBatchScope::join($query->getQuery(), 'student_realizations.student_id', 'student_realizations.submitted_at');
        HistoricalBatchScope::filter($query->getQuery(), $filters['batch_id'] ?? null);

        $realizationQuery = (clone $query)->orderByDesc('student_realizations.submitted_at')->orderByDesc('student_realizations.id')
            ->select([
                'student_realizations.id', 'student_realizations.student_id', 'student_realizations.lesson_id',
                'student_realizations.realization', 'student_realizations.submitted_at',
                'users.name as student_name', 'users.email as student_email', 'users.pseudocode',
                'lessons.title as lesson_title', 'lessons.category_id',
                'categories.name as category_name',
                'report_batch_membership.zoom_batch_id as batch_id', 'report_batch.name as batch_name',
            ]);

        if (($filters['format'] ?? null) === 'xlsx') {
            return app(ReportXlsxExporter::class)->download('realizations.xlsx', 'Realizations', [
                ['key' => 'batch_name', 'label' => 'Batch'], ['key' => 'batch_id', 'label' => 'Batch ID', 'type' => 'number'],
                ['key' => 'student_name', 'label' => 'Student'], ['key' => 'pseudocode', 'label' => 'Pseudocode'],
                ['key' => 'category_name', 'label' => 'Category'], ['key' => 'lesson_title', 'label' => 'Lesson'],
                ['key' => 'realization', 'label' => 'Realization'], ['key' => 'submitted_at', 'label' => 'Submitted', 'type' => 'date'],
            ], $realizationQuery->get(), ['Student' => $filters['student'] ?? 'All', 'Batch ID' => $filters['batch_id'] ?? 'All', 'Category ID' => $filters['category_id'] ?? 'All', 'Lesson ID' => $filters['lesson_id'] ?? 'All', 'From' => $filters['date_from'] ?? 'All', 'To' => $filters['date_to'] ?? 'All']);
        }
        $studentIds = (clone $query)->select('student_realizations.student_id')->distinct()->orderBy('student_realizations.student_id');
        $studentPage = $studentIds->paginate(25)->withQueryString();
        $allForPage = collect();
        $batchForPage = collect();
        if ($studentPage->count()) {
            $pageIds = $studentPage->pluck('student_id');
            $batchForPage = (clone $query)->whereIn('student_realizations.student_id', $pageIds)
                ->orderByDesc('student_realizations.submitted_at')->select([
                    'student_realizations.student_id', 'report_batch_membership.zoom_batch_id as batch_id', 'report_batch.name as batch_name',
                ])->get()->unique('student_id')->keyBy('student_id');
            $allForPage = StudentRealization::query()
                ->join('users', 'users.id', '=', 'student_realizations.student_id')
                ->join('lessons', 'lessons.id', '=', 'student_realizations.lesson_id')
                ->join('categories', 'categories.id', '=', 'lessons.category_id')
                ->whereIn('student_realizations.student_id', $pageIds)
                ->where('users.role', 'student')
                ->orderByDesc('student_realizations.submitted_at')->orderByDesc('student_realizations.id')
                ->select(['student_realizations.id', 'student_realizations.student_id', 'student_realizations.lesson_id',
                    'student_realizations.realization', 'student_realizations.submitted_at', 'users.name as student_name',
                    'users.email as student_email', 'users.pseudocode', 'lessons.title as lesson_title', 'lessons.category_id',
                    'categories.name as category_name'])->get()->groupBy('student_id');
        }
        $studentPage->setCollection($studentPage->getCollection()->map(function ($student) use ($allForPage, $batchForPage): array {
            $submissions = $allForPage->get($student->student_id, collect());
            $first = $submissions->first();
            $batch = $batchForPage->get($student->student_id);
            return ['id' => $student->student_id, 'student_id' => $student->student_id,
                'student_name' => $first?->student_name, 'student_email' => $first?->student_email,
                'pseudocode' => $first?->pseudocode, 'realization_count' => $submissions->count(),
                'batch_name' => $batch?->batch_name ?? 'No batch assigned', 'batch_id' => $batch?->batch_id,
                'realizations' => $submissions->map(fn ($item) => [
                    'id' => $item->id, 'lesson_id' => $item->lesson_id, 'lesson_title' => $item->lesson_title,
                    'category_name' => $item->category_name, 'realization' => $item->realization,
                    'submitted_at' => $item->submitted_at,
                ])->values()];
        }));
        $realizations = $studentPage;

        $categories = Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']);
        $lessons = empty($filters['category_id']) ? collect() : Lesson::withTrashed()
            ->where('category_id', $filters['category_id'])->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'category_id', 'title', 'deleted_at']);

        return response()->json([
            'realizations' => $realizations,
            'filters' => ['categories' => $categories, 'lessons' => $lessons, 'batches' => ZoomBatch::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at'])],
        ]);
    }
}
