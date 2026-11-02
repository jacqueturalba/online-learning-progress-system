<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentCategoryProgress;
use App\Models\StudentCategoryTransfer;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function transferAnalysisUser(string $role, string $email, string $pace = 'B'): User
{
    return User::create([
        'name' => ucfirst($role).' Transfer Tester',
        'email' => $email,
        'password' => Hash::make('StrongPass123!'),
        'role' => $role,
        'pace_group' => $pace,
    ]);
}

function transferAnalysisCategory(string $name, int $order): Category
{
    return Category::create(['name' => $name, 'sort_order' => $order, 'is_active' => true]);
}

function completeTransferAnalysisCategory(User $student, Category $category, string $lessonTitle = 'Lesson one'): Lesson
{
    $sortOrder = Lesson::where('category_id', $category->id)->count() + 1;
    $lesson = Lesson::create([
        'category_id' => $category->id,
        'title' => $lessonTitle,
        'sort_order' => $sortOrder,
        'duration_seconds' => 120,
        'is_published' => true,
    ]);
    $completedAt = now()->subDay();
    StudentLessonProgress::create([
        'student_id' => $student->id,
        'lesson_id' => $lesson->id,
        'is_completed' => true,
        'completed_at' => $completedAt,
    ]);
    StudentCategoryProgress::create([
        'student_id' => $student->id,
        'category_id' => $category->id,
        'started_at' => $completedAt->copy()->subDay(),
        'completed_at' => $completedAt,
    ]);

    return $lesson;
}

it('reports eligibility independently from explicit transfer records', function () {
    $admin = transferAnalysisUser('admin', 'transfer-analysis-admin@example.test');
    $student = transferAnalysisUser('student', 'transfer-analysis-student@example.test');
    $source = transferAnalysisCategory('Completed category', 1);
    completeTransferAnalysisCategory($student, $source);

    $this->actingAs($admin)->getJson('/api/admin/reports?report=transfers')->assertOk()
        ->assertJsonPath('transfer_summary.eligible_students', 1)
        ->assertJsonPath('transfer_summary.transferred_students', 0)
        ->assertJsonPath('transfer_summary.not_transferred_students', 1)
        ->assertJsonPath('transfer_summary.transfer_rate', 0)
        ->assertJsonPath('rows.data.0.is_transfer_eligible', 1)
        ->assertJsonPath('rows.data.0.is_transferred', 0)
        ->assertJsonPath('rows.data.0.transfer_id', null);

    expect(StudentCategoryTransfer::count())->toBe(0);
});

it('records an explicit transfer only for eligible students and reports it once', function () {
    $admin = transferAnalysisUser('admin', 'transfer-record-admin@example.test');
    $student = transferAnalysisUser('student', 'transfer-record-student@example.test');
    $source = transferAnalysisCategory('Source category', 1);
    $destination = transferAnalysisCategory('Destination category', 2);
    completeTransferAnalysisCategory($student, $source);

    $payload = ['student_id' => $student->id, 'from_category_id' => $source->id, 'to_category_id' => $destination->id];
    $this->actingAs($admin)->postJson('/api/admin/transfers', $payload)->assertCreated()
        ->assertJsonPath('transfer.from_category_id', $source->id)
        ->assertJsonPath('transfer.to_category_id', $destination->id)
        ->assertJsonPath('transfer.transferred_by', $admin->id);

    $this->postJson('/api/admin/transfers', $payload)->assertStatus(409);
    $this->getJson('/api/admin/reports?report=transfers')->assertOk()
        ->assertJsonPath('transfer_summary.eligible_students', 1)
        ->assertJsonPath('transfer_summary.transferred_students', 1)
        ->assertJsonPath('transfer_summary.not_transferred_students', 0)
        ->assertJsonPath('transfer_summary.transfer_rate', 100)
        ->assertJsonPath('rows.data.0.is_transferred', 1)
        ->assertJsonPath('rows.data.0.transferred_to_category', $destination->name);

    expect(StudentCategoryTransfer::count())->toBe(1);
});

it('rejects transfers for incomplete categories and protects the endpoint from students', function () {
    $admin = transferAnalysisUser('admin', 'transfer-invalid-admin@example.test');
    $student = transferAnalysisUser('student', 'transfer-invalid-student@example.test');
    $source = transferAnalysisCategory('Incomplete category', 1);
    $destination = transferAnalysisCategory('Another category', 2);
    Lesson::create(['category_id' => $source->id, 'title' => 'Unfinished', 'sort_order' => 1, 'duration_seconds' => 120, 'is_published' => true]);
    $payload = ['student_id' => $student->id, 'from_category_id' => $source->id, 'to_category_id' => $destination->id];

    $this->actingAs($admin)->postJson('/api/admin/transfers', $payload)->assertStatus(422);
    expect(StudentCategoryTransfer::count())->toBe(0);

    auth()->logout();
    $this->actingAs($student)->getJson('/api/admin/reports?report=transfers')->assertForbidden();
    $this->postJson('/api/admin/transfers', $payload)->assertForbidden();
});

it('applies category lesson date and pace filters to the eligible cohort', function () {
    $admin = transferAnalysisUser('admin', 'transfer-filter-admin@example.test');
    $included = transferAnalysisUser('student', 'transfer-filter-included@example.test', 'A');
    $excluded = transferAnalysisUser('student', 'transfer-filter-excluded@example.test', 'B');
    $category = transferAnalysisCategory('Filtered category', 1);
    $lesson = completeTransferAnalysisCategory($included, $category, 'Target lesson');
    $otherLesson = completeTransferAnalysisCategory($excluded, $category, 'Other lesson');
    $completedAt = now()->subDay();
    StudentLessonProgress::create(['student_id' => $included->id, 'lesson_id' => $otherLesson->id, 'is_completed' => true, 'completed_at' => $completedAt]);
    StudentLessonProgress::create(['student_id' => $excluded->id, 'lesson_id' => $lesson->id, 'is_completed' => true, 'completed_at' => $completedAt]);
    StudentCategoryProgress::where('student_id', $excluded->id)->where('category_id', $category->id)->update(['completed_at' => now()->subDays(10)]);
    $date = now()->subDay()->toDateString();

    $response = $this->actingAs($admin)->getJson('/api/admin/reports?'.http_build_query([
        'report' => 'transfers', 'category_id' => $category->id, 'lesson_id' => $lesson->id,
        'date_from' => $date, 'date_to' => $date, 'pace_group' => 'A',
    ]))->assertOk();

    $response->assertJsonPath('transfer_summary.eligible_students', 1)
        ->assertJsonPath('rows.data.0.student_id', $included->id)
        ->assertJsonPath('filters.lessons.0.id', $lesson->id);
});
