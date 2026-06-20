<?php

declare(strict_types=1);

// Minimal bootstrap for standalone unit tests of services that don't depend on Nextcloud runtime.
// When running inside a full Nextcloud dev env, the real bootstrap from tests/lib will override.

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../vendor/autoload.php';
