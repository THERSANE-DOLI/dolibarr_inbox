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
 * \file    inbox/ajax/composefile.php
 * \ingroup inbox
 * \brief   A file of a writing in the viewer (1.82.0): the very file that leaves with the mail, checked by the
 *          user before sending. Same answers and protections as the attachments of a mail (inbox_attserve.inc.php);
 *          only the author of the writing, in its session.
 *
 * compose = token of the writing, att = attachment, mode = check, inline, download, text, list
 */

require __DIR__.'/inbox_ajax.inc.php';
require_once __DIR__.'/inbox_attserve.inc.php';
dol_include_once('/inbox/class/inboxcomposer.class.php');

inboxAjaxStart(false);
$composer = new InboxComposer($db);
$file = $composer->attachmentFile($user, (string) GETPOST('compose', 'aZ09'), (string) GETPOST('att', 'aZ09'));
if ($file === null) {
	inboxAjaxError($langs->transnoentities('InboxAttNotFound'), 404);
}
inboxAttServe(new InboxAttachmentScan($db), $file, GETPOST('mode', 'aZ09') ?: 'download', array('att' => (string) GETPOST('att', 'aZ09')));
