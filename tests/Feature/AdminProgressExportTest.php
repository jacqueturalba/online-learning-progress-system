<?php

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('exports all lesson history with grouped status and date columns', function () {
    $admin = User::create(['name' => 'Export Admin', 'email' => 'export-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
    $student = User::create(['name' => 'Export Student', 'email' => 'export-student@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'student', 'pseudocode' => 'STU-EXPORT123']);
    $category = Category::create(['name' => 'Export category', 'sort_order' => 1, 'is_active' => true]);
    $completedLesson = Lesson::create(['category_id' => $category->id, 'title' => 'Completed lesson', 'sort_order' => 1, 'is_published' => true]);
    $startedLesson = Lesson::create(['category_id' => $category->id, 'title' => 'Started lesson', 'sort_order' => 2, 'is_published' => true]);
    $notStartedLesson = Lesson::create(['category_id' => $category->id, 'title' => 'Not started lesson', 'sort_order' => 3, 'is_published' => true]);
    $completedAt = now()->setDate(2026, 11, 9)->setTime(8, 49);
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $completedLesson->id, 'started_at' => $completedAt->copy()->subHour(), 'completed_at' => $completedAt, 'last_watched_at' => $completedAt, 'progress_percentage' => 100, 'is_completed' => true]);
    StudentLessonProgress::create(['student_id' => $student->id, 'lesson_id' => $startedLesson->id, 'started_at' => $completedAt->copy()->subDays(1), 'last_watched_at' => $completedAt->copy()->subDays(1), 'progress_percentage' => 45, 'is_completed' => false]);

    $response = $this->actingAs($admin)->get('/api/admin/progress?format=xlsx');
    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $xlsx = $response->getContent();
    $readZipFile = static function (string $archive, string $wanted): string {
        $offset = 0;
        while (($offset = strpos($archive, "PK\x03\x04", $offset)) !== false) {
            $header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($archive, $offset + 4, 26));
            $name = substr($archive, $offset + 30, $header['name']);
            $dataOffset = $offset + 30 + $header['name'] + $header['extra'];
            if ($name === $wanted) return substr($archive, $dataOffset, $header['compressed']);
            $offset = $dataOffset + $header['compressed'];
        }
        return '';
    };
    $sheet = $readZipFile($xlsx, 'xl/worksheets/sheet1.xml');
    $styles = $readZipFile($xlsx, 'xl/styles.xml');
    $pseudocode = User::query()->whereKey($student->id)->value('pseudocode');
    expect($xlsx)->toStartWith("PK\x03\x04")
        ->and($sheet)->toContain('Completed lesson')
        ->and($sheet)->toContain('Started lesson')
        ->and($sheet)->toContain('Not started lesson')
        ->and($sheet)->toContain('Complete')
        ->and($sheet)->toContain('45% watched')
        ->and($sheet)->toContain('Not completed')
        ->and($sheet)->toContain('mergeCell ref="N5:O5"')
        ->and($sheet)->toContain('mergeCell ref="P5:Q5"')
        ->and($sheet)->toContain('s="6"')
        ->and($sheet)->toContain($pseudocode)
        ->and($styles)->toContain('mmm d, yyyy, h:mm AM/PM');
});
