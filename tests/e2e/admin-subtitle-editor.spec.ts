import { expect, test } from '@playwright/test';

test('admin subtitle editor keeps long drafts across sections, warns before discard, and gates approval', async ({ page }) => {
  const englishSource = [
    '1\n00:00:01,000 --> 00:00:03,000\nThe original English cue with a long sentence for comfortable review.',
    '2\n00:05:01,000 --> 00:05:04,000\nA second source cue at the next review section.',
    '3\n00:10:01,000 --> 00:10:05,000\nA third source cue in the later section.',
  ].join('\n\n') + '\n';
  const initialTranslation = [
    { index: 1, start: '00:00:01,000', end: '00:00:03,000', text: 'Orihinal na Filipino cue na may mahabang pangungusap para sa komportableng pagsusuri.' },
    { index: 2, start: '00:05:01,000', end: '00:05:04,000', text: 'Ikalawang salin para sa susunod na seksyon.' },
    { index: 3, start: '00:10:01,000', end: '00:10:05,000', text: 'Ikatlong salin sa mas huling seksyon.' },
  ];
  const transcriptText = 'This is a long English transcript sentence that should wrap naturally and remain readable while an admin reviews the entire lesson transcript. '.repeat(18);
  let transcriptDraft = transcriptText;
  let transcriptApproved = false;
  let englishDraft = englishSource;
  let translationCues = structuredClone(initialTranslation);
  let filipinoApproved = false;
  const approvals: string[] = [];

  const workflow = () => ({
    lesson_id: 5,
    preparations: [],
    transcripts: [
      { operation_id: 11, status: 'succeeded', text: transcriptDraft, approved: transcriptApproved, offset_seconds: 125.5,
        audio: { url: '/api/admin/lessons/5/subtitle-audio/12', timeline_start_seconds: 5.5, duration_seconds: 3600 } },
      { operation_id: 12, status: 'succeeded', text: 'Second audio section.', approved: false, offset_seconds: 305.5,
        audio: { url: '/api/admin/lessons/5/subtitle-audio/12', timeline_start_seconds: 5.5, duration_seconds: 3600 } },
    ],
    alignments: [{ operation_id: 21, status: 'succeeded', approved: true, transcript_operation_id: 11, download_version_id: 'english-v1' }],
    translations: [{ operation_id: 31, status: 'succeeded', text: translationCues.map(cue => cue.text).join('\n'), target_language: 'Filipino', cues: translationCues, cue_timed: true, approved: filipinoApproved, source_alignment_operation_id: 21, source_english_srt_version_id: 'english-v1' }],
  });

  await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 1, name: 'Editor Admin', email: 'admin@example.test', role: 'admin' } } }));
  await page.route('**/api/branding', route => route.fulfill({ json: { branding: { brand_name: 'Learnwell', brand_description: 'Learn at your own pace.', logo_url: null, colors: { primary: '#087E9B', secondary: '#126B52', accent: '#855F18', background: '#F7FAFC', text: '#17212B' } } } }));
  await page.route(/\/api\/admin\/lessons(?:\?.*)?$/, route => route.fulfill({ json: {
    lessons: { data: [{ id: 5, category_id: 1, title: 'One Hour Sample Lesson', description: null, video_stream_url: null, thumbnail_url: null, duration_seconds: 3600, sort_order: 1, is_published: false, deleted_at: null, progress_count: 0, category: { id: 1, name: 'Basic', deleted_at: null } }], total: 1, current_page: 1, last_page: 1 },
    categories: [{ id: 1, name: 'Basic', deleted_at: null }],
  } }));
  await page.route('**/api/admin/lessons/5/subtitle-workflow', route => route.fulfill({ json: workflow() }));
  await page.route('**/api/admin/lessons/5/subtitles', route => route.fulfill({ json: { lesson_id: 5, subtitles: [{ operation_id: 21, language: 'en', version_id: 'english-v1', approved_at: '2026-11-01T00:00:00Z', is_current: true, download_url: '/api/admin/lessons/5/subtitles/en/21/english-v1/download' }], review_sources: [] } }));
  await page.route('**/api/admin/whisperx-alignments/21/preview', route => route.fulfill({ json: { operation_id: 21, srt_text: englishDraft, is_draft: englishDraft !== englishSource } }));
  await page.route('**/api/admin/lessons/5/subtitles/en/21/english-v1/download', route => route.fulfill({ status: 200, contentType: 'application/x-subrip', body: englishSource }));
  await page.route('**/api/admin/lessons/5/transcripts/11/draft', async route => {
    transcriptDraft = (route.request().postDataJSON() as { text: string }).text;
    await route.fulfill({ json: { operation_id: 11, text: transcriptDraft, review_status: 'pending_review' } });
  });
  await page.route('**/api/admin/lessons/5/transcripts/11/approve', async route => {
    transcriptApproved = true;
    await route.fulfill({ json: { operation_id: 11, review_status: 'approved' } });
  });
  await page.route('**/api/admin/lessons/5/whisperx-alignments/21/draft', async route => {
    englishDraft = (route.request().postDataJSON() as { srt_text: string }).srt_text;
    await route.fulfill({ json: { operation_id: 21, saved_at: new Date().toISOString() } });
  });
  await page.route('**/api/admin/lessons/5/subtitles/21/en/approve', async route => {
    approvals.push((route.request().postDataJSON() as { srt_text: string }).srt_text);
    await route.fulfill({ status: 201, json: { operation_id: 21, language: 'en', version_id: 'english-v2' } });
  });
  await page.route('**/api/admin/lessons/5/translations/31/draft', async route => {
    const payload = route.request().postDataJSON() as { cues: Array<{ index: number; text: string }> };
    translationCues = translationCues.map(cue => ({ ...cue, text: payload.cues.find(item => item.index === cue.index)?.text ?? cue.text }));
    await route.fulfill({ json: { operation_id: 31, text: translationCues.map(cue => cue.text).join('\n') } });
  });
  await page.route('**/api/admin/lessons/5/subtitles/31/fil/approve', async route => {
    filipinoApproved = true;
    await route.fulfill({ status: 201, json: { operation_id: 31, language: 'fil', version_id: 'filipino-v1' } });
  });

  await page.addInitScript(() => { if (location.pathname === '/login') history.replaceState({}, '', '/admin/lessons'); });
  await page.addInitScript(() => {
    Object.defineProperty(HTMLMediaElement.prototype, 'readyState', { configurable: true, get: () => 4 });
    Object.defineProperty(HTMLMediaElement.prototype, 'duration', { configurable: true, get: () => 3600 });
    Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', {
      configurable: true, get() { return (this as HTMLMediaElement & { testCurrentTime?: number }).testCurrentTime ?? 0; },
      set(value: number) { (this as HTMLMediaElement & { testCurrentTime?: number }).testCurrentTime = value; },
    });
    Object.defineProperty(HTMLMediaElement.prototype, 'paused', { configurable: true, get() { return (this as HTMLMediaElement & { testPaused?: boolean }).testPaused ?? true; } });
    HTMLMediaElement.prototype.play = function () { (this as HTMLMediaElement & { testPaused?: boolean }).testPaused = false; return Promise.resolve(); };
    HTMLMediaElement.prototype.pause = function () { (this as HTMLMediaElement & { testPaused?: boolean }).testPaused = true; };
    HTMLMediaElement.prototype.load = function () { queueMicrotask(() => this.dispatchEvent(new Event('loadedmetadata'))); };
  });
  await page.route('**/api/admin/lessons/5/subtitle-audio/12', route => route.fulfill({ status: 200, contentType: 'audio/mpeg', body: 'audio-fixture' }));
  await page.goto('/login');
  await page.getByRole('button', { name: 'Manage subtitles for One Hour Sample Lesson' }).click();
  await expect(page.locator('audio[aria-label="Full lesson audio"]')).toHaveCount(1);
  const sharedAudio = page.locator('audio[aria-label="Full lesson audio"]');
  await page.getByRole('button', { name: 'Listen from 2:00' }).click();
  await expect.poll(() => sharedAudio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBe(120);
  await sharedAudio.evaluate(element => (element as HTMLAudioElement).play());
  await page.getByRole('button', { name: 'Listen from 5:00' }).click();
  await expect.poll(() => sharedAudio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBe(300);
  await expect.poll(() => sharedAudio.evaluate(element => (element as HTMLAudioElement).paused)).toBe(false);
  const transcriptEditor = page.getByRole('textbox', { name: 'English transcript draft' }).first();
  const revisedTranscript = 'A revised, long transcript that wraps naturally. '.repeat(24);
  await transcriptEditor.fill(revisedTranscript);
  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('unsaved subtitle edits');
    void dialog.dismiss();
  });
  await page.getByRole('button', { name: '← Curriculum' }).click();
  await expect(transcriptEditor).toHaveValue(revisedTranscript);
  await page.locator('.transcript-editor-toolbar').first().getByRole('button', { name: 'Save Changes' }).click();
  await page.locator('.transcript-editor-toolbar').first().getByRole('button', { name: 'Approve English transcript' }).click();
  await expect.poll(() => transcriptApproved).toBe(true);

  const englishEditor = page.locator('.subtitle-section-editor').nth(0);
  const englishApproval = englishEditor.getByRole('button', { name: 'Approve revised English SRT' });
  await expect(englishApproval).toBeDisabled();
  await page.locator('#en-cue-21-1').fill('Edited English cue one with a naturally wrapping sentence.');
  await englishEditor.locator('.subtitle-section-navigation').getByRole('button', { name: 'Next Section' }).click();
  await expect(page.locator('#en-cue-21-2')).toBeVisible();
  await page.locator('#en-cue-21-2').fill('Edited English cue two in section two.');
  await englishEditor.locator('.subtitle-section-picker select').selectOption('2');
  await page.locator('#en-cue-21-3').fill('Edited English cue three in section three.');
  await expect(englishEditor.locator('.subtitle-section-progress')).toContainText('3/3 sections');
  await englishEditor.getByRole('button', { name: 'Save Changes' }).click();
  await expect(englishApproval).toBeEnabled();

  const filipinoEditor = page.locator('.subtitle-section-editor').nth(1);
  await expect(filipinoEditor.locator('.filipino-source-text')).toContainText('The original English cue with a long sentence');
  await page.locator('#fil-cue-31-1').fill('Binagong Filipino cue na may mahabang pangungusap na madaling basahin.');
  await filipinoEditor.locator('.subtitle-section-navigation').getByRole('button', { name: 'Next Section' }).click();
  await page.locator('#fil-cue-31-2').fill('Binagong salin para sa ikalawang seksyon.');
  await filipinoEditor.locator('.subtitle-section-picker select').selectOption('2');
  await page.locator('#fil-cue-31-3').fill('Binagong salin para sa ikatlong seksyon.');
  await filipinoEditor.getByRole('button', { name: 'Save Changes' }).click();

  await page.reload();
  await page.getByRole('button', { name: 'Manage subtitles for One Hour Sample Lesson' }).click();
  await expect(page.getByRole('textbox', { name: 'English transcript draft' }).first()).toHaveValue(revisedTranscript);
  await expect(page.locator('#en-cue-21-1')).toHaveValue('Edited English cue one with a naturally wrapping sentence.');
  await expect(page.locator('#fil-cue-31-1')).toHaveValue('Binagong Filipino cue na may mahabang pangungusap na madaling basahin.');
  await page.locator('.subtitle-section-editor').nth(1).locator('.subtitle-section-picker select').selectOption('1');
  await expect(page.locator('#fil-cue-31-2')).toHaveValue('Binagong salin para sa ikalawang seksyon.');

  const reopenedEnglishEditor = page.locator('.subtitle-section-editor').nth(0);
  await reopenedEnglishEditor.locator('.subtitle-section-picker select').selectOption('1');
  await reopenedEnglishEditor.locator('.subtitle-section-picker select').selectOption('2');
  const reopenedEnglishApproval = reopenedEnglishEditor.getByRole('button', { name: 'Approve revised English SRT' });
  await expect(reopenedEnglishApproval).toBeEnabled();
  await reopenedEnglishApproval.click();
  await expect.poll(() => approvals.length).toBe(1);
  expect(approvals[0]).toContain('Edited English cue three in section three.');
  expect(approvals[0]).toContain('00:10:01,000 --> 00:10:05,000');

  const reopenedFilipinoEditor = page.locator('.subtitle-section-editor').nth(1);
  await reopenedFilipinoEditor.locator('.subtitle-section-picker select').selectOption('1');
  await reopenedFilipinoEditor.locator('.subtitle-section-picker select').selectOption('2');
  await reopenedFilipinoEditor.getByRole('button', { name: 'Approve Filipino subtitles' }).click();
  await expect.poll(() => filipinoApproved).toBe(true);
});
