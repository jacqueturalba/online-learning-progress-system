<script setup lang="ts">
const homeAssets = '/storage/branding/home/';
const brandingAssets = '/storage/branding/';
</script>

<template>
  <main class="public-home">
    <section class="welcome-home" aria-labelledby="welcome-home-title">
      <header class="welcome-header">
        <a class="welcome-brand" href="/" aria-label="Bible Study Portal home"><img class="welcome-brand-mark" :src="`${brandingAssets}zcmc-logo.svg`" alt=""><span>Bible Study Portal</span></a>
        <nav class="welcome-account-links" aria-label="Account"><a href="/login">Sign in</a><a href="/register">Create account</a></nav>
      </header>
      <div class="welcome-hero-content">
        <p class="welcome-eyebrow">BIBLE STUDY PORTAL</p>
        <h1 id="welcome-home-title">Let Us Discover God's Word and Grow in Understanding Together.</h1>
        <p class="welcome-hero-description">We warmly invite you to join us in studying the Bible. Through guided lessons and thoughtful discussions, may you gain a deeper understanding of Scripture and grow in your knowledge of God's Word.</p>
        <a class="welcome-cta" href="/register">Explore Bible Classes</a>
        <img class="welcome-class-video" :src="`${homeAssets}class-video.gif`" alt="A Bible class studying Scripture together" width="1920" height="1080" loading="lazy" decoding="async" fetchpriority="low">
      </div>
    </section>

    <section class="free-classes" aria-labelledby="free-classes-title">
      <div class="free-classes-layout">
        <div class="free-classes-copy">
          <h2 id="free-classes-title">A Place to Learn the Bible, Freely and Together</h2>
          <p>We believe everyone deserves the opportunity to learn and understand the Bible. That is why our classes and learning materials are offered free of charge. Take your time, follow each lesson, and discover the teachings of Scripture with us.</p>
        </div>
        <div class="free-resources" role="group" aria-label="Free Bible class lesson materials">
          <img class="free-resource free-resource-back-left" :src="`${homeAssets}freesection_lesson1.png`" alt="Bible class lesson and study materials" loading="lazy">
          <img class="free-resource free-resource-back-right" :src="`${homeAssets}freesection_lesson3.png`" alt="Bible study lesson resource" loading="lazy">
          <img class="free-resource free-resource-front" :src="`${homeAssets}freesection_lesson2.png`" alt="A free Bible class lesson handout" loading="lazy">
        </div>
      </div>
    </section>

    <section class="scripture-learning" aria-labelledby="scripture-learning-title">
      <div class="scripture-learning-layout">
        <img class="scripture-learning-visual" :src="`${homeAssets}verse.gif`" alt="An animated Bible passage study with notes on Scripture" width="1920" height="1080" loading="lazy" decoding="async" fetchpriority="low">
        <div class="scripture-learning-copy">
          <h2 id="scripture-learning-title">Let Us Look Deeper Into God's Word</h2>
          <p>There is so much to discover in every passage of Scripture. As we study its meaning, background, and connections, we can come to a clearer understanding of God's Word. We hope each lesson will help you learn, reflect, and grow in your understanding of the Bible.</p>
        </div>
      </div>
    </section>

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
      </header>
      <div class="public-hero-grid">
        <div class="public-hero-copy custom-cut-card">
          <p class="public-kicker">BIBLE STUDY PORTAL</p>
          <h1 id="public-home-title">Let's discover<br>the Word of God</h1>
          <p class="public-intro">Begin your journey to understanding the Bible through guided lessons and meaningful learning.</p>
          <svg class="custom-cut-outline" viewBox="0 0 1 1" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path d="M .13 0 H .63 C .67 0 .70 .015 .73 .05 L .94 .28 C .98 .32 1 .36 1 .42 V .87 C 1 .94 .94 1 .87 1 H .13 C .06 1 0 .94 0 .87 V .13 C 0 .06 .06 0 .13 0 Z" />
          </svg>
        </div>
        <img class="instructor-quote" :src="`${homeAssets}head_instructor_quote.png`" alt="Head Instructor Soo Oh: Since your desire to understand is good, the Lord will grant you the answer (truth). Zion Christian Mission Center PH">
      </div>
    </section>

    <footer class="public-footer"><a href="/login">Sign in</a><span aria-hidden="true">·</span><a href="/register">Create an account</a></footer>
  </main>
</template>

<style scoped>
.welcome-home,.free-classes,.scripture-learning{color:#202124;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.welcome-home{background:#fff;padding:0 clamp(20px,4vw,64px) 96px}
.welcome-header{width:min(100%,1440px);height:64px;margin:0 auto;display:flex;align-items:center;justify-content:space-between}
.welcome-brand{display:inline-flex;align-items:center;gap:9px;color:#252525;text-decoration:none;font-size:16px;font-weight:550}
.welcome-brand-mark{display:block;width:29px;height:29px;object-fit:contain;flex:none}
.welcome-account-links{display:flex;align-items:center;gap:20px}.welcome-account-links a{color:#252525;text-decoration:none;font-size:13px;font-weight:600}.welcome-account-links a:last-child{padding:9px 14px;border:1px solid #d9d9d9;border-radius:22px}
.welcome-hero-content{width:min(100%,1120px);margin:42px auto 0;display:flex;flex-direction:column;align-items:center;text-align:center}
.welcome-eyebrow{margin:0 0 9px;font-size:clamp(21px,2.1vw,30px);font-weight:700;letter-spacing:-.04em}
.welcome-hero-content h1{max-width:1000px;margin:0;font-size:clamp(37px,4.1vw,58px);font-weight:750;line-height:1.08;letter-spacing:-.045em;text-wrap:balance}
.welcome-hero-description{max-width:970px;margin:12px 0 24px;font-size:clamp(16px,1.55vw,22px);line-height:1.5;color:#373737}
.welcome-cta{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 20px;border-radius:24px;background:#3979a8;color:#fff;text-decoration:none;font-size:15px;font-weight:650;transition:background-color .15s ease,transform .15s ease}.welcome-cta:hover{background:#2e668f;transform:translateY(-1px)}
.welcome-class-video{display:block;width:min(100%,920px);height:auto;margin:36px auto 0;object-fit:contain}
.free-classes{background:#749ec0;padding:80px clamp(24px,5vw,76px)}
.free-classes-layout{width:min(100%,1140px);min-height:370px;margin:0 auto;display:grid;grid-template-columns:minmax(250px,.75fr) minmax(0,1.25fr);align-items:center;gap:clamp(40px,8vw,120px)}
.free-classes-copy h2,.scripture-learning-copy h2{margin:0 0 16px;font-size:clamp(34px,3.6vw,50px);font-weight:750;line-height:1.04;letter-spacing:-.05em;text-wrap:balance}
.free-classes-copy p,.scripture-learning-copy p{margin:0;color:#383d42;font-size:16px;line-height:1.75}
.free-resources{position:relative;width:100%;height:390px;isolation:isolate}
.free-resource{position:absolute;display:block;width:min(61%,400px);height:auto;max-height:100%;object-fit:contain;border-radius:10px;background:#fff;box-shadow:0 12px 32px rgba(27,57,79,.16);will-change:transform;transition:transform .25s ease,filter .25s ease,box-shadow .25s ease;animation-duration:7.5s;animation-timing-function:ease-in-out;animation-iteration-count:infinite}.free-resource-back-left{animation-name:resource-float-left;animation-delay:-1.1s}.free-resource-back-right{animation-name:resource-float-right;animation-delay:-3.4s}.free-resource-front{animation-name:resource-float-front;animation-delay:-2.2s}.free-resource:hover{z-index:2;filter:brightness(1.015);box-shadow:0 19px 38px rgba(27,57,79,.23);animation-play-state:paused}@keyframes resource-float-left{0%,100%{transform:translateY(0) rotate(-5deg)}50%{transform:translateY(-6px) rotate(-4deg)}}@keyframes resource-float-right{0%,100%{transform:translateY(0) rotate(5deg)}50%{transform:translateY(-8px) rotate(4deg)}}@keyframes resource-float-front{0%,100%{transform:translateX(-50%) translateY(0)}50%{transform:translateX(-50%) translateY(-5px)}}
.free-resource-back-left{left:3%;top:11%;transform:rotate(-5deg)}.free-resource-back-right{right:3%;top:10%;transform:rotate(5deg)}.free-resource-front{z-index:1;left:50%;top:3%;width:min(58%,380px);transform:translateX(-50%);box-shadow:0 16px 36px rgba(27,57,79,.2)}.free-resource-back-left:hover{transform:translateY(-6px) rotate(-5deg) scale(1.015)}.free-resource-back-right:hover{transform:translateY(-6px) rotate(5deg) scale(1.015)}.free-resource-front:hover{transform:translateX(-50%) translateY(-6px) scale(1.015)}
.scripture-learning{background:#fff;padding:90px clamp(24px,5vw,76px)}
.scripture-learning-layout{width:min(100%,1120px);min-height:420px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.95fr);align-items:center;gap:clamp(44px,8vw,116px)}
.scripture-learning-visual{display:block;width:100%;height:auto;max-height:470px;object-fit:contain;object-position:center}
.scripture-learning-copy h2{font-size:clamp(35px,3.6vw,51px)}
@media(max-width:760px){.welcome-home{padding-bottom:70px}.welcome-header{height:58px}.welcome-brand{font-size:14px}.welcome-brand-mark{width:25px;height:24px}.welcome-account-links{gap:12px}.welcome-account-links a{font-size:12px}.welcome-hero-content{margin-top:36px}.welcome-hero-content h1{font-size:clamp(35px,7vw,50px)}.welcome-hero-description{font-size:16px}.welcome-class-video{margin-top:28px}.free-classes{padding:60px 24px}.free-classes-layout{grid-template-columns:1fr;gap:28px}.free-classes-copy{max-width:620px}.free-resources{height:clamp(270px,62vw,390px);max-width:640px;margin:0 auto}.scripture-learning{padding:64px 24px}.scripture-learning-layout{grid-template-columns:1fr;gap:30px}.scripture-learning-visual{width:min(100%,560px);max-height:none;margin:0 auto}.scripture-learning-copy{max-width:640px}}
@media(max-width:420px){.welcome-account-links{gap:10px}.welcome-account-links a:last-child{padding:8px 10px}.welcome-hero-content{margin-top:28px}.welcome-hero-content h1{font-size:clamp(33px,9vw,40px)}.free-classes-copy h2,.scripture-learning-copy h2{font-size:34px}.free-resources{height:270px}.free-resource{width:68%}.free-resource-front{width:64%}}
.custom-card-shape-definitions{position:absolute;width:0;height:0;overflow:hidden}.custom-cut-card{position:relative;isolation:isolate;clip-path:url(#portal-hero-card-clip);filter:drop-shadow(0 14px 26px rgba(0,15,47,.16))}.custom-cut-outline{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none}.custom-cut-outline path{fill:none;stroke:rgba(255,255,255,.52);stroke-width:1.5px;vector-effect:non-scaling-stroke}
.public-home{min-height:100vh;overflow:hidden;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.public-hero{position:relative;min-height:740px;padding:0 clamp(28px,5vw,76px) 54px;background:url('/storage/branding/home/hero_section_bg.png') center top/cover no-repeat}.public-header{height:108px;max-width:1360px;margin:0 auto;display:flex;justify-content:flex-end;align-items:center;gap:18px}.public-wordmark{margin-right:auto;color:#fff;text-decoration:none;font:600 14px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.13em;opacity:0;pointer-events:none}.public-hero-grid{max-width:1360px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) minmax(420px,1fr);align-items:center;gap:clamp(34px,5vw,72px)}.public-hero-copy{min-height:616px;padding:clamp(42px,6.8vw,96px) clamp(34px,4.4vw,60px);border:0;border-radius:0;background:linear-gradient(140deg,rgba(219,241,255,.30),rgba(113,156,216,.08));box-shadow:inset 0 1px 0 rgba(255,255,255,.24);backdrop-filter:blur(3px);display:flex;flex-direction:column;justify-content:center}.public-kicker{margin:0 0 39px;font:500 15px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.11em}.public-hero-copy h1{margin:0;font-size:clamp(44px,5.2vw,72px);font-weight:400;line-height:.99;letter-spacing:-.055em;text-wrap:balance}.public-intro{max-width:510px;margin:48px 0 48px;font:500 clamp(17px,1.8vw,23px)/1.48 ui-monospace,SFMono-Regular,Menlo,monospace;text-transform:uppercase;letter-spacing:.005em}.instructor-quote{display:block;width:100%;max-width:615px;aspect-ratio:1;justify-self:end;object-fit:cover;border-radius:46px;box-shadow:0 22px 65px rgba(0,15,47,.22)}.public-footer{display:flex;justify-content:center;gap:12px;padding:22px 16px 32px;background:#021633;color:#a8c8ea;font-size:13px}.public-footer a{color:inherit;text-underline-offset:3px}.public-footer a:hover{color:#fff}
@media(max-width:900px){.public-hero{min-height:auto;padding:0 28px 58px}.public-header{height:88px}.public-hero-grid{grid-template-columns:minmax(0,1fr) minmax(320px,.9fr);gap:28px}.public-hero-copy{min-height:510px;padding:48px 34px;border-radius:56px 76px 56px 56px}.public-kicker{margin-bottom:30px}.public-intro{margin:34px 0}.instructor-quote{border-radius:34px}}
@media(max-width:620px){.public-hero{padding:0 18px 42px;background-position:center top;background-size:auto 100%}.public-header{height:76px}.public-wordmark{display:none}.public-hero-grid{display:flex;flex-direction:column;gap:24px}.public-hero-copy{width:100%;min-height:0;box-sizing:border-box;padding:34px 27px;border-radius:42px 58px 42px 42px}.public-kicker{margin-bottom:24px;font-size:12px}.public-hero-copy h1{font-size:clamp(42px,11vw,59px)}.public-intro{margin:27px 0;font-size:15px;line-height:1.55}.instructor-quote{width:min(100%,440px);border-radius:28px}.public-footer{padding-bottom:max(28px,env(safe-area-inset-bottom))}}
@media(prefers-reduced-motion:reduce){.public-home *{scroll-behavior:auto!important;transition:none!important}.free-resource{animation:none!important;will-change:auto}.free-resource:hover{transform:none;filter:none;box-shadow:0 12px 32px rgba(27,57,79,.16)}}
</style>
