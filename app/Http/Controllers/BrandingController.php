<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BrandingController extends Controller
{
    private const DEFAULTS = [
        'brand_name' => 'Learnwell',
        'brand_description' => 'Learn at your own pace.',
        'colors' => [
            'primary' => '#087E9B',
            'secondary' => '#126B52',
            'accent' => '#855F18',
            'background' => '#F7FAFC',
            'text' => '#17212B',
        ],
    ];

    public function show()
    {
        return response()->json(['branding' => $this->branding()]);
    }

    public function update(Request $request)
    {
        $data = Validator::make($request->all(), [
            'brand_name' => ['required', 'string', 'max:80'],
            'brand_description' => ['nullable', 'string', 'max:160'],
            'colors' => ['required', 'array:primary,secondary,accent,background,text'],
            'colors.primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.secondary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.text' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_hero_image' => ['sometimes', 'boolean'],
            'daily_verse_text' => ['nullable', 'string', 'max:1000'],
            'daily_verse_reference' => ['nullable', 'string', 'max:120'],
        ], [
            'logo.image' => 'Choose a valid JPG, PNG, or WebP image for the logo.',
            'logo.mimes' => 'The logo must be a JPG, PNG, or WebP image.',
            'logo.max' => 'The logo must be no larger than 2 MB.',
        ])->after(function ($validator) use ($request): void {
            $colors = $request->input('colors', []);
            $keys = ['primary', 'secondary', 'accent', 'background', 'text'];
            if (!is_array($colors) || count(array_intersect($keys, array_keys($colors))) < 5) {
                return;
            }
            foreach ($keys as $key) {
                if (!is_string($colors[$key]) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $colors[$key])) {
                    return;
                }
            }
            if (self::contrast($colors['text'], $colors['background']) < 4.5) {
                $validator->errors()->add('colors.text', 'Text and background colors must have a contrast ratio of at least 4.5:1.');
            }
            if (self::contrast($colors['text'], '#FFFFFF') < 4.5) {
                $validator->errors()->add('colors.text', 'Text must also contrast with the white application panels by at least 4.5:1.');
            }
            if (max(self::contrast($colors['primary'], '#FFFFFF'), self::contrast($colors['primary'], '#000000')) < 4.5) {
                $validator->errors()->add('colors.primary', 'The primary color must contrast with its button text by at least 4.5:1.');
            }
            if (self::contrast($colors['secondary'], $colors['background']) < 3) {
                $validator->errors()->add('colors.secondary', 'The secondary color must contrast with the background by at least 3:1.');
            }
            if (self::contrast($colors['accent'], $colors['background']) < 3) {
                $validator->errors()->add('colors.accent', 'The accent color must contrast with the background by at least 3:1.');
            }
        })->validate();

        $oldLogo = SiteSetting::where('key', 'logo_path')->value('value');
        $oldHero = SiteSetting::where('key', 'hero_image_path')->value('value');
        $newLogo = null;
        $newHero = null;
        if ($request->hasFile('logo')) {
            $newLogo = $request->file('logo')->store('branding', 'public');
            if (!$newLogo) {
                throw new \RuntimeException('The logo could not be stored. Please try again.');
            }
        }
        if ($request->hasFile('hero_image')) $newHero = $request->file('hero_image')->store('branding', 'public');
        $heroPath = $newHero ?? ($data['remove_hero_image'] ?? false ? null : $oldHero);
        $logoPath = $newLogo ?? ($data['remove_logo'] ?? false ? null : $oldLogo);

        try {
            DB::transaction(function () use ($data, $logoPath, $heroPath): void {
                $values = [
                    'brand_name' => $data['brand_name'],
                    'brand_description' => $data['brand_description'] ?? '',
                    'colors' => json_encode($data['colors'], JSON_THROW_ON_ERROR),
                    'logo_path' => $logoPath,
                    'hero_image_path' => $heroPath,
                    'daily_verse_text' => $data['daily_verse_text'] ?? '',
                    'daily_verse_reference' => $data['daily_verse_reference'] ?? '',
                ];
                foreach ($values as $key => $value) {
                    SiteSetting::updateOrCreate(['key' => $key], ['value' => $value]);
                }
            });
        } catch (\Throwable $exception) {
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }
            if ($newHero) Storage::disk('public')->delete($newHero);
            throw $exception;
        }

        if (($newLogo || ($data['remove_logo'] ?? false)) && $oldLogo && $oldLogo !== $logoPath) {
            Storage::disk('public')->delete($oldLogo);
        }
        if (($newHero || ($data['remove_hero_image'] ?? false)) && $oldHero && $oldHero !== $heroPath) Storage::disk('public')->delete($oldHero);

        return response()->json(['branding' => $this->branding(), 'message' => 'Branding settings saved.']);
    }

    private function branding(): array
    {
        $stored = SiteSetting::query()->whereIn('key', ['brand_name', 'brand_description', 'colors', 'logo_path', 'hero_image_path', 'daily_verse_text', 'daily_verse_reference'])->pluck('value', 'key');
        $colors = json_decode($stored['colors'] ?? '', true);

        return [
            'brand_name' => $stored->get('brand_name') ?: config('app.name', self::DEFAULTS['brand_name']),
            'brand_description' => $stored->get('brand_description') ?? self::DEFAULTS['brand_description'],
            'colors' => is_array($colors) ? array_merge(self::DEFAULTS['colors'], $colors) : self::DEFAULTS['colors'],
            'logo_url' => $stored->get('logo_path') ? Storage::disk('public')->url($stored->get('logo_path')) : null,
            'hero_image_url' => $stored->get('hero_image_path') ? Storage::disk('public')->url($stored->get('hero_image_path')) : '/storage/branding/portal-login.png',
            'daily_verse_text' => $stored->get('daily_verse_text') ?: 'Your word is a lamp to my feet and a light to my path.',
            'daily_verse_reference' => $stored->get('daily_verse_reference') ?: 'Psalm 119:105',
        ];
    }

    private static function contrast(string $first, string $second): float
    {
        $luminance = static function (string $hex): float {
            $rgb = array_map(static function (int $part): float {
                $value = $part / 255;
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]);
            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };

        $values = [$luminance($first), $luminance($second)];
        return (max($values) + 0.05) / (min($values) + 0.05);
    }
}
