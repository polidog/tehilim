# tehilim — Claude Code Rules

このリポジトリで作業するときに **必ず** 守るルール。

## 開発環境

- PHP 8.5+ (`.mise.toml` で固定)
- Composer 2.x
- 依存は `composer install` でローカルにインストールする

## コード品質ゲート (MANDATORY)

PHPコード (`src/`, `tests/`, `bin/`) に変更を加えたら、報告・コミット・PR作成の前に **必ず** 以下を順に流してすべてグリーンにする。詳細は `.claude/skills/php-quality/SKILL.md` を参照。

```bash
composer cs-fix     # php-cs-fixer による自動整形
composer cs-check   # CIと同じ dry-run チェック (差分があれば失敗)
composer stan       # PHPStan level 7 静的解析
composer test       # PHPUnit
```

### ルール

1. **`composer cs-check` と `composer stan` がグリーンでない状態を「作業完了」と報告しない。** ローカルで通っていないものはCIでも通らない。
2. **PHP-CS-Fixer の整形を手作業で逆らわない。** スタイルに不満があるなら `.php-cs-fixer.dist.php` を更新する。
3. **PHPStan のエラーを `@phpstan-ignore` で握りつぶさない。** 真因を直す。どうしても抑制が必要なら、理由を1行コメントで残す。
4. **設定ファイル (`phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `extension.neon`) を変更したときは、変更理由をコミットメッセージかPR説明に書く。** 静かにレベルを下げない。
5. **新しい依存を `require-dev` に足したらCIに通るか確認する。** `.github/workflows/ci.yml` がインストールできない依存は壊れる。

## CI

`.github/workflows/ci.yml` で以下3ジョブが並列に走る:

- `phpstan` — `composer stan`
- `php-cs-fixer` — `composer cs-check`
- `phpunit` — `composer test`

PR作成前にローカルで同じコマンドを通しておくこと。

## Skill

PHP関連の品質チェックは `.claude/skills/php-quality/` のskillでまとめている。`/php-quality` のように呼び出すか、PHP編集後は自動で発動させること。
