# Tehilim

[English](README.md) · [日本語](README.ja.md)

Prisma 風のスキーマ駆動 PHP データベースツールキット。クラスマッピングはしません。

スキーマファイル 1 枚にデータモデルを宣言すると、Tehilim が型付きクエリクライアントを生成します。エンティティクラスは存在せず、行は素の連想配列として返ります。生成された `@phpstan-type` 定義のおかげで、**PHPStan / Psalm / IDE は各フィールドの正確な形を認識**します。

```text
schema.tehilim ──► tehilim generate ──► Generated/TehilimClient.php
                                        Generated/Model/User.php
                                        Generated/Model/Post.php
```

## なぜオブジェクトではなく配列か

ORM はデータベースの形をクラス階層に押し込み、スキーマが進化するにつれてその階層が乖離していきます。Tehilim は別ルートを採用しました — データはデータ（連想配列）、**型** は PHPDoc の array shape として一級市民、形の不整合は静的解析器の仕事として任せる。

## インストール

```bash
composer require polidog/tehilim
```

PHP 8.5+、SQLite / MySQL / PostgreSQL を扱える PDO が必要です。

## スキーマ

`schema.tehilim`:

```text
datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}

generator client {
  output    = "./src/Generated"
  namespace = "App\\Generated"
}

model User {
  id        Int      @id @default(autoincrement())
  email     String   @unique
  name      String?
  createdAt DateTime @default(now())
  posts     Post[]
}

model Post {
  id        Int      @id @default(autoincrement())
  title     String
  body      String?
  published Boolean  @default(false)
  authorId  Int
  author    User     @relation(fields: [authorId], references: [id])
  createdAt DateTime @default(now())
}
```

## ワークフロー

```bash
vendor/bin/tehilim init                       # 雛形 schema.tehilim を生成
vendor/bin/tehilim generate                   # 型付きクライアントを (再) 生成
vendor/bin/tehilim migrate dev --name init    # diff してマイグレーションを書き、適用
vendor/bin/tehilim migrate deploy             # 未適用のマイグレーションを適用 (CI / prod)
vendor/bin/tehilim migrate status             # 適用済み / 未適用を一覧
vendor/bin/tehilim migrate reset              # 全テーブル drop + 全マイグレーション再適用 (開発専用)

# プロトタイピング用ショートカット — drop + recreate、履歴なし:
vendor/bin/tehilim push

# 逆方向 — 既存 DB を introspect してスキーマを生成:
vendor/bin/tehilim pull              # DB から schema.tehilim を上書き生成
vendor/bin/tehilim pull --print      # ファイルに書かず stdout に出力
```

マイグレーションは `./migrations/<YYYYmmddHHMMSSvvv>_<slug>/migration.sql` に保存され、`_snapshot.tehilim` が同じ場所に置かれます。適用済みのマイグレーションは `_tehilim_migrations` テーブルに記録されます。

### Introspection (`db pull`)

`tehilim pull` は既存 DB を読み、そこからスキーマを復元します。既存スキーマファイルの `datasource` / `generator` ブロックは保持され、`model` だけが再生成されます。接続はスキーマの datasource(または `--url <dsn>`)を使い、カラム型・nullable・主キー(単一 + 複合 `@@id`)・unique(単一 + 複合 `@@unique`)・autoincrement を復元します。

注意点:

- **リレーションはまだ推論しません。** 外部キーは `@relation` に変換されず、暗黙の many-to-many join テーブルも普通のモデルとして出力されます(次段で対応予定)。
- **SQLite は損失があります。** 型アフィニティが INTEGER/REAL/TEXT/BLOB しか区別しないため、`DateTime` / `Json` / `Boolean` / `BigInt` は `Int` / `String` に化けます。MySQL・PostgreSQL は型情報が十分なので忠実に復元できます。

## 接続

PDO は自分で用意してください — Tehilim は `PDO::ATTR_DRIVER_NAME` を見て適切な Driver を選びます。接続の属性 (charset、timezone、persistent フラグ、ステートメントキャッシュなど) はあなたの管理下に残り、他のレイヤーと PDO を共有しやすくなります。

```php
use App\Generated\TehilimClient;

$pdo = new PDO('sqlite:./dev.sqlite');
$db  = TehilimClient::fromPdo($pdo);

// あるいは URL 文字列をパースしてほしいなら:
// $db = TehilimClient::fromUrl('mysql://user:pass@host/db');
```

## 単一行の CRUD

```php
$alice = $db->user->insert(['data' => [
    'email' => 'alice@example.com',
    'name'  => 'Alice',
]]);
// $alice: array{id:int, email:string, name:string|null, createdAt:\DateTimeImmutable, posts?: list<PostRow>}

$found = $db->user->findUnique(['where' => ['email' => 'alice@example.com']]);
if ($found !== null) {
    echo $found['name'];   // PHPStan は string|null と推論
    echo $found['nope'];   // PHPStan エラー: array{...} のキーではない
}

$db->user->update(['where' => ['id' => $alice['id']], 'data' => ['name' => 'Alice C']]);
$db->user->delete(['where' => ['id' => $alice['id']]]);
$count = $db->user->count(['where' => ['name' => ['startsWith' => 'A']]]);
```

## where 演算子

`equals`、`not`、`in`、`notIn`、`lt`、`lte`、`gt`、`gte`、`contains`、`startsWith`、`endsWith`。トップレベルの `AND` / `OR` / `NOT` でサブクエリをグループ化できます。

```php
$db->post->findMany([
    'where' => [
        'OR' => [
            ['title' => ['contains' => 'PHP']],
            ['body'  => ['contains' => 'PHP']],
        ],
        'published' => true,
    ],
    'orderBy' => ['createdAt' => 'desc'],
    'take'    => 20,
]);
```

### JSON path クエリ

`Json` カラムは `path`(キーのリスト)と演算子を組み合わせて中身でフィルタできます。演算子は `equals`、`not`、`string_contains`、`string_starts_with`、`string_ends_with`、`array_contains`。比較は抽出値を text として行います。

```php
// profile が {"address": {"city": "Tokyo"}, "tags": ["php", "sql"]} のような Json カラム
$db->doc->findMany(['where' => [
    'profile' => ['path' => ['address', 'city'], 'equals' => 'Tokyo'],
]]);

$db->doc->findMany(['where' => [
    'profile' => ['path' => ['address', 'city'], 'string_contains' => 'oky'],
]]);

// ネストした配列に値が含まれるか
$db->doc->findMany(['where' => [
    'profile' => ['path' => ['tags'], 'array_contains' => 'php'],
]]);
```

`path` は常にキーの配列で渡します(ドライバが方言へ変換: PostgreSQL `#>>`/`#>`、MySQL `JSON_EXTRACT`/`JSON_CONTAINS`、SQLite `json_extract`/`json_each`)。**SQLite も JSON1 で対応**しています(Prisma は SQLite 非対応)。

## リレーション: `include` と `select`

スキーマに宣言したリレーションは、各行の optional キーとして表現されます。`include` で要求すると、Tehilim はリレーションごとに 1 本の `IN` クエリでまとめて読み込み、結果を親行にマージします。

`push` とマイグレーションは、各 `belongsTo`(`@relation`)と暗黙の many-to-many join テーブルに `FOREIGN KEY` 制約を発行し、参照先テーブルを先に作る依存順でテーブルを生成します。

```php
// hasMany: User.posts
$users = $db->user->findMany([
    'include' => ['posts' => ['where' => ['published' => true]]],
    'orderBy' => ['id' => 'asc'],
]);
foreach ($users as $u) {
    foreach ($u['posts'] ?? [] as $p) { echo "  {$p['title']}\n"; }
}

// belongsTo: Post.author
$posts = $db->post->findMany([
    'include' => ['author' => true],
]);

// カラムの部分射影 (PK は常に保持される):
$slim = $db->user->findMany([
    'select' => ['id', 'email'],
]);
// map 形式も同じ意味 — キーを動的に組み立てたい場合に便利:
$slim = $db->user->findMany([
    'select' => ['id' => true, 'email' => true],
]);
// 同梱の PHPStan 拡張 (下記参照) により戻り値型は
// list<array{id:int, email:string}> に narrow される — $row['name'] は PHPStan が怒る
```

## Many-to-many

両側にリスト型のリレーションを宣言し、どちらも `@relation` を付けなければ、Tehilim はそれを **暗黙の M2M** として扱い、`_AToB` 形式の join テーブルを自動生成します (モデル名をアルファベット順にソートし、PK は `A` 列と `B` 列に保存)。

```text
model Post {
  id    Int    @id @default(autoincrement())
  title String
  tags  Tag[]
}

model Tag {
  id    Int    @id @default(autoincrement())
  name  String @unique
  posts Post[]
}
```

`include` でロード (片側につき 1 回のバッチ `IN` クエリ):

```php
$posts = $db->post->findMany(['include' => ['tags' => true]]);
$tags  = $db->tag->findMany([
    'include' => ['posts' => ['where' => ['published' => true]]],
]);
```

エッジ操作は `connect` / `disconnect` / `set` で。呼び出し側が相手側の PK を指定します:

```php
$db->post->insert(['data' => [
    'title' => 'Hello',
    'tags'  => ['connect' => [['id' => 1], ['id' => 2]]],
]]);

$db->post->update([
    'where' => ['id' => 1],
    'data'  => ['tags' => ['disconnect' => [['id' => 2]]]],
]);

$db->post->update([
    'where' => ['id' => 1],
    'data'  => ['tags' => ['set' => [['id' => 3], ['id' => 4]]]],  // 入れ替え
]);
```

`assignedAt` のような追加カラムを join テーブルに持たせたい場合は、2 つの `@relation` フィールドと複合 `@@id` を持つ普通のモデルとして宣言してください — それが Tehilim の明示的 M2M です。

## 複合キー

```text
model Enrollment {
  userId   Int
  courseId Int
  grade    String?

  @@id([userId, courseId])
}

model Member {
  id       Int    @id @default(autoincrement())
  tenantId Int
  email    String

  @@unique([tenantId, email])
}
```

複合キーのカラムは `WhereUnique` の optional キーになるので、`findUnique` / `update` / `delete` がそのまま動きます:

```php
$db->enrollment->findUnique(['where' => ['userId' => 1, 'courseId' => 100]]);
$db->member->findUnique(['where' => ['tenantId' => 1, 'email' => 'a@x']]);
```

## バルク & upsert

```php
$db->user->insertMany([
    'data' => [
        ['email' => 'a@x'],
        ['email' => 'b@x'],
        ['email' => 'a@x'],   // 重複
    ],
    'skipDuplicates' => true,  // SQLite OR IGNORE / MySQL IGNORE / pg ON CONFLICT
]);  // => ['count' => 2]

$db->user->updateMany([
    'where' => ['active' => false],
    'data'  => ['archived' => true],
]);  // => ['count' => N]

$db->user->deleteMany(['where' => ['archived' => true]]);

$db->user->upsert([
    'where'  => ['email' => 'a@x'],
    'insert' => ['email' => 'a@x', 'name' => 'A'],
    'update' => ['name'  => 'A'],
]);  // 結果の行を返す
```

## リクエストスコープキャッシュ

呼び出し単位の opt-in なメモ化です。モデルクライアントに `cached()` を挟むと、その次の `findUnique` / `findFirst` / `findMany` / `count` がリクエストスコープのキャッシュから読み書きします。普通に呼ぶと常に DB へ行きます。書き込み (`insert` / `update` / `delete` / `upsert` / `insertMany` / `updateMany` / `deleteMany`) は **実行前にキャッシュを丸ごとフラッシュ** するので、同一リクエスト内の read-write-read パターンでも更新が見えます。

```php
$db = TehilimClient::fromPdo($pdo);

// ホットな読み — 明示的にメモ化する
$me = $db->user->cached()->findUnique(['where' => ['id' => $uid]]);   // miss → 保存
$me = $db->user->cached()->findUnique(['where' => ['id' => $uid]]);   // hit

// キャッシュしたくない読みは普通に呼ぶだけ
$fresh = $db->user->findUnique(['where' => ['id' => $uid]]);

$db->user->update(['where' => ['id' => $uid], 'data' => ['name' => 'X']]);
// この時点でキャッシュは空。次の cached() 読みは DB に行く

$db->cache()->hits();       // 観測用
$db->flushCache();          // 外で行を書き換えた時に手動でドロップ
```

`cached()` は元のモデルクライアントを浅くコピーしたインスタンスを返します。生成されたクラスの具体型をそのまま返すので、PHPStan の `select` / `include` ナローイングもそのまま効きます。複数回使う場合は変数に取り回して再利用できます:

```php
$users = $db->user->cached();
$alice = $users->findUnique(['where' => ['id' => 1]]);
$bob   = $users->findUnique(['where' => ['id' => 2]]);
```

キャッシュは 1 つの `TehilimClient` インスタンスにスコープされます — 単なる in-memory 配列で、TTL もリクエスト跨ぎの共有もありません。HTTP リクエスト (またはあなたの "unit of work") ごとに新しいクライアントを作れば、キャッシュも一緒に生まれて死にます。Relayer の `CachingDatabase` に着想を得ています。

`include` 経由でロードされる関連は自動ではキャッシュされません — `cached()` を付けたトップレベルの呼び出しだけがメモ化対象です。キャッシュキーは `serialize($args)` から生成されるので、クエリ引数はシリアライズ可能である必要があります。

## トランザクション

`transaction()` はコールバックをトランザクションの中で実行し、成功時に commit、Throwable で rollback します。ネストした呼び出しは `SAVEPOINT` を使うので、内側で失敗しても外側のトランザクションは生き残ります。

```php
use Polidog\Tehilim\Client\Rollback;

$alice = $db->transaction(function ($tx) {
    $u = $tx->user->insert(['data' => ['email' => 'a@x']]);
    $tx->post->insert(['data' => ['title' => 'Hello', 'authorId' => $u['id']]]);
    return $u;
});

// Rollback を投げると黙ってロールバックし、payload を呼び出し側へ返す
$result = $db->transaction(function ($tx) {
    $tx->user->insert(['data' => ['email' => 'temp@x']]);
    throw new Rollback('discarded');
});  // $result === 'discarded' で、何も永続化されない

// ネスト: 内側が失敗しても外側は中断しない
$db->transaction(function ($tx) {
    $tx->user->insert(['data' => ['email' => 'outer@x']]);
    try {
        $tx->transaction(function ($t2) {
            $t2->user->insert(['data' => ['email' => 'inner@x']]);
            throw new \RuntimeException('inner fail');
        });
    } catch (\RuntimeException) {
        // 内側の SAVEPOINT はロールバック、外側はそのまま続行
    }
    $tx->user->insert(['data' => ['email' => 'after@x']]);
});
```

クロージャの引数は生成済みクライアントの具体型として型付けされるので、`$tx->user`、`$tx->post` などが補完されて PHPStan のチェックも効きます。

### 分離レベル

トップレベルのトランザクションには `IsolationLevel` を指定できます。ネストした呼び出しは外側の分離レベルを引き継ぐため指定不可です(指定すると `LogicException`)。

```php
use Polidog\Tehilim\Client\IsolationLevel;

$db->transaction(function ($tx) {
    // ...
}, IsolationLevel::Serializable);
```

ドライバごとの挙動:

- **MySQL/MariaDB** — `SET TRANSACTION ISOLATION LEVEL ...` を `BEGIN` 直前に発行。4 レベルすべて利用可能。
- **PostgreSQL** — `BEGIN` 直後に `SET TRANSACTION ISOLATION LEVEL ...` を発行。`READ UNCOMMITTED` は内部的に `READ COMMITTED` として扱われます (PostgreSQL の仕様)。
- **SQLite** — 実装上 `SERIALIZABLE` のみ。それ以外を指定すると `RuntimeException` を投げます(本番 DB との挙動差を黙って覆い隠さないため)。

## コネクションプール

Tehilim はコネクションプールを内部で管理しません。PHP-FPM や CLI のように 1 リクエスト = 1 プロセスのモデルではプロセス内プールは効きにくく、長寿命ランタイム (Swoole / RoadRunner / FrankenPHP worker mode など) でしか意味を持たないためです。実運用では以下のいずれかを選んでください。

- **PHP-FPM / CLI** — `PDO::ATTR_PERSISTENT` または PgBouncer / ProxySQL のような外部プーラを使い、`TehilimClient::fromPdo($pdo)` で渡す。プロセスごとに 1 PDO + 1 client が素直。
- **Swoole / RoadRunner / FrankenPHP worker** — **リクエスト(またはコルーチン)ごとに pool から PDO を借りて `TehilimClient::fromPdo($pdo)` で client を作り、リクエスト終了時に PDO を返却して client を捨てる**。プール本体は `hyperf/db` などの既存実装を流用しても自前で組んでもよい。`BaseClient::driver` は readonly なので、ワーカーで client を使い回して PDO だけ差し替える運用はできません。リクエストスコープキャッシュも client と一緒に破棄されるので、ワーカー間で汚染しません。

> **注意:** persistent connection は速い反面、トランザクションの分離レベルなどセッション状態が次のリクエストへ漏れる罠があります。Tehilim は `SET SESSION` を発行せず、必ずトランザクションスコープの `SET TRANSACTION` を使うので、上で書いた分離レベル API は persistent でも安全です。逆にアプリ側で `SET SESSION` を発行している場合は、Tehilim から見えない汚染源になり得るので注意してください。

## プロファイラ連携

Tehilim は各操作を任意の callable でラップできます。Relayer の `Profiler::measure(string $collector, string $label, callable $fn): mixed` と同じシグネチャを採用しているので、Relayer プロファイラはそのまま first-class callable として渡せます:

```php
// Relayer 連携 — measure() を first-class callable で渡すだけ
$db = TehilimClient::fromPdo($pdo)
    ->withProfiler($relayer->profiler->measure(...));
```

独自プロファイラは同じシグネチャの callable なら何でも OK:

```php
$db->withProfiler(function (string $collector, string $label, callable $fn) {
    $start = hrtime(true);
    try {
        return $fn();
    } finally {
        error_log(sprintf('[%s/%s] %.2fms', $collector, $label, (hrtime(true) - $start) / 1e6));
    }
});

$db->withProfiler(null);  // 解除
```

### 発火するイベント

| API | collector | label |
|---|---|---|
| `findUnique` / `findFirst` / `findMany` | `tehilim.findUnique` 等 | `<Model>` |
| `insert` / `update` / `delete` / `count` | `tehilim.insert` 等 | `<Model>` |
| `upsert` / `insertMany` / `updateMany` / `deleteMany` | `tehilim.upsert` 等 | `<Model>` |

- `include` のロードも同じフックを通るので、M2M / hasMany の追加クエリは親 `findMany` の下に自然にネストします。
- `upsert` も内側の `findFirst` + `insert` / `update` の上にネスト。
- **キャッシュヒット時は profiler を呼ばない** — 実際に DB に行った操作だけが記録されます。
- プロファイラ未設定時はゼロオーバーヘッド (nullable プロパティのチェック 1 回のみ)。

## PHPStan 拡張

Tehilim はパッケージ直下に PHPStan 拡張 (`extension.neon`) を同梱しています。リテラルの `select` を渡すと **`findUnique` / `findFirst` / `findMany` の戻り値型を絞り込みます**:

```php
$row = $db->user->findUnique([
    'where'  => ['id' => 1],
    'select' => ['email', 'name'],
]);
// PHPStan からの見え方: array{email: string, name: string|null, id: int}|null
// (PK はランタイム挙動と一致させて自動付与)

echo $row['email'];   // ✓
echo $row['name'];    // ✓
echo $row['age'];     // ✗ PHPStan エラー: narrow 後のキーに存在しない
```

### セットアップ

**推奨 — `phpstan/extension-installer` で自動取り込み:**

```bash
composer require --dev phpstan/extension-installer
```

installer が各パッケージの `composer.json` の `extra.phpstan.includes` を読んで自動配線してくれるので、ユーザー側の `phpstan.neon` には何も書かなくて OK。

**手動 — `phpstan.neon` で明示 include:**

```yaml
# phpstan.neon
includes:
    - vendor/polidog/tehilim/extension.neon
```

### 仕組み

- 生成されたモデルクライアントは `public const ?string PK = 'id';` (複合 PK のモデルは `null`) を宣言。
- 拡張は native reflection でその定数を読み、narrow した shape に PK を加える — ランタイムの auto-include と一致。
- narrowing が効くのは **リテラルの** `select` のみ。以下のどちらの形式も OK:
  ```php
  ['select' => ['id', 'email']]                    // リスト省略記法
  ['select' => ['id' => true, 'email' => true]]    // map 形式
  ```
  動的に組み立てた配列 (`$select` を変数で渡す、union が解決しきれない等) は narrow されず元の `XxxRow` のまま。
- row shape に存在しないキーは黙ってスキップ。リレーション名は `select` ではなく `include` の担当なので、ここに渡しても narrow には寄与しません (ランタイムも無視する挙動と一致)。

### 動作確認

任意のテストファイルに `assertType` を入れて PHPStan を回せば確認できます:

```php
use function PHPStan\Testing\assertType;

$row = $db->user->findUnique(['where' => ['id' => 1], 'select' => ['email']]);
assertType('array{email: string, id: int}|null', $row);
```

拡張自身のテストは `tests/PHPStan/SelectNarrowingTest.php` にあるので、参考にどうぞ。

## ステータス

v0.1 — プロトタイピングや小規模アプリで使える状態。実装済み:

- array shape PHPDoc 型付きの単一行 CRUD
- where 演算子 + AND/OR/NOT
- `Json` カラムへの JSON path フィルタ (`path` + `equals` / `string_contains` / `array_contains` など)
- `include` (hasMany / belongsTo / hasOne) + `select`
- `@@id` / `@@unique` の複合キー
- 暗黙の many-to-many と `_AToB` join テーブルの自動生成
- `insertMany` / `updateMany` / `deleteMany` / `upsert`
- ネスト SAVEPOINT と `Rollback` を備えた `transaction()`
- opt-in のリクエストスコープキャッシュ (書き込みで自動フラッシュ)
- ファイルベースのマイグレーション履歴 (`migrate dev` / `deploy` / `status` / `reset`)
- DB introspection (`pull`) — テーブル・型・キー・unique を復元(リレーションは未対応)
- SQLite、MySQL/MariaDB、PostgreSQL ドライバ

未対応: 全文検索、`db pull` でのリレーション / FK 推論。生 SQL を発行したいときは `$client->driver->pdo()` (または `TehilimClient::fromPdo()`) で PDO を直接使う。

## ライセンス

MIT
