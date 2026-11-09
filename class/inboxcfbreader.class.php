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
 * \file    inbox/class/inboxcfbreader.class.php
 * \ingroup inbox
 * \brief   Reader of Compound File Binary files (OLE: Outlook .msg), read only
 */

/**
 * Reader of Compound File Binary files ([MS-CFB]), the container of the Outlook .msg files. Read only, whole file in
 * memory. The OLE reader of PhpSpreadsheet is not used: it does not keep the tree of the storages, needed to know which
 * stream belongs to which attachment.
 *
 * The file is not trusted: every sector number and chain is checked, loops are cut, sizes are bounded.
 */
class InboxCfbReader
{
	const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	const ENDOFCHAIN = 0xFFFFFFFE;
	const FREESECT = 0xFFFFFFFF;
	const NOSTREAM = 0xFFFFFFFF;

	const TYPE_STORAGE = 1;
	const TYPE_STREAM = 2;
	const TYPE_ROOT = 5;

	/** Files larger than this are refused */
	const MAX_FILE_SIZE = 62914560;

	/** @var string */
	public $error = '';

	/** @var string */
	protected $data = '';

	/** @var int */
	protected $sectorSize = 512;

	/** @var int */
	protected $miniSectorSize = 64;

	/** @var int */
	protected $miniCutoff = 4096;

	/** @var int[] */
	protected $fat = array();

	/** @var int[] */
	protected $miniFat = array();

	/** @var string Mini stream (content of the root entry) */
	protected $miniStream = '';

	/**
	 * Directory entries
	 *
	 * @var array<int,array{name:string,type:int,left:int,right:int,child:int,start:int,size:int}>
	 */
	protected $entries = array();

	/**
	 * @param string $data Content of the file
	 * @return bool
	 */
	public function load($data)
	{
		$this->error = '';
		$this->data = (string) $data;
		if (strlen($this->data) < 512 || strncmp($this->data, self::SIGNATURE, 8) !== 0) {
			return $this->fail('Not an OLE compound file');
		}
		if (strlen($this->data) > self::MAX_FILE_SIZE) {
			return $this->fail('File too large');
		}
		$shift = self::u16($this->data, 0x1E);
		$miniShift = self::u16($this->data, 0x20);
		if (($shift !== 9 && $shift !== 12) || $miniShift !== 6) {
			return $this->fail('Unsupported sector size');
		}
		$this->sectorSize = 1 << $shift;
		$this->miniSectorSize = 1 << $miniShift;
		$numFatSectors = self::u32($this->data, 0x2C);
		$firstDirSector = self::u32($this->data, 0x30);
		$this->miniCutoff = self::u32($this->data, 0x38);
		$firstMiniFatSector = self::u32($this->data, 0x3C);
		$numMiniFatSectors = self::u32($this->data, 0x40);
		$firstDifatSector = self::u32($this->data, 0x44);
		$numDifatSectors = self::u32($this->data, 0x48);
		$maxSectors = (int) ceil(strlen($this->data) / $this->sectorSize);
		if ($numFatSectors > $maxSectors || $numMiniFatSectors > $maxSectors || $numDifatSectors > $maxSectors) {
			return $this->fail('Corrupted header');
		}

		// Sectors of the FAT: 109 in the header, the next ones in the DIFAT chain
		$fatSectors = array();
		for ($i = 0; $i < 109 && count($fatSectors) < $numFatSectors; $i++) {
			$fatSectors[] = self::u32($this->data, 0x4C + 4 * $i);
		}
		$difat = $firstDifatSector;
		$perDifat = $this->sectorSize / 4 - 1;
		for ($n = 0; $n < $numDifatSectors && $difat < self::ENDOFCHAIN && count($fatSectors) < $numFatSectors; $n++) {
			$sector = $this->sector($difat);
			if ($sector === null) {
				return $this->fail('Corrupted DIFAT');
			}
			for ($i = 0; $i < $perDifat && count($fatSectors) < $numFatSectors; $i++) {
				$fatSectors[] = self::u32($sector, 4 * $i);
			}
			$difat = self::u32($sector, 4 * $perDifat);
		}
		$this->fat = array();
		foreach ($fatSectors as $fatSector) {
			$sector = $this->sector($fatSector);
			if ($sector === null) {
				return $this->fail('Corrupted FAT');
			}
			$this->fat = array_merge($this->fat, array_values(unpack('V*', $sector)));
		}

		$directory = $this->chain($firstDirSector);
		if ($directory === null) {
			return $this->fail('Corrupted directory');
		}
		$this->entries = array();
		$count = intdiv(strlen($directory), 128);
		for ($i = 0; $i < $count; $i++) {
			$raw = substr($directory, 128 * $i, 128);
			$nameLength = min(64, self::u16($raw, 0x40));
			$name = $nameLength > 2 ? (string) mb_convert_encoding(substr($raw, 0, $nameLength - 2), 'UTF-8', 'UTF-16LE') : '';
			$this->entries[$i] = array(
				'name' => $name,
				'type' => ord($raw[0x42]),
				'left' => self::u32($raw, 0x44),
				'right' => self::u32($raw, 0x48),
				'child' => self::u32($raw, 0x4C),
				'start' => self::u32($raw, 0x74),
				// Version 3 files: the high 32 bits may be garbage, a stream is never above 4 GB here
				'size' => self::u32($raw, 0x78),
			);
		}
		if (empty($this->entries) || $this->entries[0]['type'] !== self::TYPE_ROOT) {
			return $this->fail('No root entry');
		}

		$this->miniFat = array();
		if ($numMiniFatSectors > 0) {
			$miniFat = $this->chain($firstMiniFatSector);
			if ($miniFat === null) {
				return $this->fail('Corrupted mini FAT');
			}
			$this->miniFat = array_values(unpack('V*', $miniFat));
		}
		$root = $this->entries[0];
		$this->miniStream = '';
		if ($root['size'] > 0) {
			$miniStream = $this->chain($root['start']);
			if ($miniStream === null) {
				return $this->fail('Corrupted mini stream');
			}
			$this->miniStream = substr($miniStream, 0, $root['size']);
		}

		return true;
	}

	/**
	 * @return int Index of the root storage
	 */
	public function root()
	{
		return 0;
	}

	/**
	 * Entries directly inside a storage
	 *
	 * @param int $storage Index of the storage
	 * @return array<string,int> Name => index
	 */
	public function children($storage)
	{
		$out = array();
		if (!isset($this->entries[$storage])) {
			return $out;
		}
		// The children are a red-black tree: in-order walk through the left and right siblings, each entry once
		$stack = array($this->entries[$storage]['child']);
		$seen = array();
		while (!empty($stack)) {
			$index = array_pop($stack);
			if ($index === self::NOSTREAM || !isset($this->entries[$index]) || isset($seen[$index])) {
				continue;
			}
			$seen[$index] = true;
			$entry = $this->entries[$index];
			$out[$entry['name']] = $index;
			$stack[] = $entry['left'];
			$stack[] = $entry['right'];
		}

		return $out;
	}

	/**
	 * @param int $index Index of an entry
	 * @return bool
	 */
	public function isStorage($index)
	{
		return isset($this->entries[$index]) && $this->entries[$index]['type'] === self::TYPE_STORAGE;
	}

	/**
	 * Content of a stream
	 *
	 * @param int $index Index of the stream
	 * @return string '' if not a stream or unreadable
	 */
	public function stream($index)
	{
		if (!isset($this->entries[$index]) || $this->entries[$index]['type'] !== self::TYPE_STREAM) {
			return '';
		}
		$entry = $this->entries[$index];
		if ($entry['size'] === 0) {
			return '';
		}
		if ($entry['size'] < $this->miniCutoff) {
			$data = $this->miniChain($entry['start'], $entry['size']);
		} else {
			$data = $this->chain($entry['start'], $entry['size']);
		}

		return $data === null ? '' : substr($data, 0, $entry['size']);
	}

	/**
	 * @param int $number Sector number
	 * @return string|null
	 */
	protected function sector($number)
	{
		$offset = ($number + 1) * $this->sectorSize;
		if ($number >= self::ENDOFCHAIN - 4 || $offset + $this->sectorSize > strlen($this->data) + $this->sectorSize) {
			return null;
		}

		return str_pad(substr($this->data, $offset, $this->sectorSize), $this->sectorSize, "\0");
	}

	/**
	 * Sectors of a chain of the FAT, joined
	 *
	 * @param int $start   First sector
	 * @param int $maxSize Stop once this size is read (0: whole chain)
	 * @return string|null Null on a broken chain
	 */
	protected function chain($start, $maxSize = 0)
	{
		$out = '';
		$seen = array();
		$sector = $start;
		while ($sector !== self::ENDOFCHAIN && $sector !== self::FREESECT) {
			if (isset($seen[$sector]) || !isset($this->fat[$sector])) {
				return null;
			}
			$seen[$sector] = true;
			$content = $this->sector($sector);
			if ($content === null) {
				return null;
			}
			$out .= $content;
			if ($maxSize > 0 && strlen($out) >= $maxSize) {
				break;
			}
			$sector = $this->fat[$sector];
		}

		return $out;
	}

	/**
	 * Mini sectors of a chain of the mini FAT, joined
	 *
	 * @param int $start First mini sector
	 * @param int $size  Size of the stream
	 * @return string|null Null on a broken chain
	 */
	protected function miniChain($start, $size)
	{
		$out = '';
		$seen = array();
		$sector = $start;
		while ($sector !== self::ENDOFCHAIN && $sector !== self::FREESECT && strlen($out) < $size) {
			if (isset($seen[$sector]) || !isset($this->miniFat[$sector])) {
				return null;
			}
			$seen[$sector] = true;
			$offset = $sector * $this->miniSectorSize;
			if ($offset + $this->miniSectorSize > strlen($this->miniStream) + $this->miniSectorSize) {
				return null;
			}
			$out .= substr($this->miniStream, $offset, $this->miniSectorSize);
			$sector = $this->miniFat[$sector];
		}

		return $out;
	}

	/**
	 * @param string $message Error
	 * @return bool false
	 */
	protected function fail($message)
	{
		$this->error = $message;
		$this->data = '';

		return false;
	}

	/**
	 * @param string $data   Bytes
	 * @param int    $offset Offset
	 * @return int Unsigned 16 bits little endian
	 */
	public static function u16($data, $offset)
	{
		$value = unpack('v', substr($data, $offset, 2)."\0\0");

		return (int) $value[1];
	}

	/**
	 * @param string $data   Bytes
	 * @param int    $offset Offset
	 * @return int Unsigned 32 bits little endian
	 */
	public static function u32($data, $offset)
	{
		$value = unpack('V', substr($data, $offset, 4)."\0\0\0\0");

		return (int) $value[1];
	}
}
