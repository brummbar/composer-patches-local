# Development

```
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm -e COMPOSER_BINARY=composer-2.9 php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
```

Set `KEEP_FIXTURES=1` to keep the temporary test projects.
