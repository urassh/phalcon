DC := docker compose run --rm php

.PHONY: build install update test analyse smoke shell

build:
	docker compose build

install:
	$(DC) composer install

update:
	$(DC) composer update

test:
	$(DC) composer test

analyse:
	$(DC) composer analyse

smoke:
	$(DC) sh scripts/smoke.sh

shell:
	$(DC) bash
