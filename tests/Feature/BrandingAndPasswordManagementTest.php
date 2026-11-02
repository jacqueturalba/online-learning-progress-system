<?php

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function brandingPalette(array $overrides = []): array
{
    return array_merge([
        'primary' => '#087E9B',
        'secondary' => '#126B52',
        'accent' => '#855F18',
        'background' => '#F7FAFC',
        'text' => '#17212B',
    ], $overrides);
}

function passwordTestUser(string $email, string $role = 'student'): User
{
    return User::create(['name' => 'Password Test', 'email' => $email, 'password' => 'OriginalPass123!', 'role' => $role]);
}

function onePixelPng(string $name = 'northstar.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/6Y8AAAAASUVORK5CYII='),
    );
}

it('returns sensible branding defaults when no settings have been saved', function () {
    $response = $this->getJson('/api/branding')->assertOk()
        ->assertJsonPath('branding.brand_name', config('app.name'))
        ->assertJsonPath('branding.brand_description', 'Learn at your own pace.')
        ->assertJsonPath('branding.colors.primary', '#087E9B')
        ->assertJsonPath('branding.logo_url', null);

    expect($response->json('branding'))->not->toHaveKey('logo_path');
});

it('allows admins to save branding and a logo but blocks non-admin updates', function () {
    Storage::fake('public');
    $admin = passwordTestUser('branding-admin@example.test', 'admin');
    $student = passwordTestUser('branding-student@example.test');
    $data = [
        'brand_name' => 'Northstar Learning',
        'brand_description' => 'Thoughtful learning, every day.',
        'colors' => brandingPalette(),
        'logo' => onePixelPng(),
    ];

    $this->actingAs($student)->withHeader('Accept', 'application/json')->post('/api/admin/branding', ['_method' => 'PUT', ...$data])
        ->assertForbidden();
    expect(SiteSetting::count())->toBe(0);

    $this->actingAs($admin)->withHeader('Accept', 'application/json')->post('/api/admin/branding', ['_method' => 'PUT', ...$data])
        ->assertOk()
        ->assertJsonPath('branding.brand_name', 'Northstar Learning')
        ->assertJsonPath('branding.brand_description', 'Thoughtful learning, every day.')
        ->assertJsonPath('message', 'Branding settings saved.');

    $logoPath = SiteSetting::where('key', 'logo_path')->value('value');
    expect($logoPath)->toStartWith('branding/');
    Storage::disk('public')->assertExists($logoPath);
    $this->getJson('/api/branding')->assertOk()
        ->assertJsonPath('branding.logo_url', Storage::disk('public')->url($logoPath))
        ->assertJsonMissingPath('branding.logo_path');

    $replacement = onePixelPng('replacement.png');
    $this->actingAs($admin)->withHeader('Accept', 'application/json')->post('/api/admin/branding', [
        '_method' => 'PUT', 'brand_name' => 'Northstar Learning', 'brand_description' => 'Thoughtful learning, every day.',
        'colors' => brandingPalette(), 'logo' => $replacement,
    ])->assertOk();
    $replacementPath = SiteSetting::where('key', 'logo_path')->value('value');
    expect($replacementPath)->not->toBe($logoPath);
    Storage::disk('public')->assertMissing($logoPath);
    Storage::disk('public')->assertExists($replacementPath);

    $this->actingAs($admin)->withHeader('Accept', 'application/json')->post('/api/admin/branding', [
        '_method' => 'PUT', 'brand_name' => 'Northstar Learning', 'brand_description' => 'Thoughtful learning, every day.',
        'colors' => brandingPalette(), 'remove_logo' => '1',
    ])->assertOk()->assertJsonPath('branding.logo_url', null);
    Storage::disk('public')->assertMissing($replacementPath);
});

it('rejects unsupported, oversized, and low contrast branding inputs', function () {
    Storage::fake('public');
    $admin = passwordTestUser('branding-validation@example.test', 'admin');
    $base = ['brand_name' => 'Validated brand', 'brand_description' => '', 'colors' => brandingPalette()];

    $this->actingAs($admin)->withHeader('Accept', 'application/json')->post('/api/admin/branding', [
        '_method' => 'PUT', ...$base, 'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    ])->assertUnprocessable()->assertJsonValidationErrors('logo');
    $this->withHeader('Accept', 'application/json')->post('/api/admin/branding', [
        '_method' => 'PUT', ...$base, 'logo' => onePixelPng()->size(2049),
    ])->assertUnprocessable()->assertJsonValidationErrors('logo');
    $this->putJson('/api/admin/branding', [
        ...$base, 'colors' => brandingPalette(['text' => '#E5E7EB']),
    ])->assertUnprocessable()->assertJsonValidationErrors('colors.text');
    $this->putJson('/api/admin/branding', [
        ...$base, 'colors' => brandingPalette(['text' => '#FFFFFF', 'background' => '#000000']),
    ])->assertUnprocessable()->assertJsonValidationErrors('colors.text');
});

it('lets an admin update a student password without returning password data', function () {
    $admin = passwordTestUser('password-admin@example.test', 'admin');
    $student = passwordTestUser('password-target@example.test');

    $response = $this->actingAs($admin)->putJson('/api/admin/students/'.$student->id.'/password', [
        'password' => 'DifferentPass456!',
        'password_confirmation' => 'DifferentPass456!',
    ])->assertOk()->assertJsonPath('message', 'Student password updated.');

    expect(Hash::check('DifferentPass456!', $student->fresh()->password))->toBeTrue();
    expect($response->getContent())->not->toContain('DifferentPass456!')
        ->and($response->getContent())->not->toContain($student->fresh()->password);
});

it('resets student passwords to the configurable default', function () {
    config(['student.default_password' => 'ConfiguredDefault789!']);
    $admin = passwordTestUser('password-reset-admin@example.test', 'admin');
    $student = passwordTestUser('password-reset-target@example.test');

    $this->actingAs($admin)->postJson('/api/admin/students/'.$student->id.'/password/reset')
        ->assertOk()->assertJsonPath('message', 'Student password reset to the configured default.');

    expect(Hash::check('ConfiguredDefault789!', $student->fresh()->password))->toBeTrue();
    expect($this->getJson('/api/admin/students/'.$student->id)->json())->not->toHaveKey('password');
});

it('prevents students from managing another account password', function () {
    $student = passwordTestUser('password-owner@example.test');
    $other = passwordTestUser('password-other@example.test');
    $this->actingAs($student)->putJson('/api/admin/students/'.$other->id.'/password', [
        'password' => 'DifferentPass456!', 'password_confirmation' => 'DifferentPass456!',
    ])->assertForbidden();
    $this->postJson('/api/admin/students/'.$other->id.'/password/reset')->assertForbidden();
    expect(Hash::check('OriginalPass123!', $other->fresh()->password))->toBeTrue();
});

it('allows a student to change their own password with the current password and confirmation', function () {
    $student = passwordTestUser('self-password@example.test');
    $response = $this->actingAs($student)->putJson('/api/profile/password', [
        'current_password' => 'OriginalPass123!',
        'password' => 'NewSecurePass789!',
        'password_confirmation' => 'NewSecurePass789!',
    ])->assertOk()->assertJsonPath('message', 'Your password has been changed.');

    expect(Hash::check('NewSecurePass789!', $student->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($student);
    expect($response->getContent())->not->toContain('OriginalPass123!')
        ->and($response->getContent())->not->toContain('NewSecurePass789!')
        ->and($response->getContent())->not->toContain($student->fresh()->password);
});

it('rejects incorrect current passwords, confirmation mismatches, and reused passwords', function () {
    $student = passwordTestUser('self-password-validation@example.test');
    $endpoint = '/api/profile/password';

    $this->actingAs($student)->putJson($endpoint, [
        'current_password' => 'WrongPassword123!', 'password' => 'NewSecurePass789!', 'password_confirmation' => 'NewSecurePass789!',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->putJson($endpoint, [
        'current_password' => 'OriginalPass123!', 'password' => 'NewSecurePass789!', 'password_confirmation' => 'OtherSecurePass987!',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->putJson($endpoint, [
        'current_password' => 'OriginalPass123!', 'password' => 'OriginalPass123!', 'password_confirmation' => 'OriginalPass123!',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->putJson($endpoint, [
        'current_password' => 'OriginalPass123!', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(Hash::check('OriginalPass123!', $student->fresh()->password))->toBeTrue();
});

it('uses readable text colors and visible keyboard focus styling', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain('--text-secondary: #64748B')
        ->and($css)->toContain(':focus-visible')
        ->and($css)->toContain('outline-offset: 2px');

    $ratio = function (string $foreground, string $background): float {
        $luminance = static function (string $hex): float {
            $rgb = array_map(static function (int $part): float {
                $value = $part / 255;
                return $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4;
            }, [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]);
            return .2126 * $rgb[0] + .7152 * $rgb[1] + .0722 * $rgb[2];
        };
        $levels = [$luminance($foreground), $luminance($background)];
        return (max($levels) + .05) / (min($levels) + .05);
    };

    expect($ratio('#64748B', '#FFFFFF'))->toBeGreaterThanOrEqual(4.5)
        ->and($ratio('#17212B', '#F7FAFC'))->toBeGreaterThanOrEqual(4.5);
});
