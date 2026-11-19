<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Publishes the bearer uid for the rest of this request.
 *
 * setVolatileActiveUser() sets IUserSession::getUser() only. Trash restore does
 * not call that. Files_Trashbin\Sabre\RestoreFolder::moveInto() calls
 * ITrash::restore(), LegacyTrashBackend::restoreItem() calls Trashbin::restore(),
 * and Trashbin::restore() throws when OC_User::getUser() is empty. That method
 * reads the session key user_id (OC_User::getUser in the server).
 *
 * setUser() writes user_id and the active user. It does not call
 * createSessionToken(), which is the insert into oc_authtoken. setLoginName()
 * is not on IUserSession; the concrete session has it, and a missing login name
 * makes getLoginName() return null while a user is active.
 */
class DavRequestUser {
	public function __construct(
		private EphemeralPhpSession $ephemeralPhpSession,
	) {
	}

	private ?IUserSession $fallback = null;

	/**
	 * @return bool true when user_id stays in memory and is not written to the PHP session
	 */
	public function bind(IUserSession $userSession, IUser $user): bool {
		$overlaid = $this->installOverlay($userSession);
		$userSession->setUser($user);
		if (method_exists($userSession, 'setLoginName')) {
			$userSession->setLoginName($user->getUID());
		}
		if (!$overlaid) {
			$this->scheduleFallbackClear($userSession);
		}
		return $overlaid;
	}

	/**
	 * Drops a user_id that had to be written to the real session.
	 * No-op when bind() installed the overlay, and safe to call twice.
	 */
	public function releaseFallback(): void {
		$userSession = $this->fallback;
		$this->fallback = null;
		if ($userSession === null) {
			return;
		}
		$userSession->setUser(null);
		if (method_exists($userSession, 'setLoginName')) {
			$userSession->setLoginName(null);
		}
	}

	private function installOverlay(IUserSession $userSession): bool {
		if (!method_exists($userSession, 'getSession') || !method_exists($userSession, 'setSession')) {
			return false;
		}
		$inner = $userSession->getSession();
		if (!$inner instanceof ISession) {
			return false;
		}
		if ($inner instanceof RequestUserOverlay) {
			return true;
		}
		$userSession->setSession(new RequestUserOverlay($inner));
		return true;
	}

	private function scheduleFallbackClear(IUserSession $userSession): void {
		$this->fallback = $userSession;
		// Cleared before the cookieless session is destroyed, so a real
		// session write cannot be the last thing stored for this request.
		$this->ephemeralPhpSession->runBeforeDiscard(function (): void {
			$this->releaseFallback();
		});
	}
}
