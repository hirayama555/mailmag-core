<?php
declare(strict_types=1);

// ============================================================
// core/lib/rate_limit.php
// 簡易レート制限（スライディングウィンドウ）
//   - data/rate_limit/<sha1(key)>.json にヒット時刻配列を保存
//   - 主に register_mail.php の踏み台抑止に使用
//   - storage 障害時は fail-open（true を返す＝許可）。これは
//     登録機能が完全停止するより限定的な濫用を許す方がマシという判断
// ============================================================

final class RateLimit
{
    /**
     * 指定キーの直近 windowSec 秒以内のヒット数が maxPerWindow 未満なら true を返してヒットを記録。
     * 既に上限に達していれば false を返し、何も記録しない。
     */
    public static function allow(string $key, int $maxPerWindow, int $windowSec): bool
    {
        if (!is_dir(RATELIMIT_DIR)) {
            if (!@mkdir(RATELIMIT_DIR, 0755, true) && !is_dir(RATELIMIT_DIR)) {
                return true; // fail-open
            }
        }
        $path = RATELIMIT_DIR . '/' . sha1($key) . '.json';

        $fp = @fopen($path, 'c+');
        if (!$fp) return true; // fail-open
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        $raw  = stream_get_contents($fp);
        $data = json_decode((string)$raw, true);
        $hits = is_array($data['hits'] ?? null) ? $data['hits'] : [];

        $now    = time();
        $cutoff = $now - $windowSec;
        $hits   = array_values(array_filter($hits, fn($t) => is_numeric($t) && (int)$t >= $cutoff));

        if (count($hits) >= $maxPerWindow) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        $hits[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(['hits' => $hits]));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
}
