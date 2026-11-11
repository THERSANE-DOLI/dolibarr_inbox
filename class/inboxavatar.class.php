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
 * \file    inbox/class/inboxavatar.class.php
 * \ingroup inbox
 * \brief   Pictures of the correspondents (lot W3, 1.5.0)
 */

/**
 * Picture of a correspondent, looked for by the server, never by the browser (neither the address of the workstation
 * nor the moment of the reading leave Dolibarr). Order:
 * 1. Dolibarr: photo of the user of the address, else of the contact, else logo of the thirdparty recognised;
 * 2. BIMI (logo published in the DNS of the domain), only for a mail that passed DMARC from a known sender (domain
 *    recognized or thirdparty of the address, 1.88.6): a lookalike domain may publish the logo of another company;
 * 3. Gravatar, off by default (a hash of the address of each correspondent would be sent to a third party);
 * 4. nothing: the application shows the initials.
 * Kept 30 days when found, 7 days when not (llx_inbox_avatar); images only, size and time limited; SVG of BIMI
 * rebuilt from an allow list (InboxSvg, 1.88.6) and served as an image, with a CSP that forbids any script when
 * opened alone.
 */
class InboxAvatar
{
	const FOUND_DAYS = 30;
	const MISSING_DAYS = 7;
	const MAX_BYTES = 65536;

	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * @return string Folder of the cached pictures
	 */
	public static function dir()
	{
		return DOL_DATA_ROOT.'/inbox/avatars';
	}

	/**
	 * Picture for an address
	 *
	 * @param string $email Address, lower case
	 * @param int    $socid Thirdparty recognised by the index (0 if none)
	 * @param bool   $bimi  The mail passed DMARC and its sender is known: BIMI may be used
	 * @return array{type:string,data:string}|null Picture, null for the initials
	 */
	public function get($email, $socid = 0, $bimi = false)
	{
		$email = strtolower(trim((string) $email));
		if (!isValidEmail($email)) {
			return null;
		}
		if (getDolGlobalString('INBOX_AVATAR_DOLIBARR', '1') === '1') {
			$pic = $this->dolibarr($email, (int) $socid);
			if ($pic) {
				return $pic;
			}
		}
		$hash = sha1($email);
		$sources = array();
		if ($bimi && getDolGlobalString('INBOX_AVATAR_BIMI', '0') === '1') {
			$sources[] = 'bimi';
		}
		if (getDolGlobalString('INBOX_AVATAR_GRAVATAR', '0') === '1') {
			$sources[] = 'gravatar';
		}
		foreach ($sources as $source) {
			$key = $source === 'bimi' ? sha1(substr($email, strpos($email, '@') + 1)) : $hash;
			$cached = $this->cached($key, $source);
			if ($cached === false) {
				$pic = $source === 'bimi' ? $this->bimi(substr($email, strpos($email, '@') + 1)) : $this->gravatar($email);
				$this->remember($key, $source, $pic);
				$cached = $pic;
			}
			if ($cached) {
				return $cached;
			}
		}

		return null;
	}

	/**
	 * Photo of the user, of the contact, logo of the thirdparty (thumbnail when there is one)
	 *
	 * @param string $email Address
	 * @param int    $socid Thirdparty recognised
	 * @return array{type:string,data:string}|null
	 */
	protected function dolibarr($email, $socid)
	{
		global $conf;

		$p = $this->db->prefix();
		$e = "'".$this->db->escape($email)."'";
		// The user first: the person himself, a contact with the same address is a copy (same order as the head of the
		// reading, where a user of Dolibarr shows his photo)
		$u = $this->db->getRow("SELECT rowid, photo FROM ".$p."user WHERE LOWER(email) = ".$e." AND photo IS NOT NULL AND photo <> '' AND statut = 1"
			." AND (fk_soc IS NULL OR fk_soc = 0) AND entity IN (0, ".getEntity('user').") ORDER BY rowid");
		if (is_object($u)) {
			$pic = $this->file($conf->user->dir_output.'/'.((int) $u->rowid).'/photos', (string) $u->photo);
			if ($pic) {
				return $pic;
			}
		}
		$c = $this->db->getRow("SELECT rowid, photo, entity FROM ".$p."socpeople WHERE email = ".$e." AND photo IS NOT NULL AND photo <> '' AND entity IN (".getEntity('contact').")");
		if (is_object($c)) {
			$base = $conf->societe->multidir_output[(int) $c->entity] ?? $conf->societe->dir_output;
			$pic = $this->file($base.'/contact/'.((int) $c->rowid).'/photos', (string) $c->photo);
			if ($pic) {
				return $pic;
			}
		}
		if ($socid > 0) {
			$s = $this->db->getRow("SELECT rowid, logo, entity FROM ".$p."societe WHERE rowid = ".((int) $socid)." AND logo IS NOT NULL AND logo <> ''");
			if (is_object($s)) {
				$base = $conf->societe->multidir_output[(int) $s->entity] ?? $conf->societe->dir_output;
				$pic = $this->file($base.'/'.((int) $s->rowid).'/logos', (string) $s->logo);
				if ($pic) {
					return $pic;
				}
			}
		}

		return null;
	}

	/**
	 * Small thumbnail of a picture of Dolibarr, else the picture itself
	 *
	 * @param string $dir  Folder
	 * @param string $name File name
	 * @return array{type:string,data:string}|null
	 */
	protected function file($dir, $name)
	{
		$name = basename($name);
		$dot = strrpos($name, '.');
		$small = $dot !== false ? substr($name, 0, $dot).'_small'.substr($name, $dot) : '';
		foreach (array($small !== '' ? $dir.'/thumbs/'.$small : '', $dir.'/'.$name) as $path) {
			if ($path !== '' && is_readable($path) && filesize($path) <= 2097152) {
				$data = (string) file_get_contents($path);
				$type = self::imageType($data);
				if ($type) {
					return array('type' => $type, 'data' => $data);
				}
			}
		}

		return null;
	}

	/**
	 * BIMI: TXT record default._bimi.<domain> « v=BIMI1; l=https://...svg », logo fetched in https only, never on a
	 * local address, 64 KB and 4 s at most
	 *
	 * @param string $domain Domain of the sender
	 * @return array{type:string,data:string}|null
	 */
	protected function bimi($domain)
	{
		if (!preg_match('/^[a-z0-9.-]{3,253}$/', $domain) || !function_exists('dns_get_record')) {
			return null;
		}
		$records = @dns_get_record('default._bimi.'.$domain, DNS_TXT);
		$url = '';
		foreach (is_array($records) ? $records : array() as $r) {
			$txt = isset($r['txt']) ? (string) $r['txt'] : implode('', (array) ($r['entries'] ?? array()));
			if (preg_match('/v=BIMI1/i', $txt) && preg_match('/\bl=(https:\/\/[^;\s]+)/i', $txt, $m)) {
				$url = $m[1];
				break;
			}
		}
		if ($url === '') {
			return null;
		}

		return $this->fetch($url);
	}

	/**
	 * @param string $email Address
	 * @return array{type:string,data:string}|null
	 */
	protected function gravatar($email)
	{
		return $this->fetch('https://www.gravatar.com/avatar/'.md5($email).'?s=96&d=404');
	}

	/**
	 * @param string $url Address (https)
	 * @return array{type:string,data:string}|null
	 */
	protected function fetch($url)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		// IPv4 only: the check of the private and local addresses of the core resolves IPv4 only (audit INBX-009)
		$res = getURLContent($url, 'GET', '', 1, array(), array('https'), 0, -1, 3, 4, array(CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4));
		if (!is_array($res) || (int) ($res['http_code'] ?? 0) !== 200 || !isset($res['content']) || strlen((string) $res['content']) > self::MAX_BYTES) {
			return null;
		}
		return self::picture((string) $res['content']);
	}

	/**
	 * Picture from a content out of Dolibarr: its type, an SVG cleaned (InboxSvg)
	 *
	 * @param string $data Content
	 * @return array{type:string,data:string}|null
	 */
	public static function picture($data)
	{
		$type = self::imageType($data);
		if ($type === 'image/svg+xml') {
			require_once __DIR__.'/inboxsvg.class.php';
			$data = InboxSvg::clean($data);
			$type = $data !== '' ? $type : '';
		}

		return $type ? array('type' => $type, 'data' => $data) : null;
	}

	/**
	 * Type read from the content: PNG, JPEG, GIF, WebP, SVG (SVG Tiny of BIMI); anything else refused
	 *
	 * @param string $data Content
	 * @return string MIME type, '' if not a picture
	 */
	public static function imageType($data)
	{
		$head = substr((string) $data, 0, 12);
		if (strpos($head, "\x89PNG") === 0) {
			return 'image/png';
		}
		if (strpos($head, "\xFF\xD8\xFF") === 0) {
			return 'image/jpeg';
		}
		if (strpos($head, 'GIF8') === 0) {
			return 'image/gif';
		}
		if (strpos($head, 'RIFF') === 0 && substr($head, 8, 4) === 'WEBP') {
			return 'image/webp';
		}
		if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', substr((string) $data, 0, 2048))) {
			return 'image/svg+xml';
		}

		return '';
	}

	/**
	 * @param string $key    Hash
	 * @param string $source Source
	 * @return array{type:string,data:string}|null|false Picture, null (none, still valid), false (not known or too old)
	 */
	protected function cached($key, $source)
	{
		$row = $this->db->getRow("SELECT file, date_check FROM ".$this->db->prefix()."inbox_avatar WHERE hash = '".$this->db->escape($key)."' AND source = '".$this->db->escape($source)."'");
		if (!is_object($row)) {
			return false;
		}
		$age = dol_now() - (int) $this->db->jdate($row->date_check);
		if ((string) $row->file === '') {
			return $age < self::MISSING_DAYS * 86400 ? null : false;
		}
		$path = self::dir().'/'.basename((string) $row->file);
		if ($age >= self::FOUND_DAYS * 86400 || !is_readable($path)) {
			return false;
		}
		// A file kept before 1.88.6 is cleaned as well
		$pic = self::picture((string) file_get_contents($path));

		return $pic ?: false;
	}

	/**
	 * @param string                                  $key    Hash
	 * @param string                                  $source Source
	 * @param array{type:string,data:string}|null $pic    Picture or null
	 * @return void
	 */
	protected function remember($key, $source, $pic)
	{
		$file = '';
		if ($pic) {
			$dir = self::dir();
			if (!is_dir($dir)) {
				dol_mkdir($dir);
			}
			$file = $source.'-'.$key.'.'.array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg')[$pic['type']];
			if (@file_put_contents($dir.'/'.$file, $pic['data']) === false) {
				$file = '';
			}
		}
		$p = $this->db->prefix();
		$this->db->query("DELETE FROM ".$p."inbox_avatar WHERE hash = '".$this->db->escape($key)."' AND source = '".$this->db->escape($source)."'");
		$this->db->query("INSERT INTO ".$p."inbox_avatar (hash, source, file, date_check) VALUES ('".$this->db->escape($key)."', '".$this->db->escape($source)."', '".$this->db->escape($file)."', '".$this->db->idate(dol_now())."')");
	}
}
