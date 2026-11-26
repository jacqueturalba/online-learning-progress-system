<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentCategoryProgress;
use App\Models\StudentLessonProgress;
use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function currentBatchReportUser(string $role, string $email): User
{
    return User::create([
        'name' => ucfirst($role).' Batch Report User',
        'email' => $email,
        'password' => Hash::make('StrongPass123!'),
        'role' => $role,
        'pace_group' => 'B',
    ]);
}

function currentBatchReportMembership(ZoomBatch $batch, User $student, string $status = 'active'): ZoomBatchMembership
{
    return ZoomBatchMembership::create([
        'zoom_batch_id' => $batch->id,
        'student_id' => $student->id,
        'status' => $status,
        'joined_at' => now(),
        'ended_at' => $status === 'active' ? null : now(),
    ]);
}

it('filters historical learning activity by current batch across report tabs and exports', function () {
    $admin = currentBatchReportUser('admin', 'current-batch-report-admin@example.test');
    $assigned = currentBatchReportUser('student', 'current-batch-report-assigned@example.test');
    $unassigned = currentBatchReportUser('student', 'current-batch-report-unassigned@example.test');
    $assigned->update(['name' => 'Assigned Historical Learner']);
    $unassigned->update(['name' => 'Unassigned Historical Learner']);
    $batch = ZoomBatch::create(['name' => 'Current activity batch', 'status' => 'active']);
    currentBatchReportMembership($batch, $assigned);

    $category = Category::create(['name' => 'Historical activity category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create([
        'category_id' => $category->id,
        'title' => 'Completed before assignment',
        'sort_order' => 1,
        'duration_seconds' => 120,
        'is_published' => true,
    ]);
    $activityDate = now()->subDays(10);

    foreach ([$assigned, $unassigned] as $student) {
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'is_completed' => true,
            'completed_at' => $activityDate,
            'last_watched_at' => $activityDate,
        ]);
        StudentCategoryProgress::create([
            'student_id' => $student->id,
            'category_id' => $category->id,
            'started_at' => $activityDate,
            'completed_at' => $activityDate,
        ]);
        DB::table('student_realizations')->insert([
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'realization' => 'Local test content',
            'submitted_at' => $activityDate,
            'created_at' => $activityDate,
            'updated_at' => $activityDate,
        ]);
    }

    $this->actingAs($admin);
    foreach (['lesson_completion', 'student_completion', 'category_progress', 'realizations'] as $report) {
        $response = $this->getJson('/api/admin/reports?'.http_build_query([
            'report' => $report,
            'batch_id' => $batch->id,
            'category_id' => $category->id,
            'date_from' => $activityDate->toDateString(),
            'date_to' => $activityDate->toDateString(),
        ]))->assertOk();

        expect($response->json('rows.total'))->toBe(1)
            ->and($response->json('rows.data.0.batch_id'))->toBe($batch->id)
            ->and($response->json('rows.data.0.batch_name'))->toBe($batch->name);

        if ($report !== 'lesson_completion') {
            expect($response->json('rows.data.0.student_id'))->toBe($assigned->id);
        }
    }

    $noBatch = $this->getJson('/api/admin/reports?'.http_build_query([
        'report' => 'student_completion', 'batch_id' => 'none', 'category_id' => $category->id,
    ]))->assertOk();
    expect($noBatch->json('rows.total'))->toBe(1)
        ->and($noBatch->json('rows.data.0.student_id'))->toBe($unassigned->id)
        ->and($noBatch->json('rows.data.0.batch_id'))->toBeNull();

    $allBatches = $this->getJson('/api/admin/reports?'.http_build_query([
        'report' => 'student_completion', 'category_id' => $category->id,
    ]))->assertOk();
    expect($allBatches->json('rows.total'))->toBe(2);

    $excel = $this->get('/api/admin/reports?'.http_build_query([
        'report' => 'student_completion', 'format' => 'xlsx', 'batch_id' => $batch->id, 'category_id' => $category->id,
    ]))->assertOk();
    expect($excel->getContent())->toStartWith("PK\x03\x04")
        ->and($excel->getContent())->toContain($assigned->name)
        ->and($excel->getContent())->not->toContain($unassigned->name);
});

it('attributes activity to the latest active membership and does not duplicate rows', function () {
    $admin = currentBatchReportUser('admin', 'latest-current-batch-admin@example.test');
    $student = currentBatchReportUser('student', 'latest-current-batch-student@example.test');
    $oldBatch = ZoomBatch::create(['name' => 'Earlier current batch', 'status' => 'active']);
    $newBatch = ZoomBatch::create(['name' => 'Latest current batch', 'status' => 'active']);
    currentBatchReportMembership($oldBatch, $student);
    currentBatchReportMembership($newBatch, $student);

    $category = Category::create(['name' => 'Transfer activity category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'One completion', 'sort_order' => 1, 'duration_seconds' => 90, 'is_published' => true]);
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'is_completed' => true, 'completed_at' => now()->subDay()]);

    $this->actingAs($admin)->getJson('/api/admin/reports?'.http_build_query([
        'report' => 'student_completion', 'batch_id' => $newBatch->id,
    ]))->assertOk()->assertJsonPath('rows.total', 1)
        ->assertJsonPath('rows.data.0.batch_id', $newBatch->id)
        ->assertJsonPath('rows.data.0.batch_name', $newBatch->name);

    $this->getJson('/api/admin/reports?'.http_build_query([
        'report' => 'student_completion', 'batch_id' => $oldBatch->id,
    ]))->assertOk()->assertJsonPath('rows.total', 0);
});
