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

    /**
     * @deprecated findByEmail→addSubscriber の二段呼び出しには race window があるため使用しない。
     *             重複チェック込みの原子的登録は addSubscriberIfNew()（modifyCsvAtomic 経由）を使うこと。
     */
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

    /**
     * 複数購読者を「1回の原子的読み書き」で一括追加する。
     *
     * CSV インポートで 1 件ずつ addSubscriberIfNew を呼ぶと、件数分だけ
     * CSV 全体の read-modify-write（ロック→全行読込→全行書き戻し）が走り
     * O(N^2) でファイル I/O が膨張、件数が多いと 504 タイムアウトする。
     * 本メソッドは 1 回の LOCK_EX 内で「既存＋バッチ内の重複（email・大小無視）を
     * 弾きつつ新規行をまとめて追記」し、計算量を O(N+M) に抑える。
     *
     * @param array<int,array<string,mixed>> $subs 各要素は
     *        id/email/name/status/token/created_at/updated_at を含むこと。
     * @return int 実際に追加できた件数
     */
    public static function addSubscribersBulk(array $subs): int
    {
        if (empty($subs)) return 0;

        $added = 0;
        self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            function (array $rows) use ($subs, &$added) {
                // 既存 email を集合化（小文字キー）→ 重複判定を O(1) に
                $seen = [];
                foreach ($rows as $r) {
                    $seen[strtolower($r['email'] ?? '')] = true;
                }
                foreach ($subs as $sub) {
                    $email = trim((string)($sub['email'] ?? ''));
                    $key   = strtolower($email);
                    if ($key === '' || isset($seen[$key])) continue; // 空 or 既存/バッチ内重複
                    $seen[$key] = true;
                    $rows[] = [
                        'id'         => $sub['id'],
                        'email'      => $email,
                        'name'       => $sub['name'] ?? '',
                        'status'     => $sub['status'] ?? '1',
                        'token'      => $sub['token'],
                        'created_at' => $sub['created_at'],
                        'updated_at' => $sub['updated_at'] ?? $sub['created_at'],
                    ];
                    $added++;
                }
                return $added > 0 ? $rows : false; // 1件も追加なしなら書き戻さない
            }
        );
        return $added;
    }

    public static function findByEmail(string $email): ?array
    {
        $needle = strtolower($email);
        foreach (self::getSubscribers() as $sub) {
            if (strtolower($sub['email']) === $needle) return $sub;
        }
        return null;
    }

    /**
     * ハードバウンスした購読者を原子的にエラー停止（status=0）にする。
     * email 一致で「現在 status=1（有効）」の行のみ 0 に変更する。
     * @return string|null 停止した購読者ID。該当なし/既に非有効なら null。
     */
    public static function markBounced(string $email): ?string
    {
        $needle = strtolower(trim($email));
        if ($needle === '') return null;

        $bouncedId = null;
        self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            function (array $rows) use ($needle, &$bouncedId) {
                $changed = false;
                foreach ($rows as &$row) {
                    if (strtolower($row['email']) === $needle && $row['status'] === '1') {
                        $row['status']     = '0';
                        $row['updated_at'] = date('Y-m-d H:i:s');
                        $bouncedId = $row['id'];
                        $changed = true;
                        break;
                    }
                }
                unset($row);
                return $changed ? $rows : false; // 変更なしは書き戻さない
            }
        );
        return $bouncedId;
    }

    /**
     * email 群を「1回の原子的読み書き」で一括エラー停止（status 1→0）にする。
     *
     * NowGetter 等の外部システムが既に把握している不達アドレス一覧を、
     * 次の大量配信の前にまとめて停止する用途。1件ずつ markBounced を呼ぶと
     * CSV 全体の read-modify-write が件数分走り O(N*M) になるため、
     * addSubscribersBulk と同様に 1 回の LOCK_EX 内で O(N+M) に抑える。
     *
     * @param array<int,string> $emails 停止対象メールアドレス（大小・前後空白は無視）
     * @return array{suppressed:int,skipped:int}
     *         suppressed = 実際に 1→0 にした件数 /
     *         skipped    = 入力のうち未登録・既に非有効でスキップした件数
     */
    public static function suppressEmailsBulk(array $emails): array
    {
        // 入力を正規化（小文字・トリム・空除去・重複排除）して集合化
        $targets = [];
        foreach ($emails as $e) {
            $key = strtolower(trim((string)$e));
            if ($key !== '') $targets[$key] = true;
        }
        if (empty($targets)) return ['suppressed' => 0, 'skipped' => 0];

        $suppressed = 0;
        self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            function (array $rows) use ($targets, &$suppressed) {
                foreach ($rows as &$row) {
                    $key = strtolower($row['email'] ?? '');
                    if (isset($targets[$key]) && ($row['status'] ?? '') === '1') {
                        $row['status']     = '0';
                        $row['updated_at'] = date('Y-m-d H:i:s');
                        $suppressed++;
                    }
                }
                unset($row);
                return $suppressed > 0 ? $rows : false; // 1件も変更なしなら書き戻さない
            }
        );
        return [
            'suppressed' => $suppressed,
            'skipped'    => count($targets) - $suppressed,
        ];
    }

    /**
     * 指定ドメイン群に一致する購読者を「1回の原子的読み書き」で物理削除する。
     *
     * Yahoo 系（EXCLUDE_DOMAINS）のように恒久的に別経路へ回すアドレスを
     * 配信リストから取り除く用途。modifyCsvAtomic が削除前に .bak へ退避するため
     * 誤操作時は直前状態へ復旧できる。
     *
     * @param array<int,string> $domains 削除対象ドメイン（@ 以降, 小文字想定）
     * @return int 削除した件数
     */
    public static function deleteByDomains(array $domains): int
    {
        // ドメインを正規化して集合化（O(1) 判定）
        $set = [];
        foreach ($domains as $d) {
            $key = strtolower(trim((string)$d));
            if ($key !== '') $set[$key] = true;
        }
        if (empty($set)) return 0;

        $deleted = 0;
        self::modifyCsvAtomic(
            DATA_DIR . '/subscribers.csv',
            ['id','email','name','status','token','created_at','updated_at'],
            function (array $rows) use ($set, &$deleted) {
                $kept = [];
                foreach ($rows as $row) {
                    if (isset($set[mailmag_email_domain($row['email'] ?? '')])) {
                        $deleted++;
                        continue; // この行は削除
                    }
                    $kept[] = $row;
                }
                return $deleted > 0 ? $kept : false; // 該当なしは書き戻さない
            }
        );
        return $deleted;
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
            if ($data) { $list[] = $data; }
            else { self::logCorruptJson($f); }
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

    // ---- 開封トラッキング（追記専用ログ）------------------

    /**
     * 開封を1件記録する。data/opens/<queueId>.log に
     * 「日時<TAB>subId」を追記する（FILE_APPEND|LOCK_EX で同時書き込み安全）。
     * 集計は getOpenStats() が行うため、ここでは重複排除しない（延べを保持）。
     */
    public static function recordOpen(string $queueId, string $subId): void
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $queueId);
        if ($safeId === '' || $subId === '') return;

        if (!is_dir(OPENS_DIR)) @mkdir(OPENS_DIR, 0755, true);

        // subId 内のタブ・改行は記録を壊すので除去（subId は通常 UUID/英数）
        $cleanSub = str_replace(["\t", "\r", "\n"], '', $subId);
        $line = date('Y-m-d H:i:s') . "\t" . $cleanSub . PHP_EOL;
        @file_put_contents(OPENS_DIR . '/' . $safeId . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 指定キューの開封統計を返す。
     * @return array{unique:int, total:int}
     *   unique = 開封したユニーク購読者数 / total = 延べ開封回数
     */
    public static function getOpenStats(string $queueId): array
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $queueId);
        $path   = OPENS_DIR . '/' . $safeId . '.log';
        if ($safeId === '' || !is_file($path)) {
            return ['unique' => 0, 'total' => 0];
        }

        $total   = 0;
        $seen    = [];
        $fp = @fopen($path, 'r');
        if (!$fp) return ['unique' => 0, 'total' => 0];
        flock($fp, LOCK_SH);
        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $total++;
            $parts = explode("\t", $line);
            $subId = $parts[1] ?? '';
            if ($subId !== '') $seen[$subId] = true;
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['unique' => count($seen), 'total' => $total];
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
            if ($data) { $list[] = $data; }
            else { self::logCorruptJson($f); }
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
            // ftruncate→書き直しは「書き込み途中でプロセスが落ちると
            // ファイルが空のまま残る（＝全データ消失）」非クラッシュ安全な操作。
            // ロック方式（LOCK_EX on $fp）は据え置きで直列性を保ったまま、
            // 書き込み直前に現在の内容を .bak へ退避しておく。万一書き込み途中で
            // プロセスが落ちても、.bak から直前状態を復旧できる。
            if (filesize($path) > 0) {
                @copy($path, $path . '.bak');
            }
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
    /**
     * 破損 JSON（json_decode 失敗かつ中身が空でない）を error.log に記録する。
     * 一覧系メソッドは破損ファイルを黙ってスキップするため、pending キューが
     * 破損して未送信のまま放置される事故を可観測にする（無通知化の防止）。
     */
    private static function logCorruptJson(string $path): void
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim((string)$raw) === '') return; // 空ファイルは破損扱いしない
        @file_put_contents(
            DATA_DIR . '/error.log',
            '[' . date('Y-m-d H:i:s') . '] CORRUPT JSON skipped: ' . $path . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private static function writeJson(string $path, $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // tmp ファイルへ全量書き込み → rename で差し替え。
        // fopen('w') は flock 取得前にファイルを 0 バイト切り詰めるため、
        // 書き込み中のクラッシュ/kill で JSON が空になる事故を避ける。
        // 同一 FS 上の rename は原子的（POSIX）なので、読み手は常に
        // 旧内容か新内容のどちらか完全な状態のみを見る。
        // json_encode が false を返す（不正UTF-8等）場合に (string) で '' を書くと
        // 0バイトの壊れた JSON を rename してしまうため、ここで中断する。
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $tmp  = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

        $fp = @fopen($tmp, 'wb');
        if (!$fp) return false;
        if (fwrite($fp, $json) === false) {
            fclose($fp);
            @unlink($tmp);
            return false;
        }
        fflush($fp);
        fclose($fp);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
