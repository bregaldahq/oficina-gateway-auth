# oficina-gateway-auth — guia para agentes

Borda do sistema Oficina Mecânica: o API Gateway (HTTP API), o VPC Link e as duas Lambdas, a
`auth-cpf` (emite o JWT do cliente) e a `jwt-authorizer` (valida o JWT em toda rota protegida).
PHP 8.2 no runtime **Bref** (layer `php-82`) mais Terraform. Aplicado depois do cluster.

O sistema tem quatro repos; o mapa, as regras entre eles e as ferramentas (`bin/ws`) ficam no
repositório principal `oficina-mecanica`. O contrato entre os repos é o
`docs/fase-3/CONTRATOS.md` do `oficina-api`. Se ele divergir daqui, **o documento vence**.

## Código (`src/`)

As duas Lambdas recebem o **mesmo zip** (`scripts/build-package.sh` → `build/lambda.zip`); muda
só o `handler`. Quem emite e quem valida o token rodam o mesmo `JwtProvider`.

- `Handler/AuthCpfHandler.php`: `POST /auth/cpf`. Valida o CPF pelo dígito verificador antes de
  consultar o cliente, decide entre 200, 400, 403, 404, 500 e 503 (tabela da seção 5 da Fase 3,
  mais o 503 da Fase 4) e emite o JWT com `role=customer`.
- `Handler/JwtAuthorizerHandler.php`: authorizer REQUEST com *simple response*. Valida
  assinatura e `exp` e devolve `context.customerId` e `context.role`.
- `Security/JwtProvider.php`: monta o token **exatamente** como o `JwtProvider` da aplicação. O
  arquivo não é idêntico (namespace, comentários); o que precisa ser igual é o token.
- `Secrets/SecretsManagerProvider.php`: guarda o segredo em cache estático entre invocações. Uma
  rotação só é percebida num container novo.
- `Domain/Cpf.php` replica o value object `Document` da aplicação.
- `Repository/HttpCustomerRepository.php` (Fase 4, `docs/fase-4/CONTRATOS.md` §7 no
  `oficina-api`): `GET /internal/customers/by-document/{cpf}` no serviço de OS, pelo NLB
  interno (`OS_BASE_URL`), com o `INTERNAL_TOKEN` do segredo de auth. Sem resposta ou 5xx vira
  `OsUnavailableException` (503); 401 ou corpo fora do contrato é defeito de configuração (500).
  A Lambda **não lê mais o banco**: não use PDO aqui.
- `handler-auth.php` e `handler-authorizer.php` só montam as dependências. Os handlers são
  classes puras, que recebem um array e devolvem um array, testáveis sem AWS.

## Infra (`*.tf`)

- `lambda.tf`: a `auth-cpf` roda **dentro** da VPC (subnets privadas) com SG próprio, que sai
  na 80 para o NLB interno e na 443 para o endpoint do Secrets Manager. O `jwt-authorizer` roda **fora** da VPC, sem ENI e sem cold start extra.
- `iam.tf`: um role por função. Nenhuma das duas lê o segredo de banco.
- `apigateway.tf`:
  - rotas dedicadas sem authorizer: `POST /auth/cpf` (Lambda), `GET /api/health`,
    `GET /api/ready`, `POST /api/auth/login`, `GET /docs`, `GET /docs/{proxy+}` e
    `GET /swagger.yaml` (as seis últimas vão para o NLB);
  - `ANY /api/{proxy+}` passa pelo `jwt-authorizer` e segue via VPC Link para o NLB.
  - Fase 4 (`docs/fase-4/CONTRATOS.md` §4, no `oficina-api`): um prefixo por microsserviço, cada
    um com integração e listener próprios no NLB (`:80` OS, `:81` Faturamento, `:82` Execução).
    `ANY /api/billing/{proxy+}` e `ANY /api/execution/{proxy+}` passam pelo authorizer; as
    rotas públicas de cada serviço (sondas, docs e os webhooks de aprovação e do Mercado Pago, no
    Faturamento) têm rota dedicada. O SG do VPC Link sai nas portas 80 a 82.
  - As rotas ficam escritas uma a uma, com `route_key` literal: o checker de contratos do
    repositório principal lê o `apigateway.tf` como texto e confere cada rota contra o serviço
    para onde a integração aponta. Rota gerada por `for_each` some para ele.
  - `/internal/*` nunca entra no gateway (o checker acusa `ROTA-5`).
- `newrelic-logs.tf`: logs vão para o New Relic por Kinesis Firehose, não pela extensão de
  Lambda, que falharia em silêncio na VPC sem NAT.
- `ssm.tf`: publica `apigw/endpoint` e `apigw/api_id`.

## Contratos

- **JWT (seção 4):** `tests/Contract/JwtContractTest.php` compara um token gerado com entrada
  fixa contra uma string literal. A aplicação tem o teste espelho, com o **mesmo** literal, e o
  `docs/fase-3/contract-token.md` de lá também. Se o teste quebrar, descubra o que mudou no
  `JwtProvider` e reverta. Nunca atualize o literal só de um lado.
- **Rotas (seção 5):** rota pública da aplicação precisa de rota dedicada sem authorizer aqui.
  Sem o header `Authorization`, o gateway responde 401 antes de chamar o authorizer, então a
  lista `PUBLIC_ROUTES` do authorizer não basta.
- **Consome** (`data.tf`):
  - da fundação: `network/vpc_id`, `network/private_subnet_ids`, `network/vpc_cidr`,
    e `auth/secret_arn`;
  - do cluster: `nlb/listener_arn`, `nlb/faturamento/listener_arn`, `nlb/execucao/listener_arn`
    e `nlb/dns_name`.
- **Publica:** `apigw/endpoint` e `apigw/api_id`.

## Validar

- A partir do repositório principal: `bin/ws verify --repo gateway` (composer, teste de
  contrato, phpunit, phpstan nível 8, `terraform fmt` e `validate`).
- Avulso: `bin/ws exec gateway vendor/bin/phpunit --filter Jwt`.
- O `make test`/`make stan` daqui chamam `vendor/bin/...` direto e **só funcionam com PHP
  local**, que a máquina de desenvolvimento não tem.

O `.terraform.lock.hcl` é **versionado** aqui: se mudar provider, atualize com
`bin/ws exec gateway terraform init -backend=false` e commite o lock. Os ARNs dos layers (Bref,
New Relic) em `envs/*.tfvars` envelhecem: confira a versão antes de mudar.

## Armadilhas

| Sintoma | Causa | O que pega |
|---|---|---|
| Token da Lambda recusado pela app (401) | `JwtProvider` mudou de um lado só (claims, ordem, base64url) | Testes de contrato dos dois repos e `bin/check-contracts.py` (JWT-1/2) |
| Rota pública responde 401 pelo gateway | Falta rota dedicada sem authorizer | `bin/check-contracts.py` (ROTA-1) |
| `auth-cpf` trava até o timeout | Serviço AWS novo chamado sem endpoint na VPC sem NAT | Revisão; endpoint vai no `vpc_endpoints.tf` da fundação |
| `POST /auth/cpf` responde 503 | OS fora do ar, ou SG da Lambda sem saída na 80 | `HttpCustomerRepositoryTest` e o smoke do `deploy.yml` |
| `POST /auth/cpf` responde 500 com o OS no ar | `INTERNAL_TOKEN` diferente entre a Lambda e o OS (cache de segredo antigo) | log `auth.cpf.failed` com `exception_message` 401 |
| Lambda lê chave de segredo que não existe | Chave renomeada no `secrets.tf` da fundação | `bin/check-contracts.py` (SEG-1) |
| `destroy` do gateway parado 20 a 40 min no SG da `auth-cpf` | A ENI da Lambda em VPC demora a ser liberada pela AWS | Esperar; não apague o SG à mão |
| O state "some" | A chave do state foi trocada para o nome novo | `bin/check-contracts.py` (NOME-1); continua `oficina-lambda-auth` |

## Convenções

- Branches `feat/…`, `fix/…`, `chore/…`, `ci/…`, com PR para `develop`.
- Commits em português, sem acento no assunto, Conventional Commits com escopo
  (`fix(gateway): ...`), só com o nome do autor.
- Agente nunca roda `terraform apply`/`destroy` nem lê o valor de um segredo.
