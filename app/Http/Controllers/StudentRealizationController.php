<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\StudentRealization;
use App\Support\StudentLessonAccess;
use Illuminate\Http\Request;

class StudentRealizationController extends Controller
{
    public function updateQuestions(Request $request, Lesson $lesson)
    {
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);
        $data = $request->validate(['questions' => ['required', 'string', 'min:1', 'max:10000']]);
        $realization = StudentRealization::query()->where('student_id', $request->user()->id)->where('lesson_id', $lesson->id)->latest('id')->firstOrFail();
        $realization->update(['questions' => trim($data['questions'])]);
        return response()->json(['questions' => $realization->questions]);
    }

    public function update(Request $request, Lesson $lesson)
    {
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);
        $data = $request->validate([
            'realization' => ['required', 'string', 'min:3', 'max:20000'],
            'questions' => ['nullable', 'string', 'max:10000'],
        ]);
        $realization = StudentRealization::query()->where('student_id', $request->user()->id)
            ->where('lesson_id', $lesson->id)->latest('id')->firstOrFail();
        $realization->update([
            'realization' => trim($data['realization']),
            'questions' => trim($data['questions'] ?? '') ?: null,
            'submitted_at' => now(),
        ]);
        return response()->json(['realization' => $realization->only(['id', 'lesson_id', 'realization', 'questions', 'submitted_at'])]);
    }

    public function store(Request $request, Lesson $lesson)
    {
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);

        $data = $request->validate([
            'realization' => ['required', 'string', 'min:3', 'max:20000'],
            'questions' => ['nullable', 'string', 'max:10000'],
        ]);

        $existing = StudentRealization::query()->where('student_id', $request->user()->id)
            ->where('lesson_id', $lesson->id)->latest('id')->first();
        if ($existing) {
            $existing->update([
                'realization' => trim($data['realization']),
                'questions' => trim($data['questions'] ?? '') ?: null,
                'submitted_at' => now(),
            ]);
            return response()->json(['realization' => $existing->only(['id', 'lesson_id', 'realization', 'questions', 'submitted_at'])]);
        }

        $realization = StudentRealization::create([
            'student_id' => $request->user()->id,
            'lesson_id' => $lesson->id,
            'realization' => trim($data['realization']),
            'questions' => trim($data['questions'] ?? '') ?: null,
            'submitted_at' => now(),
        ]);

        return response()->json(['realization' => $realization->only(['id', 'lesson_id', 'realization', 'questions', 'submitted_at'])], 201);
    }
}
