<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentCategoryProgress;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function makeStudent(string $email = 'student@example.test'): User
{
    return User::create(['name' => 'Test Student', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
}

function makeAdmin(string $email = 'admin@example.test'): User
{
    return User::create(['name' => 'Test Admin', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
}

function makeCategory(string $name = 'Basic'): Category
{
    return Category::create(['name' => $name, 'sort_order' => 1, 'is_active' => true]);
}

function makeLesson(Category $category, string $title = 'Lesson one', int $sortOrder = 1, ?int $duration = 100): Lesson
{
    return Lesson::create(['category_id' => $category->id, 'title' => $title, 'duration_seconds' => $duration, 'sort_order' => $sortOrder, 'is_published' => true]);
}

it('registers a student and ignores an attempted admin role', function () {
    $response = $this->postJson('/register', ['name' => 'New Student', 'email' => 'new@example.test', 'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!', 'role' => 'admin', 'pseudocode' => 'STU-ATTACKER']);

    $response->assertCreated()->assertJsonPath('user.role', 'student');
    $this->assertAuthenticated();
    expect(User::where('email', 'new@example.test')->value('role'))->toBe('student');
    expect(User::where('email', 'new@example.test')->value('pseudocode'))->not->toBe('STU-ATTACKER');
    $response->assertJsonMissingPath('user.pseudocode');
    $this->getJson('/api/me')->assertJsonMissingPath('user.pseudocode')->assertJsonMissingPath('user.pace_group');
});

it('allows login and logout', function () {
    $student = makeStudent();

    $this->postJson('/login', ['email' => $student->email, 'password' => 'StrongPass123!'])->assertOk();
    $this->assertAuthenticatedAs($student);
    $this->postJson('/logout')->assertOk();
    $this->assertGuest();
});

it('requires authentication to read learning data', function () {
    $this->getJson('/api/learning')->assertUnauthorized();
});

it('separates student and admin routes', function () {
    $student = makeStudent();
    $admin = makeAdmin();

    $this->actingAs($student)->getJson('/api/admin/students')->assertForbidden();
    $this->actingAs($student)->getJson('/api/admin/lessons')->assertForbidden();
    $this->actingAs($student)->getJson('/api/admin/dashboard')->assertForbidden();
    $this->actingAs($admin)->getJson('/api/learning')->assertForbidden();
    $this->actingAs($admin)->getJson('/api/admin/lessons')->assertOk();
});


it('protects every admin workspace page with the admin role', function () {
    $sections = ['dashboard', 'students', 'categories', 'lessons', 'zoom', 'progress', 'realizations', 'reports', 'settings'];
    $paths = ['/admin', ...array_map(fn ($section) => '/admin/'.$section, $sections)];
    $this->withoutVite();

    foreach ($paths as $path) {
        $this->get($path)->assertRedirect('/');
    }

    $student = makeStudent();
    foreach ($paths as $path) {
        $this->actingAs($student)->get($path)->assertForbidden();
    }

    $admin = makeAdmin();
    foreach ($paths as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }
});

it('builds admin dashboard summaries from student learning records', function () {
    $admin = makeAdmin();
    $student = makeStudent();
    $inactiveStudent = makeStudent('inactive@example.test');
    $basic = makeCategory('Basic');
    $advanced = Category::create(['name' => 'Advanced', 'sort_order' => 2, 'is_active' => true]);
    $basicLesson = makeLesson($basic, 'Basic lesson');
    $advancedLesson = makeLesson($advanced, 'Advanced lesson');

    StudentLessonProgress::create([
        'student_id' => $student->id, 'lesson_id' => $basicLesson->id, 'last_position_seconds' => 100,
        'watched_seconds' => 100, 'progress_percentage' => 100, 'started_at' => now()->subHour(),
        'last_watched_at' => now()->subHour(), 'is_playing' => false, 'is_completed' => true, 'completed_at' => now()->subHour(),
    ]);
    StudentLessonProgress::create([
        'student_id' => $inactiveStudent->id, 'lesson_id' => $advancedLesson->id, 'last_position_seconds' => 20,
        'watched_seconds' => 20, 'progress_percentage' => 20, 'started_at' => now()->subDays(45),
        'last_watched_at' => now()->subDays(45), 'is_playing' => false, 'is_completed' => false,
    ]);
    StudentCategoryProgress::create(['student_id' => $student->id, 'category_id' => $basic->id, 'started_at' => now()->subHour(), 'completed_at' => now()->subHour()]);

    $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk()
        ->assertJsonPath('total_students', 2)
        ->assertJsonPath('active_students', 1)
        ->assertJsonPath('total_lessons', 2)
        ->assertJsonPath('completed_lessons', 1)
        ->assertJsonPath('transfer_eligible_students', 1)
        ->assertJsonPath('students_by_category.0.students_count', 1)
        ->assertJsonPath('students_by_category.1.students_count', 1)
        ->assertJsonPath('recent_activity.0.student_name', $student->name)
        ->assertJsonPath('recent_activity.0.lesson_title', $basicLesson->title);
});

it('lists students with server-side search, category and pace filters, and pagination', function () {
    $admin = makeAdmin();
    $category = makeCategory();
    $student = makeStudent('searchable@example.test');
    $student->update(['name' => 'Sam Searchable', 'pace_group' => 'A']);
    StudentCategoryProgress::create(['student_id' => $student->id, 'category_id' => $category->id, 'started_at' => now()]);

    foreach (range(1, 21) as $number) {
        makeStudent("page-{$number}@example.test");
    }

    $this->actingAs($admin)->getJson('/api/admin/students?search='.$student->pseudocode.'&category_id='.$category->id.'&pace_group=A')
        ->assertOk()->assertJsonPath('students.total', 1)->assertJsonPath('students.data.0.name', 'Sam Searchable')
        ->assertJsonPath('students.data.0.pseudocode', $student->pseudocode)
        ->assertJsonPath('students.data.0.pace_group', 'A')
        ->assertJsonPath('students.data.0.weekly_target', 4)
        ->assertJsonPath('students.data.0.current_category.name', $category->name);

    $this->getJson('/api/admin/students?search=searchable@example.test')->assertJsonPath('students.total', 1);
    $this->getJson('/api/admin/students?search=Sam%20Searchable')->assertJsonPath('students.total', 1);
    $this->getJson('/api/admin/students')->assertJsonPath('students.per_page', 20)->assertJsonPath('students.total', 22)->assertJsonPath('students.last_page', 2);
});

it('shows private student history and relevant Zoom sessions to admins and allows pace changes only', function () {
    $admin = makeAdmin();
    $student = makeStudent();
    $category = makeCategory();
    $lesson = makeLesson($category, 'Lesson with history');
    StudentLessonProgress::create([
        'student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 90,
        'watched_seconds' => 90, 'progress_percentage' => 90, 'started_at' => now()->subDay(),
        'last_watched_at' => now()->subHour(), 'is_playing' => false, 'is_completed' => true, 'completed_at' => now()->subHour(),
    ]);
    StudentCategoryProgress::create(['student_id' => $student->id, 'category_id' => $category->id, 'started_at' => now()->subDay(), 'completed_at' => now()->subHour()]);
    $zoom = \App\Models\ZoomSession::create([
        'category_id' => $category->id, 'title' => 'Category check-in', 'meeting_url' => 'https://zoom.example.test/meeting',
        'scheduled_at' => now()->addDay(), 'is_active' => true,
    ]);

    $this->getJson("/api/admin/students/{$student->id}")->assertUnauthorized();
    $this->actingAs($student)->getJson("/api/admin/students/{$student->id}")->assertForbidden();
    $details = $this->actingAs($admin)->getJson("/api/admin/students/{$student->id}")->assertOk()
        ->assertJsonPath('student.real_name', $student->name)
        ->assertJsonPath('student.email', $student->email)
        ->assertJsonPath('student.personal_information.real_name', $student->name)
        ->assertJsonPath('student.pseudocode', $student->pseudocode)
        ->assertJsonPath('student.current_category.name', $category->name)
        ->assertJsonPath('student.current_category.completed_lessons', 1)
        ->assertJsonPath('student.learning_history.0.lessons.0.title', $lesson->title)
        ->assertJsonPath('student.learning_history.0.lessons.0.is_completed', true)
        ->assertJsonPath('student.zoom_assignments.0.id', $zoom->id);

    $details->assertJsonPath('student.is_active', true);
    $this->putJson("/api/admin/students/{$student->id}/pace-group", ['pace_group' => 'A', 'pseudocode' => 'STU-CHANGED'])
        ->assertOk()->assertJsonPath('pace_group', 'A')->assertJsonPath('weekly_target', 4);
    expect($student->fresh()->pseudocode)->toBe($details->json('student.pseudocode'));
    expect($student->fresh()->pace_group)->toBe('A');

    $this->putJson("/api/admin/students/{$student->id}/pace-group", ['pace_group' => 'unbounded'])->assertUnprocessable()->assertJsonValidationErrors('pace_group');
    $this->actingAs($student)->putJson("/api/admin/students/{$student->id}/pace-group", ['pace_group' => 'C'])->assertForbidden();
    expect(fn () => $student->fresh()->forceFill(['pseudocode' => 'STU-CHANGED'])->save())->toThrow(LogicException::class);
    $this->actingAs($student)->getJson('/api/me')->assertJsonMissingPath('user.pseudocode')->assertJsonMissingPath('user.is_active');
});

it('saves playback only to the authenticated student and exposes only their progress', function () {
    $category = makeCategory();
    $lesson = makeLesson($category);
    $owner = makeStudent();
    $other = makeStudent('other@example.test');

    $this->actingAs($owner)->putJson("/api/lessons/{$lesson->id}/progress", [
        'student_id' => $other->id,
        'position_seconds' => 0,
        'is_playing' => false,
    ])->assertOk()->assertJsonPath('progress.student_id', $owner->id);

    expect(StudentLessonProgress::where('student_id', $owner->id)->count())->toBe(1);
    expect(StudentLessonProgress::where('student_id', $other->id)->count())->toBe(0);
    $this->actingAs($other)->getJson('/api/learning')->assertOk()->assertJsonPath('categories.0.lessons.0.progress', null);
});

it('saves, pauses, and returns the resume position without crediting paused time', function () {
    $category = makeCategory();
    $lesson = makeLesson($category);
    $student = makeStudent();

    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => true])->assertOk();
    $this->travel(10)->seconds();
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 10, 'is_playing' => false])
        ->assertOk()->assertJsonPath('progress.watched_seconds', 10)->assertJsonPath('progress.last_position_seconds', 10);

    $this->travel(60)->seconds();
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 10, 'is_playing' => false])->assertOk()->assertJsonPath('progress.watched_seconds', 10);
    $this->getJson('/api/learning')->assertJsonPath('categories.0.lessons.0.progress.last_position_seconds', 10);
});

it('does not grant watch time or completion for an arbitrary seek or duration claim', function () {
    $category = makeCategory();
    $lesson = makeLesson($category);
    $student = makeStudent();

    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 90, 'duration_seconds' => 1000, 'is_playing' => false])
        ->assertOk()->assertJsonPath('progress.watched_seconds', 0)->assertJsonPath('progress.is_completed', false);

    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => true])->assertOk();
    $this->travel(10)->seconds();
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 100, 'is_playing' => true])
        ->assertOk()->assertJsonPath('progress.watched_seconds', 0)->assertJsonPath('progress.is_completed', false);
    $this->travel(10)->seconds();
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 100, 'is_playing' => false])
        ->assertOk()->assertJsonPath('progress.is_completed', false);
});

it('completes after server-timed playback and keeps one progress record on repeated completion', function () {
    $category = makeCategory();
    $lesson = makeLesson($category);
    $student = makeStudent();

    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => true])->assertOk();
    for ($second = 10; $second <= 90; $second += 10) {
        $this->travel(10)->seconds();
        $response = $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => $second, 'is_playing' => true]);
        $response->assertOk()->assertJsonPath('progress.watched_seconds', $second);
        $response->assertJsonPath('progress.is_completed', $second >= 90);
    }

    $completedAt = StudentLessonProgress::firstOrFail()->completed_at;
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 90, 'is_playing' => false])
        ->assertOk()->assertJsonPath('progress.is_completed', true);

    expect(StudentLessonProgress::count())->toBe(1);
    expect(StudentLessonProgress::firstOrFail()->completed_at->equalTo($completedAt))->toBeTrue();
});

it('marks categories complete from their published lessons and clears stale completion when a lesson is added', function () {
    $category = makeCategory();
    $first = makeLesson($category, 'Lesson one', 1);
    $student = makeStudent();
    $completeLesson = function (Lesson $lesson) use ($student) {
        $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => true])->assertOk();
        for ($second = 10; $second <= 90; $second += 10) {
            $this->travel(10)->seconds();
            $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => $second, 'is_playing' => true])->assertOk();
        }
    };

    $completeLesson($first);
    expect(StudentCategoryProgress::where('student_id', $student->id)->where('category_id', $category->id)->value('completed_at'))->not->toBeNull();

    $this->actingAs(makeAdmin())->postJson('/api/admin/lessons', [
        'category_id' => $category->id, 'title' => 'Lesson two', 'video_url' => 'https://videos.example.test/two.mp4', 'sort_order' => 2, 'duration_seconds' => 100, 'is_published' => true,
    ])->assertCreated();

    expect(StudentCategoryProgress::where('student_id', $student->id)->where('category_id', $category->id)->value('completed_at'))->toBeNull();
});

it('rejects publishing a lesson without a positive duration', function () {
    $admin = makeAdmin();
    $category = makeCategory();

    $this->actingAs($admin)->postJson('/api/admin/lessons', [
        'category_id' => $category->id, 'title' => 'Missing duration', 'video_url' => 'https://videos.example.test/lesson.mp4', 'is_published' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('duration_seconds');
});

it('manages lesson search, status, ordering, and video publication validation', function () {
    $admin = makeAdmin();
    $category = makeCategory();
    $first = makeLesson($category, 'First lesson', 1);
    $second = makeLesson($category, 'Second lesson', 2);

    $this->actingAs($admin)->getJson('/api/admin/lessons?search=Second&category_id='.$category->id.'&status=published')
        ->assertOk()->assertJsonCount(1, 'lessons.data')->assertJsonPath('lessons.data.0.id', $second->id);

    $this->postJson('/api/admin/lessons', ['category_id' => $category->id, 'title' => 'Bad URL', 'video_url' => 'not-a-url', 'is_published' => true, 'duration_seconds' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors('video_url');
    $this->postJson('/api/admin/lessons', ['category_id' => $category->id, 'title' => 'Duplicate order', 'video_url' => 'https://videos.example.test/duplicate.mp4', 'sort_order' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('sort_order');

    $this->patchJson("/api/admin/lessons/{$second->id}/move", ['direction' => 'up'])->assertOk();
    expect($second->fresh()->sort_order)->toBe(1);
    expect($first->fresh()->sort_order)->toBe(2);
});
it('archives lessons and categories that have progress instead of deleting history', function () {
    $admin = makeAdmin();
    $student = makeStudent();
    $category = makeCategory();
    $lesson = makeLesson($category);

    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => false])->assertOk();
    $this->actingAs($admin)->deleteJson("/api/admin/lessons/{$lesson->id}")->assertOk()->assertJsonPath('archived', true);
    expect(Lesson::find($lesson->id))->toBeNull();
    expect(Lesson::withTrashed()->findOrFail($lesson->id)->trashed())->toBeTrue();
    expect(StudentLessonProgress::where('lesson_id', $lesson->id)->exists())->toBeTrue();
    $this->actingAs($student)->getJson('/api/learning')->assertJsonCount(0, 'categories.0.lessons');
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 10, 'is_playing' => false])->assertNotFound();
    $this->actingAs($admin)->postJson("/api/admin/lessons/{$lesson->id}/restore")->assertOk();
    expect(Lesson::find($lesson->id))->not->toBeNull();
    expect(StudentLessonProgress::where('lesson_id', $lesson->id)->exists())->toBeTrue();

    $this->deleteJson("/api/admin/categories/{$category->id}")->assertOk()->assertJsonPath('archived', true);
    expect(Category::withTrashed()->findOrFail($category->id)->trashed())->toBeTrue();
    expect(StudentLessonProgress::where('lesson_id', $lesson->id)->exists())->toBeTrue();
});

it('creates, orders, edits, archives, and restores database-driven categories without deleting history', function () {
    $admin = makeAdmin();
    $student = makeStudent();
    $this->actingAs($admin)->postJson('/api/admin/categories', [
        'name' => 'Applied Robotics', 'description' => 'Custom category', 'sort_order' => 8, 'is_active' => true,
    ])->assertCreated()->assertJsonPath('category.name', 'Applied Robotics');
    $category = Category::where('name', 'Applied Robotics')->firstOrFail();
    Category::create(['name' => 'Learning practice', 'sort_order' => 5, 'is_active' => true]);
    $lesson = makeLesson($category, 'Robot foundations');
    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => false])->assertOk();

    $this->actingAs($admin)->putJson("/api/admin/categories/{$category->id}", [
        'name' => 'Applied Robotics', 'sort_order' => 2, 'is_active' => false,
    ])->assertOk()->assertJsonPath('category.sort_order', 2)->assertJsonPath('category.is_active', false);

    $list = $this->getJson('/api/admin/categories')->assertOk()->assertJsonPath('categories.0.name', 'Applied Robotics')
        ->assertJsonPath('categories.0.lessons_count', 1)->assertJsonPath('categories.1.name', 'Learning practice');
    expect($list->json('categories.0.deleted_at'))->toBeNull();

    $this->deleteJson("/api/admin/categories/{$category->id}")->assertOk()->assertJsonPath('archived', true);
    $archived = Category::withTrashed()->findOrFail($category->id);
    expect($archived->trashed())->toBeTrue();
    expect($archived->is_active)->toBeFalse();
    expect(Lesson::find($lesson->id))->not->toBeNull();
    expect(StudentLessonProgress::where('student_id', $student->id)->where('lesson_id', $lesson->id)->exists())->toBeTrue();
    $this->getJson('/api/admin/categories')->assertJsonPath('categories.0.id', $category->id)
        ->assertJsonPath('categories.0.lessons_count', 1);
    $learning = $this->actingAs($student)->getJson('/api/learning')->assertOk();
    expect(collect($learning->json('categories'))->pluck('id'))->not->toContain($category->id);

    $this->actingAs($student)->postJson("/api/admin/categories/{$category->id}/restore")->assertForbidden();
    $this->actingAs($admin)->postJson("/api/admin/categories/{$category->id}/restore")->assertOk()
        ->assertJsonPath('category.is_active', false)->assertJsonPath('category.lessons_count', 1);
    expect(Category::findOrFail($category->id)->trashed())->toBeFalse();
    expect(StudentLessonProgress::where('lesson_id', $lesson->id)->exists())->toBeTrue();
    $learning = $this->actingAs($student)->getJson('/api/learning')->assertOk();
    expect(collect($learning->json('categories'))->pluck('id'))->not->toContain($category->id);
    $this->actingAs($admin)->putJson("/api/admin/categories/{$category->id}", ['is_active' => true])->assertOk();
    $learning = $this->actingAs($student)->getJson('/api/learning')->assertOk();
    expect(collect($learning->json('categories'))->pluck('id'))->toContain($category->id);
    $this->actingAs($admin)->postJson("/api/admin/categories/{$category->id}/restore")->assertStatus(409);
});

it('soft deletes a category with no history and rejects lesson assignment to archived categories', function () {
    $admin = makeAdmin();
    $category = makeCategory('Unused category');

    $this->actingAs($admin)->deleteJson("/api/admin/categories/{$category->id}")->assertOk();
    expect(Category::withTrashed()->findOrFail($category->id)->trashed())->toBeTrue();
    $this->postJson('/api/admin/lessons', [
        'category_id' => $category->id, 'title' => 'Should be rejected', 'duration_seconds' => 100, 'is_published' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->postJson("/api/admin/categories/{$category->id}/restore")->assertOk();
});

it('reports filtered progress from saved student learning records', function () {
    $admin = makeAdmin();
    $student = makeStudent();
    $student->update(['pace_group' => 'A']);
    $category = makeCategory('Progress category');
    $first = makeLesson($category, 'Started lesson', 1);
    $current = makeLesson($category, 'Current lesson', 2);
    $now = now();
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $first->id, 'started_at' => $now->copy()->subMinutes(10), 'last_watched_at' => $now->copy()->subMinutes(10), 'is_completed' => true, 'progress_percentage' => 100]);
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $current->id, 'started_at' => $now, 'last_watched_at' => $now, 'is_completed' => false, 'progress_percentage' => 35]);
    StudentCategoryProgress::create(['student_id' => $student->id, 'category_id' => $category->id, 'started_at' => $now->copy()->subHour(), 'completed_at' => $now]);

    $response = $this->actingAs($admin)->getJson('/api/admin/progress?student='.$student->pseudocode.'&category_id='.$category->id.'&pace_group=A&date_from='.$now->format('Y-m-d').'&date_to='.$now->format('Y-m-d'))
        ->assertOk()->assertJsonCount(1, 'students.data')->assertJsonPath('students.data.0.pseudocode', $student->pseudocode)
        ->assertJsonPath('students.data.0.lessons_started', 2)->assertJsonPath('students.data.0.lessons_completed', 1)
        ->assertJsonPath('students.data.0.weekly_target', 4)
        ->assertJsonPath('students.data.0.completion_percentage', 50)->assertJsonPath('students.data.0.current_lesson', $current->title)
        ->assertJsonPath('students.data.0.current_category', $category->name)->assertJsonPath('students.data.0.transfer_eligible', false);
    expect($response->json('filters.lessons'))->toHaveCount(2);

    $this->getJson('/api/admin/progress?lesson_id='.$current->id)->assertOk()
        ->assertJsonCount(1, 'students.data')->assertJsonPath('students.data.0.lessons_started', 1)
        ->assertJsonPath('students.data.0.lessons_completed', 0)->assertJsonPath('students.data.0.completion_percentage', 0);
    StudentLessonProgress::where('student_id', $student->id)->where('lesson_id', $current->id)->update(['is_completed' => true]);
    $this->getJson('/api/admin/progress?student='.$student->pseudocode)
        ->assertOk()->assertJsonPath('students.data.0.lessons_completed', 2)
        ->assertJsonPath('students.data.0.completion_percentage', 100)->assertJsonPath('students.data.0.transfer_eligible', true);
});
it('encrypts Zoom integration credentials and manages student-specific links', function () {
    $admin = makeAdmin();
    $student = makeStudent();
    $pseudocode = $student->pseudocode;

    $this->actingAs($admin)->postJson('/api/admin/zoom/credentials', [
        'account_id' => 'account-private-123', 'client_id' => 'client-private-456', 'client_secret' => 'secret-private-789',
    ])->assertOk()->assertJsonPath('credentials.configured', true)->assertJsonMissingPath('credentials.client_secret');
    expect(\Illuminate\Support\Facades\DB::table('zoom_integration_settings')->where('id', 1)->value('client_secret'))->not->toBe('secret-private-789');
    expect(\App\Models\ZoomIntegrationSetting::find(1)->client_secret)->toBe('secret-private-789');
    $this->getJson('/api/admin/zoom')->assertOk()->assertJsonMissingPath('credentials.client_secret');

    $this->putJson("/api/admin/zoom/assignments/{$student->id}", [
        'zoom_url' => 'https://zoom.us/j/123456789', 'is_active' => true,
    ])->assertOk()->assertJsonPath('assignment.is_active', true);
    $this->getJson('/api/admin/zoom?search='.$pseudocode.'&status=active')->assertOk()
        ->assertJsonPath('students.data.0.name', $student->name)
        ->assertJsonPath('students.data.0.pseudocode', $pseudocode)
        ->assertJsonPath('students.data.0.zoom_assignment.zoom_url', 'https://zoom.us/j/123456789');
    expect($student->fresh()->pseudocode)->toBe($pseudocode);
    $this->putJson("/api/admin/zoom/assignments/{$student->id}", ['zoom_url' => 'invalid', 'is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('zoom_url');
});
it('restores soft-deleted development categories when the local seeder is rerun', function () {
    $category = makeCategory();
    $category->delete();

    (new DatabaseSeeder())->run();

    expect(Category::where('name', 'Basic')->exists())->toBeTrue();
    expect(Category::withTrashed()->where('name', 'Basic')->firstOrFail()->trashed())->toBeFalse();
});

it('blocks development seed credentials outside local and testing environments', function () {
    $this->app->detectEnvironment(fn () => 'production');

    expect(fn () => (new DatabaseSeeder())->run())->toThrow(RuntimeException::class);
});
