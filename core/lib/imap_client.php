<?php
declare(strict_types=1);

// ============================================================
// core/lib/imap_client.php - 最小 IMAP クライアント（v1.5.0 新規）
//
// 目的:
//   バウンス受信専用メールボックスから未読メッセージを取得し、
//   処理後に削除する。IMAP ポーリング方式によりメールパイプが
//   使えない共用レンタルサーバーでもバウンス処理を実現する。
//
// 設計方針:
//   - php-imap 拡張（imap_open 等）は使わない。共用ホスティングでの
//     可用性が不定なため、SmtpClient と同様に raw socket で実装する。
//   - 実装する IMAP コマンドは必要最小限
//     （LOGIN / SELECT / SEARCH / FETCH / STORE / EXPUNGE / LOGOUT）。
//   - FETCH レスポンスの literal "{n}" を正確に読むため専用メソッドで処理。
//   - PHP 7.4 互換: match / nullsafe / 名前付き引数を使わない。
// ============================================================

final class ImapException extends RuntimeException {}

final class ImapClient
{
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $secure;  // 'ssl'（暗黙SSL=993） | 'tls'（STARTTLS=143） | ''（平文）
    private int    $timeout;

    /** @var resource|null */
    private $conn   = null;
    private int $tagNum = 1;

    public function __construct(
        string $host,
        int    $port,
        string $user,
        string $pass,
        string $secure  = 'ssl',
        int    $timeout = 30
    ) {
        $this->host    = $host;
        $this->port    = $port;
        $this->user    = $user;
        $this->pass    = $pass;
        $this->secure  = in_array($secure, ['ssl', 'tls', ''], true) ? $secure : 'ssl';
        $this->timeout = $timeout;
    }

    /**
     * 接続・認証。
     * @throws ImapException
     */
    public function connect(): void
    {
        $transport = ($this->secure === 'ssl') ? 'ssl://' : 'tcp://';
        $remote    = $transport . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $conn   = @stream_socket_client(
            $remote, $errno, $errstr,
            (float)$this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($conn === false) {
            throw new ImapException("IMAP接続に失敗しました: {$errstr} ({$errno})");
        }

        $this->conn = $conn;
        stream_set_timeout($this->conn, $this->timeout);

        // サーバーグリーティング: * OK ... または * PREAUTH ...
        $greeting = $this->readLine();
        if (strncmp($greeting, '* OK', 4) !== 0 && strncmp($greeting, '* PREAUTH', 9) !== 0) {
            $this->close();
            throw new ImapException('IMAP グリーティングが不正です: ' . trim($greeting));
        }

        // STARTTLS（ポート143 / tls モード）
        if ($this->secure === 'tls') {
            $tag  = $this->nextTag();
            $this->sendRaw("{$tag} STARTTLS\r\n");
            $resp = $this->readUntilTagged($tag);
            if ($resp['status'] !== 'OK') {
                $this->close();
                throw new ImapException('STARTTLS に失敗しました: ' . $resp['message']);
            }
            $ok = @stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                $this->close();
                throw new ImapException('IMAP TLS ハンドシェイクに失敗しました');
            }
        }

        // LOGIN（特殊文字を含む認証情報はクォート文字列で送る）
        $tag  = $this->nextTag();
        $this->sendRaw("{$tag} LOGIN " . $this->quoteString($this->user) . ' ' . $this->quoteString($this->pass) . "\r\n");
        $resp = $this->readUntilTagged($tag);
        if ($resp['status'] !== 'OK') {
            $this->close();
            throw new ImapException('IMAP ログインに失敗しました: ' . $resp['message']);
        }
    }

    /**
     * 未読メッセージを最大 $limit 件取得する。
     * @return array<int,array{seq:int,raw:string}>
     * @throws ImapException
     */
    public function fetchUnseen(int $limit = 50): array
    {
        // SELECT INBOX
        $tag  = $this->nextTag();
        $this->sendRaw("{$tag} SELECT INBOX\r\n");
        $resp = $this->readUntilTagged($tag);
        if ($resp['status'] !== 'OK') {
            throw new ImapException('INBOX の選択に失敗しました: ' . $resp['message']);
        }

        // SEARCH UNSEEN → "* SEARCH 1 3 5 ..."
        $tag  = $this->nextTag();
        $this->sendRaw("{$tag} SEARCH UNSEEN\r\n");
        $resp = $this->readUntilTagged($tag);
        if ($resp['status'] !== 'OK') {
            throw new ImapException('SEARCH UNSEEN に失敗しました: ' . $resp['message']);
        }

        $seqNums = [];
        foreach ($resp['lines'] as $line) {
            if (strncmp($line, '* SEARCH', 8) === 0) {
                $parts = preg_split('/\s+/', trim($line));
                // $parts[0]='*', $parts[1]='SEARCH', $parts[2..]= seq numbers
                foreach (array_slice($parts, 2) as $p) {
                    if (ctype_digit($p) && $p !== '0') {
                        $seqNums[] = (int)$p;
                    }
                }
                break;
            }
        }

        $seqNums  = array_slice($seqNums, 0, $limit);
        $messages = [];

        foreach ($seqNums as $seq) {
            $raw = $this->fetchRaw($seq);
            if ($raw !== '') {
                $messages[] = ['seq' => $seq, 'raw' => $raw];
            }
        }

        return $messages;
    }

    /**
     * 指定シーケンス番号のメッセージを削除フラグを立て EXPUNGE する。
     * @param int[] $seqNums
     * @throws ImapException
     */
    public function deleteAndExpunge(array $seqNums): void
    {
        if (empty($seqNums)) {
            return;
        }

        foreach ($seqNums as $seq) {
            $tag = $this->nextTag();
            $this->sendRaw("{$tag} STORE {$seq} +FLAGS.SILENT (\\Deleted)\r\n");
            $this->readUntilTagged($tag);  // エラーは無視して続行
        }

        $tag = $this->nextTag();
        $this->sendRaw("{$tag} EXPUNGE\r\n");
        $this->readUntilTagged($tag);
    }

    /**
     * 接続を閉じる（LOGOUT）。例外は投げない。
     */
    public function close(): void
    {
        if (is_resource($this->conn)) {
            $tag = $this->nextTag();
            @fwrite($this->conn, "{$tag} LOGOUT\r\n");
            @fclose($this->conn);
        }
        $this->conn = null;
    }

    // ---- 内部ヘルパ ------------------------------------------

    private function nextTag(): string
    {
        return 'A' . $this->tagNum++;
    }

    private function sendRaw(string $data): void
    {
        if (!is_resource($this->conn)) {
            throw new ImapException('IMAP未接続でデータを送信しようとしました');
        }
        if (@fwrite($this->conn, $data) === false) {
            throw new ImapException('IMAPソケットへの書き込みに失敗しました');
        }
    }

    private function readLine(): string
    {
        if (!is_resource($this->conn)) {
            return '';
        }
        $line = @fgets($this->conn, 65536);
        if ($line === false) {
            $meta = stream_get_meta_data($this->conn);
            if (!empty($meta['timed_out'])) {
                throw new ImapException('IMAP応答がタイムアウトしました');
            }
            return '';
        }
        return $line;
    }

    private function readLiteral(int $size): string
    {
        if ($size <= 0) {
            return '';
        }
        $data      = '';
        $remaining = $size;
        while ($remaining > 0 && is_resource($this->conn)) {
            $chunk = @fread($this->conn, min($remaining, 8192));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data      .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    /**
     * タグ付き応答が来るまで読み込む。literal {n} も処理する。
     * @return array{lines:string[],status:string,message:string}
     */
    private function readUntilTagged(string $tag): array
    {
        $lines   = [];
        $status  = '';
        $message = '';
        $prefix  = $tag . ' ';
        $pLen    = strlen($prefix);

        while (true) {
            $line = $this->readLine();
            if ($line === '') {
                throw new ImapException('IMAP接続が予期せず切断されました');
            }

            // タグ付き応答: "A1 OK ..." / "A1 NO ..." / "A1 BAD ..."
            if (strncmp($line, $prefix, $pLen) === 0) {
                $rest = substr($line, $pLen);
                if (strncmp($rest, 'OK', 2) === 0) {
                    $status  = 'OK';
                    $message = trim(substr($rest, 2));
                } elseif (strncmp($rest, 'NO', 2) === 0) {
                    $status  = 'NO';
                    $message = trim(substr($rest, 2));
                } else {
                    $status  = 'BAD';
                    $message = trim(substr($rest, 3));
                }
                break;
            }

            // リテラル {n}: 続くバイト列を読む
            if (preg_match('/\{(\d+)\}\r?\n?$/', $line, $m)) {
                $lines[] = $line;
                $lines[] = $this->readLiteral((int)$m[1]);
                continue;
            }

            $lines[] = $line;
        }

        return ['lines' => $lines, 'status' => $status, 'message' => $message];
    }

    /**
     * 指定シーケンスのメッセージ生データ（RFC822）を取得する。
     */
    private function fetchRaw(int $seq): string
    {
        $tag    = $this->nextTag();
        $prefix = $tag . ' ';
        $pLen   = strlen($prefix);
        $raw    = '';

        $this->sendRaw("{$tag} FETCH {$seq} RFC822\r\n");

        while (true) {
            $line = $this->readLine();
            if ($line === '') {
                break;
            }

            // タグ付き応答 → 完了
            if (strncmp($line, $prefix, $pLen) === 0) {
                break;
            }

            // "* n FETCH (RFC822 {size}" → literal を読む
            if (preg_match('/\{(\d+)\}\r?\n?$/', $line, $m)) {
                $raw = $this->readLiteral((int)$m[1]);
                // 残り（閉じ括弧など）はタグ付き応答まで読み飛ばす
                continue;
            }
            // その他の行（")" 等）は無視して次行へ
        }

        return $raw;
    }

    /**
     * IMAP クォート文字列エンコード（ダブルクォートとバックスラッシュをエスケープ）。
     */
    private function quoteString(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
