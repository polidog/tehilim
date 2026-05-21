<?php

declare(strict_types=1);

namespace Example\Blog\Generated;

use PDO;
use Polidog\Tehilim\Client\BaseClient;
use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Driver\Drivers;
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

    /**
     * Build a client from an already-configured PDO instance.
     * The driver is inferred from PDO::ATTR_DRIVER_NAME.
     */
    public static function fromPdo(PDO $pdo): self
    {
        return new self(Drivers::forPdo($pdo));
    }

    /**
     * Convenience: parse a URL into a PDO then build the client.
     * For full control over PDO attributes, use fromPdo() instead.
     */
    public static function fromUrl(string $url, ?string $user = null, ?string $password = null): self
    {
        return self::fromPdo(Config::pdo($url, $user, $password));
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
