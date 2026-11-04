<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentRealization;
use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function realizationStudent(string $email): User
{
    return User::create(['name' => 'Realization Student', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
}

function realizationAdmin(): User
{
    return User::create(['name' => 'Realization Admin', 'email' => 'realization-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
}

function realizationLesson(Category $category, string $title): Lesson
{
    return Lesson::create(['category_id' => $category->id, 'title' => $title, 'sort_order' => 1, 'is_published' => true]);
}

it('stores a realization for the authenticated student and rejects invalid submissions', function () {
    $student = realizationStudent('submission-owner@example.test');
    $category = Category::create(['name' => 'Submission Category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = realizationLesson($category, 'Submission lesson');

    $this->actingAs($student)->postJson("/api/lessons/{$lesson->id}/realizations", [
        'student_id' => 999999,
        'realization' => 'I applied the skill in practice.',
    ])->assertCreated()->assertJsonPath('realization.lesson_id', $lesson->id);

    $saved = StudentRealization::firstOrFail();
    expect($saved->student_id)->toBe($student->id)
        ->and($saved->realization)->toBe('I applied the skill in practice.');

    $this->postJson("/api/lessons/{$lesson->id}/realizations", ['realization' => ' '])
        ->assertUnprocessable()->assertJsonValidationErrors('realization');
});

it('requires an authorized admin to view realization monitoring', function () {
    $this->getJson('/api/admin/realizations')->assertUnauthorized();
    $this->actingAs(realizationStudent('blocked-realization@example.test'))->getJson('/api/admin/realizations')->assertForbidden();
});

it('filters persisted realizations by student, category, lesson, and submitted date', function () {
    $admin = realizationAdmin();
    $student = realizationStudent('learner-one@example.test');
    $otherStudent = realizationStudent('learner-two@example.test');
    $category = Category::create(['name' => 'Realization Category', 'sort_order' => 1, 'is_active' => true]);
    $otherCategory = Category::create(['name' => 'Other Realization Category', 'sort_order' => 2, 'is_active' => true]);
    $lesson = realizationLesson($category, 'Reflection lesson');
    $otherLesson = realizationLesson($otherCategory, 'Other lesson');
    $match = StudentRealization::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'realization' => 'I practiced the key idea.', 'submitted_at' => now()->startOfDay()->addHours(10)]);
    StudentRealization::create(['student_id' => $otherStudent->id, 'lesson_id' => $otherLesson->id, 'realization' => 'A different response.', 'submitted_at' => now()->subDays(5)]);

    $response = $this->actingAs($admin)->getJson('/api/admin/realizations?student='.$student->pseudocode.'&category_id='.$category->id.'&lesson_id='.$lesson->id.'&date_from='.now()->toDateString().'&date_to='.now()->toDateString())
        ->assertOk()->assertJsonCount(1, 'realizations.data')
        ->assertJsonPath('realizations.data.0.id', $match->id)
        ->assertJsonPath('realizations.data.0.student_name', $student->name)
        ->assertJsonPath('realizations.data.0.pseudocode', $student->pseudocode)
        ->assertJsonPath('realizations.data.0.category_name', $category->name)
        ->assertJsonPath('realizations.data.0.lesson_title', $lesson->title)
        ->assertJsonPath('realizations.data.0.realization', 'I practiced the key idea.');
    expect($response->json('realizations.per_page'))->toBe(25);
});

it('paginates realization results and retains history when a lesson is archived', function () {
    $admin = realizationAdmin();
    $student = realizationStudent('retained-realization@example.test');
    $category = Category::create(['name' => 'History Category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = realizationLesson($category, 'Archived reflection');
    $record = StudentRealization::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'realization' => 'Kept for the record.', 'submitted_at' => now()]);
    $lesson->delete();

    $this->actingAs($admin)->getJson('/api/admin/realizations')->assertOk()
        ->assertJsonCount(1, 'realizations.data')->assertJsonPath('realizations.data.0.id', $record->id)
        ->assertJsonPath('realizations.data.0.lesson_title', $lesson->title);
    expect(StudentRealization::find($record->id))->not->toBeNull();
});

it('attributes realizations to the historical batch and exports all filtered rows as XLSX', function () {
    $admin = realizationAdmin();
    $student = realizationStudent('historical-batch@example.test');
    $category = Category::create(['name' => 'Historical batch category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = realizationLesson($category, 'Historical lesson');
    $oldBatch = ZoomBatch::create(['name' => 'First batch', 'status' => 'completed', 'capacity' => 30]);
    $newBatch = ZoomBatch::create(['name' => 'Second batch', 'status' => 'active', 'capacity' => 30]);
    ZoomBatchMembership::create(['zoom_batch_id' => $oldBatch->id, 'student_id' => $student->id, 'status' => 'transferred', 'joined_at' => now()->subDays(10), 'ended_at' => now()->subDays(2)]);
    ZoomBatchMembership::create(['zoom_batch_id' => $newBatch->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => now()->subDays(2)]);
    $record = StudentRealization::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'realization' => 'Historical submission content.', 'submitted_at' => now()->subDays(5)]);

    $this->actingAs($admin)->getJson('/api/admin/realizations')
        ->assertOk()->assertJsonPath('realizations.data.0.batch_id', $oldBatch->id)
        ->assertJsonPath('realizations.data.0.batch_name', $oldBatch->name);

    $response = $this->actingAs($admin)->get('/api/admin/realizations?format=xlsx&batch_id='.$oldBatch->id);
    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->getContent())->toStartWith("PK\x03\x04")
        ->and($response->getContent())->toContain('Historical submission content.')
        ->and($response->getContent())->toContain($oldBatch->name)
        ->and($response->getContent())->not->toContain($newBatch->name);
    expect(StudentRealization::find($record->id))->not->toBeNull();
});

