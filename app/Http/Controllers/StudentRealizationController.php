<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\StudentRealization;
use App\Support\StudentLessonAccess;
use Illuminate\Http\Request;

class StudentRealizationController extends Controller
{
    public function store(Request $request, Lesson $lesson)
    {
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);

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
