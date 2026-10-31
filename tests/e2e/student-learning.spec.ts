import { expect, test, type Page } from '@playwright/test';

const adminEmail = 'admin@example.test';
const adminPassword = 'ChangeMe123!';

async function asAdmin(page: Page, action: (request: (url: string, method?: string, body?: unknown) => Promise<any>) => Promise<void>, signOutAfter = true) {
  await page.evaluate(async ({ email, password }) => {
    const request = async (url: string, method = 'GET', body?: unknown) => {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
      const response = await fetch(url, {
        method, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || `Request failed: ${url}`);
      return data;
    };
    try { await request('/logout', 'POST'); } catch { /* No active browser session before setup. */ }
    await request('/login', 'POST', { email, password });
  }, { email: adminEmail, password: adminPassword });

  await action(async (url, method = 'GET', body) => page.evaluate(async ({ url, method, body }) => {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(url, {
      method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || `Request failed: ${url}`);
    return data;
  }, { url, method, body }));
  if (signOutAfter) {
    await page.evaluate(async () => {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
      await fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
    });
  }
}

test('Continue resumes playback, advances to the next lesson, and offers an autoplay fallback', async ({ page }) => {
  await page.addInitScript(() => {
    const positions = new WeakMap<HTMLMediaElement, number>();
    Object.defineProperty(HTMLMediaElement.prototype, 'duration', { configurable: true, get: () => 2 });
    Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', {
      configurable: true,
      get() { return positions.get(this) ?? 0; },
      set(value: number) { positions.set(this, Number(value)); },
    });
    let playCalls = 0;
    (window as any).__controlledVideoPlayCalls = () => playCalls;
    HTMLMediaElement.prototype.play = function () {
      playCalls += 1;
      if (playCalls === 2) return Promise.reject(new DOMException('Autoplay is blocked in this test.', 'NotAllowedError'));
      this.dispatchEvent(new Event('play'));
      return Promise.resolve();
    };
    HTMLMediaElement.prototype.pause = function () { this.dispatchEvent(new Event('pause')); };
  });

  await page.route('https://media.example.test/**', route => route.fulfill({
    status: 200, contentType: 'video/mp4', body: 'controlled video fixture',
  }));
  await page.route('**/api/learning', async route => {
    const response = await route.fetch();
    const payload = await response.json();
    for (const category of payload.categories) {
      for (const lesson of category.lessons) lesson.video_url = `https://media.example.test/lesson-${lesson.id}.mp4`;
    }
    await route.fulfill({ response, body: JSON.stringify(payload) });
  });

  await page.goto('/');
  let lessonIds: number[] = [];
  try {
    await asAdmin(page, async request => {
      const result = await request('/api/admin/lessons');
      lessonIds = result.lessons.data.filter((lesson: { title: string }) => lesson.title.startsWith('Basic · Lesson ')).slice(0, 2).map((lesson: { id: number }) => lesson.id);
      expect(lessonIds).toHaveLength(2);
      for (const id of lessonIds) await request(`/api/admin/lessons/${id}`, 'PUT', { duration_seconds: 2, video_url: `https://media.example.test/lesson-${id}.mp4` });
    });

    await page.getByRole('button', { name: 'Create an account' }).click();
    await page.getByLabel('Your name').fill('Playwright Student');
    await page.getByLabel('Email address').fill(`student-${Date.now()}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('StrongPass123!');
    await page.getByLabel('Confirm password').fill('StrongPass123!');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Good morning, Playwright/ })).toBeVisible();

    await page.evaluate(async lessonId => {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
      const put = (position: number, isPlaying: boolean) => fetch(`/api/lessons/${lessonId}/progress`, {
        method: 'PUT', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ position_seconds: position, is_playing: isPlaying }),
      });
      await put(0, true);
      await new Promise(resolve => setTimeout(resolve, 1100));
      await put(1, true);
      await put(1, false);
    }, lessonIds[0]);

    await page.reload();
    await page.getByRole('button', { name: 'Continue learning' }).click();
    const firstVideo = page.locator('.lesson-modal video');
    await expect(firstVideo).toBeVisible();
    await firstVideo.evaluate(video => video.dispatchEvent(new Event('loadedmetadata')));
    await expect.poll(() => firstVideo.evaluate(video => video.currentTime)).toBe(1);

    await page.waitForTimeout(2200);
    await firstVideo.evaluate(video => {
      video.currentTime = 2;
      video.dispatchEvent(new Event('ended'));
    });
    await expect(page.getByRole('heading', { name: 'Basic · Lesson 2' })).toBeVisible();
    const secondVideo = page.locator('.lesson-modal video');
    await secondVideo.evaluate(video => video.dispatchEvent(new Event('loadedmetadata')));
    await expect(page.getByRole('button', { name: 'Continue lesson' })).toBeVisible();
    await page.getByRole('button', { name: 'Continue lesson' }).click();
    await expect(page.getByRole('button', { name: 'Continue lesson' })).toBeHidden();
  } finally {
    if (lessonIds.length) {
      await asAdmin(page, async request => {
        for (const id of lessonIds) await request(`/api/admin/lessons/${id}`, 'PUT', { duration_seconds: 300, video_url: `https://media.example.test/lesson-${id}.mp4` });
      }).catch(() => undefined);
    }
  }
});

test('student and admin views use the shared palette and fit mobile and desktop widths', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await page.getByRole('button', { name: 'Create an account' }).click();
  await page.getByLabel('Your name').fill('Responsive Student');
  await page.getByLabel('Email address').fill(`responsive-${Date.now()}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('StrongPass123!');
  await page.getByLabel('Confirm password').fill('StrongPass123!');
  await page.getByRole('button', { name: 'Create account', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Continue learning' })).toBeVisible();
  await expect(page.locator('.sidebar nav .nav-item')).toHaveText(['Home', 'My learning', 'Progress', 'Profile']);

  const mobileStudent = await page.evaluate(() => ({
    widthFits: document.documentElement.scrollWidth <= window.innerWidth,
    primary: getComputedStyle(document.documentElement).getPropertyValue('--brand-blue').trim(),
    actionColor: getComputedStyle(document.querySelector('.hero-button')!).backgroundColor,
    canvas: getComputedStyle(document.body).backgroundColor,
  }));
  expect(mobileStudent).toEqual({ widthFits: true, primary: '#05a1d8', actionColor: 'rgb(5, 161, 216)', canvas: 'rgb(247, 250, 252)' });

  await asAdmin(page, async () => {}, false);
  await page.reload();
  await expect(page).toHaveURL(/\/admin\/dashboard$/);
  await expect(page.getByRole('heading', { name: 'Students by category' })).toBeVisible();
  const dashboard = await page.evaluate(async () => (await (await fetch('/api/admin/dashboard')).json()));
  await expect(page.locator('.dashboard-metric').nth(0)).toContainText(String(dashboard.total_students));
  await expect(page.locator('.dashboard-metric').nth(1)).toContainText(String(dashboard.active_students));
  await expect(page.locator('.dashboard-metric').nth(2)).toContainText(String(dashboard.total_lessons));
  await expect(page.locator('.dashboard-metric').nth(3)).toContainText(String(dashboard.completed_lessons));
  await expect(page.locator('.dashboard-metric').nth(4)).toContainText(String(dashboard.transfer_eligible_students));
  await expect(page.getByRole('link', { name: 'Students', exact: true }).last()).toHaveAttribute('href', '/admin/students');
  await expect(page.getByRole('link', { name: 'Lessons', exact: true }).last()).toHaveAttribute('href', '/admin/lessons');
  await expect(page.getByRole('link', { name: 'Progress', exact: true }).last()).toHaveAttribute('href', '/admin/progress');
  await expect(page.getByRole('link', { name: 'Realizations', exact: true }).last()).toHaveAttribute('href', '/admin/realizations');
  await expect(page.getByRole('link', { name: 'Reports', exact: true }).last()).toHaveAttribute('href', '/admin/reports');
  const adminLabels = ['Dashboard', 'Students', 'Categories', 'Lessons', 'Zoom', 'Progress', 'Realizations', 'Reports', 'Settings'];
  await expect(page.locator('.admin-sidebar nav .nav-item')).toHaveText(adminLabels);
  const mobileAdminFits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
  expect(mobileAdminFits).toBe(true);

  await page.setViewportSize({ width: 768, height: 900 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('.mobile-menu').click();
  await page.getByRole('navigation', { name: 'Admin navigation' }).getByRole('link', { name: 'Students' }).click();
  await expect(page).toHaveURL(/\/admin\/students$/);
  await expect(page.locator('.admin-nav-item[aria-current="page"]')).toHaveText('Students');
  await expect(page.getByRole('heading', { name: 'Students' })).toBeVisible();
  const firstStudent = await page.evaluate(async () => (await (await fetch('/api/admin/students')).json()).students.data[0]);
  await page.getByRole('searchbox', { name: 'Search students' }).fill(firstStudent.pseudocode);
  const studentRow = page.locator('.student-table tbody tr').filter({ hasText: firstStudent.name });
  await expect(studentRow).toBeVisible();
  await studentRow.getByRole('button', { name: `View ${firstStudent.name}` }).click();
  await expect(page.getByRole('heading', { name: firstStudent.name })).toBeVisible();
  const originalPace = firstStudent.pace_group;
  await page.getByLabel('Student pace group').selectOption(originalPace === 'A' ? 'B' : 'A');
  await page.getByRole('button', { name: 'Save pace' }).click();
  await expect(page.getByRole('status')).toContainText('Pace group updated.');
  await page.getByLabel('Student pace group').selectOption(originalPace);
  await page.getByRole('button', { name: 'Save pace' }).click();
  await expect(page.getByRole('status')).toContainText('Pace group updated.');

  await page.setViewportSize({ width: 1280, height: 900 });
  const desktop = await page.evaluate(() => ({
    sidebarVisible: getComputedStyle(document.querySelector('.sidebar')!).transform === 'none',
    mainOffset: Number.parseFloat(getComputedStyle(document.querySelector('.main-area')!).marginLeft),
    widthFits: document.documentElement.scrollWidth <= window.innerWidth,
  }));
  expect(desktop.sidebarVisible).toBe(true);
  expect(desktop.mainOffset).toBeGreaterThan(0);
  expect(desktop.widthFits).toBe(true);

  await page.getByRole('navigation', { name: 'Admin navigation' }).getByRole('link', { name: 'Categories' }).click();
  await expect(page).toHaveURL(/\/admin\/categories$/);
  await page.setViewportSize({ width: 390, height: 844 });
  const categoryName = `Playwright Category ${Date.now()}`;
  await page.getByLabel('Category name').fill(categoryName);
  await page.locator('textarea[name="description"]').fill('Created by the category management browser test.');
  await page.getByLabel('Display order').fill('37');
  await page.getByLabel('Available for student progression').uncheck();
  await page.locator('.category-editor-form').getByRole('button', { name: 'Add category' }).click();
  const categoryCard = page.locator('.managed-category-card').filter({ hasText: categoryName });
  await expect(categoryCard).toContainText('Unavailable');
  await expect(categoryCard).toContainText('Order 37');
  await expect(categoryCard).toContainText('0 lessons');

  await categoryCard.getByRole('button', { name: `Edit ${categoryName}` }).click();
  await page.getByLabel('Display order').fill('3');
  await page.locator('.category-editor-form').getByRole('button', { name: 'Save changes' }).click();
  await expect(categoryCard).toContainText('Order 3');

  page.once('dialog', dialog => dialog.accept());
  await categoryCard.getByRole('button', { name: `Archive ${categoryName}` }).click();
  await expect(categoryCard).toContainText('Archived');
  await categoryCard.getByRole('button', { name: `Restore ${categoryName}` }).click();
  await expect(categoryCard).toContainText('Unavailable');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
