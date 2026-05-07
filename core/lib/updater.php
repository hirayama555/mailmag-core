<?php
declare(strict_types=1);

// ============================================================
// core/lib/updater.php
//
// GitHub Releases から core.zip を取得し、Ed25519 署名を検証してから
// 原子的に core/ を差し替える。クライアントは何も意識しない。
//
// 設計の前提:
//   - REPO 定数 = "<owner>/<repo>"（リポジトリ作成後に書き換える）
//   - PUBKEY_HEX 定数 = リリース署名に使う公開鍵（hex）。
//     tools/sign-release.php keygen で生成し、その出力を貼り付ける。
//     空のままだと検証は必ず失敗する（安全側のデフォルト）。
//   - 各リリースは2つのアセットを含む:
//       core.zip      … 新コアをトップレベルに格納した zip
//                       （bootstrap.php / lib/ / app/ / includes/ / VERSION）
//       core.zip.sig  … core.zip に対する Ed25519 detached signature
//   - GitHub リポジトリが private の期間中は data/.update_token に
//     読み取り専用 PAT を置く運用。public 化後はファイルが無くて構わない。
// ============================================================

final class Updater
{
    /** 公開鍵 (hex)。tools/sign-release.php keygen の出力を貼る。 */
    private const PUBKEY_HEX = 'd9444703043fb67e83c437b474e57cc5609dd336e86d212a01640c3897ca8f1b';

    /** GitHub リポジトリ "<owner>/<repo>"。リリース作成前に書き換える。 */
    private const REPO = 'hirayama555/mailmag-core';

    private const ASSET_ZIP = 'core.zip';
    private const ASSET_SIG = 'core.zip.sig';

    private const HTTP_TIMEOUT = 30;

    /**
     * 1日1回呼ばれる想定のエントリポイント。
     * @return array{status:string, message:string, version?:string}
     */
    public static function checkAndApply(): array
    {
        try {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return self::log('error', 'sodium 拡張が無効');
            }
            if (self::PUBKEY_HEX === '' || self::REPO === 'OWNER/REPO') {
                return self::log('error', 'Updater 未設定 (PUBKEY_HEX / REPO)');
            }

            $current = self::currentVersion();
            $release = self::fetchLatestRelease();
            if (!$release) {
                return self::log('error', 'GitHub API 取得失敗');
            }

            $latest = ltrim((string)($release['tag_name'] ?? ''), 'v');
            if ($latest === '') {
                return self::log('error', 'tag_name が空');
            }
            if (!self::isNewer($latest, $current)) {
                return self::log('noop', "最新版です ({$current})");
            }

            // アセット URL を発掘
            [$zipUrl, $zipApiUrl] = self::pickAsset($release, self::ASSET_ZIP);
            [$sigUrl, $sigApiUrl] = self::pickAsset($release, self::ASSET_SIG);
            if (!$zipUrl || !$sigUrl) {
                return self::log('error', 'リリースアセットが揃っていません');
            }

            $token = self::readToken();
            // private リポジトリ期間中は API URL + Bearer + Accept:octet-stream
            // public 化後はトークン無しで browser_download_url が通る
            $zipData = $token
                ? self::downloadAuth($zipApiUrl, $token)
                : self::download($zipUrl);
            $sigData = $token
                ? self::downloadAuth($sigApiUrl, $token)
                : self::download($sigUrl);

            if ($zipData === null || $sigData === null) {
                return self::log('error', 'アセットのダウンロード失敗');
            }

            // ---- 署名検証（最重要）----
            $pub = hex2bin(self::PUBKEY_HEX);
            if ($pub === false || strlen($pub) !== 32) {
                return self::log('error', '公開鍵フォーマット不正');
            }
            if (strlen($sigData) !== 64) {
                return self::log('error', '署名サイズ不正 (' . strlen($sigData) . ')');
            }
            if (!sodium_crypto_sign_verify_detached($sigData, $zipData, $pub)) {
                return self::log('error', '署名検証失敗 — zip を破棄');
            }

            // ---- 展開＆原子的差し替え ----
            self::applyZip($zipData);

            return self::log('updated', "更新成功: {$current} → {$latest}", $latest);
        } catch (Throwable $e) {
            return self::log('error', 'Exception: ' . $e->getMessage());
        }
    }

    // ---- 内部ヘルパ -------------------------------------------

    private static function currentVersion(): string
    {
        $path = CORE_DIR . '/VERSION';
        return is_file($path) ? trim((string)file_get_contents($path)) : '0.0.0';
    }

    /** "1.2.10" > "1.2.9" を正しく判定する単純なセマンティックバージョン比較 */
    private static function isNewer(string $a, string $b): bool
    {
        return version_compare($a, $b, '>');
    }

    private static function readToken(): string
    {
        $path = DATA_DIR . '/.update_token';
        if (!is_file($path)) return '';
        return trim((string)file_get_contents($path));
    }

    /** @return array{0: ?string, 1: ?string}  [browser_url, api_url] */
    private static function pickAsset(array $release, string $name): array
    {
        foreach ($release['assets'] ?? [] as $a) {
            if (($a['name'] ?? '') === $name) {
                return [$a['browser_download_url'] ?? null, $a['url'] ?? null];
            }
        }
        return [null, null];
    }

    private static function fetchLatestRelease(): ?array
    {
        $url = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
        $headers = [
            'User-Agent: mailmag-updater',
            'Accept: application/vnd.github+json',
        ];
        $token = self::readToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $body = self::httpGet($url, $headers);
        if ($body === null) return null;
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    private static function download(string $url): ?string
    {
        return self::httpGet($url, ['User-Agent: mailmag-updater']);
    }

    private static function downloadAuth(string $apiUrl, string $token): ?string
    {
        return self::httpGet($apiUrl, [
            'User-Agent: mailmag-updater',
            'Authorization: Bearer ' . $token,
            'Accept: application/octet-stream',
        ]);
    }

    /** allow_url_fopen ベースの単純 GET。失敗時 null。 */
    private static function httpGet(string $url, array $headers): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers),
                'timeout' => self::HTTP_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
            ],
            'https' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers),
                'timeout' => self::HTTP_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return null;
        // ステータスコード確認
        if (isset($http_response_header[0])
            && !preg_match('#HTTP/\S+\s+2\d\d#', (string)$http_response_header[0])
        ) {
            return null;
        }
        return $body;
    }

    /**
     * core.zip を一時ディレクトリに展開し、現 core/ と原子的に差し替える。
     * 失敗時は何もしない（既存 core/ は無傷）。
     */
    private static function applyZip(string $zipData): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive 拡張が必要です');
        }

        $tmpRoot = DATA_DIR . '/.update_tmp';
        // クリーンな tmp を再作成
        if (is_dir($tmpRoot)) self::rrmdir($tmpRoot);
        if (!@mkdir($tmpRoot, 0755, true)) {
            throw new RuntimeException('tmp ディレクトリ作成失敗: ' . $tmpRoot);
        }

        $zipPath  = $tmpRoot . '/core.zip';
        $stageDir = $tmpRoot . '/core_new';

        if (file_put_contents($zipPath, $zipData) === false) {
            throw new RuntimeException('zip 書き込み失敗');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('zip 展開失敗');
        }
        if (!@mkdir($stageDir, 0755, true)) {
            $zip->close();
            throw new RuntimeException('stage ディレクトリ作成失敗');
        }
        $zip->extractTo($stageDir);
        $zip->close();

        // 展開後 stageDir/bootstrap.php が存在することを最低限確認
        if (!is_file($stageDir . '/bootstrap.php')) {
            throw new RuntimeException('展開結果に bootstrap.php が無い（zip 構造異常）');
        }

        // ---- 原子的差し替え ----
        $coreDir   = CORE_DIR;
        $oldDir    = $coreDir . '.old.' . date('YmdHis');

        // 同一ファイルシステム上の rename は原子的（POSIX 仕様）
        if (!@rename($coreDir, $oldDir)) {
            throw new RuntimeException('既存 core/ のリネーム失敗');
        }
        if (!@rename($stageDir, $coreDir)) {
            // ロールバック試行
            @rename($oldDir, $coreDir);
            throw new RuntimeException('新 core/ への切り替え失敗（ロールバック実施）');
        }

        // 旧コアを削除（失敗しても致命ではない。次回 cron で掃除されるよう .old 名で残す）
        self::rrmdir($oldDir);
        self::rrmdir($tmpRoot);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 結果をログに追記しつつ構造化結果を返す。
     * @return array{status:string, message:string, version?:string}
     */
    private static function log(string $status, string $message, ?string $version = null): array
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$status}: {$message}" . PHP_EOL;
        @file_put_contents(DATA_DIR . '/update.log', $line, FILE_APPEND | LOCK_EX);
        $r = ['status' => $status, 'message' => $message];
        if ($version !== null) $r['version'] = $version;
        return $r;
    }
}
