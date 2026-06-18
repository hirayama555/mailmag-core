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
$draftTestEmail      = '';
$draftScheduleType   = 'now';
$htmlModeChecked     = !empty($prefill['html_body']);
// 開封計測は既定 ON。履歴からの再送時は元の選択を踏襲する。
$openTrackingChecked = array_key_exists('open_tracking', $prefill)
    ? !empty($prefill['open_tracking'])
    : true;
if (!empty($_SESSION['send_draft'])) {
    $draft = $_SESSION['send_draft'];
    unset($_SESSION['send_draft']);
    $prefill['subject']      = $draft['subject']      ?? '';
    $prefill['body']         = $draft['body']         ?? '';
    $prefill['html_body']    = $draft['html_body']    ?? '';
    $prefill['scheduled_at'] = $draft['scheduled_at'] ?? '';
    $htmlModeChecked         = !empty($draft['html_mode']);
    $openTrackingChecked     = !empty($draft['open_tracking']);
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
                        <p class="form-hint">チェックすると HTML 本文欄（ビジュアルエディタ）が表示されます。画像はツールバーの画像ボタンから、事前にアップロードした画像を選んで挿入できます。</p>
                    </div>

                    <div id="html_body_group" style="display:<?= $htmlModeChecked ? 'block' : 'none' ?>;">
                        <div class="form-group">
                            <label class="form-label">HTML 本文</label>
                            <textarea name="html_body" id="html_body" class="form-control"
                                      style="min-height:320px;font-family:monospace;"
                            ><?= htmlspecialchars($prefill['html_body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <p class="form-hint">
                                上の「本文」欄はテキストメール用（HTML非対応のメーラー向け）に引き続き使用されます。<br>
                                HTML 本文が空の場合はテキストのみで送信されます。<br>
                                ※ ビジュアルエディタが読み込めない環境では、この欄に直接 HTML を記述できます。
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="open_tracking" name="open_tracking" value="1"
                                   <?= $openTrackingChecked ? 'checked' : '' ?>>
                            開封を計測する
                        </label>
                        <p class="form-hint">
                            HTMLメールに 1×1 の透明画像を埋め込み、開封率を計測します。<br>
                            ※ HTMLメール送信時のみ有効。受信側の画像ブロック等により実数とずれる目安値です。
                        </p>
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

<!-- ===== 画像ピッカー モーダル ===== -->
<div id="mediaModal" class="media-modal" style="display:none;">
    <div class="media-modal__backdrop" onclick="closeMediaPicker()"></div>
    <div class="media-modal__panel">
        <div class="media-modal__head">
            <h3 style="margin:0;">画像ライブラリ</h3>
            <button type="button" class="btn btn-ghost" onclick="closeMediaPicker()">&times; 閉じる</button>
        </div>

        <div class="media-modal__toolbar">
            <label class="btn btn-primary" style="cursor:pointer;margin:0;">
                &#10133; 新規アップロード
                <input type="file" id="mediaFileInput" accept="image/jpeg,image/png,image/gif,image/webp"
                       style="display:none;" onchange="uploadMedia(this)">
            </label>
            <span id="mediaUsage" class="text-muted" style="font-size:12px;"></span>
        </div>

        <div id="mediaError" class="alert alert-danger" style="display:none;margin:0 0 10px;"></div>
        <div id="mediaUploading" class="text-muted" style="display:none;font-size:13px;margin-bottom:8px;">アップロード中…</div>

        <div id="mediaGrid" class="media-grid">
            <p class="text-muted" style="grid-column:1/-1;">読み込み中…</p>
        </div>

        <div class="media-modal__foot">
            <button type="button" class="btn btn-ghost" id="mediaDeleteBtn" onclick="deleteSelectedMedia()" disabled>選択した画像を削除</button>
            <div class="flex gap-2">
                <button type="button" class="btn btn-outline" onclick="closeMediaPicker()">キャンセル</button>
                <button type="button" class="btn btn-primary" id="mediaApplyBtn" onclick="applySelectedMedia()" disabled>適用</button>
            </div>
        </div>
    </div>
</div>

<style>
.media-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;}
.media-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.5);}
.media-modal__panel{position:relative;background:#fff;border-radius:10px;width:min(880px,94vw);max-height:90vh;
    display:flex;flex-direction:column;padding:18px;box-shadow:0 12px 40px rgba(0,0,0,.3);}
.media-modal__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.media-modal__toolbar{display:flex;align-items:center;gap:14px;margin-bottom:12px;}
.media-modal__foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb;}
.media-grid{flex:1;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;min-height:200px;}
.media-thumb{position:relative;border:2px solid transparent;border-radius:8px;overflow:hidden;cursor:pointer;background:#f3f4f6;aspect-ratio:1/1;}
.media-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.media-thumb.selected{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.3);}
.media-thumb__size{position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);color:#fff;font-size:10px;padding:2px 4px;}
</style>

<!-- TinyMCE（自己ホスト版・GPL / APIキー不要）。読込失敗時は素の textarea にフォールバック -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>

<script>
const MEDIA_URL  = '<?= SITE_URL ?>media.php';
const MEDIA_CSRF = '<?= Token::getCsrf() ?>';

function toggleSchedule(el) {
    document.getElementById('schedule_inputs').style.display =
        el.value === 'reserve' ? 'block' : 'none';
}

// ---- HTMLエディタ（TinyMCE）----------------------------------
function hasEditor() {
    return window.tinymce && tinymce.get('html_body');
}
function initHtmlEditor() {
    if (!window.tinymce || hasEditor()) return;
    tinymce.init({
        selector: '#html_body',
        license_key: 'gpl',
        language: 'ja',
        // 言語パックは本体に同梱されないため i18n パッケージから読む（読込失敗時は英語UIにフォールバック）
        language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@latest/langs7/ja.js',
        height: 380,
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'link image lists code autolink',
        toolbar: 'undo redo | bold italic underline forecolor | bullist numlist | '
               + 'alignleft aligncenter alignright | link image | removeformat | code',
        // メール互換のため class ではなくインラインスタイルで出力
        forced_root_block: 'p',
        convert_urls: false,
        file_picker_types: 'image',
        file_picker_callback: function (callback) {
            openMediaPicker(callback);
        }
    });
}
function destroyHtmlEditor() {
    if (hasEditor()) tinymce.get('html_body').remove();
}
function toggleHtmlMode(el) {
    const group = document.getElementById('html_body_group');
    group.style.display = el.checked ? 'block' : 'none';
    if (el.checked) {
        initHtmlEditor();
    } else {
        destroyHtmlEditor();
        document.getElementById('html_body').value = '';
    }
}
function loadTemplate() {
    const sel = document.getElementById('tpl_select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('subject').value = opt.dataset.subject;
    document.getElementById('body').value    = opt.dataset.body;
    const htmlBody = opt.dataset.htmlBody || '';
    const htmlMode = document.getElementById('html_mode');
    htmlMode.checked = htmlBody !== '';
    toggleHtmlMode(htmlMode);
    if (hasEditor()) {
        tinymce.get('html_body').setContent(htmlBody);
    } else {
        document.getElementById('html_body').value = htmlBody;
    }
}

// 送信前にエディタ内容を textarea へ書き戻す（TinyMCE 使用時）
document.getElementById('sendForm').addEventListener('submit', function () {
    if (window.tinymce) tinymce.triggerSave();
});

// 初期表示時、HTMLモードが既に ON ならエディタを起動
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('html_mode').checked) initHtmlEditor();
});

// ---- 画像ピッカー -------------------------------------------
let mediaCallback = null;   // TinyMCE へ URL を返すコールバック
let mediaSelected = null;   // 選択中 {name, url}

function openMediaPicker(cb) {
    mediaCallback = cb || null;
    mediaSelected = null;
    document.getElementById('mediaApplyBtn').disabled  = true;
    document.getElementById('mediaDeleteBtn').disabled = true;
    document.getElementById('mediaError').style.display = 'none';
    document.getElementById('mediaModal').style.display = 'flex';
    loadMediaList();
}
function closeMediaPicker() {
    document.getElementById('mediaModal').style.display = 'none';
    mediaCallback = null;
}
function showMediaError(msg) {
    const el = document.getElementById('mediaError');
    el.textContent = msg;
    el.style.display = 'block';
}
function fmtBytes(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1) + 'MB';
    if (b >= 1024)    return (b / 1024).toFixed(1) + 'KB';
    return b + 'B';
}
function loadMediaList() {
    const grid = document.getElementById('mediaGrid');
    grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;">読み込み中…</p>';
    fetch(MEDIA_URL + '?action=list', {credentials: 'same-origin'})
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { showMediaError(data.error || '一覧の取得に失敗しました。'); return; }
            document.getElementById('mediaUsage').textContent =
                '画像登録状況: ' + fmtBytes(data.total) + ' / ' + fmtBytes(data.total_max);
            if (!data.items.length) {
                grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;">'
                    + 'まだ画像がありません。「新規アップロード」から追加してください。</p>';
                return;
            }
            grid.innerHTML = '';
            data.items.forEach(it => {
                const div = document.createElement('div');
                div.className = 'media-thumb';
                div.title = it.name;
                div.innerHTML = '<img src="' + it.url + '" alt="" loading="lazy">'
                    + '<span class="media-thumb__size">' + fmtBytes(it.size) + '</span>';
                div.onclick = () => selectMedia(div, it);
                grid.appendChild(div);
            });
        })
        .catch(() => showMediaError('通信エラーが発生しました。'));
}
function selectMedia(el, item) {
    document.querySelectorAll('.media-thumb.selected').forEach(n => n.classList.remove('selected'));
    el.classList.add('selected');
    mediaSelected = item;
    document.getElementById('mediaApplyBtn').disabled  = false;
    document.getElementById('mediaDeleteBtn').disabled = false;
}
function uploadMedia(input) {
    if (!input.files || !input.files[0]) return;
    const fd = new FormData();
    fd.append('csrf_token', MEDIA_CSRF);
    fd.append('file', input.files[0]);
    document.getElementById('mediaError').style.display = 'none';
    document.getElementById('mediaUploading').style.display = 'block';
    fetch(MEDIA_URL + '?action=upload', {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(r => r.json())
        .then(data => {
            document.getElementById('mediaUploading').style.display = 'none';
            input.value = '';
            if (!data.ok) { showMediaError(data.error || 'アップロードに失敗しました。'); return; }
            loadMediaList();
        })
        .catch(() => {
            document.getElementById('mediaUploading').style.display = 'none';
            showMediaError('通信エラーが発生しました。');
        });
}
function deleteSelectedMedia() {
    if (!mediaSelected) return;
    if (!confirm('「' + mediaSelected.name + '」を削除しますか？\nこの画像を使用中のメールがあると表示されなくなります。')) return;
    const fd = new FormData();
    fd.append('csrf_token', MEDIA_CSRF);
    fd.append('name', mediaSelected.name);
    fetch(MEDIA_URL + '?action=delete', {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { showMediaError(data.error || '削除に失敗しました。'); return; }
            mediaSelected = null;
            document.getElementById('mediaApplyBtn').disabled  = true;
            document.getElementById('mediaDeleteBtn').disabled = true;
            loadMediaList();
        })
        .catch(() => showMediaError('通信エラーが発生しました。'));
}
function applySelectedMedia() {
    if (!mediaSelected) return;
    if (typeof mediaCallback === 'function') {
        mediaCallback(mediaSelected.url, {alt: ''});
    }
    closeMediaPicker();
}
</script>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
