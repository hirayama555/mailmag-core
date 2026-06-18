<?php
declare(strict_types=1);

// ============================================================
// core/app/setup.php - 初回セットアップ
//
// 旧版からの主要変更:
//   - セットアップ完了時に client_id (UUID v4) を自動生成し
//     admin.json に保存する。インスタンス識別子（複数台運用時の
//     区別など、内部用）として利用される。
//   - setup_done が真ならファイル冒頭で die（自己防御）
// ============================================================

Auth::start();

$admin = FileDB::getAdmin();

// 設定済みなら拒否（自己防御）
if (!empty($admin['setup_done'])) {
    http_response_code(403);
    die('セットアップは完了済みです。このファイルへのアクセスは無効化されました。');
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = $_POST;

    if (empty($p['site_name']))                          $error = 'サイト名を入力してください。';
    elseif (empty($p['admin_email']))                    $error = '管理者メールアドレスを入力してください。';
    elseif (!filter_var(trim($p['admin_email']), FILTER_VALIDATE_EMAIL)) $error = '管理者メールアドレスの形式が正しくありません。';
    elseif (empty($p['from_email']))                     $error = '送信元メールアドレスを入力してください。';
    elseif (!filter_var(trim($p['from_email']), FILTER_VALIDATE_EMAIL))  $error = '送信元メールアドレスの形式が正しくありません。';
    elseif (!empty($p['reply_to']) && !filter_var(trim($p['reply_to']), FILTER_VALIDATE_EMAIL))             $error = 'Reply-Toアドレスの形式が正しくありません。';
    elseif (!empty($p['register_email']) && !filter_var(trim($p['register_email']), FILTER_VALIDATE_EMAIL)) $error = '空メール受信アドレスの形式が正しくありません。';
    elseif (empty($p['password']))                       $error = 'パスワードを入力してください。';
    elseif (strlen($p['password']) < 8)                  $error = 'パスワードは8文字以上にしてください。';
    elseif ($p['password'] !== $p['password_confirm'])   $error = 'パスワードが一致しません。';
    else {
        $newAdmin = [
            'client_id'      => Uuid::v4(), // インスタンス識別子（生成のみ。現状用途なし）
            'site_name'      => trim($p['site_name']),
            'admin_email'    => trim($p['admin_email']),
            'admin_password' => Auth::hashPassword($p['password']),
            'from_name'      => trim($p['from_name'] ?? ''),
            'from_email'     => trim($p['from_email']),
            'reply_to'       => trim($p['reply_to'] ?? '') ?: trim($p['from_email']),
            'register_email' => trim($p['register_email'] ?? '') ?: trim($p['from_email']),
            'batch_size'     => max(10, min(500, (int)($p['batch_size'] ?? 100))),
            'send_interval'  => max(0, min(5, (float)($p['send_interval'] ?? 0.1))),
            'double_optin'   => true,
            'footer_text'    => "──────────────────────\n本メールの配信停止はこちら：\n{{unsubscribe_url}}\n──────────────────────",
            'setup_done'     => true,
            'created_at'     => date('Y-m-d H:i:s'),
        ];
        FileDB::saveAdmin($newAdmin);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>初期セットアップ - MailMag</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card" style="max-width:520px;">
        <h1>初期セットアップ</h1>
        <p class="sub">MailMagへようこそ。初回設定を行ってください。</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                セットアップが完了しました。<br>
                <strong>セキュリティのため setup.php を削除するか .htaccess で deny してください。</strong>
            </div>
            <a href="<?= SITE_URL ?>index.php" class="btn btn-primary w-full">ログインページへ</a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label class="form-label">サイト名（メルマガ名）<span class="required">*</span></label>
                    <input type="text" name="site_name" class="form-control"
                           value="<?= htmlspecialchars($_POST['site_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">管理者メールアドレス<span class="required">*</span></label>
                    <input type="email" name="admin_email" class="form-control"
                           value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">送信者名</label>
                        <input type="text" name="from_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['from_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">送信元メールアドレス<span class="required">*</span></label>
                        <input type="email" name="from_email" class="form-control"
                               value="<?= htmlspecialchars($_POST['from_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">空メール受信アドレス</label>
                    <input type="email" name="register_email" class="form-control"
                           value="<?= htmlspecialchars($_POST['register_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <p class="form-hint">空欄の場合は送信元メールアドレスを使用</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">1バッチ送信件数</label>
                        <input type="number" name="batch_size" class="form-control" min="10" max="500"
                               value="<?= (int)($_POST['batch_size'] ?? 100) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">送信間隔（秒）</label>
                        <input type="number" name="send_interval" class="form-control"
                               min="0" max="5" step="0.05"
                               value="<?= htmlspecialchars((string)($_POST['send_interval'] ?? '0.1'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">パスワード<span class="required">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label class="form-label">パスワード確認<span class="required">*</span></label>
                        <input type="password" name="password_confirm" class="form-control" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full btn-lg">セットアップ完了</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
