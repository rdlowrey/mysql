<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Socket\ConnectContext;
use Amp\Sql\SqlConfig;

final class MysqlConfig extends SqlConfig
{
    public const DEFAULT_PORT = 3306;
    public const BIN_CHARSET = 45; // utf8mb4_general_ci

    /** @var array<non-empty-string, non-empty-string> Psalm has a bug with the spread operator. */
    public const KEY_MAP = [
        ...parent::KEY_MAP,
        'compress' => 'compression',
        'useCompression' => 'compression',
        'cs' => 'charset',
        'localInfile' => 'local-infile',
    ];

    public const DEFAULT_CHARSET = "utf8mb4";
    public const DEFAULT_COLLATE = "utf8mb4_general_ci";

    private bool $useCompression;

    private bool $useLocalInfile;

    private ConnectContext $context;

    private string $charset;

    private string $collate;

    /* @var string private key to use for sha256_password auth method */
    private string $key;

    public static function fromString(string $connectionString, ConnectContext $context = null): self
    {
        /** @psalm-suppress InvalidArgument Psalm does not recognize {@see self::KEY_MAP} is in fact a map. */
        $parts = self::parseConnectionString($connectionString, self::KEY_MAP);

        if (!isset($parts['host'])) {
            throw new \Error('Host must be provided in connection string');
        }

        return new self(
            host: $parts['host'],
            port: (int) ($parts['port'] ?? self::DEFAULT_PORT),
            user: $parts['user'] ?? null,
            password: $parts['password'] ?? null,
            database: $parts['db'] ?? null,
            context: $context,
            charset: $parts['charset'] ?? self::DEFAULT_CHARSET,
            collate: $parts['collate'] ?? self::DEFAULT_COLLATE,
            useCompression: ($parts['compression'] ?? '') === 'on',
            useLocalInfile: ($parts['local-infile'] ?? '') === 'on'
        );
    }

    public static function fromAuthority(
        string $authority,
        string $user,
        string $password,
        ?string $database = null,
        ?ConnectContext $context = null
    ): self {
        [$host, $port] = \explode(':', $authority, 2) + ['', (string) self::DEFAULT_PORT];

        return new self(
            host: $host,
            port: (int) $port,
            user: $user,
            password: $password,
            database: $database,
            context: $context,
        );
    }

    public function __construct(
        string $host,
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        ?ConnectContext $context = null,
        string $charset = self::DEFAULT_CHARSET,
        string $collate = self::DEFAULT_COLLATE,
        bool $useCompression = false,
        string $key = '',
        bool $useLocalInfile = false
    ) {
        parent::__construct($host, $port, $user, $password, $database);

        $this->context = $context ?? (new ConnectContext);
        $this->charset = $charset;
        $this->collate = $collate;
        $this->useCompression = $useCompression;
        $this->key = $key;
        $this->useLocalInfile = $useLocalInfile;
    }

    public function getConnectionString(): string
    {
        return $this->getHost()[0] == "/"
            ? 'unix://' . $this->getHost()
            : 'tcp://' . $this->getHost() . ':' . $this->getPort();
    }

    public function isCompressionEnabled(): bool
    {
        return $this->useCompression;
    }

    public function withCompression(): self
    {
        $new = clone $this;
        $new->useCompression = true;
        return $new;
    }

    public function withoutCompression(): self
    {
        $new = clone $this;
        $new->useCompression = false;
        return $new;
    }

    public function isLocalInfileEnabled(): bool
    {
        return $this->useLocalInfile;
    }

    public function withLocalInfile(): self
    {
        $new = clone $this;
        $new->useLocalInfile = true;
        return $new;
    }

    public function withoutLocalInfile(): self
    {
        $new = clone $this;
        $new->useLocalInfile = false;
        return $new;
    }

    public function getConnectContext(): ConnectContext
    {
        return $this->context;
    }

    public function withConnectContext(ConnectContext $context): self
    {
        $new = clone $this;
        $new->context = $context;
        return $new;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getCollation(): string
    {
        return $this->collate;
    }

    public function withCharset(string $charset, string $collate): self
    {
        $new = clone $this;
        $new->charset = $charset;
        $new->collate = $collate;
        return $new;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function withKey(string $key): self
    {
        $new = clone $this;
        $new->key = $key;
        return $new;
    }
}
