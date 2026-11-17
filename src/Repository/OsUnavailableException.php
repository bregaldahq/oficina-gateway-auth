<?php

declare(strict_types=1);

namespace Oficina\Auth\Repository;

/**
 * O serviço de OS não respondeu, ou respondeu com erro do lado dele: o `POST /auth/cpf`
 * devolve 503 (docs/fase-4/CONTRATOS.md §7, no oficina-api).
 */
final class OsUnavailableException extends RepositoryException
{
}
