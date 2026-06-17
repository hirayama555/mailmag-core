<?php
declare(strict_types=1);

Auth::requireLogin();

$admin     = FileDB::getAdmin();
$templates = FileDB::getTemplates();
$counts    = FileDB::countByStatus();

// テンプレート読み込み
$prefill = [];
if (!empty($_GET['tpl'])) {
    $tpl = FileDB::getTemplate($_GET['tpl']);
    if ($tpl) $prefill = $tpl;
}
// 履歴から再送
if (!empty($_GET['history_id'])) {
    $h = FileDB::getHistory($_GET['history_id']);
    if ($h) { $prefill = $h; }
}

// テスト送信・入力エラー後のフォーム復元（PRG で消えた入力をセッションから復旧）。
// 復元したらすぐ破棄（ワンタイム）。ブラウザ更新で古い下書きが蒸し返さないようにする。
$draftTestEmail    = '';
$draftScheduleType = 'now';
$htmlModeChecked   = !empty($prefill['html_body']);
if (!empty($_SESSION['send_draft'])) {
    $draft = $_SESSION['send_draft'];
    unset($_SESSION['send_draft']);
    $prefill['subject']      = $draft['subject']      ?? '';
    $prefill['body']         = $draft['body']         ?? '';
    $prefill['html_body']    = $draft['html_body']    ?? '';
    $prefill['scheduled_at'] = $draft['scheduled_at'] ?? '';
    $htmlModeChecked         = !empty($draft['html_mode']);
    $draftTestEmail          = $draft['test_email']    ?? '';
    $draftScheduleType       = $draft['schedule_type'] ?? 'now';
}

// クエリパラメータによるフラッシュメッセージ
$flashMsg = '';
$flashType = 'danger';
$msgMap = [
    'test_ok'          => ['info',    'テスト送信しました。'],
    'test_fail'        => ['danger',  'テスト送信に失敗しました。メール設定を確認してください。'],
    'test_invalid'     => ['danger',  'テスト送信先のメールアドレスが不正です。'],
    'no_subscribers'   => ['warn',    '配信対象の有効な購読者がいません。'],
    'empty'            => ['danger',  '件名と本文を入力してください。'],
    'invalid_schedule' => ['danger',  '予約日時が不正または過去の日時です。未来の日時を指定してください。'],
    'post_too_large'   => ['danger',  '送信データが大きすぎます。画像は外部ホスティング（外部URL参照）を使用してください。'],
];
$msgKey = $_GET['msg'] ?? ($_GET['err'] ?? '');
if (isset($msgMap[$msgKey])) {
    [$flashType, $flashMsg] = $msgMap[$msgKey];
}

$pageTitle = 'メール送信';
$activeNav = 'send';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<form method="post" action="<?= SITE_URL ?>send_exec.php" id="sendForm">
    <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">

    <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

        <!-- 左カラム：本文入力 -->
        <div>
            <div class="card mb-4">
                <div class="card-header"><h2>メール内容</h2></div>
                <div class="card-body">

                    <?php if ($templates): ?>
                    <div class="form-group">
                        <label class="form-label">テンプレートから読み込む</label>
                        <div class="flex gap-2">
                            <select id="tpl_select" class="form-control" style="max-width:280px;">
                                <option value="">選択してください</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= htmlspecialchars($t['id'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-subject="<?= htmlspecialchars($t['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-body="<?= htmlspecialchars($t['body'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-html-body="<?= htmlspecialchars($t['html_body'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-ghost" onclick="loadTemplate()">読み込む</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">件名<span class="required">*</span></label>
                        <input type="text" name="subject" id="subject" class="form-control" required
                               value="<?= htmlspecialchars($prefill['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">本文<span class="required">*</span></label>
                        <textarea name="body" id="body" class="form-control" required
                                  style="min-height:320px;font-family:monospace;"
                        ><?= htmlspecialchars($prefill['body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        <p class="form-hint">
                            使用できる変数: <code>{{name}}</code>（購読者名）
                            <code>{{email}}</code>（メールアドレス）<br>
                            フッターの購読解除URLは自動付加されます。
                        </p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="html_mode" name="html_mode" value="1"
                                   onchange="toggleHtmlMode(this)"
                                   <?= $htmlModeChecked ? 'checked' : '' ?>>
                            HTMLメールとして送信する
                        </label>
                        <p class="form-hint">チェックすると HTML 本文欄が表示されます。画像は外部URL（&lt;img src="https://..."&gt;）で参照してください。</p>
                    </div>

                    <div id="html_body_group" style="display:<?= $htmlModeChecked ? 'block' : 'none' ?>;">
                        <div class="form-group">
                            <label class="form-label">HTML 本文</label>
                            <textarea name="html_body" id="html_body" class="form-control"
                                      style="min-height:320px;font-family:monospace;"
                            ><?= htmlspecialchars($prefill['html_body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <p class="form-hint">
                                上の「本文」欄はテキストメール用（HTML非対応のメーラー向け）に引き続き使用されます。<br>
                                HTML 本文が空の場合はテキストのみで送信されます。
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- テスト送信 -->
            <div class="card">
                <div class="card-header"><h2>テスト送信</h2></div>
                <div class="card-body">
                    <p class="text-muted mb-4">本送信前に内容を確認できます。指定したアドレスに1通だけ送信します。</p>
                    <div class="flex gap-3">
                        <input type="email" name="test_email" class="form-control"
                               placeholder="テスト送信先メールアドレス"
                               value="<?= htmlspecialchars($draftTestEmail !== '' ? $draftTestEmail : ($admin['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" name="send_mode" value="test" class="btn btn-outline">
                            テスト送信
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 右カラム：送信設定 -->
        <div>
            <div class="card mb-4">
                <div class="card-header"><h2>送信対象</h2></div>
                <div class="card-body">
                    <div class="stat-card primary" style="margin-bottom:12px;">
                        <div class="label">送信対象者数</div>
                        <div class="value"><?= number_format($counts['active']) ?></div>
                        <div class="sub">有効な購読者</div>
                    </div>
                    <p class="text-muted" style="font-size:12px;">
                        エラー停止（<?= number_format($counts['stopped']) ?>件）・
                        購読解除（<?= number_format($counts['unsubscribed']) ?>件）は除外されます。
                    </p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h2>送信タイミング</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">
                            <input type="radio" name="schedule_type" value="now"
                                   <?= $draftScheduleType !== 'reserve' ? 'checked' : '' ?>
                                   onchange="toggleSchedule(this)">
                            &nbsp;すぐに送信
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <input type="radio" name="schedule_type" value="reserve"
                                   <?= $draftScheduleType === 'reserve' ? 'checked' : '' ?>
                                   onchange="toggleSchedule(this)">
                            &nbsp;日時を指定して予約
                        </label>
                    </div>
                    <div id="schedule_inputs" style="display:<?= $draftScheduleType === 'reserve' ? 'block' : 'none' ?>;margin-top:8px;">
                        <input type="datetime-local" name="scheduled_at" class="form-control"
                               min="<?= date('Y-m-d\TH:i') ?>"
                               value="<?= htmlspecialchars($prefill['scheduled_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <p class="form-hint">CRONが1分ごとにチェックします</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <button type="submit" name="send_mode" value="send"
                            class="btn btn-primary w-full btn-lg"
                            onclick="return confirm('送信を開始しますか？')">
                        &#9993; 送信キューに追加
                    </button>
                    <p class="text-muted text-center mt-2" style="font-size:12px;">
                        <?= number_format($admin['batch_size'] ?? 100) ?>件ずつ分割送信されます
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function toggleSchedule(el) {
    document.getElementById('schedule_inputs').style.display =
        el.value === 'reserve' ? 'block' : 'none';
}
function toggleHtmlMode(el) {
    const group = document.getElementById('html_body_group');
    group.style.display = el.checked ? 'block' : 'none';
    if (!el.checked) {
        document.getElementById('html_body').value = '';
    }
}
function loadTemplate() {
    const sel = document.getElementById('tpl_select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('subject').value   = opt.dataset.subject;
    document.getElementById('body').value      = opt.dataset.body;
    const htmlBody = opt.dataset.htmlBody || '';
    document.getElementById('html_body').value = htmlBody;
    const htmlMode = document.getElementById('html_mode');
    htmlMode.checked = htmlBody !== '';
    toggleHtmlMode(htmlMode);
}
</script>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
