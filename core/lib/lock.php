<?php
declare(strict_types=1);

// ============================================================
// core/lib/lock.php
// flock ベースの非ブロッキングプロセス排他ロック
//   - 主に cron スクリプトの重複起動を防ぐ
//   - LOCK_EX | LOCK_NB により取得失敗時は即時 false を返す
//   - スクリプト終了時にカーネルが自動解放するため leak しにくい
// ============================================================

final class Lock
{
    /** @var array<string, resource> 取得中のロックを保持（GCで閉じないように） */
    private static array $held = [];

    /**
     * 排他ロックを取得。取得できなければ false。
     */
    public static function acquire(string $name): bool
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $name);
        if ($safe === '') return false;

        if (!is_dir(LOCK_DIR)) {
            @mkdir(LOCK_DIR, 0755, true);
        }
        $path = LOCK_DIR . '/' . $safe . '.lock';

        $fp = @fopen($path, 'c');
        if (!$fp) return false;

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        // PID を書いておくとデバッグに便利
        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid() . "\n");
        fflush($fp);

        self::$held[$safe] = $fp;
        return true;
    }

    /**
     * 明示的に解放（通常はスクリプト終了で自動解放されるため不要）
     */
    public static function release(string $name): void
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $name);
        if (!isset(self::$held[$safe])) return;
        $fp = self::$held[$safe];
        flock($fp, LOCK_UN);
        fclose($fp);
        unset(self::$held[$safe]);
    }
}
