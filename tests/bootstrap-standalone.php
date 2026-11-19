<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Loads Composer (for OCP stubs and PHPUnit) and the app classes under lib/.
 * Does not boot Nextcloud. tests/phpunit.xml still boots the server and is
 * unchanged; run tests/phpunit.standalone.xml for the patches that do not
 * need a server tree.
 */

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
	require_once $vendor;
}

$thirdParty = getenv('NEXTCLOUD_3RDPARTY');
if (is_string($thirdParty) && $thirdParty !== '') {
	$autoload = rtrim($thirdParty, '/') . '/autoload.php';
	if (is_file($autoload)) {
		require_once $autoload;
	}
}

if (interface_exists(\OCP\ISession::class)) {
	require_once __DIR__ . '/doubles/SessionDoubles.php';
}

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\UserOIDC\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$relative = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = dirname(__DIR__) . '/lib/' . $relative . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
