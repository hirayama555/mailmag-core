# アップグレード手順（Phase 2 で本格整備予定）

このドキュメントは Phase 1 段階のスタブです。Phase 2 で `cron_update.php` による自動更新が実装され次第、フローを記述します。

## 現時点（Phase 1）

- 手動で `core.zip` を再ダウンロード → 既存 `core/` を入れ替え
- `data/` と `config.php` には触らない

## Phase 2 完了後（予定）

```
1. GitHub にて新タグを push（v0.2.0 など）
2. GitHub Actions が core.zip + manifest.json + .sig を自動生成
3. 各クライアントの cron_update.php が翌日 0:00 にこれを取得
4. 署名検証 → SHA-256 検証 → 一時 dir に展開 → 既存 core/ をアトミック差替え
5. 失敗時は古い core/ のまま継続稼働 + Sentry に warning
```

## ロールバック

`data/core.bak/` に直前バージョンが1世代分残るため、緊急時はそれを `core/` に rename し直すことで戻せます。
