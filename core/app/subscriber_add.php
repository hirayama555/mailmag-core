<?php
declare(strict_types=1);

Auth::requireLogin();

$success = 0;
$errors  = [];
$skipped = 0;
$mode    = 'single';

// 一括メンテナンス（停止・削除）の結果。null = 未実行。
$suppressResult    = null;   // ['suppressed'=>int,'skipped'=>int]
$unsubscribeResult = null;   // ['unsubscribed'=>int,'skipped'=>int]
$deletedCount      = null;   // Yahoo 系 物理削除件数

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $mode = $_POST['mode'] ?? 'single';

        if ($mode === 'csv') {
            $up      = $_FILES['csv_file'] ?? null;
            $upError = $up['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($upError !== UPLOAD_ERR_OK) {
                // アップロード自体が失敗（未選択・サイズ超過・サーバー設定 等）
                $uploadMessages = [
                    UPLOAD_ERR_INI_SIZE   => 'ファイルサイズがサーバーの上限（upload_max_filesize）を超えています。',
                    UPLOAD_ERR_FORM_SIZE  => 'ファイルサイズが上限を超えています。',
                    UPLOAD_ERR_PARTIAL    => 'ファイルが途中までしかアップロードされませんでした。再度お試しください。',
                    UPLOAD_ERR_NO_FILE    => 'ファイルが選択されていません。CSVファイルを選んでからインポートしてください。',
                    UPLOAD_ERR_NO_TMP_DIR => 'サーバーに一時保存先がありません。サーバー管理者にご確認ください。',
                    UPLOAD_ERR_CANT_WRITE => 'サーバーへの書き込みに失敗しました。',
                    UPLOAD_ERR_EXTENSION  => 'PHP拡張によりアップロードが中断されました。',
                ];
                $errors[] = $uploadMessages[$upError] ?? ('ファイルのアップロードに失敗しました（コード: ' . (int)$upError . '）。');
            } else {
                $fp = fopen($up['tmp_name'], 'r');
                if (!$fp) {
                    $errors[] = 'CSVファイルを読み込めませんでした。';
                } else {
                    // まず全行をパースしてバッチを組み、最後に一括追加する。
                    // 1件ずつ addSubscriberIfNew を呼ぶと CSV 全体の read-modify-write が
                    // 行数分走り O(N^2) → 件数が多いと 504 タイムアウトになるため。
                    $batch   = [];
                    $invalid = 0;
                    $usedIds = [];
                    $lineNum = 0;
                    while (($row = fgetcsv($fp)) !== false) {
                        $lineNum++;
                        if ($lineNum === 1) continue; // ヘッダー行
                        $email = isset($row[0]) ? trim($row[0]) : '';
                        // 先頭セルに残り得る BOM を除去（Excel保存のCSV対策）
                        $email = preg_replace('/^\xEF\xBB\xBF/', '', $email);
                        $name  = isset($row[1]) ? trim($row[1]) : '';
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
                        // バッチ内で ID が衝突しないよう、重複時は再生成する
                        do { $id = FileDB::generateId(); } while (isset($usedIds[$id]));
                        $usedIds[$id] = true;
                        $now = date('Y-m-d H:i:s');
                        $batch[] = [
                            'id'         => $id,
                            'email'      => $email,
                            'name'       => $name,
                            'status'     => '1',
                            'token'      => Token::generate(),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    fclose($fp);

                    // 1回の原子的読み書きで一括追加（既存・バッチ内の重複は自動スキップ）
                    $success = FileDB::addSubscribersBulk($batch);
                    $skipped = $invalid + (count($batch) - $success); // 不正 + 重複

                    // 1件も追加されなかった場合は、原因が分かるよう明示する（無反応の解消）
                    if ($success === 0 && empty($errors)) {
                        $errors[] = '登録できる新規アドレスがありませんでした（不正な形式または重複: ' . $skipped . '件）。'
                                  . 'CSVの文字コード（UTF-8）・列順（1列目=メールアドレス／2列目=名前）・1行目がヘッダー行であることをご確認ください。';
                    }
                }
            }
        } elseif ($mode === 'suppress_csv') {
            // 既知の不達アドレス一覧（NowGetter 等）を一括エラー停止する。
            // 追加用 CSV と同じく 1 列目をメールアドレスとして読む。
            $up      = $_FILES['suppress_file'] ?? null;
            $upError = $up['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($upError !== UPLOAD_ERR_OK) {
                $uploadMessages = [
                    UPLOAD_ERR_INI_SIZE   => 'ファイルサイズがサーバーの上限（upload_max_filesize）を超えています。',
                    UPLOAD_ERR_FORM_SIZE  => 'ファイルサイズが上限を超えています。',
                    UPLOAD_ERR_PARTIAL    => 'ファイルが途中までしかアップロードされませんでした。再度お試しください。',
                    UPLOAD_ERR_NO_FILE    => 'ファイルが選択されていません。停止対象のCSVを選んでください。',
                    UPLOAD_ERR_NO_TMP_DIR => 'サーバーに一時保存先がありません。サーバー管理者にご確認ください。',
                    UPLOAD_ERR_CANT_WRITE => 'サーバーへの書き込みに失敗しました。',
                    UPLOAD_ERR_EXTENSION  => 'PHP拡張によりアップロードが中断されました。',
                ];
                $errors[] = $uploadMessages[$upError] ?? ('ファイルのアップロードに失敗しました（コード: ' . (int)$upError . '）。');
            } else {
                $fp = fopen($up['tmp_name'], 'r');
                if (!$fp) {
                    $errors[] = 'CSVファイルを読み込めませんでした。';
                } else {
                    $emails  = [];
                    $lineNum = 0;
                    while (($row = fgetcsv($fp)) !== false) {
                        $lineNum++;
                        if ($lineNum === 1) continue; // ヘッダー行
                        $email = isset($row[0]) ? trim($row[0]) : '';
                        $email = preg_replace('/^\xEF\xBB\xBF/', '', $email); // BOM除去
                        if ($email !== '') $emails[] = $email;
                    }
                    fclose($fp);

                    if (empty($emails)) {
                        $errors[] = 'CSVから停止対象のアドレスを読み取れませんでした（1列目=メールアドレス／1行目はヘッダー）。';
                    } else {
                        $suppressResult = FileDB::suppressEmailsBulk($emails);
                    }
                }
            }
        } elseif ($mode === 'unsubscribe_csv') {
            // 外部システム（NowGetter 等）で購読解除された読者一覧を一括購読解除する。
            // 停止用 CSV と同じく 1 列目をメールアドレスとして読む。
            $up      = $_FILES['unsubscribe_file'] ?? null;
            $upError = $up['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($upError !== UPLOAD_ERR_OK) {
                $uploadMessages = [
                    UPLOAD_ERR_INI_SIZE   => 'ファイルサイズがサーバーの上限（upload_max_filesize）を超えています。',
                    UPLOAD_ERR_FORM_SIZE  => 'ファイルサイズが上限を超えています。',
                    UPLOAD_ERR_PARTIAL    => 'ファイルが途中までしかアップロードされませんでした。再度お試しください。',
                    UPLOAD_ERR_NO_FILE    => 'ファイルが選択されていません。購読解除対象のCSVを選んでください。',
                    UPLOAD_ERR_NO_TMP_DIR => 'サーバーに一時保存先がありません。サーバー管理者にご確認ください。',
                    UPLOAD_ERR_CANT_WRITE => 'サーバーへの書き込みに失敗しました。',
                    UPLOAD_ERR_EXTENSION  => 'PHP拡張によりアップロードが中断されました。',
                ];
                $errors[] = $uploadMessages[$upError] ?? ('ファイルのアップロードに失敗しました（コード: ' . (int)$upError . '）。');
            } else {
                $fp = fopen($up['tmp_name'], 'r');
                if (!$fp) {
                    $errors[] = 'CSVファイルを読み込めませんでした。';
                } else {
                    $emails  = [];
                    $lineNum = 0;
                    while (($row = fgetcsv($fp)) !== false) {
                        $lineNum++;
                        if ($lineNum === 1) continue; // ヘッダー行
                        $email = isset($row[0]) ? trim($row[0]) : '';
                        $email = preg_replace('/^\xEF\xBB\xBF/', '', $email); // BOM除去
                        if ($email !== '') $emails[] = $email;
                    }
                    fclose($fp);

                    if (empty($emails)) {
                        $errors[] = 'CSVから購読解除対象のアドレスを読み取れませんでした（1列目=メールアドレス／1行目はヘッダー）。';
                    } else {
                        $unsubscribeResult = FileDB::unsubscribeEmailsBulk($emails);
                    }
                }
            }
        } elseif ($mode === 'suppress_yahoo') {
            // Yahoo 系（EXCLUDE_DOMAINS）を配信リストから物理削除する。
            $deletedCount = FileDB::deleteByDomains(mailmag_excluded_domains());
        } else {
            $email = trim($_POST['email'] ?? '');
            $name  = trim($_POST['name']  ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'メールアドレスの形式が正しくありません。';
            } else {
                // 原子的追加: 同時POSTでの重複登録を物理的に防ぐ
                $added = FileDB::addSubscriberIfNew([
                    'id'         => FileDB::generateId(),
                    'email'      => $email,
                    'name'       => $name,
                    'status'     => '1',
                    'token'      => Token::generate(),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                if ($added) {
                    $success = 1;
                } else {
                    $errors[] = 'このメールアドレスはすでに登録されています。';
                }
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
        <?= number_format($success) ?>件の購読者を追加しました。<?php if ($skipped > 0): ?>（重複・不正でスキップ: <?= number_format($skipped) ?>件）<?php endif; ?>
        <a href="<?= SITE_URL ?>subscribers.php">購読者一覧へ</a>
    </div>
<?php endif; ?>
<?php if ($suppressResult !== null): ?>
    <div class="alert alert-success">
        <?= number_format($suppressResult['suppressed']) ?>件をエラー停止しました。<?php if ($suppressResult['skipped'] > 0): ?>（未登録・既に停止/解除済みでスキップ: <?= number_format($suppressResult['skipped']) ?>件）<?php endif; ?>
        <a href="<?= SITE_URL ?>subscribers.php?status=0">エラー停止一覧へ</a>
    </div>
<?php endif; ?>
<?php if ($unsubscribeResult !== null): ?>
    <div class="alert alert-success">
        <?= number_format($unsubscribeResult['unsubscribed']) ?>件を購読解除しました。<?php if ($unsubscribeResult['skipped'] > 0): ?>（未登録・既に解除済みでスキップ: <?= number_format($unsubscribeResult['skipped']) ?>件）<?php endif; ?>
        <a href="<?= SITE_URL ?>subscribers.php?status=9">購読解除一覧へ</a>
    </div>
<?php endif; ?>
<?php if ($deletedCount !== null): ?>
    <div class="alert alert-success">
        Yahoo系アドレスを <?= number_format($deletedCount) ?>件 削除しました。<?php if ($deletedCount === 0): ?>（該当なし）<?php endif; ?>
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

<div class="card mt-4">
    <div class="card-header"><h2>一括メンテナンス（不達停止・購読解除・Yahoo削除）</h2></div>
    <div class="card-body">
        <div class="alert alert-info">
            大量配信の前に、<strong>既に不達と分かっているアドレスを停止</strong>したり、
            <strong>外部システムで解除された読者を購読解除</strong>したり、
            <strong>配信できないドメインを削除</strong>してリストを浄化します。
            送信レピュテーション（迷惑メール判定）の悪化を防ぐために重要です。
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;">

            <div>
                <h3 style="margin-top:0;">不達アドレスを一括エラー停止</h3>
                <p class="form-hint">
                    NowGetter 等で既にエラーと判明しているアドレス一覧（CSV）を取り込み、
                    該当する有効購読者を<strong>エラー停止（status: 0）</strong>にします。削除はしません（後から復元可能）。<br>
                    CSV は <code>1列目=メールアドレス</code>、1行目はヘッダーとして読み飛ばします。
                </p>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                    <input type="hidden" name="mode" value="suppress_csv">
                    <div class="form-group">
                        <label class="form-label">停止対象CSV<span class="required">*</span></label>
                        <input type="file" name="suppress_file" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary">CSVから一括停止</button>
                </form>
            </div>

            <div>
                <h3 style="margin-top:0;">解除アドレスを一括購読解除</h3>
                <p class="form-hint">
                    NowGetter 等の外部システムで<strong>購読解除された読者一覧（CSV）</strong>を取り込み、
                    該当購読者を<strong>購読解除（status: 9）</strong>にします。エラー停止と違い「本人の解除」として記録します（後から復元可能）。<br>
                    CSV は <code>1列目=メールアドレス</code>、1行目はヘッダーとして読み飛ばします。
                </p>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                    <input type="hidden" name="mode" value="unsubscribe_csv">
                    <div class="form-group">
                        <label class="form-label">解除対象CSV<span class="required">*</span></label>
                        <input type="file" name="unsubscribe_file" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary">CSVから一括購読解除</button>
                </form>
            </div>

            <div>
                <h3 style="margin-top:0;">Yahoo系アドレスを一括削除</h3>
                <p class="form-hint">
                    対象ドメイン:
                    <code><?= htmlspecialchars(str_replace(',', ' / ', EXCLUDE_DOMAINS), ENT_QUOTES, 'UTF-8') ?></code><br>
                    これらは送信者レピュテーション（SGS）により一括配信が拒否されるため、
                    配信リストから<strong>物理削除</strong>します（別経路で配信する想定）。
                    削除前に自動でバックアップ（<code>.bak</code>）を取得します。
                </p>
                <form method="post"
                      onsubmit="return confirm('Yahoo系（<?= htmlspecialchars(str_replace(',', ' / ', EXCLUDE_DOMAINS), ENT_QUOTES, 'UTF-8') ?>）のアドレスを配信リストから削除します。よろしいですか？');">
                    <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                    <input type="hidden" name="mode" value="suppress_yahoo">
                    <button type="submit" class="btn btn-danger">Yahoo系を一括削除</button>
                </form>
            </div>

        </div>
    </div>
</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
