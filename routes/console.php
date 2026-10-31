<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('app:create-admin', function () {
    $name = trim((string) $this->ask('Admin name'));
    $email = strtolower(trim((string) $this->ask('Admin email')));

    if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A name and valid email address are required.');
        return 1;
    }

    if (User::where('email', $email)->exists()) {
        $this->error('That email already belongs to an account. No account was changed.');
        return 1;
    }

    $password = (string) $this->secret('Password (minimum 12 characters)');
    $confirmation = (string) $this->secret('Confirm password');

    if (strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
        $this->error('Passwords must match and contain at least 12 characters.');
        return 1;
    }

    User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => 'admin', 'email_verified_at' => now()]);
    $this->info("Admin account created for {$email}.");
})->purpose('Create a production admin account without using development seed credentials');
