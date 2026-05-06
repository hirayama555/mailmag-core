<?php
declare(strict_types=1);

// セッション開始（CSRF用）
Auth::start();

$admin = FileDB::getAdmin();
$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。再度お試しください。';
    } else {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name']  ?? '');

        // ---- レート制限（メールフラッド/メールボム増幅防止）----
        // per-IP: 同一IPから 10件/時 まで（社内一括登録などを阻害しすぎないバランス）
        // per-email: 同一アドレスへの確認メール送信は 1件/10分まで
        // どちらか上限超過 → 完了画面を表示するが実際にはメール送信しない
        // （UIから攻撃者が判別できないようにエラーを出さない）
        $ipKey    = 'register_ip_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $emailKey = 'register_email_' . strtolower($email);
        $ipOk     = RateLimit::allow($ipKey, 10, 3600);
        $emailOk  = filter_var($email, FILTER_VALIDATE_EMAIL)
                    ? RateLimit::allow($emailKey, 1, 600)
                    : false;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'メールアドレスの形式が正しくありません。';
        } elseif (!$ipOk || !$emailOk) {
            // レート制限超過: enumeration 防止のため完了画面に誘導（メール送信しない）
            $done = true;
        } elseif (FileDB::findByEmail($email)) {
            // すでに本登録済み → メールアドレスを漏らさないよう同じ完了画面
            $done = true;
        } else {
            // 既に保留中かチェック
            $pending = FileDB::getPending();
            $exists  = false;
            foreach ($pending as $p) {
                if (strtolower($p['email']) === strtolower($email)) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $token = Token::generate();
                FileDB::addPending([
                    'email'      => $email,
                    'name'       => $name,
                    'token'      => $token,
                    'source'     => 'web',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $mailer = new Mailer($admin);
                $mailer->sendConfirmMail($email, Token::confirmUrl($token));
            }
            // 重複でも完了画面（enumeration 対策）
            $done = true;
        }
    }
}

$siteName = htmlspecialchars($admin['site_name'] ?? 'メルマガ', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>読者登録 - <?= $siteName ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">
</head>
<body>
<div class="public-wrap">
    <div class="public-card">
        <div style="text-align:center;margin-bottom:28px;">
            <h1 style="font-size:22px;font-weight:700;color:#1e293b;"><?= $siteName ?></h1>
            <p style="color:#64748b;margin-top:6px;">読者登録</p>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success">
                確認メールを送信しました。<br>
                届いたメール内のURLをクリックして登録を完了してください。<br>
                <small style="color:#64748b;">メールが届かない場合は迷惑メールフォルダをご確認ください。</small>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                <div class="form-group">
                    <label class="form-label">メールアドレス<span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required autofocus
                           placeholder="example@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">お名前</label>
                    <input type="text" name="name" class="form-control"
                           placeholder="山田 太郎"
                           value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <p style="font-size:12px;color:#64748b;margin-bottom:16px;">
                    ご入力いただいたメールアドレスへ確認メールが届きます。<br>
                    メール内のURLをクリックすることで登録が完了します。
                </p>
                <button type="submit" class="btn btn-primary w-full">確認メールを送る</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
