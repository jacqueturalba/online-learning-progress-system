import { expect, test } from '@playwright/test';

test('a student can register, resume a lesson, and see completion', async ({ page }) => {
  const email = `student-${Date.now()}@example.test`;
  await page.goto('/');
  await page.getByRole('button', { name: 'Create an account' }).click();
  await page.getByLabel('Your name').fill('Playwright Student');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('StrongPass123!');
  await page.getByLabel('Confirm password').fill('StrongPass123!');
  await page.getByRole('button', { name: 'Create account', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Good morning, Playwright/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Basic' })).toBeVisible();
  await page.getByRole('button', { name: /Basic · Lesson 1/ }).click();
  await expect(page.getByRole('heading', { name: 'Basic · Lesson 1' })).toBeVisible();
  await page.getByRole('button', { name: 'Mark lesson complete' }).click();
  await expect(page.getByText('1 of 8 lessons', { exact: false })).toBeVisible();
});
