<?php

declare(strict_types=1);

namespace Example\Blog\Generated;

use Polidog\Tehilim\Client\BaseClient;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Driver;
use Example\Blog\Generated\Model\UserClient;
use Example\Blog\Generated\Model\PostClient;

final class TehilimClient extends BaseClient
{
    public readonly UserClient $user;
    public readonly PostClient $post;

    public function __construct(Driver $driver)
    {
        parent::__construct($driver);
        $this->user = new UserClient($driver);
        $this->registerModel('User', $this->user);
        $this->post = new PostClient($driver);
        $this->registerModel('Post', $this->post);
    }

    public static function connect(Config $config): self
    {
        return new self($config->driver());
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T|mixed
     */
    public function transaction(callable $fn): mixed
    {
        return parent::transaction($fn);
    }
}
