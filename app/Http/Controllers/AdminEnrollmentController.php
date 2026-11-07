<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\StudentEnrollmentForm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEnrollmentController extends Controller
{
    public function settings()
    {
        return response()->json(['settings'=>DB::table('enrollment_settings')->where('id',1)->first(), 'categories'=>Category::query()->where('is_active',true)->orderBy('sort_order')->orderBy('id')->get(['id','name'])]);
    }

    public function saveSettings(Request $request)
    {
        $data=$request->validate(['enabled'=>['required','boolean'],'category_id'=>['nullable','integer','exists:categories,id'],'lesson_number'=>['required','integer','min:0','max:10000']]);
        if ($data['enabled'] && $data['lesson_number'] > 0) {
            $count=Category::find($data['category_id'] ?? 0)?->lessons()->where('is_published',true)->count() ?? 0;
            if ($data['lesson_number'] > $count) return response()->json(['message'=>'The selected category does not have that published lesson number.','errors'=>['lesson_number'=>['Choose a lesson number available in the selected category.']]],422);
        }
        DB::table('enrollment_settings')->updateOrInsert(['id'=>1], ['enabled'=>$data['enabled'],'category_id'=>$data['category_id'],'lesson_number'=>$data['lesson_number'],'updated_at'=>now(),'created_at'=>now()]);
        return $this->settings();
    }

    public function show(User $student)
    {
        abort_unless($student->role==='student',404);
        return response()->json(['forms'=>$student->enrollmentForms()->orderByDesc('revision')->get(), 'requirement'=>DB::table('student_enrollment_settings')->where('student_id',$student->id)->first(), 'student'=>['id'=>$student->id,'name'=>$student->name,'email'=>$student->email,'pseudocode'=>$student->pseudocode,'country'=>$student->country,'address'=>$student->address,'phone_country_code'=>$student->phone_country_code,'phone_number'=>$student->phone_number]]);
    }

    public function saveStudentSettings(Request $request, User $student)
    {
        abort_unless($student->role==='student',404);
        $data=$request->validate(['enabled'=>['required','boolean'],'category_id'=>['nullable','integer','exists:categories,id'],'lesson_number'=>['required','integer','min:0','max:10000']]);
        if ($data['enabled'] && $data['lesson_number']>0) {
            $count=Category::find($data['category_id']??0)?->lessons()->where('is_published',true)->count()??0;
            if($data['lesson_number']>$count)return response()->json(['message'=>'The selected category does not have that published lesson number.'],422);
        }
        DB::table('student_enrollment_settings')->updateOrInsert(['student_id'=>$student->id],['enabled'=>$data['enabled'],'category_id'=>$data['category_id'],'lesson_number'=>$data['lesson_number'],'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['requirement'=>DB::table('student_enrollment_settings')->where('student_id',$student->id)->first()]);
    }

    public function review(Request $request, User $student)
    {
        abort_unless($student->role==='student',404);
        $data=$request->validate(['form_id'=>['required','integer','exists:student_enrollment_forms,id'],'decision'=>['required','in:approve,revision'],'reason'=>['required_if:decision,revision','nullable','string','max:2000']]);
        $form=$student->enrollmentForms()->findOrFail($data['form_id']);
        abort_unless($form->status==='submitted',422);
        $form->update(['status'=>$data['decision']==='approve'?'approved':'needs_revision','revision_reason'=>$data['decision']==='revision'?$data['reason']:null,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]);
        return response()->json(['form'=>$form->fresh()]);
    }

    private function selected(Request $request)
    {
        $data=$request->validate(['student_ids'=>['required','array','min:1','max:500'],'student_ids.*'=>['required','integer','distinct','exists:users,id']]);
        $query=User::query()->where('role','student')->whereIn('id',$data['student_ids']);
        abort_unless($query->count()===count($data['student_ids']),422,'Select valid student accounts only.');
        return $query->with(['paceGroup','enrollmentForms'=>fn($q)=>$q->orderByDesc('revision')])->orderBy('name')->get();
    }

    public function export(Request $request)
    {
        $students=$this->selected($request);
        $batchMemberships=\App\Models\ZoomBatchMembership::query()->with('zoomBatch')->whereIn('student_id',$students->pluck('id'))->where('status','active')->orderByDesc('id')->get()->unique('student_id')->keyBy('student_id');
        $keys=['student_name','email','pseudocode','pace_group','weekly_target','batch_name','batch_id','enrollment_status','submission_date','first_name','middle_name','last_name','gender','birthday','age','civil_status','children','country_current','nationality','first_language','second_language','english_level','phone','facebook','address','province','invited_by','study_source','networking_business','employment','student_course','work_description','church_position','church_name','believer_years','church_address','theology_studied','school_name','major','study_duration','level','religion','denomination','church_category','pastor_position','ministry_period','church_members','privacy_acknowledged','privacy_acknowledged_at','privacy_version'];
        $labels=array_map(fn($key)=>['key'=>$key,'label'=>ucwords(str_replace('_',' ',$key))],$keys);
        $rows=$students->map(function($student) use($keys,$batchMemberships){$form=$student->enrollmentForms->first();$membership=$batchMemberships->get($student->id);$data=$form?->data??[];$row=[];foreach($keys as $key)$row[$key]=match($key){'student_name'=>$student->name,'email'=>$student->email,'pseudocode'=>$student->pseudocode,'pace_group'=>$student->pace_group,'weekly_target'=>$student->paceGroup?->weekly_target??0,'batch_name'=>$membership?->zoomBatch?->name??'','batch_id'=>$membership?->zoom_batch_id,'enrollment_status'=>$form?->status??'not_started','submission_date'=>$form?->submitted_at,'privacy_acknowledged'=>$form?->privacy_acknowledged?'Yes':'No','privacy_acknowledged_at'=>$form?->privacy_acknowledged_at,'privacy_version'=>$form?->privacy_version,default=>$data[$key]??''};return (object)$row;});
        return app(\App\Services\ReportXlsxExporter::class)->download('enrollment-forms.xlsx','Student Enrollment Forms',$labels,$rows);
    }

    public function print(Request $request)
    {
        $students=$this->selected($request);
        return view('admin.enrollment-print',['students'=>$students]);
    }
}
