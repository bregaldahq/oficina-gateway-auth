<?php

declare(strict_types=1);

/**
 * Amarracao fina com o Bref para a Lambda `auth-cpf`.
 *
 * Toda a regra vive em `Oficina\Auth\Handler\AuthCpfHandler`, que e' uma classe pura
 * e testavel sem o Bref instalado. Este arquivo so' monta as dependencias reais
 * (Secrets Manager e o cliente HTTP do serviço de OS) e delega.
 */

use Oficina\Auth\Handler\AuthCpfHandler;
use Oficina\Auth\Repository\HttpCustomerRepository;
use Oficina\Auth\Secrets\SecretsManagerProvider;

require __DIR__ . '/vendor/autoload.php';

$region       = getenv('AWS_REGION') ?: 'us-east-1';
$authSecretId = getenv('AUTH_SECRET_ID') ?: '';
// O NLB interno, porta 80 (serviço de OS). Fase 4: o cliente vem da rota interna do OS.
$osBaseUrl    = getenv('OS_BASE_URL') ?: '';

$secrets    = new SecretsManagerProvider($region);
$repository = new HttpCustomerRepository($osBaseUrl, $secrets, $authSecretId);
$handler    = new AuthCpfHandler($repository, $secrets, $authSecretId);

return static fn (array $event): array => $handler->handle($event);
