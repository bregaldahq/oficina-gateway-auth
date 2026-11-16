<?php

declare(strict_types=1);

namespace Oficina\Auth\Repository;

/**
 * O mínimo do cliente que o token precisa, como a rota interna do serviço de OS devolve
 * (`id`, `name`, `status`).
 */
final class Customer
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
