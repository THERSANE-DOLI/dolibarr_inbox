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
 * by a user, never filtered by GETPOST: it goes through here (audit INBX-030, INBX-046, INBX-047, INBX-048).
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
		$text = dol_trunc(self::controls((string) $text, true), $max, 'right', 'UTF-8', 1);

		return dol_nl2br(dol_escape_htmltag($text, 0, 1));
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
		// An entity would come back as a character on a screen that decodes it: decoded first, then neutralised
		$text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string) preg_replace('/\s+/u', ' ', self::controls($text, false)));
		$text = strtr($text, array('<' => "\u{2039}", '>' => "\u{203A}", '"' => "'", '`' => "'"));

		return dol_trunc($text, $max, 'right', 'UTF-8', 1);
	}

	/**
	 * Control characters removed (lines kept on demand)
	 *
	 * @param string $text  Text
	 * @param bool   $lines Keep the line breaks
	 * @return string
	 */
	protected static function controls($text, $lines)
	{
		$text = str_replace("\r\n", "\n", $text);

		return (string) preg_replace($lines ? '/[\x00-\x08\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $lines ? '' : ' ', $text);
	}
}
