<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Official Enrollment Forms</title>
  <style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #eef2f4; color: #202e38; font: 10.5pt/1.45 Arial, Helvetica, sans-serif; }
    .toolbar { position: sticky; top: 0; z-index: 2; display: flex; justify-content: flex-end; padding: 10px max(18px, calc((100% - 850px)/2)); background: #263d4b; }
    .toolbar button { padding: 9px 15px; border: 1px solid #fff; border-radius: 5px; background: #fff; color: #263d4b; font-weight: 700; cursor: pointer; }
    .form { width: min(850px, calc(100% - 32px)); min-height: 900px; margin: 22px auto; padding: 34px 40px; background: white; box-shadow: 0 5px 24px #1831421a; page-break-after: always; }
    .form:last-child { page-break-after: auto; }
    .document-kicker { margin: 0; color: #557080; font-size: 9pt; font-weight: 700; letter-spacing: 2px; text-align: center; }
    .document-title { margin: 5px 0 19px; color: #193c51; font: 700 21pt/1.2 Georgia, 'Times New Roman', serif; text-align: center; }
    .identity { display: grid; grid-template-columns: minmax(0,1fr) 120px; gap: 22px; align-items: center; padding: 17px 0; border-top: 2px solid #23475a; border-bottom: 1px solid #cbd5db; }
    .identity h2 { margin: 0 0 5px; color: #193c51; font: 700 19pt/1.2 Georgia, 'Times New Roman', serif; }
    .identity p { margin: 3px 0; color: #526571; }
    .identity strong { color: #263d4b; }
    .picture, .picture-placeholder { display: block; width: 105px; height: 130px; margin: auto; border: 1px solid #bdc9cf; object-fit: cover; }
    .picture-placeholder { display: grid; place-items: center; padding: 10px; color: #6b7b84; background: #f5f7f8; text-align: center; font-size: 9pt; }
    .picture-caption { margin-top: 4px; color: #60717b; text-align: center; font-size: 8pt; }
    .section { margin-top: 18px; break-inside: avoid; }
    .section h3 { margin: 0 0 8px; padding: 0 0 5px; border-bottom: 1px solid #9fb1bb; color: #24536b; font-size: 10pt; letter-spacing: .7px; text-transform: uppercase; }
    .fields { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 7px 18px; }
    .field { min-width: 0; padding: 4px 0 6px; border-bottom: 1px solid #e0e6e9; break-inside: avoid; }
    .field.wide { grid-column: 1 / -1; }
    .label { display: block; color: #657782; font-size: 8pt; font-weight: 700; text-transform: capitalize; }
    .value { display: block; min-height: 15px; color: #202e38; white-space: pre-wrap; overflow-wrap: anywhere; }
    .documents { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 16px; }
    .document-card { min-height: 66px; padding: 10px; border: 1px solid #d5dfe4; border-radius: 4px; }
    .document-card h4 { margin: 0 0 8px; color: #526571; font-size: 8.5pt; letter-spacing: .4px; text-transform: uppercase; }
    .id-preview { display: block; max-width: 100%; max-height: 260px; object-fit: contain; object-position: left top; }
    .document-link { color: #155d7b; font-weight: 700; overflow-wrap: anywhere; }
    .privacy { margin-top: 15px; padding: 10px 12px; border-left: 3px solid #638071; background: #f3f7f4; color: #455a50; font-size: 8.5pt; }
    @media (max-width: 620px) { .form { padding: 22px 18px; } .identity { grid-template-columns: minmax(0,1fr) 88px; gap: 10px; } .picture,.picture-placeholder { width: 82px; height: 104px; } .fields,.documents { grid-template-columns: 1fr; } }
    @media print {
      body { background: #fff; font-size: 9.5pt; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
      .toolbar { display: none; }
      .form { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
      .section { margin-top: 13px; }
      .identity,.section,.field,.document-card { break-inside: avoid; }
      a { color: inherit; text-decoration: none; }
    }
  </style>
</head>
<body>
<div class="toolbar"><button onclick="printWhenReady()">Print / Save as PDF</button></div>
@foreach($students as $student)
  @php($form = $student->enrollmentForms->first())
  @php($d = $form?->data ?? [])
  <article class="form">
    <p class="document-kicker">STUDENT ADMISSION · REVISION {{ $form?->revision ?? '—' }}</p>
    <h1 class="document-title">OFFICIAL ENROLLMENT FORM</h1>
    <header class="identity">
      <div><h2>{{ trim(($d['first_name'] ?? '').' '.($d['middle_name'] ?? '').' '.($d['last_name'] ?? '')) ?: $student->name }}</h2>
        <p><strong>Student account:</strong> {{ $student->name }}</p><p><strong>Email:</strong> {{ $student->email }}</p><p><strong>Student ID:</strong> {{ $student->pseudocode }}</p>
        <p><strong>Status:</strong> {{ ucfirst(str_replace('_',' ',$form?->status ?? 'not started')) }} · {{ $form?->submitted_at?->format('M j, Y g:i A') ?? 'Not submitted' }}</p>
      </div>
      <aside>@if($form?->picture_path)<img class="picture" src="{{ route('enrollment-form.document', ['form'=>$form->id,'kind'=>'picture']) }}" alt="Uploaded student picture"><div class="picture-caption">Student picture</div>@else<div class="picture-placeholder">No student picture</div>@endif</aside>
    </header>
    @foreach([
      'Personal Information'=>['first_name','middle_name','last_name','gender','birthday','age','civil_status','children','country_current','nationality','first_language','second_language','english_level'],
      'Contact Information'=>['phone','email','facebook','address','province','invited_by','study_source'],
      'Employment and Background'=>['networking_business','employment','student_course','work_description'],
      'Church Information'=>['church_position','church_name','believer_years','church_address'],
      'Education and Ministry'=>['theology_studied','school_name','major','study_duration','level','religion','denomination','church_category','pastor_position','ministry_period','church_members']
    ] as $section=>$fields)
      <section class="section"><h3>{{ $section }}</h3><div class="fields">
        @foreach($fields as $field)
          <div class="field {{ in_array($field,['address','church_address','work_description','facebook'],true) ? 'wide' : '' }}"><span class="label">{{ str_replace('_',' ',$field) }}</span><span class="value">{{ $d[$field] ?? '—' }}</span></div>
        @endforeach
      </div></section>
    @endforeach
    <section class="section"><h3>Identification and Supporting Documents</h3><div class="documents">
      <div class="document-card"><h4>Identification document</h4>
        @if($form?->id_document_path)
          @if(in_array(strtolower(pathinfo($form->id_document_path, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp']))
            <img class="id-preview" src="{{ route('enrollment-form.document', ['form'=>$form->id,'kind'=>'id']) }}" alt="Student identification document">
          @else
            <a class="document-link" href="{{ route('enrollment-form.document', ['form'=>$form->id,'kind'=>'id']) }}">Open private identification PDF</a>
          @endif
        @else <span class="value">No identification document provided</span> @endif
      </div>
      <div class="document-card"><h4>Privacy acknowledgment</h4><span class="value">{{ $form?->privacy_acknowledged ? 'Acknowledged' : 'Not acknowledged' }}</span><span class="label">{{ $form?->privacy_acknowledged_at?->format('M j, Y g:i A') ?? '' }} · Version {{ $form?->privacy_version ?? '—' }}</span></div>
    </div></section>
    @if($form?->revision_reason)<div class="privacy"><strong>Revision instructions:</strong> {{ $form->revision_reason }}</div>@endif
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
