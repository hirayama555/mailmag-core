<?php
declare(strict_types=1);

// ============================================================
// core/app/open.php - 開封トラッキング用エンドポイント（公開）
//
// HTMLメールに埋め込まれた 1×1 透明ピクセルが読み込まれると叩かれる。
//   GET q=<queueId> t=<購読者トークン>
// 認証は不要（受信側メールクライアントがアクセスするため）。
// トークン・キューIDが妥当なら開封を1件記録し、いかなる場合も
// 1×1 透明 GIF を no-cache で返す（有効性を漏らさない・画像は必ず出す）。
// ============================================================

$queueId = (string)($_GET['q'] ?? '');
$token   = (string)($_GET['t'] ?? '');

// キューIDは英数・_- のみ許可（パストラバーサル等の防御は FileDB 側でも実施）
$safeQueue = preg_replace('/[^a-zA-Z0-9_\-]/', '', $queueId);

// 実在するキャンペーン（履歴あり）かつ有効なトークンのときだけ記録する。
// 履歴チェックを挟むことで、任意の q 値で空ログを量産されるのを防ぐ。
if ($safeQueue !== '' && $token !== '' && FileDB::getHistory($safeQueue) !== null) {
    $sub = FileDB::findByToken($token);
    if ($sub !== null && isset($sub['id']) && $sub['id'] !== '') {
        FileDB::recordOpen($safeQueue, (string)$sub['id']);
    }
}

// ---- 1×1 透明 GIF を返す ----------------------------------
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

// 出力前に何も送られていないことを確認（万一の警告出力対策）
if (!headers_sent()) {
    header('Content-Type: image/gif');
    header('Content-Length: ' . strlen($gif));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
echo $gif;
