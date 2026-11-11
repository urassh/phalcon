DC := docker compose run --rm php

PACKAGES := server core http framework

# make test PKG=server のように個別のパッケージだけ回せる
PKG ?= $(PACKAGES)

# 各パッケージのディレクトリで $(1) を実行する
each = $(DC) sh -c 'set -e; for p in $(PKG); do echo "== $$p"; (cd packages/$$p && $(1)); done'

.PHONY: build install update test analyse boundaries smoke shell

build:
	docker compose build

install:
	$(DC) composer install
	$(call each,composer install)

update:
	$(DC) composer update
	$(call each,composer update)

test:
	$(call each,composer test)

analyse:
	$(call each,composer analyse)

boundaries:
	sh scripts/check-boundaries.sh

smoke:
	$(DC) sh scripts/smoke.sh

shell:
	$(DC) bash
