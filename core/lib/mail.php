<?php
declare(strict_types=1);

// ============================================================
// core/lib/mail.php - メール送信ラッパー（テキスト＋HTML multipart）
//
// 旧版からの主要修正:
//   - 旧 lib/mail.php:52 で $htmlBody を textBody のエスケープで上書きする
//     致命的バグを修正。HTML本文が空でなければそのまま multipart の HTML パートに使う。
//   - 受信者メールアドレスのヘッダーインジェクション対策を追加。
// ============================================================

final class Mailer
{
    private array $admin;

    public function __construct(array $admin)
    {
        $this->admin = $admin;
    }

    /**
     * メルマガ送信（テキスト＋HTML 両対応）
     *
     * @param string $to        送信先メールアドレス
     * @param string $subject   件名
     * @param string $textBody  テキスト本文（変数置換済み）
     * @param string $htmlBody  HTML本文（変数置換済み）。空ならテキストのみ
     * @param string $unsubUrl  購読解除URL
     */
    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody = '',
        string $unsubUrl = ''
    ): bool {
        // 受信者の検証（ヘッダーインジェクション・改行混入防止）
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        if (preg_match('/[\r\n]/', $to)) return false;

        $fromName  = (string)($this->admin['from_name']  ?? '');
        $fromEmail = (string)($this->admin['from_email'] ?? '');
        $replyTo   = (string)($this->admin['reply_to']   ?? $fromEmail);

        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ||
            preg_match('/[\r\n]/', $fromEmail) || preg_match('/[\r\n]/', $replyTo)) {
            return false;
        }

        // フッターをテキスト本文に追加
        $footer        = (string)($this->admin['footer_text'] ?? '');
        $footerText    = str_replace('{{unsubscribe_url}}', $unsubUrl, $footer);
        $textBodyFull  = $textBody . "\n\n" . $footerText;

        // List-Unsubscribe ヘッダー（RFC 8058 / Gmail 推奨）
        $listUnsubHeader = $unsubUrl !== '' ? "<{$unsubUrl}>" : '';

        $boundary = '----=_MailMag_' . md5(uniqid('', true));

        if ($htmlBody !== '') {
            // HTML パート末尾にエスケープ済みフッターを追加
            $htmlFooter = '<br><hr style="border:none;border-top:1px solid #ccc;margin:20px 0">'
                . '<p style="font-size:12px;color:#888;">'
                . nl2br(htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8'))
                . '</p>';

            // multipart/alternative
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($textBodyFull)) . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($htmlBody . $htmlFooter)) . "\r\n"
                . "--{$boundary}--";

            $contentType = "multipart/alternative; boundary=\"{$boundary}\"";
        } else {
            // テキストのみ
            $body        = $textBodyFull;
            $contentType = 'text/plain; charset=UTF-8';
        }

        // mb_encode_mimeheader は RFC 2047 の 75 byte/行制限に従って自動折返しする。
        // 手書きの '=?UTF-8?B?...?=' は長い件名で制限を超え、MTA が折返しを誤る恐れがある。
        // mb_internal_encoding('UTF-8') は bootstrap.php で設定済み。
        $encodedSubject  = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        $encodedFromName = $fromName !== ''
            ? (mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n") . ' <' . $fromEmail . '>')
            : $fromEmail;

        $headers  = "From: {$encodedFromName}\r\n";
        $headers .= "Reply-To: {$replyTo}\r\n";
        $headers .= "Content-Type: {$contentType}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: MailMag/" . MAILMAG_CORE_VERSION . "\r\n";
        if ($listUnsubHeader !== '') {
            $headers .= "List-Unsubscribe: {$listUnsubHeader}\r\n";
            $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
        }

        // Envelope-From を明示し、SPF alignment（DMARC pass）を成立させる。
        // $fromEmail は FILTER_VALIDATE_EMAIL + CR/LF チェック済み。
        // 万一の混入に備え escapeshellarg で防御を多層化。
        $additionalParams = '-f' . escapeshellarg($fromEmail);

        return mail($to, $encodedSubject, $body, $headers, $additionalParams);
    }

    /**
     * 確認メール送信（ダブルオプトイン）
     */
    public function sendConfirmMail(string $to, string $confirmUrl): bool
    {
        $siteName = (string)($this->admin['site_name'] ?? 'メルマガ');
        $subject  = "【{$siteName}】メールアドレスの確認";
        $body     = "{$siteName} への登録申請を受け付けました。\r\n\r\n"
            . "以下のURLをクリックして登録を完了してください。\r\n"
            . $confirmUrl . "\r\n\r\n"
            . "このメールに心当たりのない場合は無視してください。\r\n"
            . "URLをクリックしない限り登録は完了しません。\r\n\r\n"
            . "─────────────────────\r\n"
            . $siteName;

        return $this->send($to, $subject, $body);
    }

    /**
     * 変数置換（{{name}} / {{email}}）
     */
    public static function replacePlaceholders(string $text, array $subscriber): string
    {
        $text = str_replace('{{name}}',  (string)($subscriber['name']  ?? ''), $text);
        $text = str_replace('{{email}}', (string)($subscriber['email'] ?? ''), $text);
        return $text;
    }
}
