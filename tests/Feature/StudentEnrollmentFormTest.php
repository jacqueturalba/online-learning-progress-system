<?php

use App\Models\StudentLessonProgress;
use App\Models\StudentEnrollmentForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps the enrollment form hidden until the configured ordered lesson is completed', function () {
    $student = User::create(['name'=>'Learner','email'=>'learner@example.test','password'=>'StrongPass123!','role'=>'student']);
    $category = makeCategory();
    $first = makeLesson($category, 'First', 10);
    makeLesson($category, 'Second', 20);
    DB::table('enrollment_settings')->insert(['id'=>1,'enabled'=>true,'category_id'=>$category->id,'lesson_number'=>2,'created_at'=>now(),'updated_at'=>now()]);

    $this->actingAs($student)->getJson('/api/enrollment-form')->assertForbidden();
    StudentLessonProgress::create(['student_id'=>$student->id,'lesson_id'=>$first->id,'is_completed'=>true,'completed_at'=>now()]);
    $this->getJson('/api/enrollment-form')->assertForbidden();
    $second = $category->lessons()->orderBy('sort_order')->orderBy('id')->skip(1)->first();
    StudentLessonProgress::create(['student_id'=>$student->id,'lesson_id'=>$second->id,'is_completed'=>true,'completed_at'=>now()]);
    $this->getJson('/api/enrollment-form')->assertOk()->assertJsonPath('form', null);
});

it('validates category lesson numbers and allows immediate access at zero', function () {
    $admin=User::create(['name'=>'Admin','email'=>'admin-enroll@example.test','password'=>'StrongPass123!','role'=>'admin']);
    $category=makeCategory();
    $this->actingAs($admin)->putJson('/api/admin/enrollment-settings',['enabled'=>true,'category_id'=>$category->id,'lesson_number'=>1])->assertUnprocessable();
    $this->putJson('/api/admin/enrollment-settings',['enabled'=>true,'category_id'=>$category->id,'lesson_number'=>0])->assertOk()->assertJsonPath('settings.lesson_number',0);
});

it('saves a draft once and blocks students from reviewing enrollment forms', function () {
    $student=User::create(['name'=>'Draft Learner','email'=>'draft@example.test','password'=>'StrongPass123!','role'=>'student']);
    $this->actingAs($student)->postJson('/api/enrollment-form',['action'=>'draft','data'=>['first_name'=>'Draft','email'=>$student->email]])->assertOk()->assertJsonPath('form.status','draft');
    $this->postJson('/api/enrollment-form',['action'=>'draft','data'=>['first_name'=>'Saved again']])->assertOk();
    expect($student->enrollmentForms()->count())->toBe(1);
    $this->getJson('/api/admin/enrollment-settings')->assertForbidden();
});

it('preserves submission history when an admin returns a form and the student resubmits', function () {
    $student=User::create(['name'=>'Submit Learner','email'=>'submit@example.test','password'=>'StrongPass123!','role'=>'student']);
    $admin=User::create(['name'=>'Reviewer','email'=>'reviewer@example.test','password'=>'StrongPass123!','role'=>'admin']);
    $data=['first_name'=>'Submit','last_name'=>'Learner','gender'=>'Female','birthday'=>'2000-02-01','civil_status'=>'Single','children'=>'No','country_current'=>'Singapore','nationality'=>'Filipino','first_language'=>'Tagalog','english_level'=>'Intermediate','phone'=>'+63 9123456789','email'=>$student->email,'address'=>'Manila','church_position'=>'Non-Pastor','church_name'=>'Community Church','believer_years'=>'5','church_address'=>'Makati','privacy_acknowledged'=>true];
    $this->actingAs($student)->postJson('/api/enrollment-form',['action'=>'submit','data'=>$data])->assertOk()->assertJsonPath('form.status','submitted')->assertJsonPath('form.privacy_acknowledged',true);
    $first=$student->enrollmentForms()->firstOrFail();
    $this->actingAs($admin)->postJson('/api/admin/students/'.$student->id.'/enrollment/review',['form_id'=>$first->id,'decision'=>'revision','reason'=>'Please confirm your address.'])->assertOk()->assertJsonPath('form.status','needs_revision');
    $this->actingAs($student)->postJson('/api/enrollment-form',['action'=>'submit','data'=>$data])->assertOk()->assertJsonPath('form.revision',2);
    expect($student->enrollmentForms()->count())->toBe(2);
    expect($first->fresh()->status)->toBe('needs_revision');
});

it('preserves and independently replaces private documents across enrollment revisions', function () {
    Storage::fake('local');
    $student=User::create(['name'=>'Document Learner','email'=>'documents@example.test','password'=>'StrongPass123!','role'=>'student']);
    $admin=User::create(['name'=>'Document Reviewer','email'=>'document-reviewer@example.test','password'=>'StrongPass123!','role'=>'admin']);
    $data=['first_name'=>'Document','last_name'=>'Learner','gender'=>'Female','birthday'=>'2000-02-01','civil_status'=>'Single','children'=>'No','country_current'=>'Singapore','nationality'=>'Filipino','first_language'=>'Tagalog','english_level'=>'Intermediate','phone'=>'+63 9123456789','email'=>$student->email,'address'=>'Manila','church_position'=>'Non-Pastor','church_name'=>'Community Church','believer_years'=>'5','church_address'=>'Makati','privacy_acknowledged'=>true];
    $png=fn(string $name)=>UploadedFile::fake()->createWithContent($name,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8q0AAAAASUVORK5CYII='));
    $save=function(string $action='submit',array $files=[]) use($student,$data){return $this->actingAs($student)->post('/api/enrollment-form',['action'=>$action,'data'=>json_encode($data),...$files]);};
    $returnForRevision=function(StudentEnrollmentForm $form) use($student,$admin){return $this->actingAs($admin)->postJson('/api/admin/students/'.$student->id.'/enrollment/review',['form_id'=>$form->id,'decision'=>'revision','reason'=>'Please review your attached information.']);};

    $save('submit',['id_document'=>$png('id-first.png'),'picture'=>$png('picture-first.png')])->assertOk();
    $first=$student->enrollmentForms()->latest('revision')->firstOrFail();
    $firstId=$first->id_document_path; $firstPicture=$first->picture_path;
    expect(Storage::disk('local')->exists($firstId))->toBeTrue()->and(Storage::disk('local')->exists($firstPicture))->toBeTrue();
    $this->actingAs($student)->get(route('enrollment-form.document',['form'=>$first,'kind'=>'picture']))->assertOk();
    $this->actingAs($student)->getJson('/api/enrollment-form')->assertJsonPath('documents.picture_url',route('enrollment-form.document',['form'=>$first,'kind'=>'picture']))->assertJsonPath('documents.id_url',route('enrollment-form.document',['form'=>$first,'kind'=>'id']));
    $other=User::create(['name'=>'Other Student','email'=>'other-documents@example.test','password'=>'StrongPass123!','role'=>'student']);
    $this->actingAs($other)->get(route('enrollment-form.document',['form'=>$first,'kind'=>'picture']))->assertNotFound();

    $returnForRevision($first)->assertOk();
    $save('draft')->assertOk();
    $second=$student->enrollmentForms()->latest('revision')->firstOrFail();
    expect($second->id_document_path)->toBe($firstId)->and($second->picture_path)->toBe($firstPicture);
    $save()->assertOk();

    $returnForRevision($second)->assertOk();
    $save('submit',['id_document'=>$png('id-replacement.png')])->assertOk();
    $third=$student->enrollmentForms()->latest('revision')->firstOrFail();
    expect($third->id_document_path)->not->toBe($firstId)->and($third->picture_path)->toBe($firstPicture);
    expect(Storage::disk('local')->exists($firstId))->toBeTrue();

    $returnForRevision($third)->assertOk();
    $save('submit',['picture'=>$png('picture-replacement.png')])->assertOk();
    $fourth=$student->enrollmentForms()->latest('revision')->firstOrFail();
    expect($fourth->id_document_path)->toBe($third->id_document_path)->and($fourth->picture_path)->not->toBe($firstPicture);

    $returnForRevision($fourth)->assertOk();
    $save('submit',['id_document'=>$png('id-both.png'),'picture'=>$png('picture-both.png')])->assertOk();
    $fifth=$student->enrollmentForms()->latest('revision')->firstOrFail();
    expect($fifth->id_document_path)->not->toBe($fourth->id_document_path)->and($fifth->picture_path)->not->toBe($fourth->picture_path);
    expect($student->enrollmentForms()->count())->toBe(5);
    foreach ([$first,$second,$third,$fourth,$fifth] as $revision) {
        if ($revision->id_document_path) expect(Storage::disk('local')->exists($revision->id_document_path))->toBeTrue();
        if ($revision->picture_path) expect(Storage::disk('local')->exists($revision->picture_path))->toBeTrue();
    }
});

it('exports selected students as an Excel workbook and print-ready separate forms', function () {
    Storage::fake('local');
    $admin=User::create(['name'=>'Export Admin','email'=>'export-admin@example.test','password'=>'StrongPass123!','role'=>'admin']);
    $student=User::create(['name'=>'Export Student','email'=>'export-student@example.test','password'=>'StrongPass123!','role'=>'student']);
    $studentWithoutPicture=User::create(['name'=>'No Picture Student','email'=>'no-picture@example.test','password'=>'StrongPass123!','role'=>'student']);
    $picture=UploadedFile::fake()->createWithContent('export-picture.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8q0AAAAASUVORK5CYII='))->store('enrollment/'.$student->id,'local');
    $form=StudentEnrollmentForm::create(['student_id'=>$student->id,'revision'=>1,'status'=>'submitted','data'=>['first_name'=>'Export','church_position'=>'Pastor'],'picture_path'=>$picture,'submitted_at'=>now(),'privacy_acknowledged'=>true,'privacy_version'=>'2026-11-06-v1']);
    $this->actingAs($admin)->postJson('/api/admin/students/enrollment/export',['student_ids'=>[$student->id]])->assertOk()->assertHeader('content-type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->postJson('/api/admin/students/enrollment/print',['student_ids'=>[$student->id,$studentWithoutPicture->id]])->assertOk()->assertSee('OFFICIAL ENROLLMENT FORM')->assertSee('Export Student')->assertSee('Uploaded student picture')->assertSee(route('enrollment-form.document',['form'=>$form,'kind'=>'picture']))->assertSee('picture-placeholder');
    $this->postJson('/api/admin/students/enrollment/export',['student_ids'=>[$admin->id]])->assertUnprocessable();
});
