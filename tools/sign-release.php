<?php
declare(strict_types=1);

// ============================================================
// tools/sign-release.php
//
// 開発者ローカルで使う Ed25519 鍵管理 + 署名 + 検証ツール。
// 配布物（core.zip）には含まれない。リポジトリ側の運用補助用。
//
// 使い方:
//   php tools/sign-release.php keygen          鍵ペア生成（初回のみ）
//   php tools/sign-release.php pubkey          公開鍵 (hex) を表示
//                                              → core/lib/updater.php に貼る
//   php tools/sign-release.php sign <zipfile>  <zipfile>.sig を生成
//   php tools/sign-release.php verify <zipfile> 署名を検証（sanity check）
//
// 秘密鍵は ~/.mailmag/release-priv.bin にバイナリで保管（mode 0600）。
// 紛失すると以降のリリースに署名できなくなるのでバックアップ推奨。
// ============================================================

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "PHP sodium 拡張が必要です（PHP 7.2+ 標準搭載）。\n");
    exit(1);
}

// ---- 鍵ファイル位置 ---------------------------------------
$home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
$keyDir  = rtrim(str_replace('\\', '/', $home), '/') . '/.mailmag';
$privKey = $keyDir . '/release-priv.bin';

// ---- サブコマンド -----------------------------------------
$cmd  = $argv[1] ?? 'help';
$arg2 = $argv[2] ?? '';

switch ($cmd) {
    case 'keygen':
        if (is_file($privKey)) {
            fwrite(STDERR, "ERROR: 秘密鍵が既に存在します: {$privKey}\n");
            fwrite(STDERR, "上書きすると過去リリースの署名が再現できなくなるので拒否します。\n");
            exit(1);
        }
        if (!is_dir($keyDir)) mkdir($keyDir, 0700, true);
        $kp   = sodium_crypto_sign_keypair();
        $priv = sodium_crypto_sign_secretkey($kp);
        $pub  = sodium_crypto_sign_publickey($kp);
        if (file_put_contents($privKey, $priv) === false) {
            fwrite(STDERR, "ERROR: 秘密鍵を書き込めませんでした: {$privKey}\n");
            exit(1);
        }
        @chmod($privKey, 0600);
        echo "鍵ペアを生成しました。\n";
        echo "  秘密鍵: {$privKey}  (mode 0600 推奨。バックアップして安全に保管してください)\n";
        echo "  公開鍵 (hex):\n    " . bin2hex($pub) . "\n";
        echo "\n次の手順:\n";
        echo "  1. 上の公開鍵 hex を core/lib/updater.php の PUBKEY_HEX 定数に貼り付ける\n";
        echo "  2. git commit して push（公開鍵は公開して問題ありません）\n";
        break;

    case 'pubkey':
        if (!is_file($privKey)) {
            fwrite(STDERR, "ERROR: 秘密鍵がありません。先に `keygen` を実行してください。\n");
            exit(1);
        }
        $priv = (string)file_get_contents($privKey);
        $pub  = sodium_crypto_sign_publickey_from_secretkey($priv);
        echo bin2hex($pub) . "\n";
        break;

    case 'sign':
        if ($arg2 === '' || !is_file($arg2)) {
            fwrite(STDERR, "Usage: sign <zipfile>\n");
            exit(1);
        }
        if (!is_file($privKey)) {
            fwrite(STDERR, "ERROR: 秘密鍵がありません。先に `keygen` を実行してください。\n");
            exit(1);
        }
        $priv = (string)file_get_contents($privKey);
        $data = (string)file_get_contents($arg2);
        // detached signature: 64 bytes
        $sig  = sodium_crypto_sign_detached($data, $priv);
        $sigPath = $arg2 . '.sig';
        file_put_contents($sigPath, $sig);
        echo "署名を生成しました: {$sigPath}\n";
        echo "  対象: {$arg2} (" . strlen($data) . " bytes)\n";
        echo "  署名: " . strlen($sig) . " bytes (Ed25519)\n";
        break;

    case 'verify':
        if ($arg2 === '' || !is_file($arg2)) {
            fwrite(STDERR, "Usage: verify <zipfile>\n");
            exit(1);
        }
        $sigPath = $arg2 . '.sig';
        if (!is_file($sigPath)) {
            fwrite(STDERR, "ERROR: 署名ファイルがありません: {$sigPath}\n");
            exit(1);
        }
        if (!is_file($privKey)) {
            fwrite(STDERR, "ERROR: 秘密鍵がありません（公開鍵導出のため必要）。\n");
            exit(1);
        }
        $priv = (string)file_get_contents($privKey);
        $pub  = sodium_crypto_sign_publickey_from_secretkey($priv);
        $data = (string)file_get_contents($arg2);
        $sig  = (string)file_get_contents($sigPath);
        if (sodium_crypto_sign_verify_detached($sig, $data, $pub)) {
            echo "OK: 署名は有効です\n";
        } else {
            fwrite(STDERR, "FAIL: 署名検証に失敗\n");
            exit(2);
        }
        break;

    case 'help':
    default:
        echo "Usage:\n";
        echo "  php tools/sign-release.php keygen           鍵ペア生成（初回のみ）\n";
        echo "  php tools/sign-release.php pubkey           公開鍵 (hex) 表示\n";
        echo "  php tools/sign-release.php sign <zipfile>   <zipfile>.sig を生成\n";
        echo "  php tools/sign-release.php verify <zipfile> 署名検証\n";
        echo "\n秘密鍵保管先: {$privKey}\n";
        break;
}
