.PHONY: install test stan package fmt validate

# Sem PHP ou Composer na máquina, roda em container: o mesmo PHP 8.2 do CI.
DOCKER   := docker run --rm -u $(shell id -u):$(shell id -g) -e HOME=/tmp -v $(CURDIR):/app -w /app
PHP      := $(if $(shell command -v php),php,$(DOCKER) php:8.2-cli php)
COMPOSER := $(if $(shell command -v composer),composer,$(DOCKER) composer:2.7)

install:
	$(COMPOSER) install

test:
	$(PHP) vendor/bin/phpunit

stan:
	$(PHP) vendor/bin/phpstan analyse --no-progress

package:
	./scripts/build-package.sh

fmt:
	terraform fmt -recursive

validate:
	terraform init -backend=false && terraform validate
