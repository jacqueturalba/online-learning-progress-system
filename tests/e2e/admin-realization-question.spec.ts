import { expect, test } from '@playwright/test';

test('admin realization context shows the submitted question with its Question label', async ({ page }) => {
  await page.addInitScript(() => history.replaceState({}, '', '/admin/realizations'));
  await page.route('**/api/me', route => route.fulfill({ json: {
    user: { id: 1, name: 'Review Admin', email: 'admin@example.test', role: 'admin' },
  } }));
  await page.route('**/api/branding', route => route.fulfill({ json: {
    branding: { brand_name: 'Bible Study Portal', brand_description: 'Learn together.', logo_url: null,
      colors: { primary: '#087E9B', secondary: '#126B52', accent: '#855F18', background: '#F7FAFC', text: '#17212B' } },
  } }));
  await page.route('**/api/admin/realizations**', route => route.fulfill({ json: {
    realizations: { data: [{ id: 12, student_id: 12, student_name: 'Sample Student', student_email: 'student@example.test',
      pseudocode: 'ST-12', batch_id: null, batch_name: 'No batch assigned', realization_count: 1, realizations: [] }],
      current_page: 1, last_page: 1, total: 1 },
    filters: { categories: [], lessons: [], batches: [] },
  } }));
  await page.route('**/api/admin/lesson-milestone/responses', route => route.fulfill({ json: { responses: [] } }));
  await page.route('**/api/admin/students/12', route => route.fulfill({ json: {
    student: { learning_history: [{ id: 4, name: 'Basic', completed_lessons: 1, total_lessons: 1, progress_percentage: 100,
      lessons: [{ id: 7, title: 'Lesson One', is_completed: true, progress_percentage: 100,
        realization: 'I will practice this lesson this week.', questions: 'How can I apply this at work?' }] }] },
  } }));

  await page.goto('/');
  await page.getByRole('button', { name: /View Realization/ }).click();
  const entry = page.locator('.realization-lesson').filter({ hasText: 'Lesson One' });
  await expect(entry.getByText('Question', { exact: true })).toBeVisible();
  await expect(entry).toContainText('How can I apply this at work?');
  await expect(entry.getByText('Question', { exact: true })).toHaveCount(1);
});
