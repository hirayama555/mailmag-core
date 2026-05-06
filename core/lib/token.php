<?php
declare(strict_types=1);

// ============================================================
// core/lib/token.php - トークン生成・検証
// ============================================================

final class Token
{
    /**
     * 購読解除・オプトイン確認用ユニークトークン生成（40文字16進）
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * CSRFトークン生成（セッションに保存。なければ新規）
     */
    public static function getCsrf(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * CSRFトークン検証（タイミング攻撃耐性のため hash_equals）
     */
    public static function verifyCsrf(string $token): bool
    {
        if (empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function unsubscribeUrl(string $token): string
    {
        return SITE_URL . 'unsubscribe.php?token=' . urlencode($token);
    }

    public static function confirmUrl(string $token): string
    {
        return SITE_URL . 'register_confirm.php?token=' . urlencode($token);
    }
}
