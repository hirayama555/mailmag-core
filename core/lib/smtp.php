<?php
declare(strict_types=1);

// ============================================================
// core/lib/smtp.php - 最小 SMTP Auth 送信クライアント（v1.1.4 新規）
//
// 目的:
//   PHP mail() はローカル MTA に直接渡るため、レンタルサーバーが
//   提供する「SMTP 認証経由の送信のみ DKIM 署名する」仕組みの
//   対象外になる。本クラスで SMTP Auth 送信を行い dkim=pass を得る。
//
// 設計方針:
//   - MIME 本文の構築は Mailer 側（mail.php）に残し、本クラスは
//     「組み上がった生メッセージを運ぶ」transport に責務を限定する。
//   - cron_queue.php は Mailer インスタンスをバッチ全件で使い回すため、
//     接続をインスタンス寿命の間キープアライブして connect/AUTH の
//     繰り返しを避ける（共有サーバーの接続数制限・速度対策）。
//   - サーバー非依存: host/port/secure を可変にし、カゴヤ(587/STARTTLS)も
//     シン・レンタルサーバー(465/SSL) も同一コードで対応する。
//   - PHP 7.4 互換: match / nullsafe / 名前付き引数を使わない。
// ============================================================

final class SmtpException extends RuntimeException
{
}

final class SmtpClient
{
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $secure; // 'ssl'（暗黙SSL=465） | 'tls'（STARTTLS=587） | ''（平文）
    private int    $timeout;

    /** @var resource|null */
    private $conn = null;
    private bool $authed = false;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $pass,
        string $secure = 'tls',
        int $timeout = 30
    ) {
        $this->host    = $host;
        $this->port    = $port;
        $this->user    = $user;
        $this->pass    = $pass;
        $this->secure  = in_array($secure, ['ssl', 'tls', ''], true) ? $secure : 'tls';
        $this->timeout = $timeout;
    }

    /**
     * 接続・認証（遅延・冪等）。既に認証済みなら何もしない。
     *
     * @throws SmtpException
     */
    public function connect(): void
    {
        if ($this->isConnected() && $this->authed) {
            return;
        }

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
            $remote,
            $errno,
            $errstr,
            (float)$this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($conn === false) {
            throw new SmtpException("SMTP接続に失敗しました: {$errstr} ({$errno})");
        }

        $this->conn   = $conn;
        $this->authed = false;
        stream_set_timeout($this->conn, $this->timeout);

        // サーバーグリーティング
        $this->expect(220);

        $ehlo = $this->ehloName();
        $this->command("EHLO {$ehlo}", 250);

        // STARTTLS（587 などの平文ポートを TLS に昇格）
        if ($this->secure === 'tls') {
            $this->command('STARTTLS', 220);

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }

            $ok = @stream_socket_enable_crypto($this->conn, true, $cryptoMethod);
            if ($ok !== true) {
                $this->close();
                throw new SmtpException('STARTTLS のTLSハンドシェイクに失敗しました');
            }

            // RFC 3207: STARTTLS 後は EHLO を再送する
            $this->command("EHLO {$ehlo}", 250);
        }

        // AUTH LOGIN（ユーザー名・パスワードをそれぞれ base64 で送る）
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->user), 334);
        // パスワードは例外メッセージに含めない（command は送信値を出力しない）
        $this->command(base64_encode($this->pass), 235);

        $this->authed = true;
    }

    /**
     * 1通送信する。生メッセージ（ヘッダー + 空行 + 本文）を DATA で渡す。
     * 失敗時は接続を破棄し、次回 send() で再接続させる。
     *
     * @param string   $from       Envelope-From（SPF alignment 用）
     * @param string[] $recipients RCPT TO の宛先
     * @param string   $rawMessage To/Subject/From 等のヘッダーを含む完全なメッセージ
     * @throws SmtpException
     */
    public function send(string $from, array $recipients, string $rawMessage): bool
    {
        $this->connect();

        try {
            $this->command('MAIL FROM:<' . $from . '>', 250);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', 250);
            }
            $this->command('DATA', 354);

            // 改行を CRLF に正規化し、行頭ドットをエスケープ（RFC 5321 ドットスタッフィング）
            $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $rawMessage);
            $normalized = preg_replace('/^\./m', '..', $normalized);

            $this->write($normalized . "\r\n.\r\n");
            $this->expect(250);

            return true;
        } catch (SmtpException $e) {
            // セッションが壊れている可能性があるため接続を破棄して伝播。
            // 呼び出し側（Mailer）は false を返し、次の宛先で再接続される。
            $this->close();
            throw $e;
        }
    }

    /**
     * 接続を閉じる（QUIT）。例外は投げない。
     */
    public function close(): void
    {
        if (is_resource($this->conn)) {
            @fwrite($this->conn, "QUIT\r\n");
            @fclose($this->conn);
        }
        $this->conn   = null;
        $this->authed = false;
    }

    // ---- 内部ヘルパ ------------------------------------------

    private function isConnected(): bool
    {
        return is_resource($this->conn) && !feof($this->conn);
    }

    /**
     * コマンドを送信し、期待するレスポンスコードを検証する。
     * 例外メッセージにはサーバーからの応答のみを含め、送信値（パスワード等）は含めない。
     *
     * @throws SmtpException
     */
    private function command(string $cmd, int $expected): string
    {
        $this->write($cmd . "\r\n");
        return $this->expect($expected);
    }

    /**
     * @throws SmtpException
     */
    private function write(string $data): void
    {
        if (!is_resource($this->conn)) {
            throw new SmtpException('SMTP未接続でデータを送信しようとしました');
        }
        $bytes = @fwrite($this->conn, $data);
        if ($bytes === false) {
            throw new SmtpException('SMTPソケットへの書き込みに失敗しました');
        }
    }

    /**
     * @throws SmtpException
     */
    private function expect(int $expected): string
    {
        list($code, $data) = $this->readResponse();
        if ($code !== $expected) {
            throw new SmtpException(
                "SMTP想定外応答: {$expected} を期待しましたが {$code} を受信しました: " . trim($data)
            );
        }
        return $data;
    }

    /**
     * SMTP レスポンスを読む。複数行（"250-..." 継続）に対応。
     *
     * @return array{0:int,1:string} [コード, 生応答]
     * @throws SmtpException
     */
    private function readResponse(): array
    {
        $code = 0;
        $data = '';

        while (is_resource($this->conn)) {
            $line = fgets($this->conn, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($this->conn);
                if (!empty($meta['timed_out'])) {
                    throw new SmtpException('SMTP応答がタイムアウトしました');
                }
                break;
            }

            $data .= $line;
            $code  = (int)substr($line, 0, 3);

            // 4文字目が '-' なら継続行。それ以外（空白）で完了。
            if (isset($line[3]) && $line[3] === '-') {
                continue;
            }
            break;
        }

        return [$code, $data];
    }

    /**
     * EHLO で名乗るホスト名。サニタイズ済みの安全なトークンを返す。
     */
    private function ehloName(): string
    {
        $name = gethostname();
        if ($name === false || $name === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $name)) {
            $name = 'localhost';
        }
        return $name;
    }
}
