<?php
declare(strict_types=1);

Auth::requireLogin();

$templates = FileDB::getTemplates();
$editId    = $_GET['id'] ?? '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } elseif (isset($_POST['delete']) && $editId) {
        $templates = array_values(array_filter($templates, fn($t) => $t['id'] !== $editId));
        FileDB::saveTemplates($templates);
        header('Location: ' . SITE_URL . 'template.php?msg=deleted');
        exit;
    } else {
        $name     = trim($_POST['name']      ?? '');
        $subject  = trim($_POST['subject']   ?? '');
        $body     = trim($_POST['body']      ?? '');
        $htmlBody = trim($_POST['html_body'] ?? '');

        if (!$name) {
            $error = 'テンプレート名を入力してください。';
        } else {
            if ($editId) {
                foreach ($templates as &$t) {
                    if ($t['id'] === $editId) {
                        $t['name']      = $name;
                        $t['subject']   = $subject;
                        $t['body']      = $body;
                        $t['html_body'] = $htmlBody;
                        break;
                    }
                }
                unset($t);
            } else {
                $templates[] = [
                    'id'         => FileDB::generateId(),
                    'name'       => $name,
                    'subject'    => $subject,
                    'body'       => $body,
                    'html_body'  => $htmlBody,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
            FileDB::saveTemplates($templates);
            header('Location: ' . SITE_URL . 'template.php?msg=saved');
            exit;
        }
    }
}

$editTarget = null;
if ($editId) {
    foreach ($templates as $t) {
        if ($t['id'] === $editId) { $editTarget = $t; break; }
    }
}

$msg = $_GET['msg'] ?? '';
$pageTitle = 'テンプレート管理';
$activeNav = 'template';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if ($msg === 'saved'):   ?><div class="alert alert-success">テンプレートを保存しました。</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success">テンプレートを削除しました。</div><?php endif; ?>
<?php if ($error):             ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">

    <div class="card">
        <div class="card-header"><h2>テンプレート一覧</h2></div>
        <div class="table-wrap">
            <?php if (empty($templates)): ?>
                <p class="text-muted text-center" style="padding:32px;">テンプレートはまだありません</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>名前</th><th>件名</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($templates as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="truncate" style="max-width:200px;">
                                <?= htmlspecialchars($t['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="?id=<?= urlencode($t['id']) ?>" class="btn btn-ghost btn-sm">編集</a>
                                    <a href="<?= SITE_URL ?>send.php?tpl=<?= urlencode($t['id']) ?>"
                                       class="btn btn-outline btn-sm">使う</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><?= $editTarget ? 'テンプレート編集' : '新規テンプレート' ?></h2>
            <?php if ($editTarget): ?>
                <a href="<?= SITE_URL ?>template.php" class="btn btn-ghost btn-sm">+ 新規</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                <div class="form-group">
                    <label class="form-label">テンプレート名<span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= htmlspecialchars($editTarget['name'] ?? $_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">件名</label>
                    <input type="text" name="subject" class="form-control"
                           value="<?= htmlspecialchars($editTarget['subject'] ?? $_POST['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">本文</label>
                    <textarea name="body" class="form-control" style="min-height:240px;font-family:monospace;"><?= htmlspecialchars($editTarget['body'] ?? $_POST['body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">HTML 本文</label>
                    <textarea name="html_body" class="form-control" style="min-height:240px;font-family:monospace;" placeholder="（任意）HTMLメール用。空ならテキストのみ送信"><?= htmlspecialchars($editTarget['html_body'] ?? $_POST['html_body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="form-hint">空欄ならテキストのみのテンプレートになります。送信画面で読み込むと自動で HTML モードが ON になります。</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary">保存</button>
                    <?php if ($editTarget): ?>
                        <button type="submit" name="delete" value="1" class="btn btn-danger"
                                onclick="return confirm('削除しますか？')">削除</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
