import { expect, test } from '@playwright/test';

test('lesson upload reports progress, disables duplicate submits, and retains fields on failure', async ({ page }) => {
  await page.addInitScript(() => {
    const fileSize = Object.getOwnPropertyDescriptor(File.prototype, 'size');
    Object.defineProperty(File.prototype, 'size', {
      configurable: true,
      get() {
        if (this.name === '785.188-mb.mp4') return 785_188_000;
        if (this.name === 'exact-limit.mp4') return 1000 * 1024 * 1024;
        if (this.name === 'too-large.mp4') return 1000 * 1024 * 1024 + 1;
        return fileSize?.get?.call(this);
      },
    });
  });
  await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 1, name: 'Admin', email: 'admin@example.test', role: 'admin' } } }));
  await page.route('**/api/admin/dashboard', route => route.fulfill({ json: { total_students: 0, active_students: 0, total_lessons: 0, completed_lessons: 0, transfer_eligible_students: 0, students_by_category: [], recent_activity: [] } }));
  await page.route('**/login', route => route.fulfill({ status: 200, json: { user: { id: 1, role: 'admin' } } }));
  let createCount = 0;
  await page.route('**/api/admin/lessons**', async route => {
    if (route.request().method() !== 'POST') return route.fulfill({ json: {
      lessons: { data: [], total: 0, current_page: 1, last_page: 1 },
      categories: [{ id: 1, name: 'Basic', deleted_at: null }],
    } });
    createCount++;
    await new Promise(resolve => setTimeout(resolve, 1200));
    if (createCount <= 2) return route.fulfill({ status: 201, json: { lesson: { id: createCount } } });
    if (createCount === 4) return route.fulfill({ status: 413, json: { message: 'Request body too large' } });
    return route.fulfill({ status: 422, json: { message: 'The given data was invalid.', errors: { video: ['The video field must be a file of type: mp4, mov, webm, m4v, avi.'] } } });
  });

  await page.goto('/');
  await page.evaluate(async () => {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    await fetch('/login', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ email: 'admin@example.test', password: 'ChangeMe123!' }) });
  });
  await page.goto('/admin/lessons');
  await page.locator('.lesson-editor-form select').selectOption('1');
  await page.getByLabel('Title').fill('First upload');
  await page.getByLabel('Video Upload').setInputFiles({ name: '785.188-mb.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(1024) });
  await page.getByRole('button', { name: 'Add lesson' }).click();

  const progress = page.getByRole('progressbar', { name: 'Video upload progress' });
  await expect(progress).toBeVisible();
  await expect(page.getByRole('button', { name: 'Uploading…' })).toBeDisabled();
  await expect(progress).toHaveAttribute('max', '100');
  await expect(page.getByRole('status')).toContainText(/Uploading video · \d+%/);
  await expect(page.getByRole('status')).toContainText('Lesson added.', { timeout: 5000 });

  await page.locator('.lesson-editor-form select').selectOption('1');
  await page.getByLabel('Title').fill('Exact limit upload');
  await page.getByLabel('Video Upload').setInputFiles({ name: 'exact-limit.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(1024) });
  await page.getByRole('button', { name: 'Add lesson' }).click();
  await expect(page.getByRole('status')).toContainText('Lesson added.', { timeout: 5000 });

  await page.locator('.lesson-editor-form select').selectOption('1');
  await page.getByLabel('Title').fill('Keep these details');
  await page.getByLabel('Video Upload').setInputFiles({ name: 'too-large.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(1024) });
  await expect(page.getByRole('alert')).toContainText('The video is too large. Choose a file no larger than 1000 MB.');
  await expect(page.getByLabel('Title')).toHaveValue('Keep these details');

  await page.getByLabel('Title').fill('Keep these details');
  await page.getByLabel('Video Upload').setInputFiles({ name: 'second.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(3 * 1024 * 1024) });
  await page.getByRole('button', { name: 'Add lesson' }).click();
  await expect(page.getByRole('alert')).toContainText('Unsupported video type. Choose an MP4, MOV, WebM, M4V, or AVI file.');
  await expect(page.getByLabel('Title')).toHaveValue('Keep these details');
  await expect(page.getByLabel('Video Upload')).toHaveValue(/second\.mp4/);

  await page.getByLabel('Video Upload').setInputFiles({ name: 'server-rejected.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(1024) });
  await page.getByRole('button', { name: 'Add lesson' }).click();
  await expect(page.getByRole('alert')).toContainText('request-body limit is below the upload size');
  expect(createCount).toBe(4);
});

test('student playback uses the protected stream, hides download controls, and resumes and seeks', async ({ page }) => {
  await page.addInitScript(() => {
    const positions = new WeakMap<HTMLMediaElement, number>();
    Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', {
      configurable: true,
      get() { return positions.get(this) ?? 0; },
      set(value: number) { positions.set(this, Number(value)); },
    });
    HTMLMediaElement.prototype.play = function () { this.dispatchEvent(new Event('play')); return Promise.resolve(); };
  });
  await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 8, name: 'Student', email: 'student@example.test', role: 'student' } } }));
  await page.route('**/api/learning', route => route.fulfill({ json: { categories: [{
    id: 1, name: 'Basic', description: null, total_lessons: 1, completed_lessons: 0, progress_percentage: 0,
    lessons: [{ id: 9, title: 'Resume lesson', description: null, video_stream_url: '/api/lessons/9/video', duration_seconds: 100, sort_order: 1, is_unlocked: true, realization_submitted: false, progress: {
      last_position_seconds: 24, watched_seconds: 30, progress_percentage: 30, is_completed: false, last_watched_at: '2026-11-01T00:00:00Z',
    } }],
  }] } }));
  let lastPosition = -1;
  await page.route('**/api/lessons/9/progress', async route => {
    const body = route.request().postDataJSON();
    lastPosition = body.position_seconds;
    return route.fulfill({ json: { progress: { last_position_seconds: lastPosition, watched_seconds: 30, progress_percentage: 30, is_completed: false, last_watched_at: '2026-11-02T00:00:00Z' } } });
  });
  let streamRequested = false;
  await page.route('**/api/lessons/9/video', route => {
    streamRequested = true;
    return route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(1024) });
  });

  await page.goto('/');
  await page.getByRole('button', { name: 'Continue learning' }).click();
  const video = page.locator('.lesson-modal video');
  await expect(video).toBeVisible();
  await expect(video).toHaveAttribute('controlslist', /nodownload/);
  await expect(video).toHaveAttribute('src', '/api/lessons/9/video');
  await expect(page.locator('.lesson-modal a[download]')).toHaveCount(0);
  const contextMenuPrevented = await video.evaluate(element => {
    const contextMenu = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
    element.dispatchEvent(contextMenu);
    return contextMenu.defaultPrevented;
  });
  expect(contextMenuPrevented).toBe(true);
  await video.evaluate(element => element.dispatchEvent(new Event('loadedmetadata')));
  await expect.poll(() => video.evaluate(element => element.currentTime)).toBe(24);
  await expect.poll(() => streamRequested).toBe(true);
  await video.evaluate(element => { element.currentTime = 45; element.dispatchEvent(new Event('timeupdate')); });
  await expect.poll(() => lastPosition).toBe(45);
});

test('student stays on a completed lesson until its realization unlocks Next Lesson', async ({ page }) => {
  await page.addInitScript(() => {
    const positions = new WeakMap<HTMLMediaElement, number>();
    Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', {
      configurable: true,
      get() { return positions.get(this) ?? 0; },
      set(value: number) { positions.set(this, Number(value)); },
    });
    let playCalls = 0;
    (window as any).__playCalls = () => playCalls;
    HTMLMediaElement.prototype.play = function () { playCalls++; this.dispatchEvent(new Event('play')); return Promise.resolve(); };
  });
  let isCompleted = false;
  let hasRealization = false;
  await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 9, name: 'Student', email: 'student@example.test', role: 'student' } } }));
  await page.route('**/api/learning', route => route.fulfill({ json: { categories: [{
    id: 1, name: 'Basic', description: null, total_lessons: 2, completed_lessons: isCompleted ? 1 : 0, progress_percentage: isCompleted ? 50 : 0,
    lessons: [
      { id: 11, title: 'Basic lesson 1', description: null, video_stream_url: '/api/lessons/11/video', duration_seconds: 100, sort_order: 1, is_unlocked: true, realization_submitted: hasRealization, progress: { last_position_seconds: 0, watched_seconds: isCompleted ? 90 : 0, progress_percentage: isCompleted ? 90 : 0, is_completed: isCompleted, last_watched_at: null } },
      { id: 12, title: 'Basic lesson 2', description: null, video_stream_url: isCompleted && hasRealization ? '/api/lessons/12/video' : null, duration_seconds: 100, sort_order: 2, is_unlocked: isCompleted && hasRealization, realization_submitted: false, progress: null },
    ],
  }] } }));
  await page.route('**/api/lessons/11/progress', route => route.fulfill({ json: { progress: { last_position_seconds: isCompleted ? 95 : 50, watched_seconds: isCompleted ? 90 : 50, progress_percentage: isCompleted ? 90 : 50, is_completed: isCompleted, last_watched_at: '2026-11-02T00:00:00Z' } } }));
  await page.route('**/api/lessons/11/realizations', async route => {
    hasRealization = true;
    return route.fulfill({ status: 201, json: { realization: { id: 5, lesson_id: 11, realization: 'I learned this lesson.', submitted_at: '2026-11-02T00:00:00Z' } } });
  });
  await page.route('**/api/lessons/*/video', route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(1024) }));

  await page.goto('/');
  await page.getByRole('button', { name: 'Continue learning' }).click();
  let video = page.locator('.lesson-modal video');
  await expect(video).toBeVisible();
  await video.evaluate(element => element.dispatchEvent(new Event('loadedmetadata')));
  await video.evaluate(element => { element.currentTime = 50; element.dispatchEvent(new Event('ended')); });
  await expect(page.getByRole('heading', { name: 'Basic lesson 1' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Next Lesson' })).toBeDisabled();
  await expect(page.locator('.lesson-navigation-notice')).toContainText('Watch at least 90% of this lesson to unlock the next lesson.');

  isCompleted = true;
  await video.evaluate(element => { element.currentTime = 95; element.dispatchEvent(new Event('ended')); });
  await expect(page.getByRole('heading', { name: 'Basic lesson 1' })).toBeVisible();
  await expect(page.locator('.lesson-navigation-notice')).toContainText('Lesson complete. Submit your realization above to unlock the next lesson.');
  await expect(page.getByRole('button', { name: 'Next Lesson' })).toBeDisabled();

  await page.getByLabel('What did you take away from this lesson?').fill('I learned this lesson.');
  await page.getByRole('button', { name: 'Save realization' }).click();
  await expect(page.getByRole('button', { name: 'Next Lesson' })).toBeEnabled();
  await expect(page.getByText('Realization saved. The next lesson is ready when you are.')).toBeVisible();
  const playCallsBeforeNext = await page.evaluate(() => (window as any).__playCalls());
  await page.getByRole('button', { name: 'Next Lesson' }).click();
  await expect(page.getByRole('heading', { name: 'Basic lesson 2' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Previous Lesson' })).toBeEnabled();
  video = page.locator('.lesson-modal video');
  await video.evaluate(element => element.dispatchEvent(new Event('loadedmetadata')));
  expect(await page.evaluate(() => (window as any).__playCalls())).toBe(playCallsBeforeNext);
  await page.getByRole('button', { name: 'Previous Lesson' }).click();
  await expect(page.getByRole('heading', { name: 'Basic lesson 1' })).toBeVisible();
});
