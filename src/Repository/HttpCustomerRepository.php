<?php

declare(strict_types=1);

namespace Oficina\Auth\Repository;

use Oficina\Auth\Secrets\SecretsProviderInterface;
use Throwable;

/**
 * Consulta o cliente no serviço de OS, pela rota interna
 * `GET /internal/customers/by-document/{cpf}` com o header `X-Internal-Token`
 * (docs/fase-4/CONTRATOS.md §7, no oficina-api). Na Fase 4 a Lambda não lê mais o banco
 * do OS: ela chama o NLB interno, de dentro da VPC.
 *
 * - 200: o cliente;
 * - 404 com `{"error":"Cliente não encontrado."}`: não cadastrado (`null`). Outro 404 (a rota
 *   não existe: OS antigo, sem a rota interna) é defeito de implantação, não cliente ausente;
 * - sem resposta, ou 5xx: `OsUnavailableException` (o handler devolve 503);
 * - qualquer outra coisa (401 de token trocado, corpo fora do contrato): `RepositoryException`
 *   (500), porque é defeito de configuração, não indisponibilidade.
 */
final class HttpCustomerRepository implements CustomerRepositoryInterface
{
    private const PATH = '/internal/customers/by-document/';

    /** O corpo do 404 da rota interna quando o CPF não está cadastrado (InternalCustomerController). */
    private const NOT_FOUND = 'Cliente não encontrado.';

    /** @var callable(string, array<string, string>): array{0: int, 1: string} */
    private $transport;

    /** @param (callable(string, array<string, string>): array{0: int, 1: string})|null $transport */
    public function __construct(
        private readonly string $baseUrl,
        private readonly SecretsProviderInterface $secrets,
        private readonly string $authSecretId,
        ?callable $transport = null,
        float $timeoutSeconds = 3.0,
    ) {
        $this->transport = $transport ?? self::streamTransport($timeoutSeconds);
    }

    public function findByDocument(string $document): ?Customer
    {
        if ($this->baseUrl === '') {
            throw new RepositoryException('OS_BASE_URL não configurada.');
        }

        $token = $this->secrets->get($this->authSecretId)['INTERNAL_TOKEN'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RepositoryException('INTERNAL_TOKEN ausente do segredo de auth.');
        }

        $url = rtrim($this->baseUrl, '/') . self::PATH . rawurlencode($document);

        try {
            [$status, $body] = ($this->transport)($url, ['X-Internal-Token' => $token, 'Accept' => 'application/json']);
        } catch (Throwable $e) {
            throw new OsUnavailableException('O serviço de OS não respondeu.', 0, $e);
        }

        if ($status === 404) {
            /** @var mixed $data */
            $data = json_decode($body, true);
            if (is_array($data) && ($data['error'] ?? null) === self::NOT_FOUND) {
                return null;
            }

            throw new RepositoryException('Rota interna de cliente não encontrada no serviço de OS.');
        }
        if ($status === 0 || $status >= 500) {
            throw new OsUnavailableException("O serviço de OS respondeu {$status}.");
        }
        if ($status !== 200) {
            throw new RepositoryException("Consulta interna de cliente recusada: {$status}.");
        }

        /** @var mixed $data */
        $data = json_decode($body, true);
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['name'] ?? null) || !is_string($data['status'] ?? null)) {
            throw new RepositoryException('Resposta da consulta interna fora do contrato.');
        }

        return new Customer($data['id'], $data['name'], $data['status']);
    }

    /** @return callable(string, array<string, string>): array{0: int, 1: string} */
    private static function streamTransport(float $timeoutSeconds): callable
    {
        return static function (string $url, array $headers) use ($timeoutSeconds): array {
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = "{$name}: {$value}";
            }
            $context = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $lines),
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true, // 4xx e 5xx também devolvem o corpo e o status
            ]]);

            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                throw new \RuntimeException("Sem resposta de {$url}.");
            }

            /** @var list<string> $responseHeaders */
            $responseHeaders = $http_response_header;
            $status          = 0;
            foreach ($responseHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int)$m[1]; // o último vale, se houve redirecionamento
                }
            }

            return [$status, $body];
        };
    }
}
