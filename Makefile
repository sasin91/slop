# make test | lint | check
#
# Tests are .phpt files (as in php-src) in a tests/ folder next to the code,
# run by bin/run-tests.php. Without make (e.g. PowerShell):
#   php bin/run-tests.php -q modules/welcome/tests

PHP ?= php
JOBS ?= $(shell nproc 2>/dev/null || echo 4)

.PHONY: check test lint

check: lint test

test:
	$(PHP) bin/run-tests.php -q -j$(JOBS) --show-diff modules/welcome/tests

lint:
	find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 -P$(JOBS) $(PHP) -l > /dev/null
