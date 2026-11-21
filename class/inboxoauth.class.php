<?php
/* Copyright (C) 2026 THERSANE www.thersane.fr
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    inbox/class/inboxoauth.class.php
 * \ingroup inbox
 * \brief   Access token of an OAuth service of the core (Microsoft 365, Google) for IMAP / SMTP XOAUTH2
 */

/**
 * The tokens are managed by the OAuth module of the core (Setup > OAuth), as for the email collector: inbox only
 * reads the token of the service chosen on the mailbox, refreshing it when it is about to expire.
 */
class InboxOAuth
{
	/**
	 * @return array<string,string> Services set up in the core: key (ex: GOOGLE-MyCompany) => label as the setup of
	 *                              the core shows it, « MyCompany (Google) » (the provider alone without a label)
	 */
	public static function listServices()
	{
		global $conf, $langs;

		$out = array();
		if (!isModEnabled('oauth')) {
			return $out;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/oauth.lib.php';
		$supported = getSupportedOauth2Array();
		foreach ($conf->global as $key => $value) {
			if (!preg_match('/^OAUTH_([A-Z0-9]+)(-[^_]+)?_ID$/', (string) $key, $reg) || (string) $value === '') {
				continue;
			}
			// Name of the provider as in Setup > OAuth (« Microsoft Exchange Online [SMTP/IMAP] »), never the name of its
			// callback file (« Microsoft3 »), which the user never sees
			$name = 'OAUTH_'.$reg[1].'_NAME';
			$provider = is_object($langs) ? $langs->trans($name) : $name;
			if ($provider === $name) {
				$provider = isset($supported[$name]['name']) ? (string) $supported[$name]['name'] : ucfirst(strtolower($reg[1]));
			}
			$service = $reg[1].(isset($reg[2]) ? $reg[2] : '');
			$out[$service] = isset($reg[2]) ? substr($reg[2], 1).' ('.$provider.')' : $provider;
		}

		return $out;
	}

	/**
	 * Access token of a service, refreshed when needed
	 *
	 * @param string $service Service key as in the setup of the core (ex: GOOGLE-MyCompany)
	 * @param string $error   Error, plain text, if '' is returned
	 * @return string          Access token, '' on error
	 */
	public static function getAccessToken($service, &$error = '')
	{
		global $conf, $db;

		$error = '';
		if (!isModEnabled('oauth')) {
			$error = 'OAuth module of Dolibarr not enabled';
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/oauth.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/includes/OAuth/bootstrap.php';

		// Same resolution of the name as the email collector of the core
		$supported = getSupportedOauth2Array();
		$provider = preg_match('/^.*-/', (string) $service) ? preg_replace('/^.*-/', '', (string) $service) : '';
		$nameKey = 'OAUTH_'.preg_replace('/-.*$/', '', strtoupper((string) $service)).'_NAME';
		$callback = empty($supported[$nameKey]['callbackfile']) ? 'Unknown' : $supported[$nameKey]['callbackfile'];
		$serviceName = ucfirst(strtolower($callback)).($provider ? '-'.$provider : '');
		$tenantKey = 'OAUTH_'.strtoupper($callback).($provider ? '-'.$provider : '').'_TENANT';

		try {
			$storage = new \OAuth\Common\Storage\DoliStorage($db, $conf, $provider, getDolGlobalString($tenantKey));
			$token = $storage->retrieveAccessToken($serviceName);
			$endOfLife = (is_object($token) && method_exists($token, 'getEndOfLife')) ? $token->getEndOfLife() : -9002;
			if (is_object($token) && $endOfLife !== -9002 && $endOfLife !== -9001 && time() > ($endOfLife - 30)) {
				$credentials = new \OAuth\Common\Consumer\Credentials(
					getDolGlobalString('OAUTH_'.$service.'_ID'),
					getDolGlobalString('OAUTH_'.$service.'_SECRET'),
					getDolGlobalString('OAUTH_'.$service.'_URLCALLBACK')
				);
				$scopes = array();
				if (preg_match('/^Microsoft/', $serviceName)) {
					$tmp = explode('-', $serviceName);
					$scopes = explode(',', getDolGlobalString('OAUTH_'.strtoupper($tmp[0]).(empty($tmp[1]) ? '' : '-'.$tmp[1]).'_SCOPE'));
				}
				$factory = new \OAuth\ServiceFactory();
				$parts = explode('-', $serviceName);
				$api = $factory->createService($parts[0], $credentials, $storage, $scopes);
				$refresh = $token->getRefreshToken();
				$token = $api->refreshAccessToken($token);
				// The answer gives the refresh token only once: keep it
				$token->setRefreshToken($refresh);
				$storage->storeAccessToken($serviceName, $token);
				$token = $storage->retrieveAccessToken($serviceName);
			}
			if (!is_object($token)) {
				$error = 'OAuth token not found for '.$serviceName;
				return '';
			}

			return (string) $token->getAccessToken();
		} catch (Exception $e) {
			$error = 'OAuth: '.$e->getMessage();
			return '';
		}
	}
}
