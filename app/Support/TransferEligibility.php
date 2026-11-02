<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TransferEligibility
{
    /** Correlated category eligibility query shared by progress monitoring and transfer recording. */
    public static function eligibleCategoriesFor(string $studentColumn): Builder
    {
        $incompletePublishedLesson = DB::table('lessons as eligible_lessons')
            ->whereColumn('eligible_lessons.category_id', 'eligible_categories.id')
            ->where('eligible_lessons.is_published', true)->whereNull('eligible_lessons.deleted_at')
            ->whereNotExists(DB::table('student_lesson_progress as completion')
                ->whereColumn('completion.lesson_id', 'eligible_lessons.id')
                ->whereColumn('completion.student_id', $studentColumn)
                ->where('completion.is_completed', true));

        return DB::table('categories as eligible_categories')
            ->where('eligible_categories.is_active', true)->whereNull('eligible_categories.deleted_at')
            ->whereExists(DB::table('lessons as published_lessons')
                ->whereColumn('published_lessons.category_id', 'eligible_categories.id')
                ->where('published_lessons.is_published', true)->whereNull('published_lessons.deleted_at'))
            ->whereNotExists($incompletePublishedLesson);
    }
}
