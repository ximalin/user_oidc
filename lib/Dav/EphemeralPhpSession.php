<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCP\IRequest;

/**
 * Removes the PHP session a cookieless DAV bearer request just created.
 *
 * OC::initSession() runs before any app code and always starts a PHP session
 * (lib/OC.php). The block that returned early for an Authorization header on
 * /remote.php/dav is commented out: CalDAV clients such as DAVx5 broke when
 * it was enabled (nextcloud/server issue 37277). session_write_close() at the
 * end of initSession releases the lock; it does not delete the file.
 * Swapping in OC\Session\Memory afterwards cannot erase that file either, and
 * Memory is a private server class.
 *
 * A client that drops Set-Cookie therefore leaves one sess_* (or one redis
 * key) per request. This object deletes that session at the end of the
 * request when the client did not send a session cookie. A cookie that was
 * already on the request is left alone: it may be a browser login.
 *
 * Flags are process-wide. The DAV listener and the bearer backend must agree
 * even if the container builds two objects.
 */
class EphemeralPhpSession {
	private static bool $discard = false;

	private static bool $shutdownRegistered = false;

	/** @var list<callable(): void> */
	private static array $beforeDiscard = [];

	public static function resetState(): void {
		self::$discard = false;
		self::$beforeDiscard = [];
	}

	public function armIfStatelessBearer(IRequest $request): void {
		$header = $request->getHeader('Authorization');
		$name = session_name();
		$cookie = ($name !== '') ? $request->getCookie($name) : null;
		self::$discard = self::shouldDiscard(
			self::isBearerAuthorization($header),
			is_string($cookie) && $cookie !== '',
		);
		if (self::$discard) {
			$this->registerShutdown();
		}
	}

	public function isArmed(): bool {
		return self::$discard;
	}

	/**
	 * Runs at the end of the request, before the session storage is removed.
	 * Used when user_id had to be written to the real session and must be
	 * cleared before that session is destroyed.
	 */
	public function runBeforeDiscard(callable $callback): void {
		self::$beforeDiscard[] = $callback;
		$this->registerShutdown();
	}

	public static function isBearerAuthorization(string $header): bool {
		return preg_match('/^bearer\s+\S/i', $header) === 1;
	}

	public static function shouldDiscard(bool $isBearer, bool $requestBroughtSessionCookie): bool {
		return $isBearer && !$requestBroughtSessionCookie;
	}

	/**
	 * Resolves the files-handler path. Returns null when the id is not a safe
	 * session id or the configured directory depth is not one this code understands.
	 * $savePath follows PHP's "N;/path" form; an empty path uses $defaultDir.
	 */
	public static function sessionFile(string $sessionId, string $savePath, string $defaultDir): ?string {
		if (preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) !== 1) {
			return null;
		}
		$levels = 0;
		$dir = $savePath;
		if (str_contains($savePath, ';')) {
			$parts = explode(';', $savePath);
			$dir = (string)array_pop($parts);
			if (isset($parts[0]) && preg_match('/^\d+$/', $parts[0]) === 1) {
				$levels = (int)$parts[0];
			} else {
				return null;
			}
		}
		if ($levels < 0 || $levels > 3 || $levels > strlen($sessionId)) {
			return null;
		}
		if ($dir === '') {
			$dir = $defaultDir;
		}
		$path = rtrim($dir, '/');
		for ($i = 0; $i < $levels; $i++) {
			$path .= '/' . $sessionId[$i];
		}
		return $path . '/sess_' . $sessionId;
	}

	public function onShutdown(): void {
		$callbacks = self::$beforeDiscard;
		self::$beforeDiscard = [];
		foreach ($callbacks as $callback) {
			$callback();
		}
		if (!self::$discard) {
			return;
		}
		self::$discard = false;
		$this->discardNow();
	}

	/**
	 * Closes the session and deletes what this request created.
	 * For the files handler the file is unlinked after write_close, because
	 * session_destroy() deletes the storage and the following write_close
	 * creates it again. Other handlers get session_destroy().
	 */
	public function discardNow(): bool {
		if (session_status() === PHP_SESSION_DISABLED) {
			return false;
		}
		$id = session_id();
		if (!is_string($id) || $id === '') {
			return false;
		}
		$handler = (string)ini_get('session.save_handler');
		$file = null;
		if ($handler === 'files' || $handler === '') {
			$file = self::sessionFile($id, (string)ini_get('session.save_path'), sys_get_temp_dir());
		}

		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION = [];
			session_write_close();
		}

		if ($file !== null) {
			if (is_file($file)) {
				return unlink($file);
			}
			return true;
		}

		if (!@session_start([
			'use_cookies' => false,
			'use_only_cookies' => true,
			'cache_limiter' => '',
		])) {
			return false;
		}
		$_SESSION = [];
		$destroyed = session_destroy();
		session_write_close();
		return $destroyed;
	}

	private function registerShutdown(): void {
		if (self::$shutdownRegistered) {
			return;
		}
		self::$shutdownRegistered = true;
		register_shutdown_function(static function (): void {
			(new self())->onShutdown();
		});
	}
}
