<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\StudentEnrollmentForm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StudentEnrollmentController extends Controller
{
    public const PRIVACY_VERSION = '2026-11-06-v1';
    public const PRIVACY_TEXT = "By filling up this form, I (registrant, 'my') acknowledge the usage of my personal information by Zion Christian Mission Center (registrar, 'they', 'their') for the processing of my admission and while I am undergoing the program (for contact purposes) to their online class, I understand that they will only use it for the said purposes with adherence to RA No. 10173 (Data Privacy Act of 2012).";

    private function settings(User $student) { return \Illuminate\Support\Facades\DB::table('student_enrollment_settings')->where('student_id',$student->id)->first() ?: \Illuminate\Support\Facades\DB::table('enrollment_settings')->where('id', 1)->first(); }

    private function eligible(User $student): bool
    {
        $setting = $this->settings($student);
        if (!$setting || !$setting->enabled) return true;
        if (!$setting->category_id || !$setting->lesson_number) return false;
        $lesson = Category::find($setting->category_id)?->lessons()->where('is_published', true)->orderBy('sort_order')->orderBy('id')->skip($setting->lesson_number - 1)->first();
        return $lesson && $student->lessonProgress()->where('lesson_id', $lesson->id)->where('is_completed', true)->exists();
    }

    public function show(Request $request)
    {
        abort_unless($this->eligible($request->user()), 403, 'Complete the required lesson to access your Official Enrollment Form.');
        $form = $request->user()->enrollmentForms()->latest('revision')->first();
        $student=$request->user();
        return response()->json(['form' => $form, 'documents'=>$this->documentLinks($form), 'prefill'=>['first_name'=>explode(' ',$student->name)[0]??'','email'=>$student->email,'phone'=>trim(($student->phone_country_code??'').' '.($student->phone_number??'')),'address'=>$student->address], 'privacy_text' => self::PRIVACY_TEXT, 'privacy_version' => self::PRIVACY_VERSION]);
    }

    public function save(Request $request)
    {
        abort_unless($this->eligible($request->user()), 403, 'Complete the required lesson to access your Official Enrollment Form.');
        if (is_string($request->input('data'))) {
            $decoded = json_decode($request->input('data'), true);
            if (!is_array($decoded)) throw ValidationException::withMessages(['data' => 'The enrollment form data is invalid.']);
            $request->merge(['data' => $decoded]);
        }
        $input = $request->validate([
            'action' => ['required', 'in:draft,submit'], 'data' => ['required', 'array'],
            'id_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $data = $input['data'];
        if (!empty($data['birthday'])) {
            try { $data['age'] = \Illuminate\Support\Carbon::parse($data['birthday'])->age; }
            catch (\Throwable) { $data['age'] = null; }
        }
        if ($input['action'] === 'submit') {
            $rules = ['first_name'=>'required|string|max:100','last_name'=>'required|string|max:100','gender'=>'required|in:Male,Female','birthday'=>'required|date|before:today','civil_status'=>'required|in:Single,Married,Widowed,Separated,Divorced','children'=>'required|in:Yes,No','country_current'=>'required|string|max:100','nationality'=>'required|string|max:100','first_language'=>'required|string|max:100','english_level'=>'required|in:Beginner,Elementary,Intermediate,Upper-Intermediate,Advanced,Proficient','phone'=>'required|string|max:60','email'=>'required|email|max:255','address'=>'required|string|max:500','church_position'=>'required|in:Pastor,Non-Pastor','church_name'=>'required|string|max:255','believer_years'=>'required|string|max:80','church_address'=>'required|string|max:500'];
            if (($data['church_position'] ?? '') === 'Pastor') foreach (['theology_studied','school_name','major','study_duration','level','religion','denomination','church_category','pastor_position','ministry_period','church_members'] as $field) $rules[$field] = 'required|string|max:255';
            $request->validate(['data' => ['required','array']]);
            validator($data, $rules)->validate();
            if (empty($data['privacy_acknowledged'])) throw ValidationException::withMessages(['data.privacy_acknowledged' => 'Please acknowledge the data privacy statement before submitting.']);
        }
        $student = $request->user();
        $current = $student->enrollmentForms()->latest('revision')->first();
        if ($input['action'] === 'submit' && $current && in_array($current->status, ['submitted','approved'], true)) abort(409, 'This form has already been submitted.');
        $previousRevision = $current && $current->status === 'needs_revision' ? $current : null;
        if ($previousRevision) $current = null;
        $form = $current ?: new StudentEnrollmentForm([
            'student_id'=>$student->id,
            'revision'=>(int)$student->enrollmentForms()->max('revision') + 1,
            'id_document_path'=>$previousRevision?->id_document_path,
            'picture_path'=>$previousRevision?->picture_path,
        ]);
        $form->data = $data;
        if ($request->hasFile('id_document')) $form->id_document_path = $request->file('id_document')->store('enrollment/'.$student->id, 'local');
        if ($request->hasFile('picture')) $form->picture_path = $request->file('picture')->store('enrollment/'.$student->id, 'local');
        if (!empty($data['privacy_acknowledged'])) { $form->privacy_acknowledged = true; $form->privacy_acknowledged_at ??= now(); $form->privacy_version = self::PRIVACY_VERSION; }
        $form->status = $input['action'] === 'submit' ? 'submitted' : 'draft';
        if ($input['action'] === 'submit') $form->submitted_at = now();
        $form->save();
        return response()->json(['form'=>$form, 'documents'=>$this->documentLinks($form)]);
    }

    private function documentLinks(?StudentEnrollmentForm $form): array
    {
        return [
            'id_url'=>$form?->id_document_path ? route('enrollment-form.document', ['form'=>$form->id,'kind'=>'id']) : null,
            'picture_url'=>$form?->picture_path ? route('enrollment-form.document', ['form'=>$form->id,'kind'=>'picture']) : null,
        ];
    }

    public function document(Request $request, StudentEnrollmentForm $form, string $kind)
    {
        abort_unless($request->user()->role === 'admin' || $form->student_id === $request->user()->id, 404);
        abort_unless(in_array($kind, ['id','picture'], true), 404);
        $path = $kind === 'id' ? $form->id_document_path : $form->picture_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->response($path, basename($path), ['Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
    }
}
