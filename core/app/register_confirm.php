<?php
declare(strict_types=1);

$admin = FileDB::getAdmin();
$token = $_GET['token'] ?? '';
$state = 'invalid'; // invalid / expired / already / done

if ($token) {
    $pending = FileDB::findPendingByToken($token);

    if (!$pending) {
        // すでに本登録済みか確認
        $sub = FileDB::findByToken($token);
        $state = $sub ? 'already' : 'invalid';
    } else {
        // 有効期限チェック（48時間）
        $createdAt = strtotime($pending['created_at']);
        if (time() - $createdAt > 48 * 3600) {
            FileDB::deletePending($token);
            $state = 'expired';
        } elseif (FileDB::findByEmail($pending['email'])) {
            FileDB::deletePending($token);
            $state = 'already';
        } else {
            $now = date('Y-m-d H:i:s');
            $sub = [
                'id'         => FileDB::generateId(),
                'email'      => $pending['email'],
                'name'       => $pending['name'] ?? '',
                'status'     => '1',
                'token'      => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            FileDB::addSubscriber($sub);
            FileDB::deletePending($token);

            // 登録完了メール送信
            $siteName    = $admin['site_name'] ?? 'メルマガ';
            $unsubUrl    = Token::unsubscribeUrl($token);
            $mailer      = new Mailer($admin);
            $welcomeBody = "{$siteName} への登録が完了しました。\r\n\r\n"
                . "今後ともよろしくお願いいたします。\r\n\r\n"
                . "購読を解除する場合は以下のURLからお手続きください。\r\n"
                . $unsubUrl;
            $mailer->send($pending['email'], "【{$siteName}】登録完了のご確認", $welcomeBody, '', $unsubUrl);

            $state = 'done';
        }
    }
}

$siteName = htmlspecialchars($admin['site_name'] ?? 'メルマガ', ENT_QUOTES, 'UTF-8');

$messages = [
    'done'    => ['title' => '登録完了', 'class' => 'alert-success',
                  'text'  => '読者登録が完了しました。ご登録ありがとうございます。'],
    'already' => ['title' => '登録済み', 'class' => 'alert-success',
                  'text'  => 'このメールアドレスはすでに登録されています。'],
    'expired' => ['title' => 'リンクの有効期限切れ', 'class' => 'alert-danger',
                  'text'  => '確認リンクの有効期限（48時間）が切れています。お手数ですが、もう一度登録フォームからお申し込みください。'],
    'invalid' => ['title' => '無効なリンク', 'class' => 'alert-danger',
                  'text'  => '確認リンクが無効です。URLを確認して再度お試しください。'],
];
$m = $messages[$state] ?? $messages['invalid'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8') ?> - <?= $siteName ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">
</head>
<body>
<div class="public-wrap">
    <div class="public-card">
        <div style="text-align:center;margin-bottom:28px;">
            <h1 style="font-size:22px;font-weight:700;color:#1e293b;"><?= $siteName ?></h1>
        </div>

        <div class="alert <?= $m['class'] ?>">
            <strong><?= htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <?= htmlspecialchars($m['text'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <?php if ($state === 'expired' || $state === 'invalid'): ?>
            <div style="text-align:center;margin-top:20px;">
                <a href="<?= SITE_URL ?>register.php" class="btn btn-primary">登録フォームへ戻る</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
