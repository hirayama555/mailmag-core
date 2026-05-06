<?php
declare(strict_types=1);

Auth::requireLogin();

$admin   = FileDB::getAdmin();
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $p = $_POST;

        // パスワード変更（入力された場合のみ）
        $newPassword = $admin['admin_password'];
        if (!empty($p['password'])) {
            if (strlen($p['password']) < 8) {
                $error = 'パスワードは8文字以上にしてください。';
            } elseif ($p['password'] !== $p['password_confirm']) {
                $error = 'パスワードが一致しません。';
            } else {
                $newPassword = Auth::hashPassword($p['password']);
            }
        }

        if (!$error) {
            $admin['site_name']      = trim($p['site_name'] ?? '');
            $admin['admin_email']    = trim($p['admin_email'] ?? '');
            $admin['admin_password'] = $newPassword;
            $admin['from_name']      = trim($p['from_name'] ?? '');
            $admin['from_email']     = trim($p['from_email'] ?? '');
            $admin['reply_to']       = trim($p['reply_to'] ?? '');
            $admin['register_email'] = trim($p['register_email'] ?? '');
            $admin['batch_size']     = max(10, min(500, (int)($p['batch_size'] ?? 100)));
            $admin['send_interval']  = max(0, min(5, (float)($p['send_interval'] ?? 0.1)));
            $admin['footer_text']    = $p['footer_text'] ?? '';

            FileDB::saveAdmin($admin);
            $success = true;
        }
    }
}

$pageTitle = 'システム設定';
$activeNav = 'settings';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success">設定を保存しました。</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">

    <div class="card mb-4">
        <div class="card-header"><h2>基本設定</h2></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">サイト名（メルマガ名）<span class="required">*</span></label>
                <input type="text" name="site_name" class="form-control" required
                       value="<?= htmlspecialchars($admin['site_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">管理者メールアドレス</label>
                <input type="email" name="admin_email" class="form-control"
                       value="<?= htmlspecialchars($admin['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <?php if (!empty($admin['client_id'])): ?>
            <div class="form-group">
                <label class="form-label">クライアントID（読み取り専用）</label>
                <input type="text" class="form-control font-mono"
                       value="<?= htmlspecialchars($admin['client_id'], ENT_QUOTES, 'UTF-8') ?>"
                       readonly>
                <p class="form-hint">エラー集約・サポート問い合わせ時の識別子</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>メール送信設定</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">送信者名（From名）</label>
                    <input type="text" name="from_name" class="form-control"
                           value="<?= htmlspecialchars($admin['from_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">送信元メールアドレス<span class="required">*</span></label>
                    <input type="email" name="from_email" class="form-control" required
                           value="<?= htmlspecialchars($admin['from_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Reply-Toアドレス</label>
                    <input type="email" name="reply_to" class="form-control"
                           value="<?= htmlspecialchars($admin['reply_to'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <p class="form-hint">空欄の場合は送信元と同じ</p>
                </div>
                <div class="form-group">
                    <label class="form-label">空メール受信アドレス</label>
                    <input type="email" name="register_email" class="form-control"
                           value="<?= htmlspecialchars($admin['register_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <p class="form-hint">レンタルサーバーで受信パイプに設定するアドレス</p>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">メールフッター</label>
                <textarea name="footer_text" class="form-control" style="min-height:100px;"><?= htmlspecialchars($admin['footer_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <p class="form-hint">{{unsubscribe_url}} で購読解除URLに置換されます</p>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>送信制御（分割送信）</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">1バッチあたりの送信件数</label>
                    <input type="number" name="batch_size" class="form-control" min="10" max="500"
                           value="<?= (int)($admin['batch_size'] ?? 100) ?>">
                    <p class="form-hint">100件推奨。レンタルサーバーの制限に合わせて調整</p>
                </div>
                <div class="form-group">
                    <label class="form-label">メール間の待機時間（秒）</label>
                    <input type="number" name="send_interval" class="form-control"
                           min="0" max="5" step="0.05"
                           value="<?= htmlspecialchars((string)($admin['send_interval'] ?? 0.1), ENT_QUOTES, 'UTF-8') ?>">
                    <p class="form-hint">0.1秒推奨</p>
                </div>
            </div>
            <div class="alert alert-info">
                <strong>空メール受信パイプの設定（レンタルサーバー管理パネル）</strong><br>
                メール → メールアドレス → 上記「空メール受信アドレス」を選択 →
                転送先に <code>/usr/local/bin/php <?= htmlspecialchars(BASE_DIR, ENT_QUOTES, 'UTF-8') ?>/register_mail.php</code> を設定
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>パスワード変更</h2></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">新しいパスワード</label>
                    <input type="password" name="password" class="form-control" minlength="8"
                           autocomplete="new-password">
                    <p class="form-hint">変更しない場合は空欄</p>
                </div>
                <div class="form-group">
                    <label class="form-label">パスワード確認</label>
                    <input type="password" name="password_confirm" class="form-control"
                           autocomplete="new-password">
                </div>
            </div>
        </div>
    </div>

    <div class="flex gap-3">
        <button type="submit" class="btn btn-primary btn-lg">設定を保存</button>
        <a href="<?= SITE_URL ?>dashboard.php" class="btn btn-ghost btn-lg">キャンセル</a>
    </div>
</form>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
