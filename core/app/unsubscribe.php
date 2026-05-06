<?php
declare(strict_types=1);

// RFC 8058: List-Unsubscribe=One-Click
// GmailなどがPOSTで1クリック購読解除を送信する。
// 通常クリックはGETで来る → 確認画面表示。

$admin = FileDB::getAdmin();
$token = $_GET['token'] ?? '';
$state = 'form';
$sub   = null;

if (empty($token)) {
    $state = 'invalid';
} else {
    $sub = FileDB::findByToken($token);
    if (!$sub) {
        $state = 'invalid';
    } elseif ($sub['status'] === '9') {
        $state = 'done';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        FileDB::updateSubscriber($sub['id'], ['status' => '9', 'unsubscribed_at' => date('Y-m-d H:i:s')]);
        $state = 'done';
    }
}

$siteName = htmlspecialchars($admin['site_name'] ?? 'メルマガ', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>購読解除 - <?= $siteName ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">
</head>
<body>
<div class="public-wrap">
    <div class="public-card">
        <div style="text-align:center;margin-bottom:28px;">
            <h1 style="font-size:22px;font-weight:700;color:#1e293b;"><?= $siteName ?></h1>
            <p style="color:#64748b;margin-top:6px;">購読解除</p>
        </div>

        <?php if ($state === 'form'): ?>
            <p style="color:#475569;margin-bottom:24px;text-align:center;line-height:1.7;">
                以下のメールアドレスの購読を解除します。<br>
                よろしければ「購読解除する」を押してください。
            </p>
            <?php if ($sub): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;
                        padding:12px 16px;margin-bottom:24px;text-align:center;
                        font-size:14px;color:#334155;">
                <?= htmlspecialchars($sub['email'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
            <form method="post">
                <button type="submit" class="btn btn-danger w-full">購読解除する</button>
            </form>
            <div style="text-align:center;margin-top:16px;">
                <a href="<?= SITE_URL ?>register.php" style="font-size:13px;color:#94a3b8;">
                    キャンセル（引き続き購読する）
                </a>
            </div>

        <?php elseif ($state === 'done'): ?>
            <div class="alert alert-success">
                購読解除が完了しました。<br>
                今まで購読していただきありがとうございました。
            </div>

        <?php else: ?>
            <div class="alert alert-danger">
                無効なリンクです。URLを確認して再度お試しください。
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
