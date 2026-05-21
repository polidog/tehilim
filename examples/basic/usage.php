<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/generated/TehilimClient.php';
require __DIR__ . '/generated/Model/UserClient.php';
require __DIR__ . '/generated/Model/PostClient.php';

use Example\Blog\Generated\TehilimClient;
use Polidog\Tehilim\Config;

$db = TehilimClient::connect(Config::fromUrl('sqlite:' . __DIR__ . '/blog.sqlite'));

$alice = $db->user->create(['data' => [
    'email' => 'alice@example.com',
    'name'  => 'Alice',
]]);
echo "Created user #{$alice['id']} <{$alice['email']}>\n";

$db->post->create(['data' => [
    'title'    => 'Hello, Tehilim',
    'body'     => 'First post.',
    'authorId' => $alice['id'],
    'published'=> true,
]]);
$db->post->create(['data' => [
    'title'    => 'Draft',
    'authorId' => $alice['id'],
]]);

$published = $db->post->findMany([
    'where'   => ['published' => true],
    'orderBy' => ['createdAt' => 'desc'],
    'take'    => 10,
]);

foreach ($published as $p) {
    echo "- [{$p['id']}] {$p['title']}\n";
}

$count = $db->post->count(['where' => ['authorId' => $alice['id']]]);
echo "Posts by Alice: {$count}\n";

$found = $db->user->findUnique(['where' => ['email' => 'alice@example.com']]);
var_export($found);
echo "\n";
