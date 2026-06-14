# アップグレード手順

MailMag のコア（`core/`）は、初回展開後は **自動更新** されます。
クライアントは「鍵」「署名」を一切意識しません。本書は挙動の説明と、緊急時の手動操作をまとめます。

## 自動更新のしくみ

更新チェックは独立した cron 行ではなく、既存の `cron_queue.php` に相乗りして動きます。

```
1. cron_queue.php が起動するたびに data/.update_check の mtime を確認
2. 前回チェックから 24 時間以上経過していれば Updater::checkAndApply() を実行
3. GitHub Releases の最新タグを取得し、現在の core/VERSION と比較
   （data/.update_token があれば private リポジトリを Bearer 認証で取得、
    無ければ public の browser_download_url を使用）
4. 新しければ core.zip と core.zip.sig を取得
5. Ed25519 署名を検証（updater.php に埋め込まれた公開鍵で detached 検証）
6. zip エントリ名を検査（Zip Slip 防御）→ 一時ディレクトリへ展開
7. 展開結果に bootstrap.php があることを確認
8. 既存 core/ を core.old.<時刻> にリネーム → 新 core/ へ原子的 rename で差替え
9. 結果を data/update.log に記録
```

いずれの段階で失敗しても **既存の core/ はそのまま残り、稼働は継続** します（fail-safe）。
署名検証に失敗した zip は決して展開・適用されません。

> `data/` と `config.php` は自動更新で **上書きされません**。クライアント固有データは保護されます。

## ロールバック（緊急時）

差替え時の旧コアは `core.old.<時刻>`（例: `core.old.20260614T0900`）として一時的に残ります。
新バージョンに問題が出た場合は、この旧ディレクトリを `core/` に rename し直すことで戻せます。

```bash
cd /path/to/mailmag
mv core core.broken
mv core.old.20260614T0900 core
```

旧コアは次回 cron 実行時に掃除されるため、ロールバックが必要なら早めに実施してください。

## 手動更新（自動更新を使わない場合）

```bash
cd /path/to/mailmag
wget https://github.com/hirayama555/mailmag-core/releases/latest/download/core.zip
unzip -o core.zip -d ./
rm core.zip
```

`data/` と `config.php` には触れないこと。
