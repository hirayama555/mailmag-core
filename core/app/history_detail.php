<?php
declare(strict_types=1);

Auth::requireLogin();

$id = $_GET['id'] ?? '';
$h  = $id ? FileDB::getHistory($id) : null;

if (!$h) {
    header('Location: ' . SITE_URL . 'history.php');
    exit;
}

$statusMap = [
    'done'      => ['label' => '完了',     'badge' => 'badge-success'],
    'sending'   => ['label' => '送信中',   'badge' => 'badge-warn'],
    'reserved'  => ['label' => '予約中',   'badge' => 'badge-primary'],
    'cancelled' => ['label' => 'キャンセル','badge' => 'badge-muted'],
    'failed'    => ['label' => 'エラー',   'badge' => 'badge-danger'],
];
$info = $statusMap[$h['status']] ?? ['label' => $h['status'], 'badge' => 'badge-muted'];

$pageTitle = '送信履歴詳細';
$activeNav = 'history';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;">

    <div class="card">
        <div class="card-header">
            <h2><?= htmlspecialchars($h['subject'], ENT_QUOTES, 'UTF-8') ?></h2>
            <a href="<?= SITE_URL ?>history.php" class="btn btn-ghost btn-sm">← 戻る</a>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">本文</label>
                <pre style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;
                            padding:16px;font-size:13px;line-height:1.7;white-space:pre-wrap;
                            font-family:inherit;"><?= htmlspecialchars($h['body'], ENT_QUOTES, 'UTF-8') ?></pre>
            </div>
            <a href="<?= SITE_URL ?>send.php?history_id=<?= urlencode($id) ?>"
               class="btn btn-outline">この内容で再送信</a>
        </div>
    </div>

    <div>
        <div class="card mb-4">
            <div class="card-header"><h2>送信情報</h2></div>
            <div class="card-body">
                <table style="width:100%;font-size:13px;">
                    <tr><td class="text-muted" style="padding:6px 0;width:80px;">状態</td>
                        <td><span class="badge <?= $info['badge'] ?>"><?= $info['label'] ?></span></td></tr>
                    <tr><td class="text-muted" style="padding:6px 0;">対象数</td>
                        <td><?= number_format((int)($h['total_count'] ?? 0)) ?> 件</td></tr>
                    <tr><td class="text-muted" style="padding:6px 0;">成功</td>
                        <td><?= number_format((int)($h['success_count'] ?? 0)) ?> 件</td></tr>
                    <tr><td class="text-muted" style="padding:6px 0;">開始日時</td>
                        <td style="font-size:12px;"><?= htmlspecialchars(substr($h['sent_at'] ?? '', 0, 16), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><td class="text-muted" style="padding:6px 0;">完了日時</td>
                        <td style="font-size:12px;"><?= !empty($h['finished_at']) ? htmlspecialchars(substr($h['finished_at'], 0, 16), ENT_QUOTES, 'UTF-8') : '—' ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($h['status'] === 'sending'): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-muted" style="font-size:13px;">送信中です。CRONが自動的に処理を続けます。</p>
                <div class="progress-wrap mt-2">
                    <?php
                    $pct = $h['total_count'] > 0
                        ? round($h['success_count'] / $h['total_count'] * 100) : 0;
                    ?>
                    <div class="progress-bar" style="width:<?= $pct ?>%"></div>
                </div>
                <p class="text-muted text-center mt-1" style="font-size:12px;"><?= $pct ?>%</p>
                <meta http-equiv="refresh" content="30">
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
