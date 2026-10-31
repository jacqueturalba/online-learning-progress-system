<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentCategoryProgress extends Model
{
    protected $table = 'student_category_progress';
    protected $fillable = ['student_id', 'category_id', 'started_at', 'completed_at'];
    protected function casts(): array { return ['started_at' => 'datetime', 'completed_at' => 'datetime']; }

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function category() { return $this->belongsTo(Category::class)->withTrashed(); }

    public static function refreshForStudentCategory(int $studentId, int $categoryId): void
    {
        $lessonIds = Lesson::where('category_id', $categoryId)->where('is_published', true)->pluck('id');
        $lessonCount = $lessonIds->count();
        $completedCount = $lessonCount === 0 ? 0 : StudentLessonProgress::where('student_id', $studentId)
            ->whereIn('lesson_id', $lessonIds)
            ->where('is_completed', true)
            ->count();
        $isComplete = $lessonCount > 0 && $completedCount === $lessonCount;

        $categoryProgress = self::firstOrNew(['student_id' => $studentId, 'category_id' => $categoryId]);
        $categoryProgress->started_at ??= now();
        $categoryProgress->completed_at = $isComplete ? ($categoryProgress->completed_at ?? now()) : null;
        $categoryProgress->save();
    }

    public static function refreshForCategory(int $categoryId): void
    {
        $lessonIds = Lesson::where('category_id', $categoryId)->where('is_published', true)->pluck('id');
        $lessonCount = $lessonIds->count();

        foreach (self::where('category_id', $categoryId)->get() as $categoryProgress) {
            $completedCount = $lessonCount === 0 ? 0 : StudentLessonProgress::where('student_id', $categoryProgress->student_id)
                ->whereIn('lesson_id', $lessonIds)
                ->where('is_completed', true)
                ->count();

            $isComplete = $lessonCount > 0 && $completedCount === $lessonCount;
            $categoryProgress->completed_at = $isComplete ? ($categoryProgress->completed_at ?? now()) : null;
            $categoryProgress->save();
        }
    }
}
