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
 * \file    inbox/class/inboxuserparam.class.php
 * \ingroup inbox
 * \brief   Settings of a user, for all the mailboxes or for one
 */

/**
 * Settings of a user (Settings > My account), read in cascade: user + mailbox, then user, then the default of the
 * setup (constant INBOX_DEFAULT_<PARAM>), then the default of the code. Only the known settings are accepted, each
 * one with its check.
 */
class InboxUserParam
{
	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/** @var array<int,array<string,string>> Cache per user: "account:param" => value */
	protected static $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Known settings: name => array(default, check). Check: list of values, 'int:min:max', 'json'.
	 *
	 * @return array<string,array{0:string,1:string|string[]}>
	 */
	public static function definitions()
	{
		return array(
			// Mail marked as read: at once, after a delay, or only by hand (key M), lot W3
			'read_mode' => array('delay', array('immediate', 'delay', 'manual')),
			'read_delay' => array('3', 'int:0:600'),
			// A mail put in the trash or in junk by the user is marked read first (1.90.0)
			'read_on_trash' => array('1', 'int:0:1'),
			// An archived mail is marked read first (1.96.0), a setting of its own
			'read_on_archive' => array('1', 'int:0:1'),
			// Display (lot W3): table or cards for all the folders, as Thunderbird
			'view' => array('cards', array('table', 'cards')),
			'list_columns' => array('', 'json'),
			// Tags in the table: names (badges) or dots (colour and initial, the name on hover and for screen readers)
			'list_tags_view' => array('names', array('names', 'dots')),
			'pane_widths' => array('', 'json'),
			'density' => array('normal', array('compact', 'normal', 'comfortable')),
			'text_size' => array('100', 'int:90:150'),
			'theme' => array('auto', array('auto', 'light', 'dark', 'contrast')),
			'attachments_open' => array('0', 'int:0:1'),
			// New customer from a mail (lot A1 1.79.0): last choices of the panel; contacts left to create after a full page
			'lead_last' => array('', 'json'),
			'lead_pending' => array('', 'json'),
			// Mail space (lot W3): folders folded, sort of the lists, quick filter bar, preview windows, zoom of the
			// text of the mails
			'tree_collapsed' => array('', 'json'),
			'list_sort' => array('date:desc', array('date:desc', 'date:asc', 'from:asc', 'from:desc', 'subject:asc', 'subject:desc', 'size:desc', 'size:asc')),
			'quick_filter' => array('0', 'int:0:1'),
			'folders_hidden' => array('0', 'int:0:1'),
			'windows' => array('', 'json'),
			'mail_zoom' => array('100', 'int:50:200'),
			// Conversations (1.5.0): one line per thread in the lists, the thread in the reading pane
			'conversation' => array('1', 'int:0:1'),
			// Context menus of inbox (1.5.0): 0 = the menu of the browser everywhere
			'context_menus' => array('1', 'int:0:1'),
			// Presence (1.5.0): mail open now in a shared mailbox {"a": mailbox, "m": Message-ID}, kept 60 s
			'presence' => array('', 'json'),
			// New mails (1.5.0): per mailbox, folder => last row seen by the user (InboxNewMarks)
			'seen_marks' => array('', 'json'),
			// Actions (1.6.0): mail selected after one leaves the list, gestures of the phone on the cards
			'after_move' => array('next', array('next', 'previous')),
			'swipe_left' => array('archive', array('none', 'archive', 'delete', 'read', 'flag', 'tag', 'move')),
			'swipe_right' => array('read', array('none', 'archive', 'delete', 'read', 'flag', 'tag', 'move')),
			// Colour blindness (1.7.0): colours of the tags adapted to the vision declared in the card of the user
			// (Dolibarr option MAIN_OPTIMIZEFORCOLORBLIND), 0 = the colours configured
			'tag_colors_adapt' => array('1', 'int:0:1'),
			// Folders (1.8.0): icons of the folders and mailboxes in their colour, or all grey (plain)
			'folder_icons' => array('color', array('color', 'plain')),
			// Bandeau Dolibarr of the reading folded (1.13.0)
			'dolibar_folded' => array('0', 'int:0:1'),
			// Column beside a mail open, chosen in the strip on the right (1.109.0): the Dolibarr part, the agenda panel or none
			'side_panel' => array('dolibarr', array('dolibarr', 'agenda', 'none')),
			// Agenda panel with no mail open (1.107.0, declared 1.109.0): shown (''), or closed by hand
			'agenda_mode' => array('', array('', 'pinned', 'closed')),
			// Writing (1.16.0): Ctrl+Enter sends (or nothing), size of the writing windows
			'send_shortcut' => array('ctrl_enter', array('ctrl_enter', 'off')),
			// Delay before a mail leaves (1.17.0): -1 = the one of the setup, 0 = at once
			'send_delay' => array('-1', 'int:-1:120'),
			'compose_size' => array('', 'json'),
			// Colours of the writing (1.16.1): last colour of the text and of the highlight, recent ones
			'compose_colors' => array('', 'json'),
			// Notifications (1.21.0): mailboxes counted in the unread counter (all, or only the personal ones; one can be
			// left out: count_box per mailbox), the counter in the top bar of Dolibarr, the notifications of the browser
			// (asked by the user), the new mails told per mailbox (auto: yes for a personal mailbox, no for a shared
			// one), the channels per type of notification {"type": {"b": 0|1, "m": 0|1}} (browser on, mail off by default)
			'count_scope' => array('all', array('all', 'personal')),
			'count_box' => array('1', 'int:0:1'),
			'count_in_dolibarr' => array('1', 'int:0:1'),
			'browser_notify' => array('0', 'int:0:1'),
			'new_mail_notify' => array('auto', array('auto', '1', '0')),
			'notif_channels' => array('', 'json'),
			// Read receipts asked by the mails (1.22.0): ask each time, or never send (the question is not shown)
			'mdn_mode' => array('ask', array('ask', 'never')),
			// Voice typing (1.28.0): receivers of the voice the user agreed to (provider => date), written by InboxDictation
			'dictation_consent' => array('', 'json'),
			// Pieces of voice sent to the transcription server in the current hour, kept by ajax/dictation.php (1.35.1)
			'dictation_usage' => array('', 'json'),
			// Unified views (1.29.0): the ones shown in the section « Views », the mailboxes left out of them
			'views_shown' => array('["inbox","unread","flagged","mine"]', 'json'),
			// One mailbox at a time (1.30.0): auto (on a narrow screen), all, single; the mailbox shown
			'tree_mode' => array('auto', array('auto', 'all', 'single')),
			'tree_box' => array('0', 'int:0:2147483647'),
			'unified_exclude' => array('[]', 'json'),
			// Virtual views (1.31.0): the views shared with the user that he hides
			'views_hidden' => array('[]', 'json'),
			// Order of the folders among their brothers, per mailbox: {"<mailbox>":[folders]} (1.42.0)
			'folder_order' => array('{}', 'json'),
			// Size of the folders shown in the panel (menu of the panel, 1.40.0)
			'folder_sizes' => array('0', 'int:0:1'),
			// Folders with subfolders the user opened in the panel (f<id>), the others closed (1.39.0)
			'tree_open' => array('[]', 'json'),
			// Order of the mailboxes of the user, ids (My account, 1.37.0); the others after, by name
			'accounts_order' => array('[]', 'json'),
			// Views shared with the user whose number of unread he shows (1.35.1)
			'views_counted' => array('[]', 'json'),
		);
	}

	/**
	 * @param string $param Setting
	 * @param string $value Value
	 * @return bool
	 */
	public static function isValid($param, $value)
	{
		$defs = self::definitions();
		if (!isset($defs[$param])) {
			return false;
		}
		$check = $defs[$param][1];
		$value = (string) $value;
		if (is_array($check)) {
			return in_array($value, $check, true);
		}
		if (strpos($check, 'int:') === 0) {
			list(, $min, $max) = explode(':', $check);
			return preg_match('/^-?\d+$/', $value) && (int) $value >= (int) $min && (int) $value <= (int) $max;
		}
		if ($check === 'json') {
			return $value === '' || (strlen($value) <= 4000 && is_array(json_decode($value, true)));
		}

		return false;
	}

	/**
	 * Value of a setting for a user (and a mailbox)
	 *
	 * @param User   $user      User
	 * @param string $param     Setting
	 * @param int    $accountId Mailbox, 0 = all
	 * @return string            Value ('' for an unknown setting)
	 */
	public function get(User $user, $param, $accountId = 0)
	{
		$defs = self::definitions();
		if (!isset($defs[$param])) {
			return '';
		}
		$values = $this->load($user);
		if ((int) $accountId > 0 && isset($values[((int) $accountId).':'.$param])) {
			return $values[((int) $accountId).':'.$param];
		}
		if (isset($values['0:'.$param])) {
			return $values['0:'.$param];
		}
		$global = getDolGlobalString('INBOX_DEFAULT_'.strtoupper($param));
		if ($global !== '' && self::isValid($param, $global)) {
			return $global;
		}

		return $defs[$param][0];
	}

	/**
	 * @param User $user User
	 * @return array<string,string> "account:param" => value
	 */
	protected function load(User $user)
	{
		$uid = (int) $user->id;
		if (isset(self::$cache[$uid])) {
			return self::$cache[$uid];
		}
		$values = array();
		$rows = $this->db->getRows("SELECT fk_account, param, value FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = ".$uid." LIMIT 10000");
		foreach (is_array($rows) ? $rows : array() as $row) {
			$values[((int) $row->fk_account).':'.$row->param] = (string) $row->value;
		}
		self::$cache[$uid] = $values;

		return $values;
	}

	/**
	 * Set a setting (replay safe: update or insert)
	 *
	 * @param User   $user      User
	 * @param string $param     Setting
	 * @param string $value     Value
	 * @param int    $accountId Mailbox, 0 = all
	 * @return int               > 0 if OK, < 0 if KO
	 */
	public function set(User $user, $param, $value, $accountId = 0)
	{
		if (!self::isValid($param, $value)) {
			$this->error = 'InboxErrorUserParam';
			return -1;
		}
		$prefix = $this->db->prefix();
		$where = " WHERE fk_user = ".((int) $user->id)." AND fk_account = ".((int) $accountId)." AND param = '".$this->db->escape($param)."'";
		$exists = $this->db->getRow("SELECT rowid FROM ".$prefix."inbox_userparam".$where);
		if (is_object($exists)) {
			$ok = $this->db->query("UPDATE ".$prefix."inbox_userparam SET value = '".$this->db->escape((string) $value)."'".$where);
		} else {
			$ok = $this->db->query("INSERT INTO ".$prefix."inbox_userparam (fk_user, fk_account, param, value) VALUES (".((int) $user->id).", ".((int) $accountId).", '".$this->db->escape($param)."', '".$this->db->escape((string) $value)."')");
		}
		unset(self::$cache[(int) $user->id]);
		if (!$ok) {
			$this->error = $this->db->lasterror();
			return -2;
		}

		return 1;
	}

	/**
	 * Remove a setting (back to the next level of the cascade)
	 *
	 * @param User   $user      User
	 * @param string $param     Setting
	 * @param int    $accountId Mailbox, 0 = all
	 * @return int               > 0 if OK
	 */
	public function remove(User $user, $param, $accountId = 0)
	{
		unset(self::$cache[(int) $user->id]);
		$ok = $this->db->query("DELETE FROM ".$this->db->prefix()."inbox_userparam WHERE fk_user = ".((int) $user->id)
			." AND fk_account = ".((int) $accountId)." AND param = '".$this->db->escape((string) $param)."'");

		return $ok ? 1 : -1;
	}

	/**
	 * Every setting of a user, with its effective value (for the screen My account and the JS context)
	 *
	 * @param User $user      User
	 * @param int  $accountId Mailbox, 0 = all
	 * @return array<string,string>
	 */
	public function getAll(User $user, $accountId = 0)
	{
		$out = array();
		foreach (array_keys(self::definitions()) as $param) {
			$out[$param] = $this->get($user, $param, $accountId);
		}

		return $out;
	}
}
