import { expect, test } from '@playwright/test';

const meetingResponse = (occurrenceUuid = '') => ({
  report: 'meeting_attendance',
  rows: {
    data: occurrenceUuid ? [{
      id: 51, batch_id: 7, batch_name: 'Batch 7', category_name: 'Leadership', meeting_title: 'Weekly meeting',
      occurrence_uuid: occurrenceUuid, occurrence_date: '2026-11-08T11:00:00Z', meeting_schedule: 'Weekly',
      student_id: 9, student_name: 'Alex Student', participant_name: 'Alex Student', pseudocode: 'ALEX-001',
      attendance_status: 'Matched', join_time: '2026-11-08T11:00:00Z', leave_time: '2026-11-08T11:30:00Z',
      total_duration_seconds: 1800, number_of_joins: 1, matching_method: 'email', registrant_id: 'registration-1',
      participant_id: 'participant-1', participant_email: 'alex@example.test',
    }] : [],
    current_page: 1, last_page: 1, total: occurrenceUuid ? 1 : 0, per_page: 25,
  },
  filters: {
    categories: [{ id: 2, name: 'Leadership', deleted_at: null }],
    batches: [{ id: 7, name: 'Batch 7', deleted_at: null }],
    lessons: [], pace_groups: [],
    meetings: [{ id: 21, title: 'Weekly meeting', batch_id: 7, occurrence_dates: [], occurrences: [
      { uuid: 'uuid-one', started_at: '2026-11-08T11:00:00Z' },
      { uuid: 'uuid-two', started_at: '2026-11-09T13:00:00Z' },
    ] }],
  },
  attendance_summary: {
    occurrence_count: 2, matched_count: 2, unmatched_count: 1, total_duration_seconds: 5400,
    batches: [{ batch_id: 7, batch_name: 'Batch 7', occurrences: 2, matched: 2, unmatched: 1, duration_seconds: 5400 }],
    meetings: [],
  },
});

async function signInAsAdmin(page: import('@playwright/test').Page) {
  await page.goto('/');
  await page.evaluate(async () => {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('/login', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ email: 'admin@example.test', password: 'ChangeMe123!' }) });
    if (!response.ok) throw new Error('Could not sign in as the test admin.');
  });
  await page.goto('/admin/reports');
}

test('meeting attendance occurrence selection and filter layout on desktop and mobile', async ({ page }) => {
    const reportRequests: URL[] = [];
    let emptyMode = false;
    await page.route('**/api/admin/reports*', async route => {
      const url = new URL(route.request().url());
      reportRequests.push(url);
      const occurrence = url.searchParams.get('occurrence_uuid') || '';
      const data = meetingResponse(occurrence);
      if (emptyMode) {
        data.filters.meetings = [];
        data.attendance_summary.batches = [];
        data.attendance_summary.meetings = [];
      }
      await route.fulfill({ json: data });
    });

    await signInAsAdmin(page);
    for (const viewport of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto('/admin/reports');
    await page.getByRole('button', { name: 'Meeting attendance', exact: true }).click();
    await expect(page.getByLabel('Meeting')).toBeVisible();
    await page.getByLabel('Meeting').selectOption('21');
    const occurrence = page.getByLabel('Occurrence');
    await expect(occurrence).toBeVisible();
    await expect(occurrence.locator('option')).toHaveCount(3);

    await expect(page.getByRole('heading', { name: 'Batch summaries' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Zoom participant name' })).toHaveCount(0);
    await occurrence.selectOption('uuid-two');
    await expect.poll(() => reportRequests.at(-1)?.searchParams.get('occurrence_uuid')).toBe('uuid-two');
    await expect(page.getByRole('columnheader', { name: 'Zoom participant name' })).toBeVisible();
    await expect(page.getByText('Alex Student').last()).toBeVisible();

    const layout = await page.locator('.report-filters').evaluate(element => {
      const panel = element.closest('.reports-panel')!.getBoundingClientRect();
      const labels = Array.from(element.querySelectorAll(':scope > label'));
      return {
        panelLeft: panel.left,
        panelRight: panel.right,
        labels: labels.map(label => {
          const rect = label.getBoundingClientRect();
          const control = label.querySelector('select, input')!.getBoundingClientRect();
          return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, controlLeft: control.left, controlRight: control.right, controlWidth: control.width };
        }),
      };
    });
    expect(layout.labels.length).toBeGreaterThan(4);
    for (const label of layout.labels) {
      expect(label.left).toBeGreaterThanOrEqual(layout.panelLeft);
      expect(label.right).toBeLessThanOrEqual(layout.panelRight);
      expect(label.controlWidth).toBeGreaterThan(100);
      expect(label.controlLeft).toBeGreaterThanOrEqual(label.left);
      expect(label.controlRight).toBeLessThanOrEqual(label.right);
    }
    if (viewport.name === 'mobile') {
      expect(new Set(layout.labels.map(label => Math.round(label.left))).size).toBe(1);
      expect(layout.labels.every((label, index) => index === 0 || label.top > layout.labels[index - 1].top)).toBe(true);
    }
    await expect(page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).resolves.toBe(true);
    if (viewport.name === 'desktop') {
      emptyMode = true;
      await page.reload();
      await page.getByRole('button', { name: 'Meeting attendance', exact: true }).click();
      await expect(page.getByText('No meeting occurrences are available yet.')).toBeVisible();
      await expect(page.locator('.report-table-wrap .report-table')).toHaveCount(0);
      emptyMode = false;
    }
    }
});
