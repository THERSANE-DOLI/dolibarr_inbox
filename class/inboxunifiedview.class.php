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
 * \file    class/inboxunifiedview.class.php
 * \ingroup inbox
 * \brief   Mailboxes of each unified view (1.141.0): the common list, the own list of a view, always all for « Assigned to me »
 */

/**
 * Each unified view covers the mailboxes the user reads, less the ones left out:
 * - « Assigned to me »: never any left out (a mail assigned to the user must always be there);
 * - a view with its own list (user setting views_scope, {"view": {"exclude": [ids]}}): the ones of that list;
 * - else the views of the common list (inbox, unread, sent, drafts): the ones of unified_exclude;
 * - else (followed): none.
 * The same rule is applied by the browser (js/inbox-mail.js viewAccounts()).
 */
class InboxUnifiedView
{
	/** Views following the common list (unified_exclude) as long as they have no list of their own */
	const COMMON = array('inbox', 'unread', 'sent', 'drafts');

	/** Views covering every mailbox read as long as they have no list of their own */
	const ALL = array('flagged');

	/** Views always covering every mailbox read: no list of their own */
	const ALWAYS_ALL = array('mine');

	/**
	 * Mailboxes of a view
	 *
	 * @param int[]  $readable  Mailboxes read by the user
	 * @param string $view      View (key of INBOX_LIST_VIEWS)
	 * @param string $common    unified_exclude of the user (JSON list of ids)
	 * @param string $scope     views_scope of the user (JSON)
	 * @return int[]            Mailboxes of the view, among the readable ones
	 */
	public static function accounts(array $readable, $view, $common, $scope)
	{
		$readable = array_values(array_map('intval', $readable));
		if (in_array($view, self::ALWAYS_ALL, true)) {
			return $readable;
		}
		$own = self::scopeOf($scope);
		if (isset($own[$view])) {
			$out = $own[$view];
		} elseif (in_array($view, self::COMMON, true)) {
			$out = self::ids(json_decode((string) $common, true));
		} else {
			$out = array();
		}

		return array_values(array_diff($readable, $out));
	}

	/**
	 * Own lists of the views, as written (unknown views and wrong values left aside)
	 *
	 * @param string $scope views_scope (JSON)
	 * @return array<string,int[]> view => mailboxes left out
	 */
	public static function scopeOf($scope)
	{
		$data = json_decode((string) $scope, true);
		$out = array();
		if (!is_array($data)) {
			return $out;
		}
		foreach ($data as $view => $v) {
			if (in_array($view, self::settable(), true) && is_array($v) && isset($v['exclude']) && is_array($v['exclude'])) {
				$out[$view] = self::ids($v['exclude']);
			}
		}

		return $out;
	}

	/**
	 * A value of views_scope that may be written: an object of settable views, each {"exclude": [ids]}
	 *
	 * @param string $value JSON
	 * @return bool
	 */
	public static function isValidScope($value)
	{
		$data = json_decode((string) $value, true);
		if (!is_array($data)) {
			return false;
		}
		if ($data && array_keys($data) === range(0, count($data) - 1)) {
			// A list, not an object
			return false;
		}
		foreach ($data as $view => $v) {
			if (!in_array((string) $view, self::settable(), true) || !is_array($v) || array_keys($v) !== array('exclude') || !is_array($v['exclude'])) {
				return false;
			}
			foreach ($v['exclude'] as $id) {
				if (!is_int($id) || $id <= 0) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Views that may have a list of their own
	 *
	 * @return string[]
	 */
	public static function settable()
	{
		return array_merge(self::COMMON, self::ALL);
	}

	/**
	 * @param mixed $list List of ids
	 * @return int[]
	 */
	protected static function ids($list)
	{
		return array_values(array_unique(array_filter(array_map('intval', array_slice(is_array($list) ? $list : array(), 0, 500)), function ($i) {
			return $i > 0;
		})));
	}
}
