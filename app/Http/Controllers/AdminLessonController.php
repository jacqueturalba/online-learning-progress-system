<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use Illuminate\Http\Request;

class AdminLessonController extends Controller
{
    public function store(Request $request) { $data = $this->validateLesson($request); return response()->json(['lesson' => Lesson::create($data)], 201); }
    public function update(Request $request, Lesson $lesson) { $lesson->update($this->validateLesson($request, true)); return response()->json(['lesson' => $lesson->fresh()]); }
    public function destroy(Lesson $lesson) { $lesson->delete(); return response()->noContent(); }
    private function validateLesson(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate(['category_id' => [$required, 'integer', 'exists:categories,id'], 'title' => [$required, 'string', 'max:180'], 'description' => ['nullable', 'string'], 'video_url' => ['nullable', 'url', 'max:2048'], 'duration_seconds' => ['nullable', 'integer', 'min:1'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_published' => ['sometimes', 'boolean']]);
    }
}
