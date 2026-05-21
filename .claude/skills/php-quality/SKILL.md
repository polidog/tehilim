---
name: php-quality
description: tehilimプロジェクトのPHP品質チェック。phpstan解析とphp-cs-fixerによる整形・チェックを実行する。PHPコードを変更した後、コミット前、PR作成前には必ず実行する。トリガー、phpstan、php-cs-fixer、cs-fix、cs-check、コード整形、静的解析、品質チェック、quality
---

# PHP Quality Skill

tehilimプロジェクト (PHP 8.5+) のコード品質チェックを統合的に実行するためのskill。

## いつ使うか

以下のタイミングで **必ず** 実行する:

1. PHPコード (`src/`, `tests/`, `bin/`) を編集した直後
2. コミットを作成する前
3. PR を作成・更新する前
4. ユーザーから「チェックして」「整形して」「stan」「cs-fixer」等の指示があったとき

## コマンド

### 1. PHPStan 静的解析

```bash
composer stan
```

- `phpstan.neon.dist` の設定 (level 7) で `src/` と `tests/` を解析する
- 警告・エラーがあれば必ず修正してから先に進む
- `tests/Integration/*` と `tests/PHPStan/Fixtures/*` は除外されている
- 拡張機能 (`extension.neon`) は自動でロードされる

### 2. PHP-CS-Fixer 整形チェック (CI と同じ動作)

```bash
composer cs-check
```

- `--dry-run --diff` 相当。差分があれば失敗する
- CI と同じ判定なので、必ずここをグリーンにしてからコミットする

### 3. PHP-CS-Fixer 自動整形

```bash
composer cs-fix
```

- 整形が必要なファイルを実際に書き換える
- 編集後は必ず `composer cs-check` で再確認し、続いて `composer stan` を流す

### 4. テスト (必要に応じて)

```bash
composer test
```

## 推奨ワークフロー

PHPコードを変更したあと:

```bash
composer cs-fix     # まず整形
composer cs-check   # 差分が無いことを確認 (CIと同じ)
composer stan       # 静的解析
composer test       # ユニットテスト
```

すべてグリーンになって初めて作業完了とみなす。

## 失敗したとき

- **PHPStan エラー**: 該当ファイルを修正する。型を緩めるためだけに `@phpstan-ignore` を使わない (どうしても必要な場合は理由をコメントに残す)
- **PHP-CS-Fixer 差分**: `composer cs-fix` で修正する。手作業で reformat しない
- **テスト失敗**: 実装かテストのどちらが正しいか判断し修正する

## 補足

- 設定ファイル
  - `phpstan.neon.dist`: PHPStan 設定 (level 7)
  - `.php-cs-fixer.dist.php`: PHP-CS-Fixer 設定 (PSR-12 + PhpCsFixer + risky)
- GitHub Actions (`.github/workflows/ci.yml`) で同じ `composer stan` / `composer cs-check` / `composer test` が走るので、ローカルでグリーンならCIもグリーンになるはず
