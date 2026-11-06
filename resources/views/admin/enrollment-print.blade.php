<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Official Enrollment Forms</title>
  <style>
    @page { size: A4; margin: 16mm; }
    body { font: 12px Arial, sans-serif; color: #17212b; }
    .form { page-break-after: always; padding: 12px; }
    .form:last-child { page-break-after: auto; }
    h1 { text-align: center; font-size: 20px; letter-spacing: 1px; }
    h2 { font-size: 14px; border-bottom: 1px solid #777; padding-bottom: 4px; margin: 18px 0 10px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .personal-layout { display: grid; grid-template-columns: minmax(0, 1fr) 115px; gap: 14px; align-items: start; }
    .field { border-bottom: 1px solid #bbb; min-height: 24px; padding: 4px; }
    .label { color: #555; font-size: 10px; }
    .value { display: block; white-space: pre-wrap; }
    .student-picture, .picture-placeholder { width: 105px; height: 130px; object-fit: contain; border: 1px solid #aab2b8; }
    .picture-placeholder { display: grid; place-items: center; box-sizing: border-box; padding: 8px; color: #66717a; text-align: center; font-size: 10px; }
    .picture-caption { color: #555; font-size: 10px; text-align: center; margin-top: 4px; }
    .notice { border: 1px solid #aaa; padding: 10px; margin-top: 16px; }
    .print { position: fixed; top: 8px; right: 10px; }
    @media print { .print { display: none; } }
    @media screen and (max-width: 600px) { .personal-layout { grid-template-columns: minmax(0, 1fr) 100px; gap: 8px; } .student-picture, .picture-placeholder { width: 95px; height: 118px; } }
  </style>
</head>
<body>
<button class="print" onclick="printWhenReady()">Print / Save PDF</button>
@foreach($students as $student)
  @php($form = $student->enrollmentForms->first())
  @php($d = $form?->data ?? [])
  <article class="form">
    <h1>OFFICIAL ENROLLMENT FORM</h1>
    <div class="grid">
      <div class="field"><span class="label">Student</span><span class="value">{{ $student->name }} · {{ $student->email }} · {{ $student->pseudocode }}</span></div>
      <div class="field"><span class="label">Status / submitted</span><span class="value">{{ $form?->status ?? 'Not started' }} · {{ $form?->submitted_at?->format('Y-m-d H:i') }}</span></div>
    </div>
    @foreach(['Personal Information'=>['first_name','middle_name','last_name','gender','birthday','age','civil_status','children','country_current','nationality','first_language','second_language','english_level','phone','email','facebook','address','province','invited_by','study_source'],'Employment and Background'=>['networking_business','employment','student_course','work_description'],'Church Information'=>['church_position','church_name','believer_years','church_address','theology_studied','school_name','major','study_duration','level','religion','denomination','church_category','pastor_position','ministry_period','church_members']] as $section=>$fields)
      <h2>{{ $section }}</h2>
      @if($section === 'Personal Information')
        <div class="personal-layout">
          <div class="grid">
            @foreach($fields as $field)
              <div class="field"><span class="label">{{ ucwords(str_replace('_',' ',$field)) }}</span><span class="value">{{ $d[$field] ?? '' }}</span></div>
            @endforeach
          </div>
          <aside>
            @if($form?->picture_path)
              <img class="student-picture" src="{{ route('enrollment-form.document', ['form'=>$form->id,'kind'=>'picture']) }}" alt="Uploaded student picture">
              <div class="picture-caption">Student picture</div>
            @else
              <div class="picture-placeholder">Student picture</div>
            @endif
          </aside>
        </div>
      @else
        <div class="grid">
          @foreach($fields as $field)
            <div class="field"><span class="label">{{ ucwords(str_replace('_',' ',$field)) }}</span><span class="value">{{ $d[$field] ?? '' }}</span></div>
          @endforeach
        </div>
      @endif
    @endforeach
    <div class="grid" style="margin-top: 14px">
      <div class="field"><span class="label">Identification document</span>
        @if($form?->id_document_path)
          @if(in_array(strtolower(pathinfo($form->id_document_path, PATHINFO_EXTENSION)), ['jpg','jpeg','png']))
            <img class="student-picture" src="{{ route('enrollment-form.document', ['form'=>$form->id,'kind'=>'id']) }}" alt="Uploaded identification document">
          @else
            <a href="{{ route('enrollment-form.document', ['form'=>$form->id,'kind'=>'id']) }}">Review private ID file (PDF)</a>
          @endif
        @else
          <span class="value">No ID uploaded</span>
        @endif
      </div>
      <div class="notice">Privacy acknowledged: {{ $form?->privacy_acknowledged ? 'Yes' : 'No' }} · {{ $form?->privacy_acknowledged_at?->format('Y-m-d H:i') }} · Version {{ $form?->privacy_version }}</div>
    </div>
  </article>
@endforeach
<script>
  async function printWhenReady() {
    await Promise.all(Array.from(document.images, image => image.complete ? Promise.resolve() : new Promise(resolve => { image.onload = resolve; image.onerror = resolve; })));
    window.print();
  }
</script>
</body>
</html>