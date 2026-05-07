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
        // race condition 対策: 1つの LOCK_EX 内で read-modify-write を完結
        return self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            function (array $rows) use ($id, $updates) {
                $found = false;
                foreach ($rows as &$row) {
                    if ($row['id'] === $id) {
                        foreach ($updates as $k => $v) $row[$k] = $v;
                        $row['updated_at'] = date('Y-m-d H:i:s');
                        $found = true;
                        break;
                    }
                }
                unset($row);
                return $found ? $rows : false; // 該当無しは書き戻しせず中止
            }
        );
    }

    public static function deleteSubscriber(string $id): bool
    {
        return self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            fn(array $rows) => array_values(array_filter($rows, fn($r) => $r['id'] !== $id))
        );
    }

    /**
     * 原子的な「emailが未登録なら追加」。重複時は false を返す。
     * findByEmail → addSubscriber の二段操作だと race window があるため、
     * このメソッドを呼び出し側に使わせて重複登録を物理的に防止する。
     */
    public static function addSubscriberIfNew(array $sub): bool
    {
        $needle = strtolower($sub['email'] ?? '');
        if ($needle === '') return false;

        $added = false;
        $ok = self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            function (array $rows) use ($sub, $needle, &$added) {
                foreach ($rows as $r) {
                    if (strtolower($r['email']) === $needle) {
                        return false; // 既存 → 中止
                    }
                }
                $rows[] = [
                    'id'         => $sub['id'],
                    'email'      => $sub['email'],
                    'name'       => $sub['name'] ?? '',
                    'status'     => $sub['status'] ?? '1',
                    'token'      => $sub['token'],
                    'created_at' => $sub['created_at'],
                    'updated_at' => $sub['updated_at'] ?? $sub['created_at'],
                ];
                $added = true;
                return $rows;
            }
        );
        return $ok && $added;
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
        return self::modifyCsvAtomic(
            PENDING_DIR . '/pending.csv',
            ['email','name','token','source','created_at'],
            fn(array $rows) => array_values(array_filter($rows, fn($p) => $p['token'] !== $token))
        );
    }

    /**
     * 原子的な「emailが pending に未登録なら追加」。重複時は false を返す。
     * register.php で同時2連投しても pending が重複登録されないようにする。
     */
    public static function addPendingIfNew(array $p): bool
    {
        $needle = strtolower($p['email'] ?? '');
        if ($needle === '') return false;

        if (!is_dir(PENDING_DIR)) @mkdir(PENDING_DIR, 0755, true);

        $added = false;
        $ok = self::modifyCsvAtomic(
            PENDING_DIR . '/pending.csv',
            ['email','name','token','source','created_at'],
            function (array $rows) use ($p, $needle, &$added) {
                foreach ($rows as $r) {
                    if (strtolower($r['email']) === $needle) {
                        return false; // 既存 → 中止
                    }
                }
                $rows[] = [
                    'email'      => $p['email'],
                    'name'       => $p['name']   ?? '',
                    'token'      => $p['token'],
                    'source'     => $p['source'] ?? 'web',
                    'created_at' => $p['created_at'],
                ];
                $added = true;
                return $rows;
            }
        );
        return $ok && $added;
    }

    /**
     * 原子的な「token に該当する pending を1件取り出して削除」。
     * register_confirm の「同時クリックで2回処理される」を防ぐ。
     * 該当無しの場合は null を返し、その場合 CSV は変更されない。
     */
    public static function claimPendingToken(string $token): ?array
    {
        if ($token === '') return null;

        $claimed = null;
        $ok = self::modifyCsvAtomic(
            PENDING_DIR . '/pending.csv',
            ['email','name','token','source','created_at'],
            function (array $rows) use ($token, &$claimed) {
                $remaining = [];
                foreach ($rows as $r) {
                    if ($claimed === null && $r['token'] === $token) {
                        $claimed = $r;
                        continue; // この行は削除
                    }
                    $remaining[] = $r;
                }
                if ($claimed === null) {
                    return false; // 該当無し → 書き戻ししない
                }
                return $remaining;
            }
        );
        return ($ok && $claimed !== null) ? $claimed : null;
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
     * read-modify-write を 1 つの LOCK_EX 内で完結させる汎用ヘルパ。
     *
     * - 排他ロック取得 → 全行読み込み → $fn(rows) を呼び出し →
     *   返値が array なら header + rows を書き戻し、false なら何もしない。
     * - findByEmail/addSubscriber のような二段呼び出しで発生していた
     *   race condition（同時POSTで重複行が出来る等）を構造的に潰す。
     * - $fn は array|false を返すこと（false 時は CSV を一切変更しない）。
     *
     * @param array<int,string> $header
     * @param callable(array<int,array<string,mixed>>):(array|false) $fn
     */
    private static function modifyCsvAtomic(string $path, array $header, callable $fn): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // c+ : 存在しなければ作成、ポインタ先頭、既存内容は保持、読み書き可
        $fp = @fopen($path, 'c+');
        if (!$fp) return false;

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        try {
            // ---- 読み込み ----
            rewind($fp);
            $rows = [];
            $hdr  = null;
            while (($line = fgetcsv($fp)) !== false) {
                if ($hdr === null) { $hdr = $line; continue; }
                if (count($line) === count($hdr)) {
                    $rows[] = array_combine($hdr, $line);
                }
            }

            // ---- 変更 ----
            $newRows = $fn($rows);
            if ($newRows === false) {
                return false; // 中止 (CSV は変更しない)
            }

            // ---- 書き戻し ----
            rewind($fp);
            ftruncate($fp, 0);
            fputcsv($fp, $header);
            foreach ($newRows as $row) {
                $line = [];
                foreach ($header as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($fp, $line);
            }
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
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
