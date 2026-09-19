.PHONY: up down build shell test lint

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell:
	docker compose exec php-fpm sh

test:
	docker compose exec php-fpm vendor/bin/phpunit

lint:
	docker compose exec php-fpm vendor/bin/phpstan analyse
	docker compose exec php-fpm vendor/bin/php-cs-fixer fix
