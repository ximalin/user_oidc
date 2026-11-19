<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Dav\RequestUserOverlay;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../doubles/SessionDoubles.php';

class RequestUserOverlayTest extends TestCase {
	public function testUserIdStaysOffTheInnerSession(): void {
		$inner = new ArraySession();
		$inner->set('LAST_ACTIVITY', 10);
		$overlay = new RequestUserOverlay($inner);

		$overlay->set('user_id', 'alice@example.test');
		$overlay->set('loginname', 'alice@example.test');

		$this->assertSame('alice@example.test', $overlay->get('user_id'));
		$this->assertSame('alice@example.test', $overlay->get('loginname'));
		$this->assertTrue($overlay->exists('user_id'));
		$this->assertArrayNotHasKey('user_id', $inner->data);
		$this->assertArrayNotHasKey('loginname', $inner->data);
		$this->assertNotContains('user_id', $inner->sets);
		$this->assertSame(10, $overlay->get('LAST_ACTIVITY'));
	}

	public function testAMaskedKeyDoesNotFallBackToTheBrowserUser(): void {
		$inner = new ArraySession();
		$inner->set('user_id', 'browser-user');
		$overlay = new RequestUserOverlay($inner);

		$overlay->set('user_id', 'alice@example.test');
		$overlay->remove('user_id');

		$this->assertNull($overlay->get('user_id'));
		$this->assertFalse($overlay->exists('user_id'));
		$this->assertSame('browser-user', $inner->get('user_id'));
	}

	public function testOtherKeysPassThrough(): void {
		$inner = new ArraySession();
		$overlay = new RequestUserOverlay($inner);

		$overlay->set('requesttoken', 'abc');

		$this->assertSame('abc', $inner->get('requesttoken'));
		$this->assertSame($inner, $overlay->inner());
	}
}
