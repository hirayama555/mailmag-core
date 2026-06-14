<?php
declare(strict_types=1);

// ============================================================
// core/lib/auth.php - セッション認証
// （旧 mailmag/lib/auth.php からの移植。ロジックは互換維持）
// ============================================================

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    /**
     * HTTPS 接続かどうかを判定する。
     * 直接 TLS 終端 / リバースプロキシ配下 / 443 ポートのいずれにも対応。
     * isset($_SERVER['HTTPS']) だけだと (a) プロキシ配下で落ちる
     * (b) IIS の HTTPS='off' でも true になる、という二重の弱点があるため分離。
     * HTTP のみの環境では false を返すので secure Cookie 化でログイン不能にはならない。
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return false;
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['logged_in'])
            && !empty($_SESSION['last_activity'])
            && (time() - $_SESSION['last_activity']) < SESSION_LIFETIME;
    }

    /**
     * ログインチェック。未ログインならログインページへリダイレクト
     */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . SITE_URL . 'index.php?msg=session_expired');
            exit;
        }
        // 最終アクティビティ更新
        $_SESSION['last_activity'] = time();
    }

    public static function login(string $password, array $admin): bool
    {
        self::start();
        if (empty($admin['admin_password'])) return false;
        if (!password_verify($password, $admin['admin_password'])) return false;

        session_regenerate_id(true);
        $_SESSION['logged_in']     = true;
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }
}
