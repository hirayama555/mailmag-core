<?php
declare(strict_types=1);

Auth::requireLogin();

$id = $_GET['id'] ?? '';

// IDで購読者を取得
$subs = FileDB::getSubscribers();
$sub  = null;
foreach ($subs as $s) {
    if ($s['id'] === $id) { $sub = $s; break; }
}

if (!$sub) {
    header('Location: ' . SITE_URL . 'subscribers.php');
    exit;
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } elseif (isset($_POST['delete'])) {
        // 削除（POST + CSRF 必須）
        FileDB::deleteSubscriber($id);
        header('Location: ' . SITE_URL . 'subscribers.php?msg=deleted');
        exit;
    } else {
        $email  = trim($_POST['email'] ?? '');
        $name   = trim($_POST['name']  ?? '');
        $status = $_POST['status'] ?? '1';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'メールアドレスの形式が正しくありません。';
        } else {
            $existing = FileDB::findByEmail($email);
            if ($existing && $existing['id'] !== $id) {
                $error = 'このメールアドレスはすでに使用されています。';
            } else {
                FileDB::updateSubscriber($id, [
                    'email'  => $email,
                    'name'   => $name,
                    'status' => $status,
                ]);
                foreach (FileDB::getSubscribers() as $s) {
                    if ($s['id'] === $id) { $sub = $s; break; }
                }
                $success = true;
            }
        }
    }
}

$statusMap = ['1' => '有効', '0' => 'エラー停止', '9' => '購読解除'];
$pageTitle = '購読者編集';
$activeNav = 'subscribers';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success">変更を保存しました。</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
    <div class="card-header">
        <h2>購読者編集</h2>
        <a href="<?= SITE_URL ?>subscribers.php" class="btn btn-ghost btn-sm">← 一覧へ戻る</a>
    </div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
            <div class="form-group">
                <label class="form-label">メールアドレス<span class="required">*</span></label>
                <input type="email" name="email" class="form-control" required
                       value="<?= htmlspecialchars($sub['email'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">名前</label>
                <input type="text" name="name" class="form-control"
                       value="<?= htmlspecialchars($sub['name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">ステータス</label>
                <select name="status" class="form-control">
                    <?php foreach ($statusMap as $v => $label): ?>
                        <option value="<?= $v ?>" <?= $sub['status'] === $v ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">購読解除URL（読み取り専用）</label>
                <input type="text" class="form-control font-mono"
                       value="<?= htmlspecialchars(SITE_URL . 'unsubscribe.php?token=' . $sub['token'], ENT_QUOTES, 'UTF-8') ?>"
                       readonly>
            </div>
            <p class="text-muted">登録日: <?= htmlspecialchars($sub['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="flex gap-3 mt-4">
                <button type="submit" class="btn btn-primary">保存</button>
                <button type="submit" name="delete" value="1" class="btn btn-danger"
                        formnovalidate
                        onclick="return confirm('この購読者を削除しますか？')">削除</button>
            </div>
        </form>
    </div>
</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
