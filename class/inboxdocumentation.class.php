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
 * \file    inbox/class/inboxdocumentation.class.php
 * \ingroup inbox
 * \brief   Index of the documentation of the module (1.113.0), as the menu of the UI documentation of Dolibarr
 */

/**
 * Every page of the documentation is declared here: its key, its title and description (translation keys), the
 * version that brought it, its files by language. The key asked by the page is only looked for in this index, a file
 * is never found from it: a key out of the index shows the summary.
 *
 * A new feature brings its page here in the same version. A translated page adds its language in `files`; the others
 * read the English one.
 */
class InboxDocumentation
{
	/** Language read when the page has no file for the language of the user */
	const DEFAULT_LANG = 'en_US';

	/**
	 * @var array<string,array{icon:string,pages:array<string,array{icon:string,version:string,files:array<string,string>}>}>
	 *      Groups (translation key => icon, pages); the title of a page is InboxDocTitle_<key>, its description
	 *      InboxDocDesc_<key>; files: language => path in doc/
	 */
	public $menu = array();

	/**
	 * Index of the pages
	 */
	public function __construct()
	{
		$this->menu = array(
			'InboxDocGroupStart' => array('icon' => 'fa-rocket', 'pages' => array(
				'install' => array('icon' => 'fa-download', 'version' => '1.0.0', 'files' => array('en_US' => 'admin/install.md')),
				'application' => array('icon' => 'fa-inbox', 'version' => '1.148.0', 'files' => array('en_US' => 'admin/application.md')),
				'reactivity' => array('icon' => 'fa-bolt', 'version' => '1.122.0', 'files' => array('en_US' => 'admin/reactivity.md')),
				'push' => array('icon' => 'fa-broadcast-tower', 'version' => '1.124.0', 'files' => array('en_US' => 'admin/push.md')),
				'mailto_links' => array('icon' => 'fa-at', 'version' => '1.123.0', 'files' => array('en_US' => 'admin/mailto-links.md')),
			)),
			'InboxDocGroupWork' => array('icon' => 'fa-pen', 'pages' => array(
				'writing_editor' => array('icon' => 'fa-edit', 'version' => '1.94.0', 'files' => array('en_US' => 'admin/writing-editor.md')),
				'mail_templates' => array('icon' => 'fa-file-alt', 'version' => '1.118.0', 'files' => array('en_US' => 'admin/mail-templates.md')),
				'dolibarr_sent' => array('icon' => 'fa-paper-plane', 'version' => '1.132.0', 'files' => array('en_US' => 'admin/dolibarr-sent.md')),
				'conversations' => array('icon' => 'fa-comments', 'version' => '1.139.0', 'files' => array('en_US' => 'admin/conversations.md')),
				'views' => array('icon' => 'fa-layer-group', 'version' => '1.141.0', 'files' => array('en_US' => 'admin/views.md')),
				'agenda_panel' => array('icon' => 'fa-calendar-alt', 'version' => '1.107.0', 'files' => array('en_US' => 'admin/agenda-panel.md')),
				'new_customer' => array('icon' => 'fa-user-plus', 'version' => '1.79.0', 'files' => array('en_US' => 'admin/new-customer.md')),
			)),
			'InboxDocGroupReception' => array('icon' => 'fa-shield-alt', 'pages' => array(
				'anti_spam' => array('icon' => 'fa-ban', 'version' => '1.85.0', 'files' => array('en_US' => 'admin/anti-spam.md')),
				'remote_pictures' => array('icon' => 'fa-image', 'version' => '1.149.0', 'files' => array('en_US' => 'admin/remote-pictures.md')),
				'shared_domains' => array('icon' => 'fa-globe', 'version' => '1.76.0', 'files' => array('en_US' => 'admin/shared-domains.md')),
				'data_sources' => array('icon' => 'fa-file-import', 'version' => '1.72.0', 'files' => array('en_US' => 'admin/data-sources.md')),
				'module_subscriptions' => array('icon' => 'fa-plug', 'version' => '1.112.0', 'files' => array('en_US' => 'admin/module-subscriptions.md')),
				'gone_mails' => array('icon' => 'fa-unlink', 'version' => '1.114.0', 'files' => array('en_US' => 'admin/gone-mails.md')),
			)),
			'InboxDocGroupDev' => array('icon' => 'fa-code', 'pages' => array(
				'dev_principles' => array('icon' => 'fa-balance-scale', 'version' => '1.113.0', 'files' => array('en_US' => 'dev/principles.md')),
				'dev_overview' => array('icon' => 'fa-book', 'version' => '1.0.0', 'files' => array('en_US' => 'dev/README.md')),
				'dev_extend' => array('icon' => 'fa-puzzle-piece', 'version' => '', 'files' => array('en_US' => 'dev/extend-application.md')),
				'dev_agenda' => array('icon' => 'fa-calendar-alt', 'version' => '1.107.0', 'files' => array('en_US' => 'dev/agenda.md')),
				'dev_new_customer' => array('icon' => 'fa-user-plus', 'version' => '1.79.0', 'files' => array('en_US' => 'dev/new-customer.md')),
				'dev_consumers' => array('icon' => 'fa-plug', 'version' => '1.112.0', 'files' => array('en_US' => 'dev/consumers.md')),
				'dev_combo' => array('icon' => 'fa-list', 'version' => '1.80.0', 'files' => array('en_US' => 'dev/combo.md')),
				'dev_push' => array('icon' => 'fa-broadcast-tower', 'version' => '1.124.0', 'files' => array('en_US' => 'dev/push.md')),
				'dev_mail_data' => array('icon' => 'fa-broom', 'version' => '1.104.0', 'files' => array('en_US' => 'dev/mail-data.md')),
				'dev_development' => array('icon' => 'fa-tools', 'version' => '', 'files' => array('en_US' => 'dev/development.md')),
			)),
		);
	}

	/**
	 * A page of the index
	 *
	 * @param string $key Key of the page
	 * @return array{icon:string,version:string,files:array<string,string>}|null Null when the key is not in the index
	 */
	public function page($key)
	{
		foreach ($this->menu as $group) {
			if (isset($group['pages'][$key])) {
				return $group['pages'][$key];
			}
		}

		return null;
	}

	/**
	 * Path in doc/ of a page, in the language asked or else in English; only from the index
	 *
	 * @param string $key  Key of the page
	 * @param string $lang Language (fr_FR)
	 * @return string Empty when the key is not in the index
	 */
	public function file($key, $lang)
	{
		$page = $this->page($key);
		if ($page === null) {
			return '';
		}

		return isset($page['files'][$lang]) ? $page['files'][$lang] : (isset($page['files'][self::DEFAULT_LANG]) ? $page['files'][self::DEFAULT_LANG] : '');
	}

	/**
	 * The page is read in another language than the one asked (English when it has no translation)
	 *
	 * @param string $key  Key of the page
	 * @param string $lang Language asked
	 * @return string Language of the file read, '' when it is the one asked
	 */
	public function otherLang($key, $lang)
	{
		$page = $this->page($key);
		if ($page === null || isset($page['files'][$lang])) {
			return '';
		}

		return self::DEFAULT_LANG;
	}

	/**
	 * The most recent pages, by the version that brought them
	 *
	 * @param int $count How many
	 * @return string[] Keys, the most recent first
	 */
	public function recent($count)
	{
		$all = array();
		foreach ($this->menu as $group) {
			foreach ($group['pages'] as $key => $page) {
				if ($page['version'] !== '') {
					$all[$key] = $page['version'];
				}
			}
		}
		uasort($all, function ($a, $b) {
			return version_compare($b, $a);
		});

		return array_slice(array_keys($all), 0, (int) $count);
	}

	/**
	 * The page came with one of the last series of the module installed (X.Y to X.Y-2)
	 *
	 * @param string $key           Key of the page
	 * @param string $moduleVersion Version of the module (1.113.0)
	 * @return bool
	 */
	public function isNew($key, $moduleVersion)
	{
		$page = $this->page($key);
		if ($page === null || $page['version'] === '') {
			return false;
		}
		$p = array_map('intval', explode('.', $page['version']));
		$m = array_map('intval', explode('.', (string) $moduleVersion));

		return count($p) > 1 && count($m) > 1 && $p[0] === $m[0] && $p[1] >= $m[1] - 2 && $p[1] <= $m[1];
	}

	/**
	 * Key of the page of a file, to turn the links between the files (« ../dev/consumers.md ») into links of the tab
	 *
	 * @param string $path Path in doc/ (dev/consumers.md)
	 * @return string Empty when no page of the index has this file
	 */
	public function keyOfFile($path)
	{
		foreach ($this->menu as $group) {
			foreach ($group['pages'] as $key => $page) {
				if (in_array($path, $page['files'], true)) {
					return $key;
				}
			}
		}

		return '';
	}

	/**
	 * A page in HTML: the links to the other files become links of the tab (through the index), {{conf:NAME}} shows the
	 * state of a constant of the instance
	 *
	 * @param string    $key   Key of the page
	 * @param Translate $langs Languages
	 * @param string    $self  URL of the tab
	 * @return string HTML, empty when the page has no readable file
	 */
	public function render($key, Translate $langs, $self)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/parsemd.lib.php';

		$rel = $this->file($key, $langs->defaultlang);
		$file = $rel !== '' ? dirname(__DIR__).'/doc/'.$rel : '';
		if ($file === '' || !is_readable($file)) {
			return '';
		}
		$html = dolMd2Html((string) file_get_contents($file));
		$folder = dirname($rel);
		$html = preg_replace_callback('/href="((?:\.\.\/)?[A-Za-z0-9\/-]+\.md)(?:#[a-z0-9-]*)?"/', function ($m) use ($folder, $self) {
			$target = strpos($m[1], '../') === 0 ? substr($m[1], 3) : $folder.'/'.$m[1];
			$key = $this->keyOfFile($target);
			return 'href="'.dol_escape_htmltag($self).($key !== '' ? '?page='.urlencode($key) : '').'"';
		}, $html);

		return preg_replace_callback('/\{\{conf:([A-Z0-9_]+)\}\}/', function ($m) use ($langs) {
			$on = !in_array(getDolGlobalString($m[1]), array('', '0'), true);
			return '<code>'.$m[1].'</code> '.dolGetBadge($langs->trans($on ? 'InboxDocConfOn' : 'InboxDocConfOff'), '', $on ? 'status4' : 'secondary');
		}, $html);
	}
}
