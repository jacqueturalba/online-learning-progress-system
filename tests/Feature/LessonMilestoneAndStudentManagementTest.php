<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\SiteSetting;
use App\Models\StudentLessonProgress;
use App\Models\StudentMilestoneResponse;
use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function milestoneTestUser(string $email, string $role): User
{
    return User::create(['name' => 'Milestone Test', 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => $role]);
}

it('shows a milestone after lesson completion, stores one response, and never exposes a generic Zoom URL', function () {
    $student = milestoneTestUser('milestone-student@example.test', 'student');
    $category = Category::create(['name' => 'Milestone Category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Milestone lesson', 'sort_order' => 1, 'is_published' => true]);
    $batch = ZoomBatch::create(['name' => 'Milestone batch', 'capacity' => 20, 'status' => 'active']);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Discussion', 'meeting_url' => 'https://zoom.us/generic-link', 'scheduled_at' => now()->addDays(5), 'is_active' => true, 'zoom_meeting_id' => '9988776655', 'zoom_meeting_type' => 8, 'registration_type' => 1, 'meeting_status' => 'active']);
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'is_completed' => true, 'completed_at' => now()]);
    SiteSetting::create(['key' => 'lesson_milestone', 'value' => json_encode(['enabled' => true, 'lesson_id' => $lesson->id, 'zoom_session_id' => $session->id, 'message' => 'Share your questions.'])]);

    $this->actingAs($student)->getJson('/api/learning')->assertOk()
        ->assertJsonPath('milestone.lesson_id', $lesson->id)
        ->assertJsonPath('milestone.join_url', null);
    $submittedAt = now()->startOfSecond();
    $this->travelTo($submittedAt);
    $this->postJson('/api/lesson-milestone-response', ['response' => 'I hope to discuss the lesson examples.'])->assertCreated();
    $this->postJson('/api/lesson-milestone-response', ['response' => 'A changed duplicate response.'])->assertOk();
    $saved = StudentMilestoneResponse::where('student_id', $student->id)->firstOrFail();
    expect(StudentMilestoneResponse::where('student_id', $student->id)->count())->toBe(1)
        ->and($saved->response)->toBe('I hope to discuss the lesson examples.')
        ->and($saved->submitted_at->equalTo($submittedAt))->toBeTrue()
        ->and($saved->fresh()->submitted_at->equalTo($submittedAt))->toBeTrue();
});

it('allows admins to edit and archive students while preventing deletion of accounts with history', function () {
    $admin = milestoneTestUser('student-admin@example.test', 'admin');
    $student = milestoneTestUser('editable-student@example.test', 'student');
    $this->actingAs($admin)->putJson("/api/admin/students/{$student->id}", ['name' => 'Updated Student', 'email' => $student->email, 'who_invited' => 'Community group'])->assertOk();
    $this->patchJson("/api/admin/students/{$student->id}/archive", ['is_active' => false])->assertOk();
    expect($student->fresh()->is_active)->toBeFalse()->and($student->fresh()->who_invited)->toBe('Community group');
    $this->actingAs($student->fresh())->getJson('/api/learning')->assertForbidden();

    $category = Category::create(['name' => 'Protected History Category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Retained history', 'sort_order' => 1, 'is_published' => true]);
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'is_completed' => true]);
    $this->actingAs($admin)->deleteJson("/api/admin/students/{$student->id}")->assertUnprocessable();
    expect($student->fresh())->not->toBeNull();
});

it('rejects registration without the required referral response and stores a supplied response', function () {
    $payload = ['name'=>'Referral Student','email'=>'referral-student@example.test','password'=>'StrongPass123!','password_confirmation'=>'StrongPass123!','country'=>'Philippines','address'=>'Manila','phone_country_code'=>'+63','phone_number'=>'9123456789'];
    $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('who_invited');
    $this->postJson('/register', $payload + ['who_invited'=>'N/A'])->assertCreated();
    expect(User::where('email','referral-student@example.test')->value('who_invited'))->toBe('N/A');
});

it('lets admins configure a milestone against an existing scheduled Zoom session', function () {
    $admin = milestoneTestUser('milestone-config-admin@example.test', 'admin');
    $category = Category::create(['name' => 'Configured Milestone Category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Configured milestone', 'sort_order' => 1, 'is_published' => true]);
    $batch = ZoomBatch::create(['name' => 'Configured milestone batch', 'capacity' => 10, 'status' => 'active']);
    $session = ZoomSession::create(['zoom_batch_id' => $batch->id, 'title' => 'Scheduled discussion', 'meeting_url' => 'https://zoom.us/generic-link', 'scheduled_at' => now()->addDays(8), 'is_active' => true, 'zoom_meeting_id' => '9988776656', 'zoom_meeting_type' => 8, 'registration_type' => 1, 'meeting_status' => 'active']);
    $this->actingAs($admin)->putJson('/api/admin/lesson-milestone', ['enabled'=>true,'lesson_id'=>$lesson->id,'zoom_session_id'=>$session->id,'message'=>'Bring your questions.'])->assertOk();
    $this->getJson('/api/admin/lesson-milestone')->assertOk()->assertJsonPath('settings.lesson_id',$lesson->id)->assertJsonPath('settings.zoom_session_id',$session->id);
});
