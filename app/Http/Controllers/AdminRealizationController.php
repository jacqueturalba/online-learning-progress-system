<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentRealization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminRealizationController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'student' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
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

        $realizations = $query
            ->orderByDesc('student_realizations.submitted_at')->orderByDesc('student_realizations.id')
            ->select([
                'student_realizations.id', 'student_realizations.student_id', 'student_realizations.lesson_id',
                'student_realizations.realization', 'student_realizations.submitted_at',
                'users.name as student_name', 'users.pseudocode',
                'lessons.title as lesson_title', 'lessons.category_id',
                'categories.name as category_name',
            ])
            ->paginate(25)->withQueryString();

        $categories = Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']);
        $lessons = empty($filters['category_id']) ? collect() : Lesson::withTrashed()
            ->where('category_id', $filters['category_id'])->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'category_id', 'title', 'deleted_at']);

        return response()->json([
            'realizations' => $realizations,
            'filters' => ['categories' => $categories, 'lessons' => $lessons],
        ]);
    }
}
