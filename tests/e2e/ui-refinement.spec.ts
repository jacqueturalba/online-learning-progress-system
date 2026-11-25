import { expect, test, type Page } from '@playwright/test';

async function mockAdmin(page: Page) {
  await page.addInitScript(() => history.replaceState({}, '', '/admin/lessons'));
  await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 1, name: 'Admin', email: 'admin@example.test', role: 'admin' } } }));
  await page.route('**/api/admin/lessons**', route => route.fulfill({ json: {
    lessons: { data: [], total: 0, current_page: 1, last_page: 1 },
    categories: [{ id: 1, name: 'Basic', deleted_at: null }],
  } }));
  await page.route('**/api/admin/lesson-milestone', route => route.fulfill({ json: {
    settings: { enabled: true, lesson_id: 1, zoom_session_id: 1, message: 'Discuss your questions.', image_path: null },
    lessons: [{ id: 1, title: 'Lesson One', sort_order: 1, category: { name: 'Basic' } }],
    sessions: [{ id: 1, title: 'Discussion', scheduled_at: '2027-01-01T10:00:00Z' }],
  } }));
}

async function mockStudent(page: Page) {
  await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 8, name: 'Student', email: 'student@example.test', role: 'student' } } }));
  await page.route('**/api/learning', route => route.fulfill({ json: {
    enrollment: { eligible: false, requirement_enabled: false, category_name: null, lesson_number: 0, status: 'not_started', revision_reason: null },
    milestone: null,
    categories: [{ id: 1, name: 'Basic', description: 'Course', background_color: null, thumbnail_url: null,
      total_lessons: 1, completed_lessons: 0, progress_percentage: 0,
      lessons: [{ id: 9, title: 'Lesson One', description: 'A lesson', thumbnail_url: null,
        has_video: true, duration_seconds: 120, sort_order: 1, is_unlocked: true,
        realization_submitted: false, realization_questions: null, progress: null }],
    }],
  } }));
  await page.route('**/api/lessons/9/playback-session', route => route.fulfill({ json: {
    video_url: '/api/lessons/9/video?playback=' + 'a'.repeat(64), expires_at: Date.now() + 600_000,
    expires_in_seconds: 600, playback_token: 'a'.repeat(64),
  } }));
  await page.route('**/api/lessons/9/subtitles', route => route.fulfill({ json: { tracks: [
    { language: 'en', label: 'English', url: '/api/lessons/9/subtitles/en' },
  ] } }));
  await page.route('**/api/lessons/9/subtitles/en', route => route.fulfill({ contentType: 'text/vtt', body: 'WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHello\n' }));
  await page.route('**/api/lessons/9/video**', route => route.abort());
}

test('Admin Video Library discussion settings collapse inside the section', async ({ page }) => {
  await mockAdmin(page);
  await page.setViewportSize({ width: 1440, height: 950 });
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const disclosure = page.locator('.milestone-settings-disclosure');
  await expect(disclosure).toBeVisible();
  await expect(disclosure).not.toHaveAttribute('open', '');
  await expect(page.locator('.lesson-editor-panel .eyebrow')).toContainText('VIDEO LIBRARY');
  await expect(disclosure.locator('textarea')).toBeHidden();
  await disclosure.locator('summary').click();
  await expect(disclosure.locator('textarea')).toBeVisible();
  await expect(disclosure.getByLabel('Enable notification')).toBeChecked();
  await disclosure.locator('summary').click();
  await expect(disclosure.locator('textarea')).toBeHidden();
  const editorBottom = await page.locator('.lesson-editor-panel').evaluate(element => element.getBoundingClientRect().bottom);
  const curriculumTop = await page.locator('.admin-lessons-page > .lesson-list-panel h2').evaluate(element => element.closest('.lesson-list-panel')!.getBoundingClientRect().top);
  expect(curriculumTop - editorBottom).toBeLessThan(80);
});

for (const viewport of [{ width: 1365, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
  test(`student lesson modal fits at ${viewport.width}px`, async ({ page }) => {
    await mockStudent(page);
    await page.setViewportSize(viewport);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('.lesson-tile').click();
    const modal = page.locator('.lesson-modal');
    await expect(modal).toBeVisible();
    await expect(page.locator('.closed-caption-control')).toBeVisible();
    await expect(page.locator('#student-realization')).toBeVisible();
    await expect(page.locator('#student-lesson-questions')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Previous Lesson' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Next Lesson' })).toBeVisible();
    await expect(page.locator('.cc-toggle')).toContainText('Off');
    await page.locator('.cc-toggle').click();
    await expect(page.locator('.cc-toggle')).toContainText('On');
    await page.locator('#lesson-closed-captions').selectOption('en');
    await expect(page.locator('#lesson-closed-captions')).toHaveValue('en');
    await page.locator('.cc-toggle').click();
    await expect(page.locator('.cc-toggle')).toContainText('Off');
    const dimensions = await modal.evaluate(element => {
      const modalRect = element.getBoundingClientRect();
      const columns = element.querySelector('.lesson-modal-columns')!;
      const video = element.querySelector('video')!.getBoundingClientRect();
      const activity = element.querySelector('.lesson-activity-panel')!.getBoundingClientRect();
      const captionControl = element.querySelector('.closed-caption-control')!.getBoundingClientRect();
      return {
        width: element.clientWidth, scrollWidth: element.scrollWidth,
        pageWidth: document.documentElement.clientWidth, pageScrollWidth: document.documentElement.scrollWidth,
        columns: getComputedStyle(columns).gridTemplateColumns.split(' ').length,
        modalRight: modalRect.right, videoRight: video.right,
        activityRight: activity.right, activityLeft: activity.left, activityTop: activity.top,
        videoLeft: video.left, videoTop: video.top, videoBottom: video.bottom, videoWidth: video.width,
        captionsLeft: captionControl.left, captionsTop: captionControl.top, captionsBottom: captionControl.bottom,
      };
    });
    expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.width + 1);
    expect(dimensions.pageScrollWidth).toBeLessThanOrEqual(dimensions.pageWidth + 1);
    expect(dimensions.videoRight).toBeLessThanOrEqual(dimensions.modalRight + 1);
    expect(dimensions.activityRight).toBeLessThanOrEqual(dimensions.modalRight + 1);
    expect(dimensions.videoWidth).toBeGreaterThan(300);
    expect(dimensions.columns).toBe(viewport.width <= 820 ? 1 : 2);
    expect(Math.abs(dimensions.captionsLeft - dimensions.videoLeft)).toBeLessThanOrEqual(2);
    expect(dimensions.captionsTop).toBeGreaterThanOrEqual(dimensions.videoBottom);
    if (viewport.width <= 820) expect(dimensions.activityTop).toBeGreaterThan(dimensions.captionsBottom);
    else {
      expect(Math.abs(dimensions.activityTop - dimensions.videoTop)).toBeLessThanOrEqual(2);
      expect(dimensions.activityLeft).toBeGreaterThan(dimensions.videoRight - 2);
    }
  });
}

test('student can edit realization and questions after submission without creating a second record', async ({ page }) => {
  await mockStudent(page);
  let submitted = false;
  let savedRealization = '';
  let savedQuestions = '';
  await page.route('**/api/learning', route => route.fulfill({ json: {
    enrollment: { eligible: false, requirement_enabled: false, category_name: null, lesson_number: 0, status: 'not_started', revision_reason: null }, milestone: null,
    categories: [{ id: 1, name: 'Basic', total_lessons: 1, completed_lessons: 0, progress_percentage: 0, lessons: [{ id: 9, title: 'Lesson One', has_video: false, sort_order: 1, is_unlocked: true, realization_submitted: submitted, realization_text: savedRealization || null, realization_questions: savedQuestions || null, progress: null }] }],
  } }));
  await page.route('**/api/lessons/9/realizations', async route => {
    const payload = route.request().postDataJSON();
    savedRealization = payload.realization;
    savedQuestions = payload.questions;
    submitted = true;
    await route.fulfill({ status: route.request().method() === 'POST' && savedRealization === 'First reflection text.' ? 201 : 200, json: { realization: { id: 31, lesson_id: 9, realization: savedRealization, questions: savedQuestions } } });
  });
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.locator('.lesson-tile').click();
  await page.locator('#student-realization').fill('First reflection text.');
  await page.locator('#student-lesson-questions').fill('First question?');
  await page.getByRole('button', { name: 'Save realization' }).click();
  await expect(page.locator('#student-realization')).toHaveValue('First reflection text.');
  await page.locator('#student-realization').fill('Edited reflection text.');
  await page.locator('#student-lesson-questions').fill('Edited question?');
  await page.getByRole('button', { name: 'Save realization' }).click();
  await expect(page.locator('#student-realization')).toHaveValue('Edited reflection text.');
  await expect(page.locator('#student-lesson-questions')).toHaveValue('Edited question?');
  await expect(page.getByRole('button', { name: 'Save realization' })).toBeVisible();
  expect(savedRealization).toBe('Edited reflection text.');
  expect(savedQuestions).toBe('Edited question?');
});

test('completed lessons remain revisitable and next navigation follows destination availability', async ({ page }) => {
  await mockStudent(page);
  await page.route('**/api/learning', route => route.fulfill({ json: {
    enrollment: { eligible: false, requirement_enabled: false, category_name: null, lesson_number: 0, status: 'not_started', revision_reason: null }, milestone: null,
    categories: [{ id: 1, name: 'Basic', total_lessons: 3, completed_lessons: 2, progress_percentage: 67, lessons: [
      { id: 7, title: 'Earlier lesson', has_video: false, sort_order: 1, is_unlocked: true, realization_submitted: true, progress: { is_completed: true } },
      { id: 9, title: 'Completed lesson', has_video: false, sort_order: 2, is_unlocked: true, realization_submitted: true, progress: { is_completed: true } },
      { id: 10, title: 'Locked lesson', has_video: false, sort_order: 3, is_unlocked: false, realization_submitted: false, progress: null },
    ] }],
  } }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.locator('.lesson-tile').nth(1).click();
  await expect(page.getByRole('button', { name: 'Previous Lesson', exact: true })).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Next Lesson', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Previous Lesson', exact: true }).click();
  await expect(page.locator('.lesson-modal h2')).toHaveText('Earlier lesson');
  await expect(page.getByRole('button', { name: 'Next Lesson', exact: true })).toBeEnabled();
});
