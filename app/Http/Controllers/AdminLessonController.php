<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentCategoryProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminLessonController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'published', 'draft', 'archived'])],
        ]);
        $status = $filters['status'] ?? 'all';
        $query = Lesson::withTrashed()->with(['category:id,name'])->withCount('progress');
        if ($status === 'archived') $query->onlyTrashed();
        elseif ($status === 'published') $query->whereNull('deleted_at')->where('is_published', true);
        elseif ($status === 'draft') $query->whereNull('deleted_at')->where('is_published', false);
        $query->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id));
        $query->when($filters['search'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'));

        return response()->json([
            'lessons' => $query->orderBy('category_id')->orderBy('sort_order')->orderBy('id')->paginate(50)->withQueryString(),
            'categories' => Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedLesson($request);
        $path = $request->file('video')?->store('lesson-videos', 'local');
        if ($request->hasFile('video') && !$path) throw new \RuntimeException('The uploaded video could not be stored.');
        if ($path) $data['video_path'] = $path;
        if (!array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (Lesson::withTrashed()->where('category_id', $data['category_id'])->max('sort_order') ?? 0) + 1;
        }
        try {
            $lesson = Lesson::create($data);
        } catch (\Throwable $exception) {
            if ($path) Storage::disk('local')->delete($path);
            throw $exception;
        }
        StudentCategoryProgress::refreshForCategory($lesson->category_id);

        return response()->json(['lesson' => $lesson->load('category:id,name')->loadCount('progress')], 201);
    }

    public function update(Request $request, Lesson $lesson)
    {
        $oldCategoryId = $lesson->category_id;
        $data = $this->validatedLesson($request, $lesson);
        $oldPath = $lesson->video_path;
        $newPath = $request->file('video')?->store('lesson-videos', 'local');
        if ($request->hasFile('video') && !$newPath) throw new \RuntimeException('The uploaded video could not be stored.');
        if ($newPath) $data['video_path'] = $newPath;
        try {
            $lesson->update($data);
        } catch (\Throwable $exception) {
            if ($newPath) Storage::disk('local')->delete($newPath);
            throw $exception;
        }
        if ($newPath && $oldPath && $oldPath !== $newPath) Storage::disk('local')->delete($oldPath);
        StudentCategoryProgress::refreshForCategory($oldCategoryId);
        if ($lesson->category_id !== $oldCategoryId) StudentCategoryProgress::refreshForCategory($lesson->category_id);

        return response()->json(['lesson' => $lesson->fresh()->load('category:id,name')->loadCount('progress')]);
    }

    public function destroy(Lesson $lesson)
    {
        $categoryId = $lesson->category_id;
        $lesson->delete();
        StudentCategoryProgress::refreshForCategory($categoryId);

        return response()->json(['archived' => true, 'message' => 'Lesson archived. Student learning history is preserved.']);
    }

    public function restore(int $lesson)
    {
        $record = Lesson::withTrashed()->findOrFail($lesson);
        abort_unless($record->trashed(), 409, 'Lesson is not archived.');
        $record->restore();
        StudentCategoryProgress::refreshForCategory($record->category_id);

        return response()->json(['lesson' => $record->fresh()->load('category:id,name')->loadCount('progress')]);
    }

    public function move(Request $request, int $lesson)
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $record = Lesson::findOrFail($lesson);
        $categoryId = $record->category_id;
        DB::transaction(function () use ($record, $data, $categoryId) {
            $ordered = Lesson::withTrashed()->where('category_id', $categoryId)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            $index = $ordered->search(fn ($item) => $item->id === $record->id);
            if ($index === false) return;
            $neighborIndex = $data['direction'] === 'up' ? $index - 1 : $index + 1;
            if (!isset($ordered[$neighborIndex])) return;
            $neighbor = $ordered[$neighborIndex];
            $recordOrder = $record->sort_order;
            $neighborOrder = $neighbor->sort_order;
            $temporaryOrder = (Lesson::withTrashed()->where('category_id', $categoryId)->max('sort_order') ?? 0) + 1;
            $record->update(['sort_order' => $temporaryOrder]);
            $neighbor->update(['sort_order' => $recordOrder]);
            $record->update(['sort_order' => $neighborOrder]);
        });

        return response()->json(['lessons' => Lesson::withTrashed()->where('category_id', $categoryId)->orderBy('sort_order')->orderBy('id')->get()]);
    }

    private function validatedLesson(Request $request, ?Lesson $lesson = null): array
    {
        $partial = $lesson !== null;
        $data = $request->validate([
            'category_id' => [$partial ? 'sometimes' : 'required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:180'],
            'video' => [$partial ? 'sometimes' : 'nullable', 'file', 'mimes:mp4,mov,webm,m4v,avi', 'extensions:mp4,mov,webm,m4v,avi', 'max:1024000'],
            'description' => ['sometimes', 'nullable', 'string'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
        ], [
            'video.max' => 'The video is too large. Choose a file no larger than 1000 MB.',
            'video.uploaded' => 'The video upload exceeded the server limit of 1000 MB.',
        ]);
        $categoryId = $data['category_id'] ?? $lesson?->category_id;
        $published = (bool) ($data['is_published'] ?? $lesson?->is_published ?? false);
        $hasVideo = $request->hasFile('video') || filled($lesson?->video_path);
        $duration = $data['duration_seconds'] ?? $lesson?->duration_seconds;

        $publishErrors = [];
        if ($published && !$hasVideo) $publishErrors['video'] = 'Upload a video before publishing.';
        if ($published && (!$duration || $duration < 1)) $publishErrors['duration_seconds'] = 'A positive video duration is required before publishing.';
        if ($publishErrors) {
            throw ValidationException::withMessages($publishErrors);
        }
        if (array_key_exists('sort_order', $data) || array_key_exists('category_id', $data)) {
            $order = $data['sort_order'] ?? $lesson?->sort_order;
            $existing = Lesson::withTrashed()->where('category_id', $categoryId)->where('sort_order', $order);
            if ($lesson) $existing->where('id', '<>', $lesson->id);
            if ($existing->exists()) throw ValidationException::withMessages(['sort_order' => 'This order is already used within the category.']);
        }

        if (!$partial && !array_key_exists('is_published', $data)) $data['is_published'] = false;
        return $data;
    }
}
