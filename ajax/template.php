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
 * \file    inbox/ajax/template.php
 * \ingroup inbox
 * \brief   Mail templates of inbox (lot W6 1.18.0): tab Templates of a mailbox, « Save as a template » of a writing
 */

require __DIR__.'/inbox_ajax.inc.php';
dol_include_once('/inbox/class/inboxtemplates.class.php');

$action = (string) inboxAjaxParam('action', 'aZ09');
inboxAjaxStart(in_array($action, array('save', 'delete', 'order', 'preview'), true));
$templates = new InboxTemplates($db);

/**
 * Answer of a failure
 *
 * @param InboxTemplates $templates Helper
 * @return void
 */
function inboxTemplateFail(InboxTemplates $templates)
{
	global $langs;

	$codes = array('not_found' => array(404, 'InboxTemplateGone'), 'no_right' => array(403, 'InboxTemplateNoRight'), 'bad_label' => array(422, 'InboxTemplateNeedLabel'));
	$c = $codes[$templates->errorCode] ?? array(500, 'InboxTemplateFailed');
	dol_syslog('inbox template '.$templates->errorCode.' '.$templates->error, LOG_WARNING);
	inboxAjaxReply(array('error' => $langs->transnoentities($c[1]), 'code' => $templates->errorCode), $c[0]);
}

/**
 * Mailboxes the user may attach a template to: those he manages (all of them for the admins)
 *
 * @return array<int,array{id:int,label:string}>
 */
function inboxTemplateAccounts()
{
	global $db, $user;

	$access = new InboxAccess($db);
	$out = array();
	foreach ($access->getAccounts($user, InboxAccess::LEVEL_MANAGE) as $a) {
		$out[] = array('id' => (int) $a->id, 'label' => (string) ($a->label ?: $a->email));
	}

	return $out;
}

/**
 * Languages of Dolibarr, for the language of a template
 *
 * @return array<int,array{code:string,label:string}>
 */
function inboxTemplateLangs()
{
	global $langs;

	$out = array();
	foreach ($langs->get_available_languages(DOL_DOCUMENT_ROOT, 0, 0) as $code => $label) {
		$out[] = array('code' => (string) $code, 'label' => (string) $label);
	}

	return $out;
}

switch ($action) {
	case 'list':
		// Tab Templates of a mailbox: managers of the mailbox
		$account = inboxAjaxManagedAccount((int) inboxAjaxParam('id', 'int'));
		inboxAjaxReply(array('templates' => $templates->listForAccount($user, (int) $account->id), 'accounts' => inboxTemplateAccounts(),
			'admin' => $user->hasRight('inbox', 'account', 'admin') ? 1 : 0, 'core_url' => $user->admin ? dol_buildpath('/admin/mails_templates.php', 1).'?search_type_template='.InboxTemplates::TYPE : ''));
		// no break
	case 'get':
		$res = $templates->get($user, (int) inboxAjaxParam('template', 'int'));
		if ($res === null) {
			inboxTemplateFail($templates);
		}
		inboxAjaxReply(array('template' => $res, 'keys' => InboxTemplates::keys(), 'accounts' => inboxTemplateAccounts(), 'langs' => inboxTemplateLangs()));
		// no break
	case 'keys':
		inboxAjaxReply(array('keys' => InboxTemplates::keys(), 'accounts' => inboxTemplateAccounts(), 'langs' => inboxTemplateLangs()));
		// no break
	case 'save':
		$accounts = inboxAjaxParam('accounts', 'none');
		$id = $templates->save($user, array(
			'id' => (int) inboxAjaxParam('template', 'int'),
			'label' => (string) inboxAjaxParam('label'),
			'subject' => (string) inboxAjaxParam('subject', 'none'),
			// Cleaned by the allow list of the mails (InboxMailBuilder::cleanHtml)
			'html' => (string) inboxAjaxParam('html', 'none'),
			'private' => (int) inboxAjaxParam('private', 'int'),
			'active' => inboxAjaxHas('active') ? (int) inboxAjaxParam('active', 'int') : 1,
			'lang' => (string) inboxAjaxParam('lang', 'aZ09'),
			'accounts' => is_array($accounts) ? $accounts : array(),
		));
		if (!$id) {
			inboxTemplateFail($templates);
		}
		inboxAjaxReply(array('id' => $id));
		// no break
	case 'delete':
		if (!$templates->delete($user, (int) inboxAjaxParam('template', 'int'))) {
			inboxTemplateFail($templates);
		}
		inboxAjaxReply(array('ok' => 1));
		// no break
	case 'preview':
		// The template being written, filled for a recipient (1.26.0): nothing saved, nothing sent
		$res = $templates->preview($user, (int) inboxAjaxParam('account', 'int'), (string) inboxAjaxParam('to', 'email'), (string) inboxAjaxParam('lang', 'aZ09'),
			(string) inboxAjaxParam('subject', 'none'), (string) inboxAjaxParam('html', 'none'));
		inboxAjaxReply(array('preview' => $res));
		// no break
	case 'order':
		$ids = inboxAjaxParam('ids', 'none');
		$templates->order($user, is_array($ids) ? $ids : array());
		inboxAjaxReply(array('ok' => 1));
		// no break
	default:
		inboxAjaxError('Unknown action', 400);
}
