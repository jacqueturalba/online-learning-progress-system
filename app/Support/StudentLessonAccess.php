<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class StudentLessonAccess
{
    public static function allows(int $studentId, int $lessonId): bool
    {
        $target = DB::table('lessons as l')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('l.id', $lessonId)->where('l.is_published', true)
            ->where('c.is_active', true)->whereNull('c.deleted_at')->whereNull('l.deleted_at')
            ->first(['l.id', 'l.category_id', 'l.sort_order', 'c.sort_order as category_sort_order', 'c.enable_first_lesson_access', 'c.unlock_all_lessons']);

        if (!$target) return false;

        $previousCategory = DB::table('categories')
            ->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('sort_order', '<', $target->category_sort_order)
                ->orWhere(fn ($query) => $query->where('sort_order', $target->category_sort_order)->where('id', '<', $target->category_id)))
            ->orderByDesc('sort_order')->orderByDesc('id')->first(['id']);

        $categoryAccessible = !$previousCategory || (bool) $target->enable_first_lesson_access || DB::table('student_category_progress')
            ->where('student_id', $studentId)->where('category_id', $previousCategory->id)->whereNotNull('completed_at')->exists();
        if (!$categoryAccessible) return false;
        if ((bool) $target->unlock_all_lessons) return true;

        $previousLesson = DB::table('lessons')
            ->where('category_id', $target->category_id)->where('is_published', true)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('sort_order', '<', $target->sort_order)
                ->orWhere(fn ($query) => $query->where('sort_order', $target->sort_order)->where('id', '<', $target->id)))
            ->orderByDesc('sort_order')->orderByDesc('id')->first(['id']);

        if (!$previousLesson) return true;

        return DB::table('student_lesson_progress as p')
            ->where('p.student_id', $studentId)->where('p.lesson_id', $previousLesson->id)->where('p.is_completed', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('student_realizations as r')->whereColumn('r.lesson_id', 'p.lesson_id')->where('r.student_id', $studentId))
            ->exists();
    }
}
