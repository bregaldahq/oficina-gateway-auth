<?php

declare(strict_types=1);

namespace Oficina\Auth\Tests\Unit;

use Oficina\Auth\Repository\HttpCustomerRepository;
use Oficina\Auth\Repository\OsUnavailableException;
use Oficina\Auth\Repository\RepositoryException;
use Oficina\Auth\Secrets\InMemorySecretsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A consulta do cliente no serviço de OS pela rota interna (docs/fase-4/CONTRATOS.md §7, no
 * oficina-api), com o transporte HTTP trocado por um falso.
 */
final class HttpCustomerRepositoryTest extends TestCase
{
    private const SECRET_ID = 'oficina/test/auth';

    /** @var list<array{string, array<string, string>}> */
    private array $calls = [];

    /**
     * @param array{0: int, 1: string}|RuntimeException $response
     * @param array<string, mixed>|null                $secret
     */
    private function repository(array|RuntimeException $response, string $baseUrl = 'http://nlb.interno/', ?array $secret = null): HttpCustomerRepository
    {
        return new HttpCustomerRepository(
            $baseUrl,
            new InMemorySecretsProvider([self::SECRET_ID => $secret ?? ['INTERNAL_TOKEN' => 'tok-interno']]),
            self::SECRET_ID,
            function (string $url, array $headers) use ($response): array {
                $this->calls[] = [$url, $headers];
                if ($response instanceof RuntimeException) {
                    throw $response;
                }

                return $response;
            },
        );
    }

    public function testDevolveOClienteEMandaOTokenInterno(): void
    {
        $body = json_encode(['id' => 'cli-1', 'name' => 'Maria', 'document' => '52998224725', 'status' => 'ACTIVE']);

        $customer = $this->repository([200, (string)$body])->findByDocument('52998224725');

        self::assertNotNull($customer);
        self::assertSame(['cli-1', 'Maria', true], [$customer->id, $customer->name, $customer->isActive()]);
        self::assertSame('http://nlb.interno/internal/customers/by-document/52998224725', $this->calls[0][0]);
        self::assertSame('tok-interno', $this->calls[0][1]['X-Internal-Token']);
    }

    public function testNaoCadastradoEhNull(): void
    {
        self::assertNull($this->repository([404, '{"error":"Cliente não encontrado."}'])->findByDocument('52998224725'));
    }

    /** @return iterable<string, array{array{0: int, 1: string}|RuntimeException}> */
    public static function indisponivel(): iterable
    {
        yield 'sem resposta'     => [new RuntimeException('connection refused')];
        yield 'status 0'         => [[0, '']];
        yield '502 do NLB'       => [[502, 'Bad Gateway']];
        yield '503 do serviço'   => [[503, '{"error":"x"}']];
    }

    /** @param array{0: int, 1: string}|RuntimeException $response */
    #[DataProvider('indisponivel')]
    public function testOsForaDoArViraIndisponivel(array|RuntimeException $response): void
    {
        $this->expectException(OsUnavailableException::class);
        $this->repository($response)->findByDocument('52998224725');
    }

    /** @return iterable<string, array{array{0: int, 1: string}}> */
    public static function defeitoDeConfiguracao(): iterable
    {
        yield 'token trocado'         => [[401, '{"error":"Token interno inválido."}']];
        yield 'OS sem a rota interna' => [[404, '{"error":"Rota não encontrada."}']];
        yield 'corpo que não é JSON'  => [[200, '<html>']];
        yield 'sem o status no corpo' => [[200, '{"id":"cli-1","name":"Maria"}']];
    }

    /** @param array{0: int, 1: string} $response */
    #[DataProvider('defeitoDeConfiguracao')]
    public function testRespostaForaDoContratoEhErroInternoNaoIndisponibilidade(array $response): void
    {
        try {
            $this->repository($response)->findByDocument('52998224725');
            self::fail('deveria ter lançado');
        } catch (RepositoryException $e) {
            self::assertNotInstanceOf(OsUnavailableException::class, $e);
        }
    }

    public function testSemUrlOuSemTokenNemChamaORede(): void
    {
        foreach ([['', null], ['http://nlb', ['JWT_SECRET' => 'x']]] as [$url, $secret]) {
            try {
                $this->repository([200, '{}'], $url, $secret)->findByDocument('52998224725');
                self::fail('deveria ter lançado');
            } catch (RepositoryException $e) {
                self::assertNotInstanceOf(OsUnavailableException::class, $e);
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTransportePadraoSemServidorViraIndisponivel(): void
    {
        $repository = new HttpCustomerRepository(
            'http://127.0.0.1:1',
            new InMemorySecretsProvider([self::SECRET_ID => ['INTERNAL_TOKEN' => 'tok']]),
            self::SECRET_ID,
            timeoutSeconds: 1.0,
        );

        $this->expectException(OsUnavailableException::class);
        $repository->findByDocument('52998224725');
    }
}
