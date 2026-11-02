<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class StudentLessonAccess
{
    public static function allows(int $studentId, int $lessonId): bool
    {
        $target = DB::table('lessons as l')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('l.id', $lessonId)
            ->where('l.is_published', true)
            ->where('c.is_active', true)
            ->whereNull('c.deleted_at')
            ->whereNull('l.deleted_at')
            ->first(['l.id', 'l.category_id', 'l.sort_order', 'c.sort_order as category_sort_order']);

        if (!$target) return false;

        $previousLessons = DB::table('lessons as l')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('l.is_published', true)
            ->where('c.is_active', true)
            ->whereNull('c.deleted_at')
            ->whereNull('l.deleted_at')
            ->where(function ($query) use ($target): void {
                $query->where('c.sort_order', '<', $target->category_sort_order)
                    ->orWhere(function ($query) use ($target): void {
                        $query->where('c.sort_order', $target->category_sort_order)
                            ->where('c.id', '<', $target->category_id);
                    })
                    ->orWhere(function ($query) use ($target): void {
                        $query->where('c.id', $target->category_id)
                            ->where('l.sort_order', '<', $target->sort_order);
                    })
                    ->orWhere(function ($query) use ($target): void {
                        $query->where('c.id', $target->category_id)
                            ->where('l.sort_order', $target->sort_order)
                            ->where('l.id', '<', $target->id);
                    });
            });

        $previousIds = (clone $previousLessons)->pluck('l.id');
        if ($previousIds->isEmpty()) return true;

        $completedCount = DB::table('student_lesson_progress')
            ->where('student_id', $studentId)
            ->whereIn('lesson_id', $previousIds)
            ->where('is_completed', true)
            ->count();
        if ($completedCount !== $previousIds->count()) return false;

        $realizationCount = DB::table('student_realizations')
            ->where('student_id', $studentId)
            ->whereIn('lesson_id', $previousIds)
            ->distinct('lesson_id')
            ->count('lesson_id');

        return $realizationCount === $previousIds->count();
    }
}
