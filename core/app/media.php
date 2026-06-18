<?php
declare(strict_types=1);

// ============================================================
// core/app/media.php - HTMLメール用 画像ライブラリのエンドポイント
//
// 認証必須。送信フォームの画像ピッカー（TinyMCE）から AJAX で呼ばれる。
//   GET  ?action=list           … uploads/ の画像一覧 + 容量を JSON で返す
//   POST ?action=upload         … 画像1枚をアップロード（multipart, field=file）
//   POST ?action=delete         … 画像1枚を削除（name=ファイル名）
//
// 保存先は公開ディレクトリ UPLOADS_DIR（= BASE_DIR/uploads）。
// data/ と違い公開する必要がある（メール受信側のメーラーが画像を直接取得するため）。
// その代わり uploads/.htaccess で PHP 等のスクリプト実行を禁止する（初回自動生成）。
// ============================================================

Auth::requireLogin();

// 許可する画像 MIME と拡張子の対応（拡張子はこの表からのみ決定する）
const MEDIA_ALLOWED = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$action = (string)($_GET['action'] ?? '');

// ---- API アクション（JSON）。送信フォームの画像ピッカー / 管理画面の AJAX から呼ばれる ----
if (in_array($action, ['list', 'upload', 'delete'], true)) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        ensureUploadsDir();
        switch ($action) {
            case 'list':
                echo json_encode(actionList(), JSON_UNESCAPED_SLASHES);
                break;
            case 'upload':
                requirePostCsrf();
                echo json_encode(actionUpload(), JSON_UNESCAPED_SLASHES);
                break;
            case 'delete':
                requirePostCsrf();
                echo json_encode(actionDelete(), JSON_UNESCAPED_SLASHES);
                break;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'サーバーエラーが発生しました。']);
    }
    return; // HTML レンダリングへは進まない
}

// ---- 引数なしアクセス = 画像ライブラリ管理画面（HTML）。左メニューから開く ----
ensureUploadsDir();
renderLibraryPage();

// ---- 管理画面（HTML）--------------------------------------

/**
 * 画像ライブラリ管理画面を出力する。
 * 送信フォームのモーダルと同じ API（?action=list/upload/delete）を AJAX で叩く
 * フルページ版。ここでは「選んで挿入」ではなく登録・削除・URLコピーを行う。
 */
function renderLibraryPage(): void
{
    $pageTitle = '画像ライブラリ';
    $activeNav = 'media';
    require CORE_INCLUDES_DIR . '/header.php';
    $csrf      = Token::getCsrf();
    $mediaUrl  = SITE_URL . 'media.php';
    ?>
    <div class="card">
        <div class="card-header"><h2>画像ライブラリ</h2></div>
        <div class="card-body">
            <p class="text-muted mb-4">
                メールに挿入する画像をここで管理します。アップロードした画像は、メール送信画面の
                ビジュアルエディタ（画像ボタン → 画像ライブラリ）から選んで挿入できます。<br>
                対応形式: JPEG / PNG / GIF / WebP（1ファイル <?= htmlspecialchars(formatBytes(UPLOAD_MAX_BYTES), ENT_QUOTES, 'UTF-8') ?>・
                合計 <?= htmlspecialchars(formatBytes(UPLOAD_TOTAL_MAX_BYTES), ENT_QUOTES, 'UTF-8') ?> まで）
            </p>

            <div class="flex gap-3" style="align-items:center;margin-bottom:14px;">
                <label class="btn btn-primary" style="cursor:pointer;margin:0;">
                    &#10133; 新規アップロード
                    <input type="file" id="libFileInput" accept="image/jpeg,image/png,image/gif,image/webp"
                           style="display:none;" onchange="libUpload(this)">
                </label>
                <span id="libUsage" class="text-muted" style="font-size:12px;"></span>
                <span id="libUploading" class="text-muted" style="display:none;font-size:13px;">アップロード中…</span>
            </div>

            <div id="libError" class="alert alert-danger" style="display:none;"></div>

            <div id="libGrid" class="lib-grid">
                <p class="text-muted" style="grid-column:1/-1;">読み込み中…</p>
            </div>
        </div>
    </div>

    <style>
    .lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;}
    .lib-card{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;display:flex;flex-direction:column;}
    .lib-card__thumb{aspect-ratio:1/1;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden;}
    .lib-card__thumb img{width:100%;height:100%;object-fit:cover;display:block;}
    .lib-card__body{padding:8px;display:flex;flex-direction:column;gap:6px;}
    .lib-card__size{font-size:11px;color:#64748b;}
    .lib-card__actions{display:flex;gap:6px;}
    .lib-card__actions .btn{flex:1;padding:4px 6px;font-size:12px;}
    </style>

    <script>
    const LIB_URL  = '<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8') ?>';
    const LIB_CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>';

    function libFmtBytes(b){
        if (b >= 1048576) return (b/1048576).toFixed(1) + 'MB';
        if (b >= 1024)    return (b/1024).toFixed(1) + 'KB';
        return b + 'B';
    }
    function libShowError(msg){
        const el = document.getElementById('libError');
        el.textContent = msg; el.style.display = 'block';
    }
    function libLoad(){
        const grid = document.getElementById('libGrid');
        grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;">読み込み中…</p>';
        fetch(LIB_URL + '?action=list', {credentials:'same-origin'})
            .then(r => r.json())
            .then(data => {
                if (!data.ok){ libShowError(data.error || '一覧の取得に失敗しました。'); return; }
                document.getElementById('libUsage').textContent =
                    '画像登録状況: ' + libFmtBytes(data.total) + ' / ' + libFmtBytes(data.total_max);
                if (!data.items.length){
                    grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;">'
                        + 'まだ画像がありません。「新規アップロード」から追加してください。</p>';
                    return;
                }
                grid.innerHTML = '';
                data.items.forEach(it => {
                    const card = document.createElement('div');
                    card.className = 'lib-card';
                    card.innerHTML =
                        '<div class="lib-card__thumb"><img src="' + it.url + '" alt="" loading="lazy"></div>'
                      + '<div class="lib-card__body">'
                      +   '<span class="lib-card__size">' + libFmtBytes(it.size) + '</span>'
                      +   '<div class="lib-card__actions">'
                      +     '<button type="button" class="btn btn-outline">URLコピー</button>'
                      +     '<button type="button" class="btn btn-ghost">削除</button>'
                      +   '</div>'
                      + '</div>';
                    const btns = card.querySelectorAll('button');
                    btns[0].onclick = () => libCopy(it.url, btns[0]);
                    btns[1].onclick = () => libDelete(it.name);
                    grid.appendChild(card);
                });
            })
            .catch(() => libShowError('通信エラーが発生しました。'));
    }
    function libCopy(url, btn){
        const done = () => { const t = btn.textContent; btn.textContent = 'コピーしました'; setTimeout(() => btn.textContent = t, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(url).then(done).catch(() => window.prompt('URL をコピーしてください', url));
        } else {
            window.prompt('URL をコピーしてください', url);
        }
    }
    function libUpload(input){
        if (!input.files || !input.files[0]) return;
        const fd = new FormData();
        fd.append('csrf_token', LIB_CSRF);
        fd.append('file', input.files[0]);
        document.getElementById('libError').style.display = 'none';
        document.getElementById('libUploading').style.display = 'inline';
        fetch(LIB_URL + '?action=upload', {method:'POST', body:fd, credentials:'same-origin'})
            .then(r => r.json())
            .then(data => {
                document.getElementById('libUploading').style.display = 'none';
                input.value = '';
                if (!data.ok){ libShowError(data.error || 'アップロードに失敗しました。'); return; }
                libLoad();
            })
            .catch(() => {
                document.getElementById('libUploading').style.display = 'none';
                libShowError('通信エラーが発生しました。');
            });
    }
    function libDelete(name){
        if (!confirm('「' + name + '」を削除しますか？\nこの画像を使用中のメールがあると表示されなくなります。')) return;
        const fd = new FormData();
        fd.append('csrf_token', LIB_CSRF);
        fd.append('name', name);
        fetch(LIB_URL + '?action=delete', {method:'POST', body:fd, credentials:'same-origin'})
            .then(r => r.json())
            .then(data => {
                if (!data.ok){ libShowError(data.error || '削除に失敗しました。'); return; }
                libLoad();
            })
            .catch(() => libShowError('通信エラーが発生しました。'));
    }
    document.addEventListener('DOMContentLoaded', libLoad);
    </script>
    <?php
    require CORE_INCLUDES_DIR . '/footer.php';
}

// ---- アクション実装 ----------------------------------------

/** uploads/ 内の画像一覧と合計容量を返す */
function actionList(): array
{
    $items = [];
    $total = 0;
    foreach (scandir(UPLOADS_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = UPLOADS_DIR . '/' . $f;
        if (!is_file($path)) continue;
        $ext = strtolower((string)pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, MEDIA_ALLOWED, true)) continue; // 画像以外（.htaccess 等）は除外
        $size   = (int)filesize($path);
        $total += $size;
        $items[] = [
            'name'  => $f,
            'url'   => UPLOADS_URL . rawurlencode($f),
            'size'  => $size,
            'mtime' => (int)filemtime($path),
        ];
    }
    // 新しい順
    usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

    return [
        'ok'        => true,
        'items'     => $items,
        'total'     => $total,
        'total_max' => UPLOAD_TOTAL_MAX_BYTES,
    ];
}

/** 画像1枚をアップロード */
function actionUpload(): array
{
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        return err('ファイルが送信されていません。');
    }
    $file = $_FILES['file'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return err(uploadErrorMessage((int)$file['error']));
    }

    $tmp  = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return err('アップロードの検証に失敗しました。');
    }
    if ($size <= 0) {
        return err('空のファイルです。');
    }
    if ($size > UPLOAD_MAX_BYTES) {
        return err('ファイルサイズが上限（' . formatBytes(UPLOAD_MAX_BYTES) . '）を超えています。');
    }

    // 合計容量の上限チェック（現在の合計 + 今回分）
    $current = currentTotalBytes();
    if ($current + $size > UPLOAD_TOTAL_MAX_BYTES) {
        return err('保存容量の上限（' . formatBytes(UPLOAD_TOTAL_MAX_BYTES) . '）に達しています。不要な画像を削除してください。');
    }

    // 実体の MIME を検出（拡張子は信用しない）
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($tmp);
    if (!isset(MEDIA_ALLOWED[$mime])) {
        return err('対応していない画像形式です（JPEG / PNG / GIF / WebP のみ）。');
    }
    $ext = MEDIA_ALLOWED[$mime];

    // ファイル名はランダム生成（元の名前は使わない＝トラバーサル/上書き/XSS 回避）
    $name = 'img_' . str_replace('-', '', Uuid::v4()) . '.' . $ext;
    $dest = UPLOADS_DIR . '/' . $name;

    if (!move_uploaded_file($tmp, $dest)) {
        return err('ファイルの保存に失敗しました。uploads/ の書き込み権限を確認してください。');
    }
    @chmod($dest, 0644);

    return [
        'ok'   => true,
        'name' => $name,
        'url'  => UPLOADS_URL . rawurlencode($name),
        'size' => $size,
    ];
}

/** 画像1枚を削除 */
function actionDelete(): array
{
    $name = basename((string)($_POST['name'] ?? '')); // basename でパス成分を除去
    if ($name === '' || $name === '.' || $name === '..') {
        return err('ファイル名が不正です。');
    }
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, MEDIA_ALLOWED, true)) {
        return err('画像ファイルではありません。');
    }
    $path = UPLOADS_DIR . '/' . $name;
    if (!is_file($path)) {
        return err('ファイルが見つかりません。');
    }
    if (!@unlink($path)) {
        return err('削除に失敗しました。');
    }
    return ['ok' => true];
}

// ---- ヘルパ ------------------------------------------------

/** POST かつ CSRF 有効を強制。失敗時は即終了 */
function requirePostCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST が必要です。']);
        exit;
    }
    if (!Token::verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '不正なリクエストです（CSRF）。']);
        exit;
    }
}

/** uploads/ と保護用 .htaccess を必要なら生成する */
function ensureUploadsDir(): void
{
    if (!is_dir(UPLOADS_DIR)) {
        @mkdir(UPLOADS_DIR, 0755, true);
    }
    $ht = UPLOADS_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, uploadsHtaccess());
    }
}

/**
 * uploads/ 用 .htaccess の中身。
 * PHP がモジュール版のときだけ engine off（FPM/CGI では IfModule が偽になり 500 を避ける）。
 * 併せてスクリプト系拡張子へのアクセスを拒否し、ディレクトリ一覧も禁止する。
 */
function uploadsHtaccess(): string
{
    return <<<'HT'
# uploads/ - 公開画像ディレクトリ（HTMLメール用）。
# 画像のみを置く。スクリプト実行は多層で禁止する。
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>
<FilesMatch "\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh|asp|aspx|htaccess)$">
    Order allow,deny
    Deny from all
</FilesMatch>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar
RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar
Options -Indexes
HT;
}

/** uploads/ 内の画像合計バイト数 */
function currentTotalBytes(): int
{
    $total = 0;
    foreach (scandir(UPLOADS_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = UPLOADS_DIR . '/' . $f;
        if (!is_file($path)) continue;
        $ext = strtolower((string)pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, MEDIA_ALLOWED, true)) continue;
        $total += (int)filesize($path);
    }
    return $total;
}

function err(string $msg): array
{
    return ['ok' => false, 'error' => $msg];
}

function uploadErrorMessage(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'ファイルサイズがサーバーの上限を超えています。';
        case UPLOAD_ERR_PARTIAL:
            return 'アップロードが中断されました。再試行してください。';
        case UPLOAD_ERR_NO_FILE:
            return 'ファイルが選択されていません。';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'サーバー側の一時保存に失敗しました。';
        default:
            return 'アップロードに失敗しました（コード ' . $code . '）。';
    }
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}
