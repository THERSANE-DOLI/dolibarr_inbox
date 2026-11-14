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
 * \file    inbox/class/inboxagenda.class.php
 * \ingroup inbox
 * \brief   Values written by inbox in the events of the agenda of Dolibarr (1.89.1)
 *
 * The screens of the agenda trust what the pages of Dolibarr filtered on input: comm/action/card.php shows the
 * note as HTML (dol_string_onlythesehtmltags keeps img and its attributes) and prints the title and the place raw
 * in its form (value="...") and in its card. Everything inbox writes there comes from a mail or from a text typed
 * by a user, never filtered by GETPOST: it goes through here (audit INBX-030, INBX-046, INBX-047, INBX-048), that is
 * now the one door InboxSafe (lot S2).
 */
require_once __DIR__.'/inboxsafe.class.php';

/**
 * Values of the agenda
 */
class InboxAgenda
{
	/**
	 * Note of an event from a text: escaped, lines kept
	 *
	 * @param string $text Text
	 * @param int    $max  Characters kept of the text, before escaping
	 * @return string HTML
	 */
	public static function note($text, $max = 60000)
	{
		return InboxSafe::textToHtml($text, $max);
	}

	/**
	 * Title or place of an event from a text: one line, no tag, no double quote (an attribute value of the core)
	 *
	 * @param string $text Text
	 * @param int    $max  Characters kept
	 * @return string
	 */
	public static function field($text, $max = 250)
	{
		return InboxSafe::line($text, $max);
	}
}
