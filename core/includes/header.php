<?php
// 各 core/app/*.php で $pageTitle と $activeNav を定義してから require すること
$admin    = FileDB::getAdmin();
$siteName = $admin['site_name'] ?? 'メルマガ管理';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'MailMag', ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">
</head>
<body>
<div class="layout">

    <!-- サイドバー -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
            <span>MailMag v<?= htmlspecialchars(MAILMAG_CORE_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">メイン</div>
            <a href="<?= SITE_URL ?>dashboard.php" class="<?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
                <span class="icon">&#9632;</span> ダッシュボード
            </a>

            <div class="nav-section">購読者</div>
            <a href="<?= SITE_URL ?>subscribers.php" class="<?= ($activeNav ?? '') === 'subscribers' ? 'active' : '' ?>">
                <span class="icon">&#9654;</span> 購読者一覧
            </a>
            <a href="<?= SITE_URL ?>subscriber_add.php" class="<?= ($activeNav ?? '') === 'subscriber_add' ? 'active' : '' ?>">
                <span class="icon">&#43;</span> 購読者追加
            </a>

            <div class="nav-section">メール</div>
            <a href="<?= SITE_URL ?>send.php" class="<?= ($activeNav ?? '') === 'send' ? 'active' : '' ?>">
                <span class="icon">&#9993;</span> メール送信
            </a>
            <a href="<?= SITE_URL ?>history.php" class="<?= ($activeNav ?? '') === 'history' ? 'active' : '' ?>">
                <span class="icon">&#9776;</span> 送信履歴
            </a>
            <a href="<?= SITE_URL ?>template.php" class="<?= ($activeNav ?? '') === 'template' ? 'active' : '' ?>">
                <span class="icon">&#9998;</span> テンプレート
            </a>
            <a href="<?= SITE_URL ?>media.php" class="<?= ($activeNav ?? '') === 'media' ? 'active' : '' ?>">
                <span class="icon">&#128247;</span> 画像ライブラリ
            </a>

            <div class="nav-section">設定</div>
            <a href="<?= SITE_URL ?>settings.php" class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>">
                <span class="icon">&#9881;</span> システム設定
            </a>
        </nav>

        <div class="sidebar-footer">
            <!-- ログアウトは POST + CSRF 必須（GETによるクロスサイト強制ログアウト防止） -->
            <form method="post" action="<?= SITE_URL ?>index.php" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                <button type="submit" name="logout" value="1"
                        style="background:none;border:0;padding:0;color:#64748b;font-size:12px;cursor:pointer;text-decoration:underline;">
                    ログアウト
                </button>
            </form>
        </div>
    </aside>

    <!-- メインコンテンツ -->
    <div class="main">
        <div class="topbar">
            <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="topbar-actions">
                <span class="text-muted"><?= date('Y年n月j日') ?></span>
            </div>
        </div>
        <div class="content">
