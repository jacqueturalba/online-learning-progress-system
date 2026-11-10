<?php

use App\Models\Category;
use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('paginates dashboard categories, category management, and Zoom batches on the server', function () {
    $admin = User::create(['name' => 'Pagination Admin', 'email' => 'pagination-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
    foreach (range(1, 27) as $index) {
        Category::create(['name' => "Pagination category {$index}", 'sort_order' => $index, 'is_active' => true]);
        ZoomBatch::create(['name' => "Pagination batch {$index}", 'status' => 'active', 'capacity' => 30]);
    }

    $this->actingAs($admin)->getJson('/api/admin/categories?page=2')->assertOk()
        ->assertJsonPath('categories.current_page', 2)->assertJsonPath('categories.last_page', 2)
        ->assertJsonPath('categories.total', 27)->assertJsonCount(2, 'categories.data');
    $this->getJson('/api/admin/dashboard?page=2')->assertOk()
        ->assertJsonPath('students_by_category.current_page', 2)->assertJsonPath('students_by_category.last_page', 2)
        ->assertJsonPath('students_by_category.total', 27);
    $this->getJson('/api/admin/zoom/batches?page=2')->assertOk()
        ->assertJsonPath('batches.current_page', 2)->assertJsonPath('batches.last_page', 2)
        ->assertJsonPath('batches.total', 27)->assertJsonCount(2, 'batches.data');
});

it('paginates Zoom batch rosters without changing their active member count', function () {
    $admin = User::create(['name' => 'Roster Admin', 'email' => 'roster-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
    $batch = ZoomBatch::create(['name' => 'Large roster', 'status' => 'active', 'capacity' => 30, 'created_by' => $admin->id]);
    foreach (range(1, 27) as $index) {
        $student = User::create(['name' => "Roster Student {$index}", 'email' => "roster-student-{$index}@example.test", 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
        ZoomBatchMembership::create(['zoom_batch_id' => $batch->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => now(), 'created_by' => $admin->id]);
    }

    $this->actingAs($admin)->getJson("/api/admin/zoom/batches/{$batch->id}?page=2")->assertOk()
        ->assertJsonPath('batch.students.current_page', 2)->assertJsonPath('batch.students.last_page', 2)
        ->assertJsonPath('batch.students.total', 27)->assertJsonCount(2, 'batch.students.data')
        ->assertJsonPath('batch.enrolled_count', 27);
});
