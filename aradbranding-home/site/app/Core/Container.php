<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Small service container: explicit factories, no reflection magic. */
final class Container
{
    private static ?self $instance = null;

    public static function setInstance(self $c): void
    {
        self::$instance = $c;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];

    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|object
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        throw new RuntimeException("Service not registered: {$id}");
    }

    /** Controllers and middleware receive the container in their constructor. */
    public function make(string $class): object
    {
        if ($this->has($class)) {
            return $this->get($class);
        }
        if (!class_exists($class)) {
            throw new RuntimeException("Class not found: {$class}");
        }
        return new $class($this);
    }
}
