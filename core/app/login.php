<?php
declare(strict_types=1);

// ============================================================
// core/app/login.php - ログイン画面（旧 index.php）
// ============================================================

Auth::start();

// ログアウト（POST + CSRF 必須。GET によるクロスサイト強制ログアウトを防ぐ）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('不正なリクエストです。');
    }
    Auth::logout();
    header('Location: ' . SITE_URL . 'index.php?msg=logout');
    exit;
}

// 初期設定未完了なら setup へ
$admin = FileDB::getAdmin();
if (empty($admin['setup_done'])) {
    header('Location: ' . SITE_URL . 'setup.php');
    exit;
}

// 既にログイン済みならダッシュボードへ
if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL . 'dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- ブルートフォース対策 -----------------------------
    // IP単位で 5回/5分。POST 時のみカウント消費する（GET リロードでは
    // 消費しない）ことで、フォームを開いただけでロックアウトされる
    // regression を防ぐ。CSRF 検証より先に評価し、CSRF取得→連続試行
    // のサイクルそのものを抑止する。
    $loginRateKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!RateLimit::allow($loginRateKey, 5, 300)) {
        $error = 'ログイン試行回数が多すぎます。しばらく（5分）経ってから再度お試しください。';
    } elseif (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。再度お試しください。';
    } elseif (Auth::login($_POST['password'] ?? '', $admin)) {
        header('Location: ' . SITE_URL . 'dashboard.php');
        exit;
    } else {
        $error = 'パスワードが正しくありません。';
    }
}

$msg = $_GET['msg'] ?? '';
$siteName = $admin['site_name'] ?? 'メルマガ管理システム';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン - <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="sub">管理者パスワードでログインしてください</p>

        <?php if ($msg === 'logout'): ?>
            <div class="alert alert-info">ログアウトしました。</div>
        <?php elseif ($msg === 'session_expired'): ?>
            <div class="alert alert-warn">セッションが期限切れになりました。</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
            <div class="form-group">
                <label class="form-label">パスワード <span class="required">*</span></label>
                <input type="password" name="password" class="form-control"
                       autofocus autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-full btn-lg">ログイン</button>
        </form>

        <p class="text-muted text-center mt-3">MailMag v<?= htmlspecialchars(MAILMAG_CORE_VERSION, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</div>
</body>
</html>
