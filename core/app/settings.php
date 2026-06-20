<?php
declare(strict_types=1);

Auth::requireLogin();

$admin       = FileDB::getAdmin();
$success     = false;
$error       = '';
$smtpTestOk  = false;
$smtpTestMsg = '';
$imapTestOk  = false;
$imapTestMsg = '';

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

        // メールアドレスのサーバ側検証。不正な from_email を保存すると
        // Mailer::send が常に false を返し全配信が無言で失敗するため、保存前に弾く。
        $inFromEmail     = trim($p['from_email'] ?? '');
        $inAdminEmail    = trim($p['admin_email'] ?? '');
        $inReplyTo       = trim($p['reply_to'] ?? '');
        $inRegisterEmail = trim($p['register_email'] ?? '');
        if (!$error) {
            $emailChecks = [
                '送信元メールアドレス'   => [$inFromEmail,     true],   // 必須
                '管理者メールアドレス'   => [$inAdminEmail,    false],
                'Reply-Toアドレス'       => [$inReplyTo,       false],
                '空メール受信アドレス'   => [$inRegisterEmail, false],
            ];
            foreach ($emailChecks as $label => $spec) {
                [$val, $required] = $spec;
                if ($val === '') {
                    if ($required) { $error = $label . 'を入力してください。'; break; }
                } elseif (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $error = $label . 'の形式が正しくありません。'; break;
                }
            }
        }

        if (!$error) {
            $admin['site_name']      = trim($p['site_name'] ?? '');
            $admin['admin_email']    = $inAdminEmail;
            $admin['admin_password'] = $newPassword;
            $admin['from_name']      = trim($p['from_name'] ?? '');
            $admin['from_email']     = $inFromEmail;
            // reply_to / register_email は空なら送信元にフォールバック（setup.php と同挙動）
            $admin['reply_to']       = $inReplyTo       !== '' ? $inReplyTo       : $inFromEmail;
            $admin['register_email'] = $inRegisterEmail !== '' ? $inRegisterEmail : $inFromEmail;
            $admin['batch_size']     = max(10, min(500, (int)($p['batch_size'] ?? 100)));
            $admin['send_interval']  = max(0, min(5, (float)($p['send_interval'] ?? 0.1)));
            $admin['footer_text']    = $p['footer_text'] ?? '';

            // ----- SMTP送信設定（DKIM署名対応）-----
            $admin['smtp_enabled'] = !empty($p['smtp_enabled']);
            $admin['smtp_host']    = trim($p['smtp_host'] ?? '');
            $admin['smtp_port']    = max(1, min(65535, (int)($p['smtp_port'] ?? 587)));
            $admin['smtp_user']    = trim($p['smtp_user'] ?? '');
            $admin['smtp_secure']  = in_array(($p['smtp_secure'] ?? 'tls'), ['tls', 'ssl', ''], true)
                ? (string)$p['smtp_secure']
                : 'tls';
            // パスワードは入力された場合のみ更新（管理者パスワードと同方式）
            if (!empty($p['smtp_pass'])) {
                $admin['smtp_pass'] = (string)$p['smtp_pass'];
            }

            // ----- IMAP受信設定（バウンスポーリング）-----
            $admin['imap_enabled'] = !empty($p['imap_enabled']);
            $admin['imap_host']    = trim($p['imap_host'] ?? '');
            $admin['imap_port']    = max(1, min(65535, (int)($p['imap_port'] ?? 993)));
            $admin['imap_user']    = trim($p['imap_user'] ?? '');
            $admin['imap_secure']  = in_array(($p['imap_secure'] ?? 'ssl'), ['ssl', 'tls', ''], true)
                ? (string)$p['imap_secure']
                : 'ssl';
            if (!empty($p['imap_pass'])) {
                $admin['imap_pass'] = (string)$p['imap_pass'];
            }

            FileDB::saveAdmin($admin);
            $success = true;

            // SMTP有効時は保存直後に接続テストを行い、即フィードバックする
            if ($admin['smtp_enabled']) {
                try {
                    $test = new SmtpClient(
                        (string)$admin['smtp_host'],
                        (int)$admin['smtp_port'],
                        (string)$admin['smtp_user'],
                        (string)($admin['smtp_pass'] ?? ''),
                        (string)$admin['smtp_secure']
                    );
                    $test->connect();
                    $test->close();
                    $smtpTestOk = true;
                } catch (SmtpException $e) {
                    $smtpTestMsg = $e->getMessage();
                }
            }

            // IMAP有効時は保存直後に接続テストを行い、即フィードバックする
            if ($admin['imap_enabled']) {
                try {
                    $imapTest = new ImapClient(
                        (string)$admin['imap_host'],
                        (int)$admin['imap_port'],
                        (string)$admin['imap_user'],
                        (string)($admin['imap_pass'] ?? ''),
                        (string)$admin['imap_secure']
                    );
                    $imapTest->connect();
                    $imapTest->close();
                    $imapTestOk = true;
                } catch (ImapException $e) {
                    $imapTestMsg = $e->getMessage();
                }
            }
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
<?php if ($success && $smtpTestOk): ?>
    <div class="alert alert-success">SMTP接続テストに成功しました。DKIM署名付き送信の準備が整いました。</div>
<?php elseif ($success && $smtpTestMsg !== ''): ?>
    <div class="alert alert-danger">
        設定は保存しましたが、<strong>SMTP接続テストに失敗</strong>しました:<br>
        <?= htmlspecialchars($smtpTestMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>
<?php if ($success && $imapTestOk): ?>
    <div class="alert alert-success">IMAP接続テストに成功しました。バウンスポーリングの準備が整いました。</div>
<?php elseif ($success && $imapTestMsg !== ''): ?>
    <div class="alert alert-danger">
        設定は保存しましたが、<strong>IMAP接続テストに失敗</strong>しました:<br>
        <?= htmlspecialchars($imapTestMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
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
        <div class="card-header"><h2>SMTP送信設定（DKIM署名対応）</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>DKIM署名を有効にするにはSMTP送信が必要です。</strong><br>
                多くのレンタルサーバー（カゴヤ・シン・レンタルサーバー等）は、
                <strong>SMTP認証を経由した送信のみDKIM署名を付与</strong>します。
                PHP標準の <code>mail()</code> 送信ではDKIMが付かず、Gmail等で迷惑メール判定されやすくなります。<br>
                有効化後、サーバー管理画面でDKIMの「署名付与」をONにしてください。
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="smtp_enabled" value="1"
                        <?= !empty($admin['smtp_enabled']) ? 'checked' : '' ?>>
                    SMTP送信を有効にする
                </label>
                <p class="form-hint">オフの場合はPHP標準の <code>mail()</code> で送信します（DKIMなし）</p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">SMTPホスト</label>
                    <input type="text" name="smtp_host" class="form-control"
                           placeholder="例: mss-vl-765.kagoya.net / svXXXX.xserver.jp"
                           value="<?= htmlspecialchars($admin['smtp_host'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <p class="form-hint">サーバーのメール送信(SMTP)サーバー名</p>
                </div>
                <div class="form-group">
                    <label class="form-label">ポート / 暗号化</label>
                    <div class="form-row">
                        <input type="number" name="smtp_port" class="form-control" min="1" max="65535"
                               value="<?= (int)($admin['smtp_port'] ?? 587) ?>" style="max-width:120px;">
                        <select name="smtp_secure" class="form-control">
                            <?php $sec = $admin['smtp_secure'] ?? 'tls'; ?>
                            <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                            <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
                            <option value=""    <?= $sec === ''    ? 'selected' : '' ?>>暗号化なし（非推奨）</option>
                        </select>
                    </div>
                    <p class="form-hint">587=STARTTLS、465=SSL が一般的</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">SMTPユーザー名</label>
                    <input type="text" name="smtp_user" class="form-control" autocomplete="off"
                           placeholder="通常は送信元メールアドレス"
                           value="<?= htmlspecialchars($admin['smtp_user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTPパスワード</label>
                    <input type="password" name="smtp_pass" class="form-control" autocomplete="new-password"
                           placeholder="<?= !empty($admin['smtp_pass']) ? '設定済み（変更時のみ入力）' : 'メールアカウントのパスワード' ?>">
                    <p class="form-hint">変更しない場合は空欄。<code>data/</code> 内に保存されます（.htaccess で保護）</p>
                </div>
            </div>
            <p class="form-hint">設定を保存すると自動でSMTP接続テストを行います。</p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>IMAP受信設定（バウンスポーリング）</h2></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="imap_enabled" value="1"
                           <?= !empty($admin['imap_enabled']) ? 'checked' : '' ?>>
                    IMAPポーリングによるバウンス処理を有効にする
                </label>
                <p class="form-hint">メールパイプ（.forward）が使えないサーバー向けの代替方式。bounce専用メールボックスを定期取得して不達購読者を自動停止します。</p>
            </div>

            <div class="form-group">
                <label class="form-label">IMAPサーバー</label>
                <input type="text" name="imap_host" class="form-control"
                       placeholder="例: mail.example.net"
                       value="<?= htmlspecialchars($admin['imap_host'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ポート / 暗号化</label>
                    <div class="form-row">
                        <input type="number" name="imap_port" class="form-control" min="1" max="65535"
                               value="<?= (int)($admin['imap_port'] ?? 993) ?>" style="max-width:120px;">
                        <select name="imap_secure" class="form-control">
                            <?php $isec = $admin['imap_secure'] ?? 'ssl'; ?>
                            <option value="ssl" <?= $isec === 'ssl' ? 'selected' : '' ?>>SSL/TLS (993)</option>
                            <option value="tls" <?= $isec === 'tls' ? 'selected' : '' ?>>STARTTLS (143)</option>
                            <option value=""    <?= $isec === ''    ? 'selected' : '' ?>>暗号化なし（非推奨）</option>
                        </select>
                    </div>
                    <p class="form-hint">993=SSL が一般的</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">IMAPユーザー名</label>
                    <input type="text" name="imap_user" class="form-control" autocomplete="off"
                           placeholder="例: bounce@example.net"
                           value="<?= htmlspecialchars($admin['imap_user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">IMAPパスワード</label>
                    <input type="password" name="imap_pass" class="form-control" autocomplete="new-password"
                           placeholder="<?= !empty($admin['imap_pass']) ? '設定済み（変更時のみ入力）' : 'メールアカウントのパスワード' ?>">
                    <p class="form-hint">変更しない場合は空欄。<code>data/</code> 内に保存されます（.htaccess で保護）</p>
                </div>
            </div>
            <p class="form-hint">設定を保存すると自動でIMAP接続テストを行います。<code>cron_bounce_imap.php</code> を5分ごとに実行するよう cron に登録してください。</p>
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
