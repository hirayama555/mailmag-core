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

header('Content-Type: application/json; charset=UTF-8');

// 許可する画像 MIME と拡張子の対応（拡張子はこの表からのみ決定する）
const MEDIA_ALLOWED = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$action = (string)($_GET['action'] ?? '');

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

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => '不正なアクションです。']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'サーバーエラーが発生しました。']);
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
