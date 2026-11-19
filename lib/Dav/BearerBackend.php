<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCA\UserOIDC\User\Backend;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\DAV\Auth\Backend\AbstractBearer;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Accepts an OIDC Bearer token on the DAV server without creating a session.
 *
 * Registered from SabrePluginAuthInitEvent, which Nextcloud 34 dispatches
 * before the stock bearer backend and the apache backend. The first backend
 * that succeeds wins, so OC_User::loginWithApache() does not run.
 */
class BearerBackend extends AbstractBearer {

	public function __construct(
		private Backend $userBackend,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private DavRequestUser $davRequestUser,
	) {
	}

	/**
	 * @param string $bearerToken
	 * @return string|false
	 */
	protected function validateBearerToken($bearerToken) {
		if (!is_string($bearerToken) || $bearerToken === '') {
			return false;
		}

		// The resolver reads the same Authorization header Sabre already parsed.
		// Do not open a PHP session: this request must not grow oc_authtoken.
		$uid = $this->userBackend->resolveBearerUserId(false);
		if ($uid === '') {
			return false;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return false;
		}

		// setVolatileActiveUser() is not enough: Trashbin::restore() calls
		// OC_User::getUser(), which reads the session key user_id. setUser()
		// writes that key. createSessionToken() is not called, so oc_authtoken
		// does not grow. The overlay keeps the key out of the PHP session file.
		$this->davRequestUser->bind($this->userSession, $user);
		$this->setupFilesystem($uid);

		return 'principals/users/' . $uid;
	}

	protected function setupFilesystem(string $uid): void {
		\OC_Util::setupFS($uid);
	}

	/**
	 * Leave the 401 to the stock bearer backend. Its challenge avoids a
	 * WWW-Authenticate header that some DAV clients treat as a hard failure.
	 */
	public function challenge(RequestInterface $request, ResponseInterface $response): void {
	}
}
