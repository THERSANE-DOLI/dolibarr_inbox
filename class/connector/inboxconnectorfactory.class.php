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
 * \file    inbox/class/connector/inboxconnectorfactory.class.php
 * \ingroup inbox
 * \brief   Connectors known by inbox, and the connector of a mailbox
 */

require_once __DIR__.'/inboxconnector.class.php';

/**
 * inbox ships the imap connector. Another module adds one with the hook inboxConnectors (context "inbox"): its
 * method inboxConnectors() puts in $this->results, and returns 0, code => array('class' => ..., 'file' => path relative to the custom dir as for
 * dol_include_once, 'label' => translation key, 'family' => 'mail', 'icon' => usage name of the icon). The class must
 * implement InboxConnectorInterface (extending InboxConnector is the simplest).
 */
class InboxConnectorFactory
{
	/** @var array<string,array{class:string,file:string,label:string,family:string,icon:string}>|null */
	protected static $connectors = null;

	/**
	 * @return array<string,array{class:string,file:string,label:string,family:string,icon:string}> Code => description
	 */
	public static function getConnectors()
	{
		global $hookmanager;

		if (self::$connectors !== null) {
			return self::$connectors;
		}
		$list = array(
			'imap' => array('class' => 'InboxImapConnector', 'file' => '/inbox/class/connector/inboximapconnector.class.php', 'label' => 'InboxConnectorImap', 'family' => 'mail', 'icon' => 'mail'),
		);
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($GLOBALS['db']);
		}
		$hookmanager->initHooks(array('inbox'));
		$parameters = array('connectors' => $list);
		$action = '';
		$object = null;
		$reshook = $hookmanager->executeHooks('inboxConnectors', $parameters, $object, $action);
		if ($reshook >= 0 && is_array($hookmanager->resArray)) {
			foreach ($hookmanager->resArray as $code => $desc) {
				// The connector of inbox cannot be replaced, a code is a simple identifier
				if ($code === 'imap' || !preg_match('/^[a-z0-9_]{1,32}$/', (string) $code) || !is_array($desc) || empty($desc['class']) || empty($desc['file'])) {
					continue;
				}
				$list[$code] = array(
					'class' => (string) $desc['class'],
					'file' => (string) $desc['file'],
					'label' => isset($desc['label']) ? (string) $desc['label'] : (string) $code,
					'family' => isset($desc['family']) ? (string) $desc['family'] : 'mail',
					'icon' => isset($desc['icon']) ? (string) $desc['icon'] : 'mail',
				);
			}
		}
		self::$connectors = $list;

		return $list;
	}

	/**
	 * Forget the list (tests, module enabled during the request)
	 *
	 * @return void
	 */
	public static function reset()
	{
		self::$connectors = null;
	}

	/**
	 * Connector of a mailbox
	 *
	 * @param DoliDB       $db      Database handler
	 * @param InboxAccount $account Mailbox
	 * @param string       $error   Error if null is returned
	 * @return InboxConnectorInterface|null
	 */
	public static function forAccount(DoliDB $db, InboxAccount $account, &$error = '')
	{
		global $langs;

		$langs->load('inbox@inbox');
		$connectors = self::getConnectors();
		$code = (string) $account->connector;
		if (!isset($connectors[$code])) {
			$error = $langs->transnoentities('InboxErrorConnectorUnknown', $code);
			return null;
		}
		$desc = $connectors[$code];
		if (!class_exists($desc['class'])) {
			dol_include_once($desc['file']);
		}
		if (!class_exists($desc['class']) || !in_array('InboxConnectorInterface', (array) class_implements($desc['class']), true)) {
			$error = $langs->transnoentities('InboxErrorConnectorUnknown', $code);
			return null;
		}

		return new $desc['class']($db, $account);
	}
}
