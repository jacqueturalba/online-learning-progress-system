import { expect, test } from '@playwright/test';

test('public homepage shows the Bible portal design, working auth links, and responsive media', async ({ page }) => {
  const failedAssets: string[] = [];
  page.on('response', response => {
    if (response.url().includes('/storage/branding/home/') && response.status() >= 400) failedAssets.push(`${response.status()} ${response.url()}`);
  });

  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /let's discover the word of god/i })).toBeVisible();
  await expect(page.getByRole('img', { name: /head instructor soo oh/i })).toBeVisible();
  await expect(page.getByRole('heading', { name: /ready to begin your bible study journey/i })).toBeVisible();
  await expect(page.locator('.featured-student-video')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Sign in', exact: true }).first()).toHaveAttribute('href', '/login');
  await expect(page.getByRole('link', { name: 'Sign up', exact: true })).toHaveAttribute('href', '/register');

  const media = await page.evaluate(async () => {
    const images = [...document.querySelectorAll<HTMLImageElement>('.public-home img')];
    await Promise.all(images.map(image => image.decode()));
    const heroCard = document.querySelector<HTMLElement>('.public-hero-copy')!;
    const lessonImages = [...document.querySelectorAll<HTMLImageElement>('.lesson-image-orbit img')];
    const video = document.querySelector<HTMLVideoElement>('.featured-student-video')!;
    video.preload = 'metadata'; video.load();
    await new Promise<void>((resolve, reject) => {
      if (video.readyState >= HTMLMediaElement.HAVE_METADATA) return resolve();
      video.addEventListener('loadedmetadata', () => resolve(), { once: true });
      video.addEventListener('error', () => reject(new Error('Featured video could not be loaded.')), { once: true });
      window.setTimeout(() => reject(new Error('Timed out loading featured video metadata.')), 8000);
    });
    return {
      images: images.map(image => image.naturalWidth > 0),
      lessonRatios: lessonImages.map(image => image.getBoundingClientRect().width / image.getBoundingClientRect().height),
      clipPath: getComputedStyle(heroCard).clipPath,
      duration: video.duration,
      width: video.videoWidth,
      height: video.videoHeight,
      muted: video.muted,
    };
  });
  expect(media.images.every(Boolean)).toBe(true);
  expect(media.clipPath).toContain('portal-hero-card-clip');
  expect(media.lessonRatios.every(ratio => Math.abs(ratio - 5 / 3) < 0.02)).toBe(true);
  expect(media.width).toBeGreaterThan(0);
  expect(media.height).toBeGreaterThan(0);
  expect(media.duration).toBeGreaterThan(0);
  expect(media.muted).toBe(false);

  for (const viewport of [
    { width: 1440, height: 1000 },
    { width: 1200, height: 900 },
    { width: 900, height: 900 },
    { width: 768, height: 900 },
    { width: 621, height: 900 },
    { width: 620, height: 844 },
    { width: 390, height: 844 },
    { width: 320, height: 740 },
  ]) {
    await page.setViewportSize(viewport);
    await expect(page.getByRole('heading', { name: /let's discover the word of god/i })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const ratios = await page.locator('.lesson-image-orbit img').evaluateAll(images => images.map(image => {
      const { width, height } = image.getBoundingClientRect();
      return width / height;
    }).filter(ratio => Number.isFinite(ratio)));
    expect(ratios.every(ratio => Math.abs(ratio - 5 / 3) < 0.02), `Expected 5:3 lesson previews at ${viewport.width}px; got ${ratios.join(', ')}`).toBe(true);
    const pair = await page.evaluate(() => {
      const stage = document.querySelector('.bible-media-stage')!.getBoundingClientRect();
      const cards = ['.lesson-card-5', '.lesson-card-10'].map(selector => {
        const rect = document.querySelector(selector)!.getBoundingClientRect();
        return { left: rect.left - stage.left, right: rect.right - stage.left, top: rect.top - stage.top, bottom: rect.bottom - stage.top };
      });
      return { stageWidth: stage.width, cards };
    });
    const [basicLesson06, introLesson04] = pair.cards;
    const overlaps = basicLesson06.left < introLesson04.right && basicLesson06.right > introLesson04.left
      && basicLesson06.top < introLesson04.bottom && basicLesson06.bottom > introLesson04.top;
    expect(overlaps, `Basic lesson 06 and Intro_Lesson_04 overlap at ${viewport.width}px`).toBe(false);
    expect(basicLesson06.left, `Basic lesson 06 extends beyond the stage at ${viewport.width}px`).toBeGreaterThanOrEqual(0);
    expect(introLesson04.right, `Intro_Lesson_04 extends beyond the stage at ${viewport.width}px`).toBeLessThanOrEqual(pair.stageWidth);
  }
  expect(failedAssets).toEqual([]);

  await page.getByRole('link', { name: 'Start your journey' }).click();
  await expect(page).toHaveURL(/\/register$/);
  await expect(page.getByRole('heading', { name: 'Create your account.' })).toBeVisible();
  await expect(page.getByLabel('Your name')).toBeVisible();
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Good to see you.' })).toBeVisible();
  await expect(page.getByLabel('Email address')).toBeVisible();
});
