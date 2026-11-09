import { expect, test } from '@playwright/test';

for (const viewport of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
  for (const requirementEnabled of [false, true]) {
    test(`enrollment draft stays editable and restores on ${viewport.name} (requirement ${requirementEnabled ? 'enabled' : 'disabled'})`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      let savedData: Record<string, unknown> | null = null;
      let savedDocuments = { id_url: null as string|null, picture_url: null as string|null };
      await page.route('**/api/me', route => route.fulfill({ json: { user: { id: 991, name: 'Form Student', email: 'form-student@example.test', role: 'student' } } }));
      await page.route('**/api/learning', route => route.fulfill({ json: { categories: [], enrollment: { eligible: true, requirement_enabled: requirementEnabled, category_name: requirementEnabled ? 'Required Category' : null, lesson_number: requirementEnabled ? 2 : 0, status: savedData ? 'draft' : 'not_started', revision_reason: null } } }));
      await page.route('**/api/enrollment-form', async route => {
        if (route.request().method() === 'GET') return route.fulfill({ json: { form: savedData ? { status: 'draft', data: savedData } : null, documents: savedDocuments, prefill: { email: 'form-student@example.test' }, privacy_text: 'Test acknowledgment' } });
        const raw = route.request().postData() || '';
        expect(raw).toContain('name="data"');
        const match = raw.match(/name="data"\r\n\r\n([\s\S]*?)\r\n--/);
        expect(match).not.toBeNull();
        savedData = JSON.parse(match![1]);
        if (raw.includes('filename="student-id.png"')) savedDocuments.id_url='/testdocs/id';
        if (raw.includes('filename="student-picture.png"')) savedDocuments.picture_url='/testdocs/picture';
        return route.fulfill({ json: { form: { status: raw.includes('name="action"\r\n\r\nsubmit') ? 'submitted' : 'draft' }, documents: savedDocuments } });
      });
      await page.route('**/testdocs/**', route => route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8q0AAAAASUVORK5CYII=', 'base64') }));

      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Complete form' }).click();
      const firstName = page.getByLabel('First Name');
      await expect(firstName).toBeEditable();
      await firstName.fill('Restored Student');
      await page.getByLabel('Gender').selectOption('Female');
      await page.getByLabel('Civil Status').selectOption('Single');
      await page.getByLabel('Country Currently At').selectOption('Philippines');
      await page.getByLabel('How well can you speak and understand English?').selectOption('Intermediate');
      await page.getByLabel('Position in the Church').selectOption('Non-Pastor');
      await page.getByLabel('Complete Address').fill('12 Learning Road');
      await page.getByLabel('Birthday', { exact: true }).fill('1990-01-02');
      await page.getByLabel('I acknowledge this statement (required to submit).').check();
      await page.getByLabel('Identification Card or ID (image or PDF)').setInputFiles({ name: 'student-id.png', mimeType: 'image/png', buffer: Buffer.from('png fixture') });
      await page.getByLabel('Picture').setInputFiles({ name: 'student-picture.png', mimeType: 'image/png', buffer: Buffer.from('png fixture') });
      await page.getByRole('button', { name: 'Save Draft' }).click();
      await expect(page.getByText('Draft saved.')).toBeVisible();
      await expect(page.getByRole('link', { name: 'View current ID document' })).toBeVisible();
      await expect(page.getByAltText('Currently uploaded student picture')).toBeVisible();
      expect(savedData).toMatchObject({ first_name: 'Restored Student', gender: 'Female', civil_status: 'Single', country_current: 'Philippines', english_level: 'Intermediate', church_position: 'Non-Pastor', address: '12 Learning Road', birthday: '1990-01-02', privacy_acknowledged: true });

      await page.reload();
      await page.getByRole('button', { name: 'Open form' }).click();
      await expect(page.getByLabel('First Name')).toHaveValue('Restored Student');
      await expect(page.getByLabel('Gender')).toHaveValue('Female');
      await expect(page.getByLabel('Civil Status')).toHaveValue('Single');
      await expect(page.getByLabel('Country Currently At')).toHaveValue('Philippines');
      await expect(page.getByLabel('How well can you speak and understand English?')).toHaveValue('Intermediate');
      await expect(page.getByLabel('Complete Address')).toHaveValue('12 Learning Road');
      await expect(page.getByLabel('Position in the Church')).toHaveValue('Non-Pastor');
      await expect(page.getByLabel('I acknowledge this statement (required to submit).')).toBeChecked();
      await expect(page.getByRole('link', { name: 'View current ID document' })).toBeVisible();
      await expect(page.getByAltText('Currently uploaded student picture')).toBeVisible();

      await page.getByLabel('Last Name').fill('Learner');
      await page.getByLabel('Do you have a child or children already?').selectOption('No');
      await page.getByLabel('Nationality').fill('Filipino');
      await page.getByLabel('First Language').fill('English');
      await page.getByLabel('How well can you speak and understand English?').selectOption('Proficient');
      if (viewport.name === 'desktop') await page.getByLabel('Employment', { exact: true }).fill('Employed');
      await page.getByLabel('Phone Number', { exact: true }).fill('+63 9123456789');
      await page.getByLabel('Email').fill('form-student@example.test');
      await page.getByLabel('Complete Name of Church').fill('Community Church');
      await page.getByLabel('Number of Years Being a Believer').fill('5');
      await page.getByLabel('Church Address').fill('Makati');
      await page.getByRole('button', { name: 'Submit' }).click();
      await expect(page.getByText('Submitted for review.')).toBeVisible();
      await expect(page.getByLabel('First Name')).not.toBeEditable();
    });
  }
}
