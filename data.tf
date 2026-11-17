# Acoplamento entre repositorios e' SOMENTE por SSM (secao 2 dos Contratos).
# Nada de `terraform_remote_state` apontando para o estado de outro repo.

data "aws_caller_identity" "current" {}

data "aws_ssm_parameter" "vpc_id" {
  name = "/oficina/${var.environment}/network/vpc_id"
}

data "aws_ssm_parameter" "private_subnet_ids" {
  name = "/oficina/${var.environment}/network/private_subnet_ids"
}

# Fase 4 (docs/fase-4/CONTRATOS.md §7, no oficina-api): a auth-cpf chama a rota interna do
# servico de OS pelo NLB interno, e nao le mais o banco (nem o segredo nem o SG db-client).
data "aws_ssm_parameter" "nlb_dns_name" {
  name = "/oficina/${var.environment}/nlb/dns_name"
}

data "aws_ssm_parameter" "auth_secret_arn" {
  name = "/oficina/${var.environment}/auth/secret_arn"
}

# Listener :80 do NLB interno publicado pelo repo de k8s — alvo da integracao
# HTTP_PROXY via VPC Link.
# Fase 4 (docs/fase-4/CONTRATOS.md §2 e §4, no oficina-api): um listener por microsserviço.
data "aws_ssm_parameter" "nlb_service_listener_arn" {
  for_each = toset(["faturamento", "execucao"])
  name     = "/oficina/${var.environment}/nlb/${each.key}/listener_arn"
}

data "aws_ssm_parameter" "nlb_listener_arn" {
  name = "/oficina/${var.environment}/nlb/listener_arn"
}

data "aws_ssm_parameter" "vpc_cidr" {
  name = "/oficina/${var.environment}/network/vpc_cidr"
}
