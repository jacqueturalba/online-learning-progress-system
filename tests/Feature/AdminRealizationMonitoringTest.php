<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentRealization;
use App\Models\User;
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

