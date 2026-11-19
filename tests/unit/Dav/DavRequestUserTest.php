<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Dav\DavRequestUser;
use OCA\UserOIDC\Dav\EphemeralPhpSession;
use OCA\UserOIDC\Dav\RequestUserOverlay;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../doubles/SessionDoubles.php';

class DavRequestUserTest extends TestCase {
	public function testBindPublishesTheUidWithoutWritingThePhpSession(): void {
		$inner = new ArraySession();
		$inner->set('user_id', 'browser-user');
		$session = new FakeUserSession($inner);
		$user = $this->user('alice@example.test');

		$overlaid = $this->binder()->bind($session, $user);

		$this->assertTrue($overlaid);
		$this->assertInstanceOf(RequestUserOverlay::class, $session->getSession());
		$this->assertSame($user, $session->getUser());
		$this->assertTrue($session->isLoggedIn());
		$this->assertSame('alice@example.test', $session->getSession()->get('user_id'));
		$this->assertSame('alice@example.test', $session->getSession()->get('loginname'));
		$this->assertSame('browser-user', $inner->get('user_id'));
		$this->assertArrayNotHasKey('loginname', $inner->data);
	}

	public function testBindDoesNotReplaceAnOverlayThatIsAlreadyInstalled(): void {
		$inner = new ArraySession();
		$session = new FakeUserSession(new RequestUserOverlay($inner));
		$user = $this->user('alice@example.test');

		$this->assertTrue($this->binder()->bind($session, $user));
		$this->assertSame('alice@example.test', $session->getSession()->get('user_id'));
		$this->assertArrayNotHasKey('user_id', $inner->data);
	}

	public function testWithoutSetSessionTheKeyIsWrittenAndThenReleased(): void {
		$session = new SessionWithoutSwap();
		$user = $this->user('alice@example.test');
		$binder = $this->binder();

		$this->assertFalse($binder->bind($session, $user));
		$this->assertSame('alice@example.test', $session->session->get('user_id'));

		$binder->releaseFallback();

		$this->assertNull($session->session->get('user_id'));
		$this->assertNull($session->getUser());
		$binder->releaseFallback();
	}

	private function binder(): DavRequestUser {
		return new DavRequestUser(new EphemeralPhpSession());
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
