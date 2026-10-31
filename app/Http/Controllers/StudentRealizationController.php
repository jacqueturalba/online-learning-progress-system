<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentRealization;
use Illuminate\Http\Request;

class StudentRealizationController extends Controller
{
    public function store(Request $request, Lesson $lesson)
    {
        abort_unless($lesson->is_published && Category::query()->whereKey($lesson->category_id)->where('is_active', true)->exists(), 404);

        $data = $request->validate([
            'realization' => ['required', 'string', 'min:3', 'max:20000'],
        ]);

        $realization = StudentRealization::create([
            'student_id' => $request->user()->id,
            'lesson_id' => $lesson->id,
            'realization' => trim($data['realization']),
            'submitted_at' => now(),
        ]);

        return response()->json(['realization' => $realization->only(['id', 'lesson_id', 'realization', 'submitted_at'])], 201);
    }
}
