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
 * \file    inbox/ajax/consumer.php
 * \ingroup inbox
 * \brief   Modules subscribed to a mailbox (1.112.0), section Modules of its card: the modules that declare
 *          themselves and what they are allowed; allow, change, pause, remove. Managers of the mailbox only
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxconsumer.class.php');
dol_include_once('/inbox/class/inboxfolder.class.php');
dol_include_once('/inbox/class/inboxactionlog.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('save', 'pause', 'resume', 'revoke'), true));
$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
$consumers = new InboxConsumer($db);

/**
 * The section: the switch of the instance, why this mailbox cannot have modules, the modules declared and the ones
 * still subscribed that no longer declare themselves (disabled or removed), the folders to limit a module to
 *
 * @param InboxAccount  $account   Mailbox
 * @param InboxConsumer $consumers Helper
 * @return array<string,mixed>
 */
function inboxConsumerReply(InboxAccount $account, InboxConsumer $consumers)
{
	global $db, $langs, $user;

	$declared = InboxConsumer::declared();
	$grants = $consumers->grants((int) $account->id);
	// Mails waiting for each module, given up after the last try (1.146.0)
	dol_include_once('/inbox/class/inboxconsumerdelivery.class.php');
	$queue = (new InboxConsumerDelivery($db))->counts((int) $account->id);
	foreach ($grants as $code => $g) {
		$grants[$code]['queue'] = isset($queue[$code]) ? $queue[$code] : array('waiting' => 0, 'failed' => 0);
	}
	$names = array();
	$name = function ($id) use ($db, $langs, &$names) {
		if ($id <= 0) {
			return '';
		}
		if (!isset($names[$id])) {
			$u = new User($db);
			$names[$id] = $u->fetch($id) > 0 ? $u->getFullName($langs) : '';
		}
		return $names[$id];
	};
	$list = array();
	foreach ($declared as $code => $d) {
		$g = isset($grants[$code]) ? $grants[$code] : null;
		$list[] = array(
			'code' => $code, 'label' => $d['label'], 'purpose' => $d['purpose'], 'declared' => true,
			'events' => $d['events'], 'actions' => $d['actions'],
			'grant' => $g === null ? null : array_diff_key($g, array('requested' => 1, 'id' => 1, 'fk_user' => 1)) + array('user' => $name($g['fk_user'])),
			'pending' => $g === null ? array('events' => array(), 'actions' => array()) : InboxConsumer::pending($d, $g),
		);
	}
	// Still subscribed, no longer declared: kept as set (they run again when the module is enabled), may be removed
	foreach ($grants as $code => $g) {
		if (!isset($declared[$code])) {
			$list[] = array('code' => $code, 'label' => $code, 'purpose' => '', 'declared' => false, 'events' => array(), 'actions' => array(),
				'grant' => array_diff_key($g, array('requested' => 1, 'id' => 1, 'fk_user' => 1)) + array('user' => $name($g['fk_user'])),
				'pending' => array('events' => array(), 'actions' => array()));
		}
	}
	$folders = array();
	foreach ((new InboxFolder($db))->listForAccount((int) $account->id) as $f) {
		$folders[] = array('id' => (int) $f->rowid, 'role' => (string) $f->role, 'name' => InboxActionLog::readable($db, (int) $account->id, (string) $f->path));
	}

	return array(
		'enabled' => InboxConsumer::enabled(),
		// The switch is in the setup of the module, for the administrators of Dolibarr only
		'setup_url' => !empty($user->admin) ? dol_buildpath('/inbox/admin/setup.php', 1).'#consumers' : '',
		'blocked' => InboxConsumer::blockedReason($account),
		'locked_sync' => $account->isProtected() && !$account->isLive(),
		'consumers' => $list,
		'folders' => $folders,
	);
}

/**
 * Error of the helper as a message for the user
 *
 * @param InboxConsumer $consumers Helper
 * @return never
 */
function inboxConsumerFail(InboxConsumer $consumers)
{
	global $langs;

	inboxAjaxError($langs->transnoentities($consumers->error ?: 'InboxConsumerSaveError'), 422);
}

$code = (string) inboxAjaxParam('code', 'aZ09');

switch ($action) {
	case 'list':
		inboxAjaxReply(inboxConsumerReply($account, $consumers));
		// no break (reply exits)

	case 'save':
		$set = array(
			'events' => (array) inboxAjaxParam('events', 'none'),
			'actions' => (array) inboxAjaxParam('actions', 'none'),
			'folders' => (array) inboxAjaxParam('folders', 'none'),
			'mode' => (string) inboxAjaxParam('mode', 'aZ09'),
		);
		if (!$consumers->save($user, $account, $code, $set)) {
			inboxConsumerFail($consumers);
		}
		inboxAjaxReply(inboxConsumerReply($account, $consumers));
		// no break

	case 'pause':
	case 'resume':
		if (!$consumers->setPaused($user, $account, $code, $action === 'pause')) {
			inboxConsumerFail($consumers);
		}
		inboxAjaxReply(inboxConsumerReply($account, $consumers));
		// no break

	case 'revoke':
		if (!$consumers->revoke($user, $account, $code)) {
			inboxConsumerFail($consumers);
		}
		inboxAjaxReply(inboxConsumerReply($account, $consumers));
		// no break

	default:
		inboxAjaxError('Unknown action', 400);
}
