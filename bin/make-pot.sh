#!/usr/bin/env bash
# Regenerates the translation template from PHP and the TypeScript UI.
set -euo pipefail

cd "$( dirname "$0" )/.."

npx wp-env run cli --env-cwd=wp-content/plugins/database-search-replace \
	wp i18n make-pot . languages/database-search-replace.pot \
	--slug=database-search-replace --domain=database-search-replace \
	--exclude=vendor,node_modules,tests,build,dist,docs,reference,test-mocks,bin

node bin/extract-js-strings.mjs
