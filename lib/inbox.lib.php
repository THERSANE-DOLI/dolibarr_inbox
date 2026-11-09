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
 * \file    inbox/lib/inbox.lib.php
 * \ingroup inbox
 * \brief   Library of the setup pages of Inbox
 */

/**
 * Tabs of the setup pages
 *
 * @return array<int,array<int,string>>
 */
function inboxAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("inbox@inbox");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/inbox/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/inbox/admin/readlog.php", 1);
	$head[$h][1] = $langs->trans("InboxReadLog");
	$head[$h][2] = 'readlog';
	$h++;

	// Catalogue of the components of the interface: a page for the developers and the admins, not in the application
	$head[$h][0] = dol_buildpath("/inbox/ux.php", 1);
	$head[$h][1] = $langs->trans("InboxCatalogue");
	$head[$h][2] = 'catalogue';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'inbox@inbox');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'inbox@inbox', 'remove');

	return $head;
}

/**
 * Summary of a parsed mail, shown by the tests of the setup page
 *
 * @param InboxMessage $message Mail
 * @return string HTML
 */
function inboxMessageSummary(InboxMessage $message)
{
	global $langs;

	$out = '<table class="noborder centpercent">';
	$rows = array(
		'InboxSource' => strtoupper($message->source),
		'InboxMessageId' => $message->messageId,
		'InboxSubject' => $message->subject,
		'InboxFrom' => trim($message->fromName.' <'.$message->fromEmail.'>'),
		'InboxTo' => implode(', ', array_map(function ($a) {
			return $a['email'];
		}, $message->to)),
		'Date' => $message->date > 0 ? dol_print_date($message->date, 'dayhour', 'tzuserrel') : '',
		'InboxEmbeddedMails' => (string) count($message->embedded),
	);
	foreach ($rows as $label => $value) {
		$out .= '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>'.dol_escape_htmltag($value).'</td></tr>';
	}
	$files = array();
	foreach ($message->attachments as $attachment) {
		$flags = array();
		if (!empty($attachment['inline'])) {
			$flags[] = $langs->trans('InboxInline');
		}
		if (!empty($attachment['embedded'])) {
			$flags[] = $langs->trans('InboxFromEmbeddedMail');
		}
		$files[] = dol_escape_htmltag($attachment['name']).' <span class="opacitymedium">('.$attachment['kind'].', '.dol_print_size($attachment['size'], 1, 1)
			.(!empty($flags) ? ', '.dol_escape_htmltag(implode(', ', $flags)) : '').')</span>';
	}
	$out .= '<tr class="oddeven"><td>'.$langs->trans('InboxAttachments').' ('.count($message->attachments).')</td><td>'.implode('<br>', $files).'</td></tr>';
	$out .= '<tr class="oddeven"><td>'.$langs->trans('InboxBodyText').'</td><td><div class="small" style="white-space: pre-wrap; max-height: 240px; overflow: auto;">'
		.dol_escape_htmltag(dol_trunc($message->bodyText, 1500, 'right', 'UTF-8', 1), 0, 1).'</div></td></tr>';
	$out .= '</table>';

	return $out;
}
