# Tehilim

[English](README.md) · [日本語](README.ja.md)

Prisma 風のスキーマ駆動 PHP データベースツールキット。クラスマッピングはしません。

スキーマファイル 1 枚にデータモデルを宣言すると、Tehilim が型付きクエリクライアントを生成します。エンティティクラスは存在せず、行は素の連想配列として返ります。生成された `@phpstan-type` 定義のおかげで、**PHPStan / Psalm / IDE は各フィールドの正確な形を認識**します。

```text
schema.tehilim ──► tehilim generate ──► Generated/TehilimClient.php
                                        Generated/Model/UserClient.php
                                        Generated/Model/PostClient.php
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
```

マイグレーションは `./migrations/<YYYYmmddHHMMSSvvv>_<slug>/migration.sql` に保存され、`_snapshot.tehilim` が同じ場所に置かれます。適用済みのマイグレーションは `_tehilim_migrations` テーブルに記録されます。

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

## リレーション: `include` と `select`

スキーマに宣言したリレーションは、各行の optional キーとして表現されます。`include` で要求すると、Tehilim はリレーションごとに 1 本の `IN` クエリでまとめて読み込み、結果を親行にマージします。

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
    'select' => ['id' => true, 'email' => true],
]);
```

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

読み取り (`findUnique` / `findFirst` / `findMany` / `count`) を opt-in でメモ化します。書き込み (`insert` / `update` / `delete` / `upsert` / `insertMany` / `updateMany` / `deleteMany`) は **実行前にキャッシュを丸ごとフラッシュ** するので、同一リクエスト内の read-write-read パターンでも更新が見えます。

```php
$db = TehilimClient::fromPdo($pdo)->enableCache();

// 2 回の読みで DB に行くのは 1 回だけ
$me = $db->user->findUnique(['where' => ['id' => $uid]]);   // miss → 保存
$me = $db->user->findUnique(['where' => ['id' => $uid]]);   // hit

$db->user->update(['where' => ['id' => $uid], 'data' => ['name' => 'X']]);
// この時点でキャッシュは空。次の読みは DB に行く

$db->cache()?->hits();      // 観測用
$db->flushCache();          // 外で行を書き換えた時に手動でドロップ
$db->disableCache();
```

キャッシュは 1 つの `TehilimClient` インスタンスにスコープされます — 単なる in-memory 配列で、TTL もリクエスト跨ぎの共有もありません。HTTP リクエスト (またはあなたの "unit of work") ごとに新しいクライアントを作れば、キャッシュも一緒に生まれて死にます。Relayer の `CachingDatabase` に着想を得ています。

キャッシュキーは `serialize($args)` から生成されるので、クエリ引数はシリアライズ可能である必要があります。

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

## ステータス

v0.1 — プロトタイピングや小規模アプリで使える状態。実装済み:

- array shape PHPDoc 型付きの単一行 CRUD
- where 演算子 + AND/OR/NOT
- `include` (hasMany / belongsTo / hasOne) + `select`
- `@@id` / `@@unique` の複合キー
- `insertMany` / `updateMany` / `deleteMany` / `upsert`
- ネスト SAVEPOINT と `Rollback` を備えた `transaction()`
- opt-in のリクエストスコープキャッシュ (書き込みで自動フラッシュ)
- ファイルベースのマイグレーション履歴 (`migrate dev` / `deploy` / `status` / `reset`)
- SQLite、MySQL/MariaDB、PostgreSQL ドライバ

未対応: many-to-many の暗黙リレーション、生 SQL のエスケープハッチ (`$queryRaw` / `$executeRaw`)、JSON path クエリ、全文検索、トランザクションの分離レベル指定、既存 DB からのスキーマ自己反映。

## ライセンス

MIT
