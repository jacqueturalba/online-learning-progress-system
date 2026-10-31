<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development seed data may only be created in local or testing environments.');
        }

        User::updateOrCreate(['email' => 'admin@example.test'], ['name' => 'Learning Admin', 'password' => Hash::make('ChangeMe123!'), 'role' => 'admin']);
        $students = collect([['Jamie Rivera', 'jamie@example.test'], ['Taylor Kim', 'taylor@example.test'], ['Morgan Lee', 'morgan@example.test']])->map(fn ($student) => User::updateOrCreate(['email' => $student[1]], ['name' => $student[0], 'password' => Hash::make('ChangeMe123!'), 'role' => 'student']));
        $names = ['Basic', 'Introductory', 'Intermediate', 'Advanced'];
        foreach ($names as $order => $name) {
            $category = Category::withTrashed()->updateOrCreate(['name' => $name], ['description' => "A guided set of {$name} lessons.", 'sort_order' => $order + 1, 'is_active' => true]);
            if ($category->trashed()) $category->restore();
            for ($i = 1; $i <= 2; $i++) {
                $lesson = Lesson::updateOrCreate(['category_id' => $category->id, 'sort_order' => $i], ['title' => $name.' · Lesson '.$i, 'description' => 'A short sample lesson for local development.', 'video_url' => 'https://media.example.test/lesson-'.$order.'-'.$i.'.mp4', 'duration_seconds' => 300, 'is_published' => true]);
                if ($category->name === 'Basic' && $i === 1) {
                    StudentLessonProgress::updateOrCreate(['student_id' => $students[0]->id, 'lesson_id' => $lesson->id], ['last_position_seconds' => 120, 'watched_seconds' => 120, 'progress_percentage' => 40, 'started_at' => now()->subDay(), 'last_watched_at' => now()->subHours(2), 'is_playing' => false, 'is_completed' => false, 'completed_at' => null]);
                    StudentLessonProgress::updateOrCreate(['student_id' => $students[1]->id, 'lesson_id' => $lesson->id], ['last_position_seconds' => 300, 'watched_seconds' => 300, 'progress_percentage' => 100, 'started_at' => now()->subDays(2), 'last_watched_at' => now()->subDay(), 'is_playing' => false, 'is_completed' => true, 'completed_at' => now()->subDay()]);
                }
            }
        }
    }
}
