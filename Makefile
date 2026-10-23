DC := docker compose run --rm php

.PHONY: build install update test analyse shell

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

shell:
	$(DC) bash
