<?php
declare(strict_types=1);

Auth::requireLogin();

$success = 0;
$errors  = [];
$mode    = 'single';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $mode = $_POST['mode'] ?? 'single';

        if ($mode === 'csv' && isset($_FILES['csv_file'])) {
            $fp = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if (!$fp) {
                $errors[] = 'CSVファイルを読み込めませんでした。';
            } else {
                $lineNum = 0;
                while (($row = fgetcsv($fp)) !== false) {
                    $lineNum++;
                    if ($lineNum === 1) continue;
                    $email = isset($row[0]) ? trim($row[0]) : '';
                    $name  = isset($row[1]) ? trim($row[1]) : '';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                    if (FileDB::findByEmail($email)) continue;
                    FileDB::addSubscriber([
                        'id'         => FileDB::generateId(),
                        'email'      => $email,
                        'name'       => $name,
                        'status'     => '1',
                        'token'      => Token::generate(),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $success++;
                }
                fclose($fp);
            }
        } else {
            $email = trim($_POST['email'] ?? '');
            $name  = trim($_POST['name']  ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'メールアドレスの形式が正しくありません。';
            } elseif (FileDB::findByEmail($email)) {
                $errors[] = 'このメールアドレスはすでに登録されています。';
            } else {
                FileDB::addSubscriber([
                    'id'         => FileDB::generateId(),
                    'email'      => $email,
                    'name'       => $name,
                    'status'     => '1',
                    'token'      => Token::generate(),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $success = 1;
            }
        }
    }
}

$pageTitle = '購読者追加';
$activeNav = 'subscriber_add';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if ($success > 0): ?>
    <div class="alert alert-success">
        <?= number_format($success) ?>件の購読者を追加しました。
        <a href="<?= SITE_URL ?>subscribers.php">購読者一覧へ</a>
    </div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

    <div class="card">
        <div class="card-header"><h2>手動追加（1件）</h2></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                <input type="hidden" name="mode" value="single">
                <div class="form-group">
                    <label class="form-label">メールアドレス<span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">名前</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <button type="submit" class="btn btn-primary">追加する</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>CSVインポート（一括）</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>CSVフォーマット</strong><br>
                1行目: ヘッダー行（スキップされます）<br>
                2行目以降: <code>メールアドレス,名前</code>
                <br><br>
                重複するアドレスは自動的にスキップされます。
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                <input type="hidden" name="mode" value="csv">
                <div class="form-group">
                    <label class="form-label">CSVファイル<span class="required">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
                <button type="submit" class="btn btn-primary">インポート</button>
            </form>
        </div>
    </div>

</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
