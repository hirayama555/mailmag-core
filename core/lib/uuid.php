<?php
declare(strict_types=1);

// ============================================================
// core/lib/uuid.php
// クライアント識別子用の UUID v4 生成
// ============================================================

final class Uuid
{
    /**
     * RFC 4122 準拠の UUID v4 を返す（例: 550e8400-e29b-41d4-a716-446655440000）
     */
    public static function v4(): string
    {
        $data = random_bytes(16);
        // version 4
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // variant 10xx
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
