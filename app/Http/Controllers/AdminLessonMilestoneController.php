<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\SiteSetting;
use App\Models\ZoomSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminLessonMilestoneController extends Controller
{
    private const KEY = 'lesson_milestone';

    public function show()
    {
        $value = json_decode((string) SiteSetting::where('key', self::KEY)->value('value'), true) ?: [];
        return response()->json(['settings' => $value, 'lessons' => Lesson::with('category')->where('is_published', true)->orderBy('category_id')->orderBy('sort_order')->get(['id','title','category_id','sort_order']),
            'sessions' => ZoomSession::where('is_active', true)->whereNotNull('zoom_meeting_id')->where('scheduled_at', '>', now())->orderBy('scheduled_at')->get(['id','title','scheduled_at','zoom_batch_id'])]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required','boolean'], 'lesson_id' => ['nullable','integer', Rule::exists('lessons','id')->where('is_published', true)],
            'zoom_session_id' => ['nullable','integer','exists:zoom_sessions,id'], 'message' => ['nullable','string','max:2000'],
            'image' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'], 'remove_image' => ['sometimes','boolean'],
        ]);
        if ($data['enabled'] && (empty($data['lesson_id']) || empty($data['zoom_session_id']) || empty($data['message']))) {
            return response()->json(['message' => 'Choose a lesson and a scheduled Zoom session, and enter a notification message.'], 422);
        }
        $session = !empty($data['zoom_session_id']) ? ZoomSession::findOrFail($data['zoom_session_id']) : null;
        abort_if($session && (!$session->is_active || !$session->zoom_meeting_id || $session->scheduled_at?->isPast()), 422, 'Choose an active upcoming Zoom session.');
        $settings = json_decode((string) SiteSetting::where('key', self::KEY)->value('value'), true) ?: [];
        $oldImage = $settings['image_path'] ?? null;
        $newImage = $request->file('image')?->store('lesson-milestones', 'public');
        $settings = ['enabled' => (bool)$data['enabled'], 'lesson_id' => $data['lesson_id'] ?? null,
            'zoom_session_id' => $data['zoom_session_id'] ?? null, 'message' => trim($data['message'] ?? ''),
            'image_path' => $newImage ?? (($data['remove_image'] ?? false) ? null : $oldImage)];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        if (($newImage || ($data['remove_image'] ?? false)) && $oldImage && $oldImage !== $settings['image_path']) Storage::disk('public')->delete($oldImage);
        return response()->json(['settings' => $settings]);
    }

    public function responses()
    {
        $rows = \App\Models\StudentMilestoneResponse::query()->join('users','users.id','=','student_milestone_responses.student_id')
            ->join('lessons','lessons.id','=','student_milestone_responses.lesson_id')
            ->leftJoin('zoom_sessions','zoom_sessions.id','=','student_milestone_responses.zoom_session_id')->orderByDesc('submitted_at')
            ->get(['student_milestone_responses.*','users.name as student_name','users.email as student_email','users.pseudocode','lessons.title as lesson_title','zoom_sessions.title as zoom_session_title']);
        return response()->json(['responses' => $rows]);
    }
}
