<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function paceAdmin(): User
{
    return User::create(['name' => 'Pace Admin', 'email' => 'pace-admin@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'admin']);
}

function paceStudent(): User
{
    return User::create(['name' => 'Pace Student', 'email' => 'pace-student@example.test', 'password' => Hash::make('StrongPass123!'), 'role' => 'student']);
}

it('provides database-backed A to D groups with the requested initial weekly targets', function () {
    $this->actingAs(paceAdmin())->getJson('/api/admin/students')->assertOk()
        ->assertJsonPath('pace_groups.0.value', 'A')->assertJsonPath('pace_groups.0.weekly_target', 4)
        ->assertJsonPath('pace_groups.1.value', 'B')->assertJsonPath('pace_groups.1.weekly_target', 2)
        ->assertJsonPath('pace_groups.2.value', 'C')->assertJsonPath('pace_groups.2.weekly_target', 0)
        ->assertJsonPath('pace_groups.3.value', 'D')->assertJsonPath('pace_groups.3.weekly_target', 0);
});

it('allows admins to configure all weekly targets and assigns them to students and reports', function () {
    $admin = paceAdmin();
    $student = paceStudent();
    $this->actingAs($admin)->putJson('/api/admin/pace-groups', ['groups' => [
        ['code' => 'A', 'weekly_target' => 5], ['code' => 'B', 'weekly_target' => 3],
        ['code' => 'C', 'weekly_target' => 2], ['code' => 'D', 'weekly_target' => 1],
    ]])->assertOk()->assertJsonPath('pace_groups.2.weekly_target', 2);

    $this->putJson("/api/admin/students/{$student->id}/pace-group", ['pace_group' => 'C'])
        ->assertOk()->assertJsonPath('pace_group', 'C')->assertJsonPath('weekly_target', 2);
    $this->getJson('/api/admin/students?pace_group=C')->assertOk()
        ->assertJsonPath('students.data.0.pace_group', 'C')->assertJsonPath('students.data.0.weekly_target', 2);
    $this->getJson('/api/admin/progress?pace_group=C')->assertOk()
        ->assertJsonPath('students.data.0.pace_group', 'C')->assertJsonPath('students.data.0.weekly_target', 2)
        ->assertJsonPath('filters.pace_groups.2.weekly_target', 2);
});

it('rejects invalid weekly targets and unknown student pace groups', function () {
    $admin = paceAdmin();
    $student = paceStudent();
    $this->actingAs($admin)->putJson('/api/admin/pace-groups', ['groups' => [
        ['code' => 'A', 'weekly_target' => -1], ['code' => 'B', 'weekly_target' => 2],
        ['code' => 'C', 'weekly_target' => 1], ['code' => 'D', 'weekly_target' => 1],
    ]])->assertUnprocessable();
    $this->putJson("/api/admin/students/{$student->id}/pace-group", ['pace_group' => 'Z'])->assertUnprocessable();
});
