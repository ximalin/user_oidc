<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Dav\EphemeralPhpSession;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class EphemeralPhpSessionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		EphemeralPhpSession::resetState();
	}

	protected function tearDown(): void {
		EphemeralPhpSession::resetState();
		parent::tearDown();
	}

	public function testBearerRecognition(): void {
		$this->assertTrue(EphemeralPhpSession::isBearerAuthorization('Bearer access-token'));
		$this->assertTrue(EphemeralPhpSession::isBearerAuthorization("bearer\ttoken"));
		$this->assertFalse(EphemeralPhpSession::isBearerAuthorization('Basic dXNlcjpwYXNz'));
		$this->assertFalse(EphemeralPhpSession::isBearerAuthorization('Bearer'));
		$this->assertFalse(EphemeralPhpSession::isBearerAuthorization(''));
	}

	public function testDiscardOnlyACookielessBearer(): void {
		$this->assertTrue(EphemeralPhpSession::shouldDiscard(true, false));
		$this->assertFalse(EphemeralPhpSession::shouldDiscard(true, true));
		$this->assertFalse(EphemeralPhpSession::shouldDiscard(false, false));
	}

	public function testSessionFilePaths(): void {
		$this->assertSame('/tmp/sess_abc', EphemeralPhpSession::sessionFile('abc', '/tmp', '/var/tmp'));
		$this->assertSame('/tmp/sess_abc', EphemeralPhpSession::sessionFile('abc', '', '/tmp'));
		$this->assertSame('/tmp/a/b/sess_abcdef', EphemeralPhpSession::sessionFile('abcdef', '2;/tmp', '/var/tmp'));
		$this->assertNull(EphemeralPhpSession::sessionFile('../x', '/tmp', '/tmp'));
		$this->assertNull(EphemeralPhpSession::sessionFile('abc', '9;/tmp', '/tmp'));
		$this->assertNull(EphemeralPhpSession::sessionFile('abc', 'mode;/tmp', '/tmp'));
	}

	public function testArmFollowsTheAuthorizationHeaderAndTheSessionCookie(): void {
		$name = session_name();
		$bearer = $this->request('Bearer access-token', null);
		$session = new EphemeralPhpSession();
		$session->armIfStatelessBearer($bearer);
		$this->assertTrue($session->isArmed());

		$again = new EphemeralPhpSession();
		$again->armIfStatelessBearer($this->request('Bearer access-token', 'existing-id'));
		$this->assertFalse($again->isArmed());

		$basic = new EphemeralPhpSession();
		$basic->armIfStatelessBearer($this->request('Basic dXNlcjpwYXNz', null));
		$this->assertFalse($basic->isArmed());

		$this->assertNotSame('', $name);
	}

	public function testCallbackRunsBeforeTheSessionIsDiscarded(): void {
		$order = [];
		$session = new EphemeralPhpSession();
		$session->armIfStatelessBearer($this->request('Bearer access-token', null));
		$session->runBeforeDiscard(function () use (&$order): void {
			$order[] = 'clear';
		});
		$session->onShutdown();
		$this->assertSame(['clear'], $order);
		$this->assertFalse($session->isArmed());
	}

	public function testDiscardNowUnlinksTheFileOfACookielessSession(): void {
		if (session_status() === PHP_SESSION_DISABLED) {
			$this->markTestSkipped('PHP sessions are disabled');
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		if ((string)ini_get('session.save_handler') !== 'files') {
			$this->markTestSkipped('session.save_handler is not files');
		}

		$dir = sys_get_temp_dir() . '/user-oidc-sess-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$previousPath = (string)ini_get('session.save_path');
		$previousName = session_name();
		try {
			ini_set('session.save_path', $dir);
			session_name('uoidctest');
			$this->assertTrue(session_start());
			$id = session_id();
			$_SESSION['marker'] = 1;
			session_write_close();
			$file = $dir . '/sess_' . $id;
			$this->assertFileExists($file);

			$this->assertTrue((new EphemeralPhpSession())->discardNow());
			$this->assertFileDoesNotExist($file);

			// A later write_close, as a session wrapper's destructor would call,
			// must not create the file again once the session is inactive.
			session_write_close();
			$this->assertFileDoesNotExist($file);
		} finally {
			if (session_status() === PHP_SESSION_ACTIVE) {
				session_write_close();
			}
			session_name($previousName);
			ini_set('session.save_path', $previousPath);
			if (is_dir($dir)) {
				array_map('unlink', glob($dir . '/sess_*') ?: []);
				rmdir($dir);
			}
		}
	}

	private function request(string $authorization, ?string $cookie): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($authorization);
		$request->method('getCookie')->willReturn($cookie);
		return $request;
	}
}
