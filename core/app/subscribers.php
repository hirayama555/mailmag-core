<?php
declare(strict_types=1);

Auth::requireLogin();

$subs   = FileDB::getSubscribers();
$counts = FileDB::countByStatus();

// CSVダウンロード
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="subscribers_' . date('Ymd_His') . '.csv"');
    $fp = fopen('php://output', 'w');
    // BOM（Excel対応）
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['ID', 'メールアドレス', '名前', 'ステータス', '登録日時']);
    $statusMap = ['1' => '有効', '0' => 'エラー停止', '9' => '購読解除'];
    foreach ($subs as $s) {
        if (isset($_GET['active_only']) && $s['status'] !== '1') continue;
        fputcsv($fp, [
            $s['id'], $s['email'], $s['name'],
            $statusMap[$s['status']] ?? $s['status'],
            $s['created_at'],
        ]);
    }
    fclose($fp);
    exit;
}

// 検索・フィルター
$keyword = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';

$filtered = array_filter($subs, function ($s) use ($keyword, $status) {
    if ($status !== '' && $s['status'] !== $status) return false;
    if ($keyword !== '') {
        if (stripos($s['email'], $keyword) === false
            && stripos($s['name'], $keyword) === false) return false;
    }
    return true;
});
$filtered = array_values($filtered);

// ページネーション
$perPage     = 50;
$totalPages  = max(1, (int)ceil(count($filtered) / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset      = ($currentPage - 1) * $perPage;
$paged       = array_slice($filtered, $offset, $perPage);

$statusMap  = ['1' => '有効', '0' => 'エラー停止', '9' => '購読解除'];
$badgeMap   = ['1' => 'badge-success', '0' => 'badge-danger', '9' => 'badge-muted'];

$pageTitle = '購読者一覧';
$activeNav = 'subscribers';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if (($_GET['msg'] ?? '') === 'deleted'): ?>
    <div class="alert alert-success">購読者を削除しました。</div>
<?php endif; ?>

<div class="stats-grid mb-4" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card primary"><div class="label">有効</div><div class="value"><?= number_format($counts['active']) ?></div></div>
    <div class="stat-card"><div class="label">合計</div><div class="value"><?= number_format($counts['total']) ?></div></div>
    <div class="stat-card danger"><div class="label">エラー停止</div><div class="value"><?= number_format($counts['stopped']) ?></div></div>
    <div class="stat-card"><div class="label">購読解除</div><div class="value"><?= number_format($counts['unsubscribed']) ?></div></div>
</div>

<!-- 検索 -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="search-bar">
            <input type="text" name="q" class="form-control"
                   placeholder="メールアドレス・名前で検索"
                   value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
            <select name="status" class="form-control" style="max-width:160px;">
                <option value="">すべてのステータス</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>有効</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>エラー停止</option>
                <option value="9" <?= $status === '9' ? 'selected' : '' ?>>購読解除</option>
            </select>
            <button type="submit" class="btn btn-primary">検索</button>
            <a href="<?= SITE_URL ?>subscribers.php" class="btn btn-ghost">リセット</a>
            <div style="margin-left:auto;display:flex;gap:8px;">
                <a href="<?= SITE_URL ?>subscribers.php?csv=1&active_only=1" class="btn btn-ghost btn-sm">有効のみCSV</a>
                <a href="<?= SITE_URL ?>subscribers.php?csv=1" class="btn btn-ghost btn-sm">全件CSV</a>
                <a href="<?= SITE_URL ?>subscriber_add.php" class="btn btn-primary btn-sm">+ 追加</a>
            </div>
        </form>
    </div>
</div>

<!-- テーブル -->
<div class="card">
    <div class="card-header">
        <h2>購読者一覧（<?= number_format(count($filtered)) ?>件）</h2>
    </div>
    <div class="table-wrap">
        <?php if (empty($paged)): ?>
            <p class="text-muted text-center" style="padding:32px;">該当する購読者が見つかりません</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>メールアドレス</th>
                        <th>名前</th>
                        <th>ステータス</th>
                        <th>登録日</th>
                        <th style="width:120px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paged as $i => $s): ?>
                    <tr>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td class="td-email"><?= htmlspecialchars($s['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="badge <?= $badgeMap[$s['status']] ?? 'badge-muted' ?>">
                                <?= $statusMap[$s['status']] ?? '不明' ?>
                            </span>
                        </td>
                        <td class="text-muted" style="font-size:12px;">
                            <?= htmlspecialchars(substr($s['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <a href="<?= SITE_URL ?>subscriber_edit.php?id=<?= urlencode($s['id']) ?>"
                                   class="btn btn-ghost btn-sm">編集</a>
                                <!-- 削除は POST + CSRF（GET 経由のクロスサイト削除防止）-->
                                <form method="post"
                                      action="<?= SITE_URL ?>subscriber_edit.php?id=<?= urlencode($s['id']) ?>"
                                      style="margin:0;display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                                    <button type="submit" name="delete" value="1"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('削除しますか？')">削除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-body">
        <div class="pagination">
            <?php if ($currentPage > 1): ?>
                <a href="?q=<?= urlencode($keyword) ?>&status=<?= urlencode($status) ?>&page=<?= $currentPage - 1 ?>">前へ</a>
            <?php else: ?>
                <span class="disabled">前へ</span>
            <?php endif; ?>

            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                <?php if ($i === $currentPage): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?q=<?= urlencode($keyword) ?>&status=<?= urlencode($status) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="?q=<?= urlencode($keyword) ?>&status=<?= urlencode($status) ?>&page=<?= $currentPage + 1 ?>">次へ</a>
            <?php else: ?>
                <span class="disabled">次へ</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
