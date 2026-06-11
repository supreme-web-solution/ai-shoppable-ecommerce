#!/bin/bash
set -euo pipefail

# Laravel Forge zero-downtime deploy (paste into Forge Deploy Script).
# Do NOT use "php artisan optimize" alone — it runs route:cache and will fail
# if duplicate route names exist. This script matches Forge's $VARIABLES.

cd "$FORGE_RELEASE_DIRECTORY"

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$FORGE_PHP artisan route:clear
# Rebuild config cache so new Forge Environment vars (e.g. DAILY_API_KEY) are picked up.
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan event:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan route:cache

$FORGE_PHP artisan storage:link || true
$FORGE_PHP artisan migrate --force
