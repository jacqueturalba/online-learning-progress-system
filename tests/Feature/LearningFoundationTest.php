<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function makeStudent(string $email = 'student@example.test'): User
{
    return User::create(['name' => 'Test Student', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
}

function makeLesson(): Lesson
{
    $category = Category::create(['name' => 'Basic', 'sort_order' => 1]);
    return Lesson::create(['category_id' => $category->id, 'title' => 'Lesson one', 'duration_seconds' => 100, 'sort_order' => 1, 'is_published' => true]);
}

it('registers students and starts an authenticated session', function () {
    $response = $this->postJson('/register', ['name' => 'New Student', 'email' => 'new@example.test', 'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!']);
    $response->assertCreated()->assertJsonPath('user.role', 'student');
    $this->assertAuthenticated();
});

it('allows login and logout', function () {
    $student = makeStudent();
    $this->postJson('/login', ['email' => $student->email, 'password' => 'StrongPass123!'])->assertOk();
    $this->assertAuthenticatedAs($student);
    $this->postJson('/logout')->assertOk();
    $this->assertGuest();
});

it('requires authentication to read learning data', function () { $this->getJson('/api/learning')->assertUnauthorized(); });

it('limits lesson progress to the signed-in student', function () {
    $lesson = makeLesson(); $owner = makeStudent(); $other = makeStudent('other@example.test');
    $this->actingAs($owner)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 70])->assertOk()->assertJsonPath('progress.student_id', $owner->id);
    expect(StudentLessonProgress::where('student_id', $other->id)->count())->toBe(0);
    $this->actingAs($other)->getJson('/api/learning')->assertOk()->assertJsonPath('categories.0.lessons.0.progress', null);
});

it('uses category relationships and calculates completion on the backend', function () {
    $lesson = makeLesson(); $student = makeStudent();
    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 90])->assertOk()->assertJsonPath('progress.is_completed', true);
    expect($lesson->fresh()->category->name)->toBe('Basic');
    expect(StudentLessonProgress::first()->progress_percentage)->toEqual(90);
});

it('creates and updates one progress record per student and lesson', function () {
    $lesson = makeLesson(); $student = makeStudent();
    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 20])->assertOk();
    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 50])->assertOk()->assertJsonPath('progress.progress_percentage', '50.00');
    expect(StudentLessonProgress::count())->toBe(1);
});
