<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Support;

use Oficina\Auth\Repository\Customer;
use Oficina\Auth\Repository\CustomerRepositoryInterface;
use Oficina\Auth\Repository\OsUnavailableException;
use Oficina\Auth\Repository\RepositoryException;

/**
 * Repositorio de cliente em memoria. Substitui o servico de OS nos testes dos handlers.
 */
final class FakeCustomerRepository implements CustomerRepositoryInterface
{
    /** @var array<string, Customer> */
    private array $rows = [];

    private bool $shouldFail = false;

    private bool $unavailable = false;

    public ?string $lastDocument = null;

    public function with(string $document, Customer $customer): self
    {
        $this->rows[$document] = $customer;

        return $this;
    }

    public function failing(): self
    {
        $this->shouldFail = true;

        return $this;
    }

    public function unavailable(): self
    {
        $this->unavailable = true;

        return $this;
    }

    public function findByDocument(string $document): ?Customer
    {
        $this->lastDocument = $document;

        if ($this->unavailable) {
            throw new OsUnavailableException('O serviço de OS não respondeu.');
        }

        if ($this->shouldFail) {
            throw new RepositoryException('Falha ao consultar o cliente.');
        }

        return $this->rows[$document] ?? null;
    }
}
