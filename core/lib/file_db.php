<?php
declare(strict_types=1);

// ============================================================
// core/lib/file_db.php - ファイルベースのデータ操作クラス
// （旧 mailmag/lib/file_db.php と互換）
// ============================================================

final class FileDB
{
    // ---- 管理者設定 ----------------------------------------

    public static function getAdmin(): array
    {
        $path = DATA_DIR . '/admin.json';
        if (!is_file($path)) return [];
        $json = file_get_contents($path);
        return json_decode((string)$json, true) ?? [];
    }

    public static function saveAdmin(array $data): bool
    {
        return self::writeJson(DATA_DIR . '/admin.json', $data);
    }

    // ---- 購読者 CSV ----------------------------------------

    public static function getSubscribers(): array
    {
        $path = DATA_DIR . '/subscribers.csv';
        if (!is_file($path)) return [];

        $rows = [];
        $fp = fopen($path, 'r');
        if (!$fp) return [];
        flock($fp, LOCK_SH);

        $header = null;
        while (($line = fgetcsv($fp)) !== false) {
            if ($header === null) {
                $header = $line;
                continue;
            }
            if (count($line) === count($header)) {
                $rows[] = array_combine($header, $line);
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $rows;
    }

    public static function addSubscriber(array $sub): bool
    {
        $path = DATA_DIR . '/subscribers.csv';
        $fp = fopen($path, 'a');
        if (!$fp) return false;
        flock($fp, LOCK_EX);

        if (filesize($path) === 0) {
            fputcsv($fp, ['id','email','name','status','token','created_at','updated_at']);
        }
        fputcsv($fp, [
            $sub['id'],
            $sub['email'],
            $sub['name'] ?? '',
            $sub['status'] ?? '1',
            $sub['token'],
            $sub['created_at'],
            $sub['updated_at'],
        ]);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    public static function updateSubscriber(string $id, array $updates): bool
    {
        $subs  = self::getSubscribers();
        $found = false;
        foreach ($subs as &$sub) {
            if ($sub['id'] === $id) {
                foreach ($updates as $k => $v) {
                    $sub[$k] = $v;
                }
                $sub['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($sub);
        if (!$found) return false;
        return self::writeSubscribers($subs);
    }

    public static function deleteSubscriber(string $id): bool
    {
        $subs = self::getSubscribers();
        $subs = array_values(array_filter($subs, fn($s) => $s['id'] !== $id));
        return self::writeSubscribers($subs);
    }

    public static function findByEmail(string $email): ?array
    {
        $needle = strtolower($email);
        foreach (self::getSubscribers() as $sub) {
            if (strtolower($sub['email']) === $needle) return $sub;
        }
        return null;
    }

    public static function findByToken(string $token): ?array
    {
        if ($token === '') return null;
        foreach (self::getSubscribers() as $sub) {
            if ($sub['token'] === $token) return $sub;
        }
        return null;
    }

    public static function countByStatus(): array
    {
        $counts = ['active' => 0, 'stopped' => 0, 'unsubscribed' => 0, 'total' => 0];
        foreach (self::getSubscribers() as $sub) {
            $counts['total']++;
            if ($sub['status'] === '1')      $counts['active']++;
            elseif ($sub['status'] === '0')  $counts['stopped']++;
            elseif ($sub['status'] === '9')  $counts['unsubscribed']++;
        }
        return $counts;
    }

    private static function writeSubscribers(array $subs): bool
    {
        $path = DATA_DIR . '/subscribers.csv';
        $fp = fopen($path, 'w');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        fputcsv($fp, ['id','email','name','status','token','created_at','updated_at']);
        foreach ($subs as $sub) {
            fputcsv($fp, [
                $sub['id'], $sub['email'], $sub['name'],
                $sub['status'], $sub['token'],
                $sub['created_at'], $sub['updated_at'],
            ]);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    // ---- ダブルオプトイン保留 CSV --------------------------

    public static function getPending(): array
    {
        $path = PENDING_DIR . '/pending.csv';
        if (!is_file($path)) return [];
        $rows = [];
        $fp = fopen($path, 'r');
        if (!$fp) return [];
        flock($fp, LOCK_SH);
        $header = null;
        while (($line = fgetcsv($fp)) !== false) {
            if ($header === null) { $header = $line; continue; }
            if (count($line) === count($header)) {
                $rows[] = array_combine($header, $line);
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $rows;
    }

    public static function addPending(array $p): bool
    {
        if (!is_dir(PENDING_DIR)) @mkdir(PENDING_DIR, 0755, true);
        $path = PENDING_DIR . '/pending.csv';
        $fp = fopen($path, 'a');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        if (filesize($path) === 0) {
            fputcsv($fp, ['email','name','token','source','created_at']);
        }
        fputcsv($fp, [
            $p['email'], $p['name'] ?? '',
            $p['token'], $p['source'] ?? 'web',
            $p['created_at'],
        ]);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    public static function findPendingByToken(string $token): ?array
    {
        if ($token === '') return null;
        foreach (self::getPending() as $p) {
            if ($p['token'] === $token) return $p;
        }
        return null;
    }

    public static function deletePending(string $token): bool
    {
        $list = self::getPending();
        $list = array_values(array_filter($list, fn($p) => $p['token'] !== $token));
        $path = PENDING_DIR . '/pending.csv';
        $fp = fopen($path, 'w');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        fputcsv($fp, ['email','name','token','source','created_at']);
        foreach ($list as $p) {
            fputcsv($fp, [$p['email'], $p['name'], $p['token'], $p['source'], $p['created_at']]);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    // ---- テンプレート JSON ---------------------------------

    public static function getTemplates(): array
    {
        $path = DATA_DIR . '/templates.json';
        if (!is_file($path)) return [];
        return json_decode((string)file_get_contents($path), true) ?? [];
    }

    public static function saveTemplates(array $templates): bool
    {
        return self::writeJson(DATA_DIR . '/templates.json', $templates);
    }

    public static function getTemplate(string $id): ?array
    {
        foreach (self::getTemplates() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }

    // ---- 送信履歴 JSON ------------------------------------

    public static function getHistoryList(): array
    {
        if (!is_dir(HISTORY_DIR)) return [];
        $files = glob(HISTORY_DIR . '/*.json');
        if (!$files) return [];
        $list = [];
        foreach ($files as $f) {
            $data = json_decode((string)file_get_contents($f), true);
            if ($data) $list[] = $data;
        }
        usort($list, fn($a, $b) => strcmp((string)$b['sent_at'], (string)$a['sent_at']));
        return $list;
    }

    public static function getHistory(string $id): ?array
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        $path   = HISTORY_DIR . '/' . $safeId . '.json';
        if (!is_file($path)) return null;
        return json_decode((string)file_get_contents($path), true);
    }

    public static function addHistory(array $h): bool
    {
        if (!is_dir(HISTORY_DIR)) @mkdir(HISTORY_DIR, 0755, true);
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $h['id']);
        $path   = HISTORY_DIR . '/' . $safeId . '.json';
        return self::writeJson($path, $h);
    }

    public static function updateHistory(string $id, array $updates): bool
    {
        $h = self::getHistory($id);
        if (!$h) return false;
        foreach ($updates as $k => $v) $h[$k] = $v;
        return self::addHistory($h);
    }

    // ---- 送信キュー JSON ----------------------------------

    public static function getQueueList(): array
    {
        if (!is_dir(QUEUE_DIR)) return [];
        $files = glob(QUEUE_DIR . '/*.json');
        if (!$files) return [];
        $list = [];
        foreach ($files as $f) {
            $data = json_decode((string)file_get_contents($f), true);
            if ($data) $list[] = $data;
        }
        usort($list, fn($a, $b) => strcmp((string)$a['scheduled_at'], (string)$b['scheduled_at']));
        return $list;
    }

    public static function getQueue(string $id): ?array
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        $path   = QUEUE_DIR . '/' . $safeId . '.json';
        if (!is_file($path)) return null;
        return json_decode((string)file_get_contents($path), true);
    }

    public static function saveQueue(array $q): bool
    {
        if (!is_dir(QUEUE_DIR)) @mkdir(QUEUE_DIR, 0755, true);
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $q['id']);
        $path   = QUEUE_DIR . '/' . $safeId . '.json';
        return self::writeJson($path, $q);
    }

    public static function deleteQueue(string $id): bool
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        $path   = QUEUE_DIR . '/' . $safeId . '.json';
        if (is_file($path)) @unlink($path);
        return true;
    }

    // ---- 汎用ユーティリティ --------------------------------

    public static function generateId(): string
    {
        return date('YmdHis') . sprintf('%04d', mt_rand(0, 9999));
    }

    /**
     * @param mixed $data
     */
    private static function writeJson(string $path, $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fp = fopen($path, 'w');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        fwrite($fp, (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
}
