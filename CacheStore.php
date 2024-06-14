<?php declare(strict_types=1);

namespace Salient\Cache;

use Salient\Contract\Cache\CacheStoreInterface;
use Salient\Core\AbstractStore;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use SQLite3Result;

/**
 * A PSR-16 key-value store backed by a SQLite database
 *
 * @api
 */
final class CacheStore extends AbstractStore implements CacheStoreInterface
{
    private ?int $Now = null;

    /**
     * Creates a new CacheStore object
     */
    public function __construct(string $filename = ':memory:')
    {
        $this->assertCanUpsert();

        $this->openDb(
            $filename,
            <<<SQL
CREATE TABLE IF NOT EXISTS
  _cache_item (
    item_key TEXT NOT NULL PRIMARY KEY,
    item_value BLOB,
    expires_at DATETIME,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) WITHOUT ROWID;

CREATE TRIGGER IF NOT EXISTS _cache_item_update AFTER
UPDATE ON _cache_item BEGIN
UPDATE _cache_item
SET
  set_at = CURRENT_TIMESTAMP
WHERE
  item_key = NEW.item_key;
END;
SQL
        );
    }

    /** @internal */
    protected function __clone() {}

    /**
     * @inheritDoc
     */
    public function set($key, $value, $ttl = null): bool
    {
        if ($ttl === null) {
            $expires = null;
        } elseif ($ttl instanceof DateInterval) {
            $expires = (new DateTimeImmutable())->add($ttl)->getTimestamp();
        } elseif ($ttl instanceof DateTimeInterface) {
            $expires = $ttl->getTimestamp();
        } elseif ($ttl > 0) {
            $expires = time() + $ttl;
        } else {
            return $this->delete($key);
        }

        $sql = <<<SQL
INSERT INTO
  _cache_item (item_key, item_value, expires_at)
VALUES
  (
    :item_key,
    :item_value,
    DATETIME(:expires_at, 'unixepoch')
  )
ON CONFLICT (item_key) DO
UPDATE
SET
  item_value = excluded.item_value,
  expires_at = excluded.expires_at
WHERE
  item_value IS NOT excluded.item_value
  OR expires_at IS NOT excluded.expires_at;
SQL;
        $stmt = $this->prepare($sql);
        $stmt->bindValue(':item_key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':item_value', serialize($value), \SQLITE3_BLOB);
        $stmt->bindValue(':expires_at', $expires, \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * @phpstan-impure
     */
    public function has($key, ?int $maxAge = null): bool
    {
        $sql = <<<SQL
SELECT
  COUNT(*)
FROM
  _cache_item
SQL;
        $result = $this->queryItems($sql, $key, $maxAge);
        /** @var array{int} */
        $row = $result->fetchArray(\SQLITE3_NUM);

        return (bool) $row[0];
    }

    /**
     * @phpstan-impure
     */
    public function get($key, $default = null, ?int $maxAge = null)
    {
        $sql = <<<SQL
SELECT
  item_value
FROM
  _cache_item
SQL;
        $result = $this->queryItems($sql, $key, $maxAge);
        $row = $result->fetchArray(\SQLITE3_NUM);

        return $row === false
            ? $default
            : unserialize($row[0]);
    }

    /**
     * @phpstan-impure
     */
    public function getInstanceOf($key, string $class, ?object $default = null, ?int $maxAge = null): ?object
    {
        $store = $this->maybeAsOfNow();
        if (!$store->has($key, $maxAge)) {
            return $default;
        }
        $item = $store->get($key, $default, $maxAge);
        if (!is_object($item) || !is_a($item, $class)) {
            return $default;
        }
        return $item;
    }

    /**
     * @phpstan-impure
     */
    public function getArray($key, ?array $default = null, ?int $maxAge = null): ?array
    {
        $store = $this->maybeAsOfNow();
        if (!$store->has($key, $maxAge)) {
            return $default;
        }
        $item = $store->get($key, $default, $maxAge);
        if (!is_array($item)) {
            return $default;
        }
        return $item;
    }

    /**
     * @phpstan-impure
     */
    public function getInt($key, ?int $default = null, ?int $maxAge = null): ?int
    {
        $store = $this->maybeAsOfNow();
        if (!$store->has($key, $maxAge)) {
            return $default;
        }
        $item = $store->get($key, $default, $maxAge);
        if (!is_int($item)) {
            return $default;
        }
        return $item;
    }

    /**
     * @phpstan-impure
     */
    public function getString($key, ?string $default = null, ?int $maxAge = null): ?string
    {
        $store = $this->maybeAsOfNow();
        if (!$store->has($key, $maxAge)) {
            return $default;
        }
        $item = $store->get($key, $default, $maxAge);
        if (!is_string($item)) {
            return $default;
        }
        return $item;
    }

    /**
     * @inheritDoc
     */
    public function delete($key): bool
    {
        $sql = <<<SQL
DELETE FROM _cache_item
WHERE
  item_key = :item_key;
SQL;
        $stmt = $this->prepare($sql);
        $stmt->bindValue(':item_key', $key, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        $this->db()->exec(
            <<<SQL
DELETE FROM _cache_item;
SQL
        );

        return true;
    }

    /**
     * Delete expired items
     *
     * @return true
     */
    public function clearExpired(): bool
    {
        $sql = <<<SQL
DELETE FROM _cache_item
WHERE
  expires_at <= DATETIME(:now, 'unixepoch');
SQL;
        $stmt = $this->prepare($sql);
        $stmt->bindValue(':now', $this->now(), \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMultiple($keys, $default = null, ?int $maxAge = null)
    {
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default, $maxAge);
        }
        return $values ?? [];
    }

    /**
     * @inheritDoc
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * @phpstan-impure
     */
    public function getItemCount(?int $maxAge = null): int
    {
        $sql = <<<SQL
SELECT
  COUNT(*)
FROM
  _cache_item
SQL;
        $result = $this->queryItems($sql, null, $maxAge);
        /** @var array{int} */
        $row = $result->fetchArray(\SQLITE3_NUM);

        return $row[0];
    }

    /**
     * @phpstan-impure
     */
    public function getAllKeys(?int $maxAge = null): array
    {
        $sql = <<<SQL
SELECT
  item_key
FROM
  _cache_item
SQL;
        $result = $this->queryItems($sql, null, $maxAge);
        while (($row = $result->fetchArray(\SQLITE3_NUM)) !== false) {
            $keys[] = $row[0];
        }

        return $keys ?? [];
    }

    /**
     * @inheritDoc
     */
    public function asOfNow(?int $now = null): CacheStoreInterface
    {
        if ($this->Now !== null) {
            throw new LogicException(
                sprintf('Calls to %s() cannot be nested', __METHOD__)
            );
        }

        if ($this->hasTransaction()) {
            throw new LogicException(sprintf(
                '%s() cannot be called until the instance returned previously is closed or discarded',
                __METHOD__,
            ));
        }

        $clone = clone $this;
        $clone->Now = $now ?? time();
        return $clone->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    public function close()
    {
        if (!$this->isOpen()) {
            return $this->closeDb();
        }

        if ($this->Now !== null) {
            return $this->commitTransaction()->detachDb();
        }

        $this->clearExpired();

        return $this->closeDb();
    }

    /**
     * @internal
     */
    public function __destruct()
    {
        $this->close();
    }

    private function now(): int
    {
        return $this->Now ?? time();
    }

    /**
     * @return static
     */
    private function maybeAsOfNow(): self
    {
        return $this->Now === null
            ? $this->asOfNow()
            : $this;
    }

    private function queryItems(string $sql, ?string $key, ?int $maxAge): SQLite3Result
    {
        $where = [];
        $bind = [];

        if ($key !== null) {
            $where[] = 'item_key = :item_key';
            $bind[] = [':item_key', $key, \SQLITE3_TEXT];
        }

        $bindNow = false;
        if ($maxAge === null) {
            $where[] = "(expires_at IS NULL OR expires_at > DATETIME(:now, 'unixepoch'))";
            $bindNow = true;
        } elseif ($maxAge) {
            $where[] = "DATETIME(set_at, :max_age) > DATETIME(:now, 'unixepoch')";
            $bind[] = [':max_age', "+$maxAge seconds", \SQLITE3_TEXT];
            $bindNow = true;
        }
        if ($bindNow) {
            $bind[] = [':now', $this->now(), \SQLITE3_INTEGER];
        }

        $where = implode(' AND ', $where);
        if ($where !== '') {
            $sql .= " WHERE $where";
        }

        $stmt = $this->prepare($sql);
        foreach ($bind as [$param, $value, $type]) {
            $stmt->bindValue($param, $value, $type);
        }

        return $this->execute($stmt);
    }
}
