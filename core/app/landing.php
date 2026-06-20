<?php
declare(strict_types=1);

// ============================================================
// core/app/landing.php — 読者向け 紹介ランディングページ（独立LP）
// ------------------------------------------------------------
// register.php とは別の「集客用」ページ。ヒーロー / 特徴 / 3ステップ /
// 数字 / 登録フォーム / フッターで構成し、スクロール連動の登場エフェクト
// （AOS = Animate On Scroll, CDN 読み込み）で各セクションを演出する。
//
// ・登録フォームは register.php へ POST する（CSRF トークンは同一セッション
//   で発行されるためそのまま有効。登録ロジックは register.php に一本化）。
// ・本文コピーはサンプル。クライアントは <!-- ▼編集 --> 箇所を自由に変更可。
// ・CSS はこのファイルに内包（core/ 配下なので自動更新対象）。AOS が読めない
//   環境でも内容が消えないようフォールバックを用意している。
// ============================================================

// CSRF 用にセッション開始（register.php と同一セッション → トークン共有）
Auth::start();

$admin    = FileDB::getAdmin();
$siteName = htmlspecialchars($admin['site_name'] ?? 'メルマガ', ENT_QUOTES, 'UTF-8');
$year     = date('Y');
$registerUrl = SITE_URL . 'register.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $siteName ?> ｜ 読者登録のご案内</title>
    <meta name="description" content="<?= $siteName ?> のメールマガジン。最新情報をいち早くお届けします。登録は無料・かんたん3ステップ。">

    <!-- 共通デザイントークン（:root 変数・.btn 等）を再利用 -->
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">

    <!-- AOS（Animate On Scroll）= スクロール連動の登場エフェクト -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css">

    <!-- AOS / JS が無効でも内容が見えるようにするフォールバック -->
    <noscript><style>[data-aos]{opacity:1!important;transform:none!important;}</style></noscript>

    <style>
    /* ============================================================
       LP 専用スタイル（lp- プレフィックスで admin UI と衝突回避）
       ============================================================ */
    .lp-body {
        background: #ffffff;
        color: var(--color-text);
        overflow-x: hidden;
    }
    .lp-container { width: 100%; max-width: 1080px; margin: 0 auto; padding: 0 24px; }
    .lp-section  { padding: 96px 0; }
    .lp-eyebrow {
        display: inline-block;
        font-size: 13px; font-weight: 700; letter-spacing: .12em;
        color: var(--color-primary);
        text-transform: uppercase;
        margin-bottom: 14px;
    }
    .lp-h2 {
        font-size: clamp(26px, 4vw, 38px);
        font-weight: 800; line-height: 1.3;
        margin-bottom: 16px; letter-spacing: .01em;
    }
    .lp-lead { color: var(--color-muted); font-size: 16px; max-width: 640px; }
    .lp-center { text-align: center; }
    .lp-center .lp-lead { margin-left: auto; margin-right: auto; }

    /* ---- ヘッダー（スクロールで凝縮）---------------------------- */
    .lp-header {
        position: fixed; top: 0; left: 0; right: 0; z-index: 50;
        display: flex; align-items: center; justify-content: space-between;
        padding: 18px 24px;
        background: rgba(255,255,255,0);
        backdrop-filter: blur(0px);
        transition: background .3s ease, box-shadow .3s ease, padding .3s ease;
    }
    .lp-header.is-scrolled {
        background: rgba(255,255,255,.82);
        backdrop-filter: blur(12px);
        box-shadow: 0 1px 0 rgba(15,23,42,.06), var(--shadow);
        padding: 12px 24px;
    }
    .lp-logo { font-weight: 800; font-size: 18px; color: var(--color-text); }
    .lp-header .btn { padding: 9px 18px; font-size: 14px; }

    /* ---- ヒーロー --------------------------------------------- */
    .lp-hero {
        position: relative;
        padding: 168px 0 120px;
        text-align: center;
        overflow: hidden;
    }
    /* やわらかいグラデーション・メッシュ背景（ゆっくり動く） */
    .lp-hero::before {
        content: ""; position: absolute; inset: -20% -10% 0;
        z-index: 0;
        background:
            radial-gradient(40% 50% at 18% 18%, rgba(37,99,235,.18), transparent 70%),
            radial-gradient(38% 46% at 85% 22%, rgba(99,102,241,.16), transparent 70%),
            radial-gradient(50% 50% at 50% 90%, rgba(59,130,246,.12), transparent 70%);
        animation: lp-mesh 18s ease-in-out infinite alternate;
    }
    @keyframes lp-mesh {
        from { transform: translate3d(0,0,0) scale(1); }
        to   { transform: translate3d(0,-18px,0) scale(1.06); }
    }
    .lp-hero .lp-container { position: relative; z-index: 1; }
    .lp-hero h1 {
        font-size: clamp(32px, 6vw, 60px);
        font-weight: 900; line-height: 1.18;
        letter-spacing: .01em;
        margin-bottom: 22px;
    }
    .lp-hero h1 .lp-accent {
        background: linear-gradient(120deg, var(--color-primary), #6366f1);
        -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .lp-hero p { font-size: clamp(15px, 2.4vw, 19px); color: var(--color-muted); max-width: 560px; margin: 0 auto 36px; }
    .lp-cta-row { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
    .btn-hero {
        padding: 15px 34px; font-size: 16px; font-weight: 700;
        box-shadow: 0 10px 24px rgba(37,99,235,.28);
    }
    .btn-hero:hover { transform: translateY(-2px); box-shadow: 0 16px 32px rgba(37,99,235,.34); }
    .lp-hero-note { margin-top: 18px; font-size: 13px; color: var(--color-muted); }

    /* ---- 特徴カード（スタッガー登場）-------------------------- */
    .lp-features { background: linear-gradient(180deg, #f8fafc, #ffffff); }
    .lp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 48px; }
    .lp-card {
        background: #fff; border: 1px solid var(--color-border);
        border-radius: var(--radius-lg); padding: 32px 26px;
        box-shadow: var(--shadow);
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }
    .lp-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lg); border-color: rgba(37,99,235,.35); }
    .lp-card-icon {
        width: 52px; height: 52px; border-radius: 14px;
        display: grid; place-items: center; font-size: 24px; margin-bottom: 18px;
        background: var(--color-primary-soft); color: var(--color-primary);
    }
    .lp-card h3 { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
    .lp-card p  { color: var(--color-muted); font-size: 14.5px; }

    /* ---- 3ステップ ------------------------------------------- */
    .lp-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 52px; }
    .lp-step { position: relative; padding-top: 8px; }
    .lp-step-num {
        width: 44px; height: 44px; border-radius: 50%;
        display: grid; place-items: center; font-weight: 800; font-size: 17px;
        color: #fff; background: linear-gradient(135deg, var(--color-primary), #6366f1);
        margin-bottom: 16px; box-shadow: 0 8px 18px rgba(37,99,235,.3);
    }
    .lp-step h3 { font-size: 17px; font-weight: 700; margin-bottom: 8px; }
    .lp-step p  { color: var(--color-muted); font-size: 14.5px; }

    /* ---- 数字（カウントアップ）------------------------------- */
    .lp-stats {
        background: var(--color-sidebar); color: #fff;
        border-radius: var(--radius-lg);
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
        padding: 56px 32px; margin-top: 8px;
    }
    .lp-stat { text-align: center; }
    .lp-stat-num {
        font-size: clamp(34px, 5vw, 48px); font-weight: 900; line-height: 1;
        background: linear-gradient(120deg, #93c5fd, #c7d2fe);
        -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .lp-stat-label { margin-top: 10px; color: #cbd5e1; font-size: 14px; }

    /* ---- 登録フォーム CTA ------------------------------------ */
    .lp-cta { background: linear-gradient(180deg, #ffffff, #f1f5ff); }
    .lp-form-card {
        max-width: 520px; margin: 40px auto 0;
        background: #fff; border: 1px solid var(--color-border);
        border-radius: var(--radius-lg); padding: 36px 32px;
        box-shadow: var(--shadow-lg);
    }
    .lp-form-card .form-group { text-align: left; }
    .lp-form-card .btn { margin-top: 6px; }
    .lp-cta-fineprint { font-size: 12px; color: var(--color-muted); margin-top: 14px; }

    /* ---- フッター -------------------------------------------- */
    .lp-footer {
        background: var(--color-sidebar); color: #94a3b8;
        padding: 40px 0; text-align: center; font-size: 13px;
    }
    .lp-footer a { color: #cbd5e1; }

    /* ---- レスポンシブ ---------------------------------------- */
    @media (max-width: 820px) {
        .lp-grid, .lp-steps, .lp-stats { grid-template-columns: 1fr; }
        .lp-section { padding: 72px 0; }
        .lp-hero { padding: 132px 0 88px; }
    }

    /* ---- モーション低減の配慮 -------------------------------- */
    @media (prefers-reduced-motion: reduce) {
        .lp-hero::before { animation: none; }
        .lp-card:hover, .btn-hero:hover { transform: none; }
    }
    </style>
</head>
<body class="lp-body">

<!-- ========== ヘッダー ========== -->
<header class="lp-header" id="lpHeader">
    <div class="lp-logo"><?= $siteName ?></div>
    <a href="#signup" class="btn btn-primary">無料で登録</a>
</header>

<!-- ========== ヒーロー ========== -->
<section class="lp-hero">
    <div class="lp-container">
        <!-- ▼編集: キャッチコピー -->
        <h1 data-aos="fade-up">
            知りたい情報を、<br>
            <span class="lp-accent">いちばん早く</span>あなたへ。
        </h1>
        <p data-aos="fade-up" data-aos-delay="120">
            <?= $siteName ?> のメールマガジンに登録すると、最新情報・お得なお知らせを
            メールでお届けします。登録は無料、解除もいつでもワンクリック。
        </p>
        <div class="lp-cta-row" data-aos="fade-up" data-aos-delay="240">
            <a href="#signup" class="btn btn-primary btn-hero">いますぐ無料で読者登録</a>
        </div>
        <p class="lp-hero-note" data-aos="fade-up" data-aos-delay="320">
            ※ 登録後に届く確認メールのURLをクリックで完了します
        </p>
    </div>
</section>

<!-- ========== 特徴 ========== -->
<section class="lp-section lp-features">
    <div class="lp-container lp-center">
        <span class="lp-eyebrow" data-aos="fade-up">Features</span>
        <h2 class="lp-h2" data-aos="fade-up" data-aos-delay="60">選ばれている3つの理由</h2>
        <p class="lp-lead" data-aos="fade-up" data-aos-delay="120">
            読者の「読みたい」に応える、シンプルで心地よいメールマガジン体験。
        </p>

        <div class="lp-grid">
            <!-- ▼編集: 特徴カード -->
            <div class="lp-card" data-aos="fade-up" data-aos-delay="0">
                <div class="lp-card-icon">⚡</div>
                <h3>最新情報をいち早く</h3>
                <p>公開と同時に、重要なお知らせや新着情報をあなたのメールボックスへ直接お届けします。</p>
            </div>
            <div class="lp-card" data-aos="fade-up" data-aos-delay="120">
                <div class="lp-card-icon">🎁</div>
                <h3>登録者だけの特典</h3>
                <p>メルマガ読者限定のコンテンツやキャンペーン情報など、ここでしか得られない内容をお送りします。</p>
            </div>
            <div class="lp-card" data-aos="fade-up" data-aos-delay="240">
                <div class="lp-card-icon">🔓</div>
                <h3>いつでも解除OK</h3>
                <p>配信が不要になったら、メール内のリンクからワンクリックで解除。面倒な手続きは一切ありません。</p>
            </div>
        </div>
    </div>
</section>

<!-- ========== 3ステップ ========== -->
<section class="lp-section">
    <div class="lp-container lp-center">
        <span class="lp-eyebrow" data-aos="fade-up">How it works</span>
        <h2 class="lp-h2" data-aos="fade-up" data-aos-delay="60">登録はかんたん3ステップ</h2>
        <p class="lp-lead" data-aos="fade-up" data-aos-delay="120">
            メールアドレスを入力するだけ。1分もかかりません。
        </p>

        <div class="lp-steps">
            <div class="lp-step" data-aos="fade-right" data-aos-delay="0">
                <div class="lp-step-num">1</div>
                <h3>メールアドレスを入力</h3>
                <p>下のフォームにメールアドレス（とお名前）を入力して送信します。</p>
            </div>
            <div class="lp-step" data-aos="fade-right" data-aos-delay="140">
                <div class="lp-step-num">2</div>
                <h3>確認メールを開く</h3>
                <p>届いた確認メールを開き、本文内のURLをクリックします。</p>
            </div>
            <div class="lp-step" data-aos="fade-right" data-aos-delay="280">
                <div class="lp-step-num">3</div>
                <h3>登録完了</h3>
                <p>これで登録完了。次回の配信からメールマガジンが届きます。</p>
            </div>
        </div>
    </div>
</section>

<!-- ========== 数字 ========== -->
<section class="lp-section lp-features">
    <div class="lp-container">
        <div class="lp-stats" data-aos="zoom-in">
            <!-- ▼編集: data-count に実数を設定（カウントアップ表示） -->
            <div class="lp-stat">
                <div class="lp-stat-num" data-count="1200" data-suffix="+">0</div>
                <div class="lp-stat-label">登録読者数</div>
            </div>
            <div class="lp-stat">
                <div class="lp-stat-num" data-count="98" data-suffix="%">0</div>
                <div class="lp-stat-label">到達率</div>
            </div>
            <div class="lp-stat">
                <div class="lp-stat-num" data-count="1" data-prefix="月" data-suffix="回〜">0</div>
                <div class="lp-stat-label">無理のない配信頻度</div>
            </div>
        </div>
    </div>
</section>

<!-- ========== 登録フォーム ========== -->
<section class="lp-section lp-cta" id="signup">
    <div class="lp-container lp-center">
        <span class="lp-eyebrow" data-aos="fade-up">Sign up</span>
        <h2 class="lp-h2" data-aos="fade-up" data-aos-delay="60">いますぐ無料で読者登録</h2>
        <p class="lp-lead" data-aos="fade-up" data-aos-delay="120">
            メールアドレスを入力して「確認メールを送る」を押してください。
        </p>

        <div class="lp-form-card" data-aos="fade-up" data-aos-delay="160">
            <!-- 登録処理は register.php に一本化。CSRF トークンは同一セッションで有効 -->
            <form method="post" action="<?= $registerUrl ?>">
                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                <div class="form-group">
                    <label class="form-label">メールアドレス<span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required
                           placeholder="example@example.com" autocomplete="email">
                </div>
                <div class="form-group">
                    <label class="form-label">お名前</label>
                    <input type="text" name="name" class="form-control"
                           placeholder="山田 太郎" autocomplete="name">
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-full">確認メールを送る</button>
            </form>
            <p class="lp-cta-fineprint">
                送信いただいたメールアドレスへ確認メールが届きます。<br>
                メール内のURLをクリックすると登録が完了します。
            </p>
        </div>
    </div>
</section>

<!-- ========== フッター ========== -->
<footer class="lp-footer">
    <div class="lp-container">
        <p>&copy; <?= $year ?> <?= $siteName ?>. All rights reserved.</p>
        <p style="margin-top:8px;">
            <a href="<?= SITE_URL ?>unsubscribe.php">配信停止はこちら</a>
        </p>
    </div>
</footer>

<!-- ========== スクリプト ========== -->
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script>
(function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- AOS 初期化（読み込めなかった場合は内容を即表示）---
    if (window.AOS && !reduce) {
        AOS.init({ duration: 700, easing: 'ease-out-cubic', once: true, offset: 80 });
    } else {
        // フォールバック: data-aos 要素を強制表示（CDN 失敗・モーション低減時）
        document.querySelectorAll('[data-aos]').forEach(function (el) {
            el.style.opacity = '1';
            el.style.transform = 'none';
        });
    }

    // --- ヘッダーのスクロール凝縮 ---
    var header = document.getElementById('lpHeader');
    var onScroll = function () {
        if (window.scrollY > 24) header.classList.add('is-scrolled');
        else header.classList.remove('is-scrolled');
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // --- 数字のカウントアップ（IntersectionObserver）---
    var animateCount = function (el) {
        var target = parseFloat(el.getAttribute('data-count')) || 0;
        var prefix = el.getAttribute('data-prefix') || '';
        var suffix = el.getAttribute('data-suffix') || '';
        if (reduce) { el.textContent = prefix + target + suffix; return; }
        var start = null, dur = 1400;
        var step = function (ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 3); // ease-out-cubic
            el.textContent = prefix + Math.floor(eased * target) + suffix;
            if (p < 1) requestAnimationFrame(step);
            else el.textContent = prefix + target + suffix;
        };
        requestAnimationFrame(step);
    };
    var nums = document.querySelectorAll('[data-count]');
    if ('IntersectionObserver' in window && !reduce) {
        var io = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { animateCount(e.target); obs.unobserve(e.target); }
            });
        }, { threshold: 0.4 });
        nums.forEach(function (n) { io.observe(n); });
    } else {
        nums.forEach(animateCount);
    }

    // --- アンカーのスムーススクロール ---
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var id = a.getAttribute('href');
            if (id.length < 2) return;
            var t = document.querySelector(id);
            if (!t) return;
            e.preventDefault();
            t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        });
    });
})();
</script>
</body>
</html>
