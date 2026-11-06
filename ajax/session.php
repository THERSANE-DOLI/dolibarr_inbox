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
 * \file    inbox/ajax/session.php
 * \ingroup inbox
 * \brief   State of the session: the page asks it after a reconnection to get the new CSRF token
 */

require __DIR__.'/inbox_ajax.inc.php';

inboxAjaxStart(false);
inboxAjaxReply(array('token' => currentToken(), 'user' => array('id' => (int) $user->id, 'login' => (string) $user->login)));
