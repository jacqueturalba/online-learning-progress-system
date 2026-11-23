import { expect, test } from '@playwright/test';

test('public homepage removes the study section and keeps the learning introduction responsive', async ({ page }) => {
  const failedAssets: string[] = [];
  page.on('response', response => {
    if (response.url().includes('/storage/branding/') && response.status() >= 400) failedAssets.push(`${response.status()} ${response.url()}`);
  });

  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /let us discover god's word and grow in understanding together/i })).toBeVisible();
  await expect(page.getByRole('heading', { name: /a place to learn the bible, freely and together/i })).toBeVisible();
  await expect(page.getByRole('heading', { name: /let us look deeper into god's word/i })).toBeVisible();
  await expect(page.locator('.bible-study-section')).toHaveCount(0);
  await expect(page.locator('.public-hero button, .public-hero [role="button"], .public-hero .public-cta')).toHaveCount(0);
  await expect(page.locator('.public-hero h1')).toBeVisible();
  await expect(page.locator('.public-hero .public-intro')).toBeVisible();
  await expect(page.locator('.instructor-quote')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Explore Bible Classes' })).toHaveAttribute('href', '/register');
  await expect(page.locator('.welcome-brand-mark')).toHaveAttribute('src', /zcmc-logo\.svg$/);
  await expect(page.locator('.welcome-class-video')).toHaveAttribute('src', /class-video\.gif$/);
  await expect(page.locator('.welcome-class-video')).toHaveAttribute('loading', 'lazy');
  await expect(page.locator('.welcome-class-video')).toHaveAttribute('fetchpriority', 'low');
  await expect(page.locator('.scripture-learning-visual')).toHaveAttribute('src', /verse\.gif$/);
  await expect(page.locator('.scripture-learning-visual')).toHaveAttribute('loading', 'lazy');
  await expect(page.locator('.free-resource')).toHaveCount(3);

  const brand = page.locator('.welcome-brand-mark');
  await brand.evaluate(image => (image as HTMLImageElement).decode());
  const logoDimensions = await brand.evaluate(image => ({ width: (image as HTMLImageElement).naturalWidth, height: (image as HTMLImageElement).naturalHeight }));
  expect(logoDimensions.width).toBeGreaterThan(0);
  expect(logoDimensions.height).toBeGreaterThan(0);

  const visuals = await page.locator('.free-resource').evaluateAll(images => images.map(image => ({
    animation: getComputedStyle(image).animationName,
    naturalWidth: (image as HTMLImageElement).naturalWidth,
    naturalHeight: (image as HTMLImageElement).naturalHeight,
  })));
  expect(visuals.map(image => image.animation)).toEqual(['resource-float-left', 'resource-float-right', 'resource-float-front']);
  expect(new Set(visuals.map(image => image.animation)).size).toBe(3);
  expect(visuals.every(image => image.naturalWidth > 0 && image.naturalHeight > 0)).toBe(true);
  const freeClassesBackground = await page.locator('.free-classes').evaluate(element => getComputedStyle(element).backgroundColor);
  expect(freeClassesBackground).toBe('rgb(237, 244, 248)');
  const buttonColors = await page.locator('.welcome-cta').evaluate(element => ({ background: getComputedStyle(element).backgroundColor, color: getComputedStyle(element).color }));
  expect(buttonColors).toEqual({ background: 'rgb(57, 121, 168)', color: 'rgb(255, 255, 255)' });
  await page.locator('.welcome-cta').hover();
  expect(await page.locator('.welcome-cta').evaluate(element => getComputedStyle(element).backgroundColor)).toBe('rgb(46, 102, 143)');

  for (const viewport of [{ width: 1440, height: 1000 }, { width: 900, height: 900 }, { width: 620, height: 844 }, { width: 390, height: 844 }, { width: 320, height: 740 }]) {
    await page.setViewportSize(viewport);
    await expect(page.locator('.free-resource-front')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  }

  await page.emulateMedia({ reducedMotion: 'reduce' });
  expect(await page.locator('.free-resource').first().evaluate(element => getComputedStyle(element).animationName)).toBe('none');
  expect(failedAssets).toEqual([]);
});
