<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/generated/Model/UserClient.php';
require __DIR__ . '/generated/Model/PostClient.php';
require __DIR__ . '/generated/TehilimClient.php';

use Example\Blog\Generated\TehilimClient;

// Bring your own PDO — already-configured, persistent, custom charset, etc.
$pdo = new PDO('sqlite:' . __DIR__ . '/blog.sqlite');
$db = TehilimClient::fromPdo($pdo);

// Or if you'd rather have Tehilim parse a URL for you:
// $db = TehilimClient::fromUrl('sqlite:' . __DIR__ . '/blog.sqlite');

$alice = $db->user->create(['data' => [
    'email' => 'alice@example.com',
    'name'  => 'Alice',
]]);
$bob = $db->user->create(['data' => [
    'email' => 'bob@example.com',
    'name'  => 'Bob',
]]);

$db->post->create(['data' => ['title' => 'Hello, Tehilim', 'body' => 'First post.', 'authorId' => $alice['id'], 'published' => true]]);
$db->post->create(['data' => ['title' => 'Draft',          'authorId' => $alice['id']]]);
$db->post->create(['data' => ['title' => 'Bob has thoughts','authorId' => $bob['id'],   'published' => true]]);

echo "-- users with posts --\n";
$usersWithPosts = $db->user->findMany([
    'include' => ['posts' => ['where' => ['published' => true]]],
    'orderBy' => ['id' => 'asc'],
]);
foreach ($usersWithPosts as $u) {
    echo "{$u['name']}:\n";
    foreach ($u['posts'] ?? [] as $p) {
        echo "  - [{$p['id']}] {$p['title']}\n";
    }
}

echo "\n-- posts with author --\n";
$posts = $db->post->findMany([
    'where'   => ['published' => true],
    'include' => ['author' => true],
    'orderBy' => ['createdAt' => 'desc'],
]);
foreach ($posts as $p) {
    $author = $p['author'];
    echo "[{$p['id']}] {$p['title']} — by {$author['name']}\n";
}

echo "\n-- select projection --\n";
$slim = $db->user->findMany([
    'select'  => ['id' => true, 'email' => true],
    'orderBy' => ['id' => 'asc'],
]);
var_export($slim);
echo "\n";
