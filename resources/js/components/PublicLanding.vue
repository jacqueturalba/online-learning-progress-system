<script setup lang="ts">
const homeAssets = '/storage/branding/home/';
const lessonCards = [
  { name: 'Basic_lesson_01.png', className: 'lesson-card-1', alt: 'Bible lesson about the early generations' },
  { name: 'Basic_lesson_02.png', className: 'lesson-card-2', alt: 'Bible lesson illustration' },
  { name: 'Basic_lesson_04.png', className: 'lesson-card-3', alt: 'Bible lesson illustration' },
  { name: 'Basic_lesson_05.png', className: 'lesson-card-4', alt: 'Bible lesson illustration' },
  { name: 'Basic_lesson_06.png', className: 'lesson-card-5', alt: 'Bible lesson illustration' },
  { name: 'Intro_Lesson_01.png', className: 'lesson-card-6', alt: 'Introduction to the Bible lesson' },
  { name: 'Intro_Lesson_02_1.png', className: 'lesson-card-7', alt: 'Introduction to the Bible lesson' },
  { name: 'Intro_Lesson_02_2.png', className: 'lesson-card-8', alt: 'Introduction to the Bible lesson' },
  { name: 'Intro_Lesson_03.png', className: 'lesson-card-9', alt: 'Introduction to the Bible lesson' },
  { name: 'Intro_Lesson_04.png', className: 'lesson-card-10', alt: 'Introduction to the Bible lesson' },
];
</script>

<template>
  <main class="public-home">
    <svg class="custom-card-shape-definitions" aria-hidden="true" focusable="false">
      <defs>
        <clipPath id="portal-hero-card-clip" clipPathUnits="objectBoundingBox">
          <path d="M .13 0 H .63 C .67 0 .70 .015 .73 .05 L .94 .28 C .98 .32 1 .36 1 .42 V .87 C 1 .94 .94 1 .87 1 H .13 C .06 1 0 .94 0 .87 V .13 C 0 .06 .06 0 .13 0 Z" />
        </clipPath>
      </defs>
    </svg>
    <section class="public-hero" aria-labelledby="public-home-title">
      <header class="public-header">
        <a class="public-wordmark" href="/" aria-label="Bible Study Portal home">BIBLE STUDY PORTAL</a>
        <nav class="public-auth-links" aria-label="Account">
          <a class="public-sign-in" href="/login">Sign in</a>
          <a class="public-sign-up" href="/register">Sign up</a>
        </nav>
      </header>
      <div class="public-hero-grid">
        <div class="public-hero-copy custom-cut-card">
          <p class="public-kicker">BIBLE STUDY PORTAL</p>
          <h1 id="public-home-title">Let's discover<br>the Word of God</h1>
          <p class="public-intro">Begin your journey to understanding the Bible through guided lessons and meaningful learning.</p>
          <a class="public-cta" href="/register">Start your journey</a>
          <svg class="custom-cut-outline" viewBox="0 0 1 1" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path d="M .13 0 H .63 C .67 0 .70 .015 .73 .05 L .94 .28 C .98 .32 1 .36 1 .42 V .87 C 1 .94 .94 1 .87 1 H .13 C .06 1 0 .94 0 .87 V .13 C 0 .06 .06 0 .13 0 Z" />
          </svg>
        </div>
        <img class="instructor-quote" :src="`${homeAssets}head_instructor_quote.png`" alt="Head Instructor Soo Oh: Since your desire to understand is good, the Lord will grant you the answer (truth). Zion Christian Mission Center PH">
      </div>
    </section>

    <section class="bible-study-section" aria-labelledby="bible-study-title">
      <div class="bible-study-heading">
        <h2 id="bible-study-title">Ready to begin your Bible study journey?</h2>
        <p>We're here to guide you.</p>
      </div>
      <div class="bible-media-stage">
        <div class="lesson-image-orbit" aria-label="A preview of Bible study lessons">
          <img v-for="card in lessonCards" :key="card.name" :class="card.className" :src="`${homeAssets}${card.name}`" :alt="card.alt" loading="lazy">
        </div>
        <video autoplay loop muted class="featured-student-video" :src="`${homeAssets}student_watching.mp4`" controls playsinline preload="metadata" aria-label="A student watching a Bible study lesson"></video>
      </div>
    </section>
    <footer class="public-footer"><a href="/login">Sign in</a><span aria-hidden="true">·</span><a href="/register">Create an account</a></footer>
  </main>
</template>

<style scoped>
.custom-card-shape-definitions{position:absolute;width:0;height:0;overflow:hidden}.custom-cut-card{position:relative;isolation:isolate;clip-path:url(#portal-hero-card-clip);filter:drop-shadow(0 14px 26px rgba(0,15,47,.16))}.custom-cut-outline{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none}.custom-cut-outline path{fill:none;stroke:rgba(255,255,255,.52);stroke-width:1.5px;vector-effect:non-scaling-stroke}
.public-home{min-height:100vh;background:#031c40;color:#fff;overflow:hidden;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.public-hero{position:relative;min-height:740px;padding:0 clamp(28px,5vw,76px) 54px;background:#174998 url('/storage/branding/home/hero_section_bg.png') center top/cover no-repeat}.public-header{height:108px;max-width:1360px;margin:0 auto;display:flex;justify-content:flex-end;align-items:center;gap:18px}.public-wordmark{margin-right:auto;color:#fff;text-decoration:none;font:600 14px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.13em;opacity:0;pointer-events:none}.public-auth-links{display:flex;gap:10px}.public-sign-in,.public-sign-up{min-width:104px;min-height:43px;display:inline-flex;align-items:center;justify-content:center;padding:0 18px;border-radius:6px;text-decoration:none;text-transform:uppercase;font:500 16px ui-monospace,SFMono-Regular,Menlo,monospace;transition:transform .16s ease,background-color .16s ease}.public-sign-in{color:#082546;background:#d4f1ff}.public-sign-up,.public-cta{color:#fff;background:#0878ed}.public-sign-in:hover,.public-sign-up:hover,.public-cta:hover{transform:translateY(-2px);filter:brightness(1.08)}.public-hero-grid{max-width:1360px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) minmax(420px,1fr);align-items:center;gap:clamp(34px,5vw,72px)}.public-hero-copy{min-height:616px;padding:clamp(42px,6.8vw,96px) clamp(34px,4.4vw,60px);border:0;border-radius:0;background:linear-gradient(140deg,rgba(219,241,255,.30),rgba(113,156,216,.08));box-shadow:inset 0 1px 0 rgba(255,255,255,.24);backdrop-filter:blur(3px);display:flex;flex-direction:column;justify-content:center}.public-kicker{margin:0 0 39px;font:500 15px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.11em}.public-hero-copy h1{margin:0;font-size:clamp(44px,5.2vw,72px);font-weight:400;line-height:.99;letter-spacing:-.055em;text-wrap:balance}.public-intro{max-width:510px;margin:48px 0 48px;font:500 clamp(17px,1.8vw,23px)/1.48 ui-monospace,SFMono-Regular,Menlo,monospace;text-transform:uppercase;letter-spacing:.005em}.public-cta{align-self:flex-start;min-height:51px;padding:0 20px;display:inline-flex;align-items:center;border-radius:8px;text-decoration:none;text-transform:uppercase;font:500 18px ui-monospace,SFMono-Regular,Menlo,monospace;transition:transform .16s ease,filter .16s ease}.instructor-quote{display:block;width:100%;max-width:615px;aspect-ratio:1;justify-self:end;object-fit:cover;border-radius:46px;box-shadow:0 22px 65px rgba(0,15,47,.22)}.bible-study-section{position:relative;isolation:isolate;min-height:840px;margin-top:-1px;padding:8px 24px 180px;background:#021d43 url('/storage/branding/home/shining_bible_bg.png') center bottom/100% auto no-repeat}.bible-study-section:before{position:absolute;inset:0;z-index:-1;background:linear-gradient(180deg,#061e4b 0%,rgba(1,32,76,.2) 40%,transparent 100%);content:""}.bible-study-heading{text-align:center;margin:0 auto 18px;padding:0 16px}.bible-study-heading h2,.bible-study-heading p{margin:0;font-size:clamp(28px,3.3vw,46px);font-weight:400;letter-spacing:-.055em;line-height:1.12}.bible-study-heading p{font-weight:750}.bible-media-stage{position:relative;width:min(100%,1100px);height:550px;margin:0 auto}.featured-student-video{position:absolute;z-index:3;left:50%;top:34px;transform:translateX(-50%);display:block;width:min(53%,610px);aspect-ratio:16/9;object-fit:cover;background:#071b36;box-shadow:0 22px 52px rgba(0,0,0,.35)}.lesson-image-orbit{position:absolute;inset:0}.lesson-image-orbit img{position:absolute;width:clamp(132px,17vw,238px);height:clamp(79.2px,10.2vw,142.8px);aspect-ratio:100/60;object-fit:cover;border-radius:4px;box-shadow:0 12px 28px rgba(0,0,0,.32)}.lesson-card-1{left:2%;top:220px}.lesson-card-2{left:10%;top:280px}.lesson-card-3{left:18%;top:345px}.lesson-card-4{left:25%;top:405px}.lesson-card-5{left:28%;top:465px}.lesson-card-6{right:2%;top:210px}.lesson-card-7{right:10%;top:275px}.lesson-card-8{right:18%;top:342px}.lesson-card-9{right:25%;top:405px}.lesson-card-10{right:28%;top:465px}.public-footer{display:flex;justify-content:center;gap:12px;padding:22px 16px 32px;background:#021633;color:#a8c8ea;font-size:13px}.public-footer a{color:inherit;text-underline-offset:3px}.public-footer a:hover{color:#fff}.public-home a:focus-visible,.featured-student-video:focus-visible{outline:3px solid #ffe078;outline-offset:4px}
@media(max-width:900px){.public-hero{min-height:auto;padding:0 28px 58px}.public-header{height:88px}.public-hero-grid{grid-template-columns:minmax(0,1fr) minmax(320px,.9fr);gap:28px}.public-hero-copy{min-height:510px;padding:48px 34px;border-radius:56px 76px 56px 56px}.public-kicker{margin-bottom:30px}.public-intro{margin:34px 0}.instructor-quote{border-radius:34px}.bible-study-section{min-height:730px;padding-top:8px;background-size:auto 100%}.bible-media-stage{height:480px}.featured-student-video{width:min(62%,590px)}.lesson-image-orbit img{width:clamp(118px,20vw,190px);height:clamp(70.8px,12vw,114px)}.lesson-card-1,.lesson-card-6{top:210px}.lesson-card-2,.lesson-card-7{top:275px}.lesson-card-3,.lesson-card-8{top:340px}.lesson-card-4,.lesson-card-9{top:405px}.lesson-card-5{left:28%;top:405px;display:block}.lesson-card-10{right:28%;top:405px;display:block}}
@media(max-width:620px){.public-hero{padding:0 18px 42px;background-position:center top;background-size:auto 100%}.public-header{height:76px}.public-wordmark{display:none}.public-sign-in,.public-sign-up{min-width:82px;min-height:39px;padding:0 13px;font-size:13px}.public-hero-grid{display:flex;flex-direction:column;gap:24px}.public-hero-copy{width:100%;min-height:0;box-sizing:border-box;padding:34px 27px;border-radius:42px 58px 42px 42px}.public-kicker{margin-bottom:24px;font-size:12px}.public-hero-copy h1{font-size:clamp(42px,11vw,59px)}.public-intro{margin:27px 0;font-size:15px;line-height:1.55}.public-cta{min-height:46px;font-size:14px}.instructor-quote{width:min(100%,440px);border-radius:28px}.bible-study-section{min-height:610px;padding:30px 16px 130px;background-size:auto 82%;background-position:center bottom}.bible-study-heading{margin-bottom:25px}.bible-study-heading h2,.bible-study-heading p{font-size:clamp(25px,7vw,35px)}.bible-media-stage{height:440px;width:100%}.featured-student-video{top:14px;width:min(100%,480px);border-radius:5px}.lesson-image-orbit img{width:clamp(108px,34vw,165px);height:clamp(64.8px,20.4vw,99px);border-radius:3px}.lesson-card-1{left:0;top:176px}.lesson-card-2{left:4%;top:225px}.lesson-card-3{left:8%;top:275px}.lesson-card-4{left:13%;top:325px}.lesson-card-5{left:44px;top:375px;display:block}.lesson-card-6{right:0;top:176px}.lesson-card-7{right:4%;top:225px}.lesson-card-8{right:8%;top:275px}.lesson-card-9{right:13%;top:325px}.lesson-card-10{right:44px;top:375px;display:block}.public-footer{padding-bottom:max(28px,env(safe-area-inset-bottom))}}
@media(prefers-reduced-motion:reduce){.public-home *{scroll-behavior:auto!important;transition:none!important}}
</style>
