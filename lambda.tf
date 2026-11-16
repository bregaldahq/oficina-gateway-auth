# ---------------------------------------------------------------------------
# Log groups criados explicitamente (e nao pela primeira invocacao) para que a
# retencao seja gerenciada e o IAM possa ser escopado ao ARN exato.
# ---------------------------------------------------------------------------

resource "aws_cloudwatch_log_group" "auth" {
  name              = "/aws/lambda/${local.auth_function_name}"
  retention_in_days = var.log_retention_days
}

resource "aws_cloudwatch_log_group" "authorizer" {
  name              = "/aws/lambda/${local.authorizer_function_name}"
  retention_in_days = var.log_retention_days
}

# ---------------------------------------------------------------------------
# auth-cpf — DENTRO da VPC, nas subnets privadas, com SG proprio: sai na 80 para o NLB
# interno (rota interna do servico de OS) e na 443 para o endpoint do Secrets Manager.
# Fase 4 (docs/fase-4/CONTRATOS.md §1 e §7, no oficina-api): nao usa mais o SG db-client.
# ---------------------------------------------------------------------------

resource "aws_security_group" "auth" {
  name        = "${local.auth_function_name}-sg"
  description = "Lambda auth-cpf: NLB interno (80) e endpoints da VPC (443)"
  vpc_id      = nonsensitive(data.aws_ssm_parameter.vpc_id.value)

  tags = { Name = "${local.auth_function_name}-sg" }
}

resource "aws_vpc_security_group_egress_rule" "auth_to_nlb" {
  security_group_id = aws_security_group.auth.id
  description       = "Servico de OS pelo NLB interno"
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
  cidr_ipv4         = nonsensitive(data.aws_ssm_parameter.vpc_cidr.value)
}

resource "aws_vpc_security_group_egress_rule" "auth_to_endpoints" {
  security_group_id = aws_security_group.auth.id
  description       = "Endpoint do Secrets Manager"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
  cidr_ipv4         = nonsensitive(data.aws_ssm_parameter.vpc_cidr.value)
}

resource "aws_lambda_function" "auth" {
  function_name = local.auth_function_name
  role          = aws_iam_role.auth.arn

  # `provided.al2` + layer Bref: o runtime PHP vem do layer, nao da AWS.
  runtime = "provided.al2"
  handler = var.auth_handler
  layers  = concat([local.bref_php_layer_arn], local.extra_layers)

  filename         = var.lambda_package_path
  source_code_hash = try(filebase64sha256(var.lambda_package_path), null)

  memory_size = var.auth_memory_size
  timeout     = var.auth_timeout

  vpc_config {
    subnet_ids         = local.private_subnet_ids
    security_group_ids = [aws_security_group.auth.id]
  }

  environment {
    variables = merge(
      {
        APP_ENV        = var.environment
        AUTH_SECRET_ID = local.auth_secret_arn
        OS_BASE_URL    = local.os_base_url
      },
      local.newrelic_env
    )
  }

  # As regras de saida antes da funcao: um SG novo nasce sem egress, e a Lambda sem elas
  # responderia 503 (NLB) ou 500 (Secrets Manager) no smoke do deploy.
  depends_on = [
    aws_cloudwatch_log_group.auth,
    aws_iam_role_policy_attachment.auth_vpc_access,
    aws_vpc_security_group_egress_rule.auth_to_nlb,
    aws_vpc_security_group_egress_rule.auth_to_endpoints,
  ]
}

# ---------------------------------------------------------------------------
# jwt-authorizer — FORA da VPC de proposito: nao toca no banco, e manter a
# funcao fora da VPC evita ENI/cold start extra no caminho de TODA requisicao.
# ---------------------------------------------------------------------------

resource "aws_lambda_function" "authorizer" {
  function_name = local.authorizer_function_name
  role          = aws_iam_role.authorizer.arn

  runtime = "provided.al2"
  handler = var.authorizer_handler
  layers  = concat([local.bref_php_layer_arn], local.extra_layers)

  filename         = var.lambda_package_path
  source_code_hash = try(filebase64sha256(var.lambda_package_path), null)

  memory_size = var.authorizer_memory_size
  timeout     = var.authorizer_timeout

  environment {
    variables = merge(
      {
        APP_ENV        = var.environment
        AUTH_SECRET_ID = local.auth_secret_arn
      },
      local.newrelic_env
    )
  }

  depends_on = [aws_cloudwatch_log_group.authorizer]
}
