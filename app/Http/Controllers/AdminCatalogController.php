<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class AdminCatalogController extends Controller
{
    public function index() { return response()->json(['categories' => Category::withCount('lessons')->orderBy('sort_order')->get()]); }
    public function store(Request $request) { $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:categories'], 'description' => ['nullable', 'string'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean']]); return response()->json(['category' => Category::create($data)], 201); }
    public function update(Request $request, Category $category) { $data = $request->validate(['name' => ['sometimes', 'string', 'max:120', 'unique:categories,name,'.$category->id], 'description' => ['nullable', 'string'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean']]); $category->update($data); return response()->json(['category' => $category->fresh()]); }
    public function destroy(Category $category) { $category->delete(); return response()->noContent(); }
}
