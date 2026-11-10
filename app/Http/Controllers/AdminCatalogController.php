<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminCatalogController extends Controller
{
    public function index() { $categories = Category::withTrashed()->withCount('lessons')->orderBy('sort_order')->orderBy('id')->paginate(25)->withQueryString(); $categories->getCollection()->transform(fn ($category) => array_merge($category->toArray(), ['thumbnail_url' => $category->thumbnail_path ? Storage::disk('public')->url($category->thumbnail_path) : null])); return response()->json(['categories' => $categories, 'max_sort_order' => (int) Category::withTrashed()->max('sort_order')]); }
    public function store(Request $request) { $data = $this->validated($request); if ($request->hasFile('thumbnail')) $data['thumbnail_path'] = $request->file('thumbnail')->store('branding/categories', 'public'); return response()->json(['category' => Category::create($data)], 201); }
    public function update(Request $request, Category $category) { $data = $this->validated($request, $category); if ($request->hasFile('thumbnail')) { $new = $request->file('thumbnail')->store('branding/categories', 'public'); $data['thumbnail_path'] = $new; if ($category->thumbnail_path) Storage::disk('public')->delete($category->thumbnail_path); } elseif ($request->boolean('remove_thumbnail')) { if ($category->thumbnail_path) Storage::disk('public')->delete($category->thumbnail_path); $data['thumbnail_path'] = null; } $category->update($data); return response()->json(['category' => $category->fresh()]); }
    private function validated(Request $request, ?Category $category = null): array { return $request->validate(['name' => [$category ? 'sometimes' : 'required', 'string', 'max:120', 'unique:categories,name'.($category ? ','.$category->id : '')], 'description' => ['nullable', 'string'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean'], 'enable_first_lesson_access' => ['sometimes', 'boolean'], 'unlock_all_lessons' => ['sometimes', 'boolean'], 'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_thumbnail' => ['sometimes', 'boolean'], 'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]); }
    public function destroy(Category $category)
    {
        $category->delete();
        return response()->json(['archived' => true, 'message' => 'Category archived. Learning history has been preserved.']);
    }

    public function restore(int $category)
    {
        $record = Category::withTrashed()->findOrFail($category);
        abort_unless($record->trashed(), 409, 'Category is not archived.');
        $record->restore();

        return response()->json(['category' => $record->fresh()->loadCount('lessons')]);
    }
}
