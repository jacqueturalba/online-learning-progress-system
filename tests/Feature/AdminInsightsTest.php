<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentCategoryProgress;
use App\Models\StudentCategoryTransfer;
use App\Models\StudentLessonProgress;
use App\Models\StudentRealization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('provides structured database-backed insight summaries to admins', function () {
    $admin = User::create([
        'name' => 'Insights Admin', 'email' => 'insights-admin@example.test',
        'password' => Hash::make('StrongPass123!'), 'role' => 'admin',
    ]);
    $student = User::create([
        'name' => 'Insights Student', 'email' => 'insights-student@example.test',
        'password' => Hash::make('StrongPass123!'), 'role' => 'student', 'pace_group' => 'A',
    ]);
    DB::table('pace_groups')->where('code', 'A')->update(['weekly_target' => 2]);
    $category = Category::create(['name' => 'Insights category', 'sort_order' => 1, 'is_active' => true]);
    $completedAt = now()->subDays(2);
    $lessons = collect(['Lesson one', 'Lesson two'])->map(fn (string $title, int $index) => Lesson::create([
        'category_id' => $category->id, 'title' => $title, 'sort_order' => $index + 1,
        'duration_seconds' => 120, 'is_published' => true,
    ]));

    foreach ($lessons as $index => $lesson) {
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'started_at' => $completedAt->copy()->subHour(),
            'last_watched_at' => $completedAt->copy()->addDays($index),
            'progress_percentage' => 100,
            'is_completed' => true,
            'completed_at' => $completedAt->copy()->addDays($index),
        ]);
    }
    StudentCategoryProgress::create([
        'student_id' => $student->id, 'category_id' => $category->id,
        'started_at' => $completedAt->copy()->subDay(), 'completed_at' => $completedAt,
    ]);
    StudentCategoryTransfer::create([
        'student_id' => $student->id,
        'from_category_id' => $category->id,
        'to_category_id' => Category::create(['name' => 'Next category', 'sort_order' => 2, 'is_active' => true])->id,
        'transferred_by' => $admin->id,
        'transferred_at' => now(),
    ]);
    StudentRealization::create([
        'student_id' => $student->id, 'lesson_id' => $lessons->first()->id,
        'realization' => 'A learning reflection.', 'submitted_at' => $completedAt,
    ]);

    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();
    $response = $this->actingAs($admin)->getJson('/api/admin/insights/data?'.http_build_query([
        'date_from' => $from, 'date_to' => $to,
    ]))->assertOk();

    $response->assertJsonPath('insights.schema_version', '1.0')
        ->assertJsonPath('insights.student_progress.student_count', 1)
        ->assertJsonPath('insights.student_progress.lessons_completed', 2)
        ->assertJsonPath('insights.lesson_completion.0.students_completed', 1)
        ->assertJsonPath('insights.category_completion.0.students_completed', 1)
        ->assertJsonPath('insights.transfer_analysis.eligible_students', 1)
        ->assertJsonPath('insights.transfer_analysis.transferred_students', 1)
        ->assertJsonPath('insights.transfer_analysis.transfer_rate_percentage', 100)
        ->assertJsonPath('insights.realization_activity.submission_count', 1);

    $weeklyActivity = collect($response->json('insights.weekly_learning_activity'))
        ->first(fn (array $week): bool => $week['active_students'] > 0);
    expect($weeklyActivity)->not->toBeNull()
        ->and($weeklyActivity['active_students'])->toBe(1)
        ->and($weeklyActivity['lessons_completed'])->toBe(2)
        ->and(collect($response->json('insights.pace_group_performance'))->firstWhere('pace_group', 'A')['student_count'])->toBe(1)
        ->and(collect($response->json('insights.pace_group_performance'))->firstWhere('pace_group', 'A')['students_meeting_weekly_target'])->toBe(1);
});

it('requires admin authorization and validates the insight date range', function () {
    $student = User::create([
        'name' => 'Insights Student', 'email' => 'insights-auth-student@example.test',
        'password' => Hash::make('StrongPass123!'), 'role' => 'student',
    ]);

    $this->actingAs($student)->getJson('/api/admin/insights/data')->assertForbidden();
    $admin = User::create([
        'name' => 'Insights Admin', 'email' => 'insights-auth-admin@example.test',
        'password' => Hash::make('StrongPass123!'), 'role' => 'admin',
    ]);
    $this->actingAs($admin);
    $this->getJson('/api/admin/insights/data?date_from=2026-05-10&date_to=2026-05-01')->assertUnprocessable();
    $this->getJson('/api/admin/insights/data?date_from=2025-01-01&date_to=2026-10-31')->assertUnprocessable();
});
