<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\User;
use App\Services\StudentPaceGroupAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function paceAdmin(): User
{
    return User::create(['name' => 'Pace Admin', 'email' => 'pace-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
}

function paceStudent(string $email = 'pace-student@example.test'): User
{
    return User::create(['name' => 'Pace Student', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
}

function configurePaceGroups($test, User $admin, string $period, array $targets): void
{
    $test->actingAs($admin)->putJson('/api/admin/pace-groups', [
        'measurement_period' => $period,
        'groups' => collect(['A', 'B', 'C', 'D'])->map(fn ($code) => ['code' => $code, 'minimum_completed_lessons' => $targets[$code]])->all(),
    ])->assertOk();
}

function completedPaceLessons(User $student, int $count, Carbon $completedAt): array
{
    $category = Category::firstOrCreate(['name' => 'Pace Test Category'], ['sort_order' => 80, 'is_active' => true]);
    $lessons = [];
    $baseOrder = (int) Lesson::where('category_id', $category->id)->max('sort_order');
    for ($i = 1; $i <= $count; $i++) {
        $lesson = Lesson::create(['category_id' => $category->id, 'title' => "Pace lesson {$student->id}-{$baseOrder}-{$i}", 'sort_order' => $baseOrder + $i, 'is_published' => true]);
        StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'is_completed' => true, 'completed_at' => $completedAt]);
        $lessons[] = $lesson;
    }
    return $lessons;
}

it('provides configured group thresholds and leaves legacy zero targets unconfigured', function () {
    $this->actingAs(paceAdmin())->getJson('/api/admin/students')->assertOk()
        ->assertJsonPath('pace_groups.0.value', 'A')->assertJsonPath('pace_groups.0.minimum_completed_lessons', 4)
        ->assertJsonPath('pace_groups.1.value', 'B')->assertJsonPath('pace_groups.1.minimum_completed_lessons', 2)
        ->assertJsonPath('pace_groups.2.value', 'C')->assertJsonPath('pace_groups.2.minimum_completed_lessons', null)
        ->assertJsonPath('pace_groups.3.value', 'D')->assertJsonPath('pace_groups.3.minimum_completed_lessons', null)
        ->assertJsonPath('pace_groups.0.measurement_period', 'weekly');
});

it('assigns each student to the highest matching group and marks below-D students ungrouped', function () {
    $admin = paceAdmin();
    configurePaceGroups($this, $admin, 'weekly', ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1]);
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', config('app.timezone')));
    $students = [];
    foreach ([0, 1, 2, 3, 4, 5] as $count) {
        $student = paceStudent("pace-{$count}@example.test");
        completedPaceLessons($student, $count, Carbon::now());
        $students[$count] = $student;
    }

    $this->actingAs($admin)->getJson('/api/admin/students')->assertOk();
    expect($students[0]->fresh()->pace_group)->toBeNull()
        ->and($students[1]->fresh()->pace_group)->toBe('D')
        ->and($students[2]->fresh()->pace_group)->toBe('C')
        ->and($students[3]->fresh()->pace_group)->toBe('B')
        ->and($students[4]->fresh()->pace_group)->toBe('A')
        ->and($students[5]->fresh()->pace_group)->toBe('A');
    $this->getJson('/api/admin/students?pace_group=not_grouped')->assertOk()->assertJsonPath('students.data.0.pace_group', null);
    $studentRow = collect($this->getJson('/api/admin/students')->json('students.data'))->firstWhere('id', $students[4]->id);
    expect($studentRow['pace_group'])->toBe('A')->and($studentRow['pace_completed_count'])->toBe(4)->and($studentRow['measurement_period'])->toBe('weekly');
});

it('counts weekly completions Monday through Sunday and changes assignments at Monday rollover', function () {
    $admin = paceAdmin();
    $student = paceStudent();
    configurePaceGroups($this, $admin, 'weekly', ['A' => 3, 'B' => 2, 'C' => 1, 'D' => null]);
    $monday = Carbon::parse('2026-11-23 00:00:00', config('app.timezone'));
    completedPaceLessons($student, 2, $monday->copy()->addDay());
    completedPaceLessons($student, 1, $monday->copy()->subSecond());

    $assigner = app(StudentPaceGroupAssigner::class);
    expect($assigner->summaryFor($student->id, $monday->copy()->addDays(2))['completed_count'])->toBe(2)
        ->and($assigner->summaryFor($student->id, $monday->copy()->addDays(2))['pace_group'])->toBe('B')
        ->and($assigner->summaryFor($student->id, $monday->copy()->addWeek())['completed_count'])->toBe(0)
        ->and($assigner->summaryFor($student->id, $monday->copy()->addWeek())['pace_group'])->toBeNull();
});

it('counts monthly completions only in the current calendar month and recalculates after target changes', function () {
    $admin = paceAdmin();
    $student = paceStudent();
    configurePaceGroups($this, $admin, 'monthly', ['A' => 3, 'B' => 2, 'C' => 1, 'D' => null]);
    $now = Carbon::parse('2026-11-25 12:00:00', config('app.timezone'));
    $this->travelTo($now);
    completedPaceLessons($student, 2, $now->copy()->startOfMonth()->addDays(2));
    completedPaceLessons($student, 2, $now->copy()->subMonthNoOverflow());

    $assigner = app(StudentPaceGroupAssigner::class);
    expect($assigner->summaryFor($student->id, $now)['completed_count'])->toBe(2)
        ->and($assigner->summaryFor($student->id, $now)['pace_group'])->toBe('B')
        ->and($assigner->summaryFor($student->id, $now->copy()->addMonthNoOverflow()->startOfMonth())['completed_count'])->toBe(0);

    configurePaceGroups($this, $admin, 'monthly', ['A' => 2, 'B' => 1, 'C' => null, 'D' => null]);
    $this->getJson('/api/admin/students')->assertOk();
    expect($student->fresh()->pace_group)->toBe('A');
});

it('recalculates when a student completes a lesson and counts that lesson once across revisits', function () {
    $student = paceStudent();
    $admin = paceAdmin();
    configurePaceGroups($this, $admin, 'weekly', ['A' => 1, 'B' => null, 'C' => null, 'D' => null]);
    $category = Category::create(['name' => 'Completion Category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Completed once', 'sort_order' => 1, 'duration_seconds' => 100, 'is_published' => true]);
    $this->actingAs($student)->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 0, 'is_playing' => true])->assertOk();
    for ($second = 10; $second <= 50; $second += 10) {
        $this->travel(10)->seconds();
        $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => $second, 'is_playing' => true])->assertOk();
    }
    expect($student->fresh()->pace_group)->toBe('A');
    $this->putJson("/api/lessons/{$lesson->id}/progress", ['position_seconds' => 80, 'is_playing' => false])->assertOk();
    expect(StudentLessonProgress::where('student_id', $student->id)->count())->toBe(1)
        ->and(app(StudentPaceGroupAssigner::class)->summaryFor($student->id)['completed_count'])->toBe(1);
});

it('rejects zero or unordered thresholds and does not permit manual assignments', function () {
    $admin = paceAdmin();
    $student = paceStudent();
    $this->actingAs($admin)->putJson('/api/admin/pace-groups', ['measurement_period' => 'weekly', 'groups' => [
        ['code' => 'A', 'minimum_completed_lessons' => 0], ['code' => 'B', 'minimum_completed_lessons' => 2],
        ['code' => 'C', 'minimum_completed_lessons' => 3], ['code' => 'D', 'minimum_completed_lessons' => null],
    ]])->assertUnprocessable();
    $this->putJson('/api/admin/pace-groups', ['measurement_period' => 'monthly', 'groups' => [
        ['code' => 'A', 'minimum_completed_lessons' => 2], ['code' => 'B', 'minimum_completed_lessons' => 3],
        ['code' => 'C', 'minimum_completed_lessons' => 1], ['code' => 'D', 'minimum_completed_lessons' => null],
    ]])->assertUnprocessable();
    $this->putJson("/api/admin/students/{$student->id}/pace-group", ['pace_group' => 'A'])->assertNotFound();
});
