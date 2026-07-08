<?php

declare(strict_types=1);

namespace Tests\Support;

trait CallsProtectedMethods
{
    /** Invoke a protected/private method on $object via Reflection. */
    private function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }
}
