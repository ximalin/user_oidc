<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Session\Exceptions\SessionNotAvailableException;

/**
 * In-memory ISession for the overlay tests. Not a Nextcloud class.
 */
class ArraySession implements ISession {
	/** @var array<string, mixed> */
	public array $data = [];

	/** @var list<string> */
	public array $sets = [];

	public function set(string $key, $value) {
		$this->sets[] = $key;
		$this->data[$key] = $value;
	}

	public function get(string $key) {
		return $this->data[$key] ?? null;
	}

	public function exists(string $key): bool {
		return isset($this->data[$key]);
	}

	public function remove(string $key) {
		unset($this->data[$key]);
	}

	public function clear() {
		$this->data = [];
	}

	public function reopen(): bool {
		return false;
	}

	public function close() {
	}

	public function regenerateId(bool $deleteOldSession = true, bool $updateToken = false) {
	}

	public function getId(): string {
		throw new SessionNotAvailableException();
	}
}

/**
 * The parts of OC\User\Session that bind() uses: getSession/setSession/setUser/setLoginName.
 * setUser writes user_id the same way the server does, so the test can see which session received it.
 */
class FakeUserSession implements IUserSession {
	public ?IUser $user = null;

	public ?string $loginName = null;

	public function __construct(
		public ISession $session,
	) {
	}

	public function getSession(): ISession {
		return $this->session;
	}

	public function setSession(ISession $session): void {
		$this->session->close();
		$this->session = $session;
		$this->user = null;
	}

	public function setUser($user) {
		if ($user === null) {
			$this->session->remove('user_id');
			$this->user = null;
			return;
		}
		$this->session->set('user_id', $user->getUID());
		$this->user = $user;
	}

	public function setLoginName(?string $loginName): void {
		if ($loginName === null) {
			$this->session->remove('loginname');
		} else {
			$this->session->set('loginname', $loginName);
		}
		$this->loginName = $loginName;
	}

	public function login($uid, $password) {
		return false;
	}

	public function logout() {
	}

	public function setVolatileActiveUser(?IUser $user): void {
		$this->user = $user;
	}

	public function getUser() {
		return $this->user;
	}

	public function isLoggedIn() {
		return $this->user !== null;
	}

	public function getImpersonatingUserID(): ?string {
		return null;
	}

	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
	}
}

class SessionWithoutSwap implements IUserSession {
	public ?IUser $user = null;

	public function __construct(
		public ArraySession $session = new ArraySession(),
	) {
	}

	public function setUser($user) {
		if ($user === null) {
			$this->session->remove('user_id');
			$this->user = null;
			return;
		}
		$this->session->set('user_id', $user->getUID());
		$this->user = $user;
	}

	public function setLoginName(?string $loginName): void {
		if ($loginName === null) {
			$this->session->remove('loginname');
		} else {
			$this->session->set('loginname', $loginName);
		}
	}

	public function login($uid, $password) {
		return false;
	}

	public function logout() {
	}

	public function setVolatileActiveUser(?IUser $user): void {
		$this->user = $user;
	}

	public function getUser() {
		return $this->user;
	}

	public function isLoggedIn() {
		return $this->user !== null;
	}

	public function getImpersonatingUserID(): ?string {
		return null;
	}

	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
	}
}
