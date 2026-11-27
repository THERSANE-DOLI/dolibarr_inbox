<?php
/* Copyright (C) 2026 AIR MKG
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
 * \file    inbox/class/inboxsheetreader.class.php
 * \ingroup inbox
 * \brief   Beginning of a spreadsheet (xlsx, ods, xls) as rows of cells, for the preview of an attachment
 */

require_once __DIR__.'/inboxcfbreader.class.php';

/**
 * Reader of the spreadsheets received by mail, for the preview window: the first rows of each sheet, as texts, with
 * the look their author gave them (fonts, colours, borders, alignment, widths of the columns, merged cells). Read only:
 * no formula computed (the result kept in the file is shown), no picture, no chart, no conditional formatting.
 *
 * - xlsx, xlsm, xltx, xltm (Office Open XML): a zip of XML parts, read as streams;
 * - ods, ots (OpenDocument): content.xml of the zip, read as a stream, the text shown by the author is kept;
 * - xls, xlt (Excel 97-2003, BIFF8, and BIFF5 of Excel 5/95): the stream Workbook of the OLE file (InboxCfbReader).
 *
 * The file is not trusted: nothing is unpacked on disk, XML is read node by node without entities nor network,
 * every count is bounded (sheets, rows, columns, cells, text, nodes, time). The library PhpSpreadsheet of the core is
 * not used: it builds the whole workbook in memory.
 */
class InboxSheetReader
{
	const MAX_SHEETS = 30;
	const MAX_ROWS = 1000;
	const MAX_COLS = 100;
	/** Cells kept for the whole file */
	const MAX_CELLS = 60000;
	/** Characters kept per cell */
	const MAX_CELL_LENGTH = 1000;
	/** Bytes of text kept for the whole file */
	const MAX_TEXT = 4194304;
	/** XML nodes or records read for the whole file */
	const MAX_NODES = 4000000;
	const MAX_SECONDS = 8;
	/** Different looks of cells kept for the whole file */
	const MAX_STYLES = 2000;
	/** Cells kept for their look only (a coloured or framed cell without text), for the whole file */
	const MAX_DECOR = 40000;
	/** Merged cells kept per sheet */
	const MAX_MERGES = 2000;
	/** Width of a column the file says nothing about, in pixels */
	const DEFAULT_WIDTH = 64;

	const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	const NS_STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
	const NS_FO = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';

	/** Colours of Excel named by a number (indexes 0 to 63), when the workbook does not bring its own */
	const INDEXED = array(
		'000000', 'ffffff', 'ff0000', '00ff00', '0000ff', 'ffff00', 'ff00ff', '00ffff', '000000', 'ffffff', 'ff0000', '00ff00', '0000ff',
		'ffff00', 'ff00ff', '00ffff', '800000', '008000', '000080', '808000', '800080', '008080', 'c0c0c0', '808080', '9999ff', '993366',
		'ffffcc', 'ccffff', '660066', 'ff8080', '0066cc', 'ccccff', '000080', 'ff00ff', 'ffff00', '00ffff', '800080', '800000', '008080',
		'0000ff', '00ccff', 'ccffff', 'ccffcc', 'ffff99', '99ccff', 'ff99cc', 'cc99ff', 'ffcc99', '3366ff', '33cccc', '99cc00', 'ffcc00',
		'ff9900', 'ff6600', '666699', '969696', '003366', '339966', '003300', '333300', '993300', '993366', '333399', '333333',
	);

	/** Colours of the theme of Office, in the order the cells name them, when the workbook has no theme of its own */
	const THEME = array('#ffffff', '#000000', '#eeece1', '#1f497d', '#4f81bd', '#c0504d', '#9bbb59', '#8064a2', '#4bacc6', '#f79646', '#0000ff', '#800080');

	/** Lines of the borders of Excel 97-2003, by their number: width (1 to 3) and line */
	const BIFF_BORDERS = array(
		1 => array(1, 'solid'), 2 => array(2, 'solid'), 3 => array(1, 'dashed'), 4 => array(1, 'dotted'), 5 => array(3, 'solid'), 6 => array(3, 'double'),
		7 => array(1, 'dotted'), 8 => array(2, 'dashed'), 9 => array(1, 'dashed'), 10 => array(2, 'dashed'), 11 => array(1, 'dashed'), 12 => array(2, 'dashed'),
		13 => array(2, 'dashed'),
	);

	/** Lines of the borders of Excel 2007 and later, by their name */
	const XLSX_BORDERS = array(
		'thin' => array(1, 'solid'), 'hair' => array(1, 'dotted'), 'dotted' => array(1, 'dotted'), 'dashed' => array(1, 'dashed'), 'dashDot' => array(1, 'dashed'),
		'dashDotDot' => array(1, 'dashed'), 'slantDashDot' => array(2, 'dashed'), 'medium' => array(2, 'solid'), 'mediumDashed' => array(2, 'dashed'),
		'mediumDashDot' => array(2, 'dashed'), 'mediumDashDotDot' => array(2, 'dashed'), 'thick' => array(3, 'solid'), 'double' => array(3, 'double'),
	);

	/** Number formats of Excel that are not written in the file */
	const BUILTIN_FORMATS = array(
		0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 5 => '#,##0', 6 => '#,##0', 7 => '#,##0.00', 8 => '#,##0.00',
		9 => '0%', 10 => '0.00%', 11 => '0.00E+00', 12 => '# ?/?', 13 => '# ??/??', 14 => 'm/d/yyyy', 15 => 'd-mmm-yy', 16 => 'd-mmm',
		17 => 'mmm-yy', 18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss', 22 => 'm/d/yyyy h:mm',
		27 => 'm/d/yyyy', 28 => 'm/d/yyyy', 29 => 'm/d/yyyy', 30 => 'm/d/yyyy', 31 => 'm/d/yyyy', 32 => 'h:mm', 33 => 'h:mm:ss',
		34 => 'h:mm', 35 => 'h:mm:ss', 36 => 'm/d/yyyy', 37 => '#,##0', 38 => '#,##0', 39 => '#,##0.00', 40 => '#,##0.00',
		41 => '#,##0', 42 => '#,##0', 43 => '#,##0.00', 44 => '#,##0.00', 45 => 'mm:ss', 46 => '[h]:mm:ss', 47 => 'mm:ss.0',
		48 => '##0.0E+0', 49 => '@', 50 => 'm/d/yyyy', 51 => 'm/d/yyyy', 52 => 'm/d/yyyy', 53 => 'm/d/yyyy', 54 => 'm/d/yyyy',
		55 => 'h:mm', 56 => 'h:mm', 57 => 'm/d/yyyy', 58 => 'm/d/yyyy',
	);

	const BIFF_ERRORS = array(0x00 => '#NULL!', 0x07 => '#DIV/0!', 0x0F => '#VALUE!', 0x17 => '#REF!', 0x1D => '#NAME?', 0x24 => '#NUM!', 0x2A => '#N/A');

	/** @var string '' or why nothing is shown: protected (password), unreadable */
	public $error = '';

	/** @var bool Something was left out (rows, columns, sheets, text) */
	public $truncated = false;

	/** @var string Decimal separator of the numbers */
	protected $decimal = '.';

	/** @var string Thousands separator of the numbers whose format asks one */
	protected $thousand = '';

	/** @var callable|null function (int $timestamp, string $kind): string, kind = day, dayhour, dayhoursec, hour, hoursec */
	protected $dateFormatter = null;

	/**
	 * Sheets being read: cells by row then column (0 based). A cell is array('v' => text, 't' => '', n or d, 's' =>
	 * its look when it has one), or array('p' => index) while its shared string is not read yet. widths: pixels by
	 * column; width: of the columns not named (0 = not said); merges: array(row, column, rows, columns)
	 *
	 * @var array<int,array{name:string,cells:array<int,array<int,array<string,mixed>>>,truncated:bool,widths:array<int,int>,width:int,merges:array<int,array<int,int>>}>
	 */
	protected $sheets = array();

	/** @var array<int,array<string,mixed>> Looks of the cells (see styleId()), each one once */
	protected $styles = array();

	/** @var array<string,int> A look as JSON => its index in $styles */
	protected $styleKeys = array();

	/** @var int Cells kept for their look only */
	protected $decor = 0;

	/** @var float Size of the font of the workbook, in points: the sizes of the cells are given against it (0 = not said) */
	protected $baseSize = 0.0;

	/** @var array<string,array<string,mixed>> Styles of an OpenDocument file: family:name => parent, look, width */
	protected $odsStyles = array();

	/** @var array<string,int> Styles of the cells of an OpenDocument file already turned into a look: name => index */
	protected $odsLooks = array();

	/** @var int */
	protected $cells = 0;

	/** @var int */
	protected $text = 0;

	/** @var int */
	protected $nodes = 0;

	/** @var float */
	protected $started = 0.0;

	/** @var bool Dates counted from 1904 (workbooks made on old Macs) */
	protected $date1904 = false;

	/** @var array<string,array<string,mixed>> Format code => what it asks */
	protected $formatCache = array();

	/**
	 * @param array{decimal?:string,thousand?:string,date?:callable} $options Writing of the numbers and dates (default:
	 *                                                                        1234.5, 2026-11-27 13:05)
	 */
	public function __construct(array $options = array())
	{
		if (isset($options['decimal'])) {
			$this->decimal = (string) $options['decimal'];
		}
		if (isset($options['thousand'])) {
			$this->thousand = (string) $options['thousand'];
		}
		if (isset($options['date']) && is_callable($options['date'])) {
			$this->dateFormatter = $options['date'];
		}
	}

	/**
	 * Options for the language of the user: separators of Dolibarr, dates written by dol_print_date()
	 *
	 * @param Translate $langs Language of the user
	 * @return array{decimal:string,thousand:string,date:callable}
	 */
	public static function optionsOf($langs)
	{
		$decimal = ',';
		$thousand = ' ';
		if ($langs->transnoentitiesnoconv('SeparatorDecimal') !== 'SeparatorDecimal') {
			$decimal = $langs->transnoentitiesnoconv('SeparatorDecimal');
		}
		if ($langs->transnoentitiesnoconv('SeparatorThousand') !== 'SeparatorThousand') {
			$thousand = $langs->transnoentitiesnoconv('SeparatorThousand');
		}
		if ($thousand === 'None') {
			$thousand = '';
		} elseif ($thousand === 'Space') {
			$thousand = ' ';
		}

		return array('decimal' => $decimal, 'thousand' => $thousand, 'date' => function ($timestamp, $kind) use ($langs) {
			// The time of the sheet is the one typed by its author: no time zone
			return dol_print_date($timestamp, $kind === 'hoursec' ? '%H:%M:%S' : $kind, 'gmt', $langs);
		});
	}

	/**
	 * A file the reader may show, by its signature and its name (cheap: called for every attachment listed)
	 *
	 * @param string $kind Signature of the content (InboxMimeParser::kind())
	 * @param string $name File name
	 * @return bool
	 */
	public static function accepts($kind, $name)
	{
		$name = strtolower((string) $name);
		if ($kind === 'zip') {
			return (bool) preg_match('/\.(xlsx|xlsm|xltx|xltm|ods|ots)$/', $name);
		}

		// An xlsx protected by a password is an OLE file: the preview says so
		return $kind === 'ole' && (bool) preg_match('/\.(xls|xlt|xlsx|xlsm)$/', $name);
	}

	/**
	 * First rows of each sheet
	 *
	 * @param string $content Content of the file
	 * @return array{sheets:array<int,array{name:string,rows:array<int,array<int,string|array<string,mixed>>>,truncated:bool,widths:array<int,int>,merges:array<int,array<int,int>>}>,truncated:bool,styles:array<int,array<string,mixed>>}
	 *         A cell is its text, or array('v' => text, 't' => 'n' number or 'd' date, 's' => index of its look in
	 *         styles), with only the keys it needs. Rows and columns keep their place in the sheet (empty rows and
	 *         cells included up to the last one filled). widths: pixels of each column; merges: array(row, column,
	 *         rows, columns) of each merged cell. $this->error says why there is no sheet.
	 */
	public function read($content)
	{
		$this->error = '';
		$this->truncated = false;
		$this->sheets = array();
		$this->cells = 0;
		$this->text = 0;
		$this->nodes = 0;
		$this->started = microtime(true);
		$this->date1904 = false;
		$this->styles = array();
		$this->styleKeys = array();
		$this->decor = 0;
		$this->baseSize = 0.0;
		$this->odsStyles = array();
		$this->odsLooks = array();
		$content = (string) $content;

		$previous = libxml_use_internal_errors(true);
		// A damaged zip makes the reading of its parts complain at each node: never a warning in the answer
		set_error_handler(function () {
			return true;
		}, E_WARNING | E_NOTICE);
		try {
			if (strpos($content, InboxCfbReader::SIGNATURE) === 0) {
				$this->readOle($content);
			} elseif (strpos($content, "PK\x03\x04") === 0 && class_exists('ZipArchive') && class_exists('XMLReader')) {
				$this->readZip($content);
			} else {
				$this->error = 'unreadable';
			}
		} catch (Throwable $e) {
			// A crafted file must never break the page: what was read so far is dropped
			$this->sheets = array();
			$this->error = 'unreadable';
		}
		restore_error_handler();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$out = array();
		foreach (array_keys($this->sheets) as $i) {
			$out[] = array('name' => $this->sheets[$i]['name']) + $this->sheetOut($this->sheets[$i]) + array('truncated' => $this->sheets[$i]['truncated']);
			$this->truncated = $this->truncated || $this->sheets[$i]['truncated'];
			// Given back at once: the cells are not kept twice
			$this->sheets[$i]['cells'] = array();
		}
		$this->sheets = array();
		$styles = $out ? $this->styles : array();
		$this->styles = array();
		$this->styleKeys = array();
		$this->odsStyles = array();
		$this->odsLooks = array();
		if (!$out && $this->error === '') {
			$this->error = 'unreadable';
		}

		return array('sheets' => $out, 'truncated' => $this->truncated, 'styles' => $styles);
	}

	// ---------- Common ----------

	/**
	 * A sheet as it is given: rows without holes, widths of its columns, merged cells
	 *
	 * @param array<string,mixed> $sheet Sheet read
	 * @return array{rows:array<int,array<int,string|array<string,mixed>>>,widths:array<int,int>,merges:array<int,array<int,int>>}
	 */
	protected function sheetOut(array $sheet)
	{
		$cells = $sheet['cells'];
		// What is shown ends at the last text: a look that goes on to the end of the sheet (a coloured column, a framed
		// row) makes no row nor column by itself
		$lastRow = -1;
		$lastCol = -1;
		foreach ($cells as $r => $row) {
			foreach ($row as $c => $cell) {
				if (isset($cell['v']) && $cell['v'] !== '') {
					$lastRow = max($lastRow, $r);
					$lastCol = max($lastCol, $c);
				}
			}
		}
		// A merged cell that has a text is shown whole
		foreach ($sheet['merges'] as $m) {
			if (isset($cells[$m[0]][$m[1]]['v']) && $cells[$m[0]][$m[1]]['v'] !== '') {
				$lastRow = max($lastRow, $m[0] + $m[2] - 1);
				$lastCol = max($lastCol, $m[1] + $m[3] - 1);
			}
		}
		// So are the framed cells left empty near the texts (the boxes of a form to fill in), not further
		$nearRow = $lastRow + 50;
		$nearCol = $lastCol + 10;
		foreach ($lastRow >= 0 ? $cells : array() as $r => $row) {
			foreach ($r <= $nearRow ? $row : array() as $c => $cell) {
				if ($c <= $nearCol && isset($cell['s']) && isset($cell['v']) && $cell['v'] === '' && isset($this->styles[$cell['s']])) {
					$look = $this->styles[$cell['s']];
					if (isset($look['bt']) || isset($look['br']) || isset($look['bb']) || isset($look['bl'])) {
						$lastRow = max($lastRow, $r);
						$lastCol = max($lastCol, $c);
					}
				}
			}
		}

		$rows = array();
		for ($r = 0; $r <= $lastRow; $r++) {
			$row = array();
			if (!empty($cells[$r])) {
				$width = min($lastCol, max(array_keys($cells[$r])));
				for ($c = 0; $c <= $width; $c++) {
					$cell = isset($cells[$r][$c]) ? $cells[$r][$c] : null;
					if ($cell === null || !isset($cell['v'])) {
						$row[] = '';
						continue;
					}
					$look = isset($cell['s']) && $cell['s'] >= 0;
					if (empty($cell['t']) && !$look) {
						$row[] = $cell['v'];
						continue;
					}
					$given = array('v' => $cell['v']);
					if (!empty($cell['t'])) {
						$given['t'] = $cell['t'];
					}
					if ($look) {
						$given['s'] = $cell['s'];
					}
					$row[] = $given;
				}
				while ($row && end($row) === '') {
					array_pop($row);
				}
			}
			$rows[] = $row;
		}

		$widths = array();
		for ($c = 0; $c <= $lastCol; $c++) {
			$widths[] = isset($sheet['widths'][$c]) ? $sheet['widths'][$c] : ($sheet['width'] > 0 ? $sheet['width'] : self::DEFAULT_WIDTH);
		}
		$merges = array();
		foreach ($sheet['merges'] as $m) {
			$m[2] = min($m[2], $lastRow - $m[0] + 1);
			$m[3] = min($m[3], $lastCol - $m[1] + 1);
			if ($m[2] >= 1 && $m[3] >= 1 && $m[2] * $m[3] > 1) {
				$merges[] = $m;
			}
		}

		return array('rows' => $rows, 'widths' => $widths, 'merges' => $merges);
	}

	/**
	 * A new sheet, false when the file has too many
	 *
	 * @param string $name Name
	 * @return int|false Index of the sheet
	 */
	protected function addSheet($name)
	{
		if (count($this->sheets) >= self::MAX_SHEETS) {
			$this->truncated = true;
			return false;
		}
		$name = $this->clean($name, 100);
		$this->sheets[] = array(
			'name' => $name !== '' ? $name : (string) (count($this->sheets) + 1), 'cells' => array(), 'truncated' => false,
			'widths' => array(), 'width' => 0, 'merges' => array(),
		);

		return count($this->sheets) - 1;
	}

	/**
	 * Room for a cell at this place
	 *
	 * @param int $sheet Sheet
	 * @param int $row   Row, 0 based
	 * @param int $col   Column, 0 based
	 * @return bool false: out of the part shown, the sheet is marked truncated
	 */
	protected function room($sheet, $row, $col)
	{
		if ($row < 0 || $col < 0) {
			return false;
		}
		if ($row >= self::MAX_ROWS || $col >= self::MAX_COLS || $this->cells >= self::MAX_CELLS) {
			$this->sheets[$sheet]['truncated'] = true;
			return false;
		}

		return true;
	}

	/**
	 * Keep a cell (room() said yes)
	 *
	 * @param int    $sheet Sheet
	 * @param int    $row   Row, 0 based
	 * @param int    $col   Column, 0 based
	 * @param string $value Text
	 * @param string $type  '', n (number) or d (date, time)
	 * @param int    $style Its look (styleId()), -1 for none
	 * @return void
	 */
	protected function put($sheet, $row, $col, $value, $type = '', $style = -1)
	{
		$value = $this->clean($value, self::MAX_CELL_LENGTH);
		if ($value === '') {
			$this->decorate($sheet, $row, $col, $style);
			return;
		}
		if ($this->text + strlen($value) > self::MAX_TEXT) {
			$this->sheets[$sheet]['truncated'] = true;
			return;
		}
		$this->text += strlen($value);
		if (!isset($this->sheets[$sheet]['cells'][$row][$col])) {
			$this->cells++;
		}
		$this->sheets[$sheet]['cells'][$row][$col] = $style >= 0 ? array('v' => $value, 't' => $type, 's' => $style) : array('v' => $value, 't' => $type);
	}

	/**
	 * Keep a cell without text for its look, when it shows (a background, a border). Never a reason to say that the
	 * sheet is cut: only the texts are
	 *
	 * @param int $sheet Sheet
	 * @param int $row   Row, 0 based
	 * @param int $col   Column, 0 based
	 * @param int $style Its look (styleId())
	 * @return void
	 */
	protected function decorate($sheet, $row, $col, $style)
	{
		if ($style < 0 || !$this->shows($style) || $row < 0 || $col < 0 || $row >= self::MAX_ROWS || $col >= self::MAX_COLS) {
			return;
		}
		if ($this->decor >= self::MAX_DECOR || isset($this->sheets[$sheet]['cells'][$row][$col])) {
			return;
		}
		$this->decor++;
		$this->sheets[$sheet]['cells'][$row][$col] = array('v' => '', 't' => '', 's' => $style);
	}

	/**
	 * A look that shows on a cell without text
	 *
	 * @param int $style Look
	 * @return bool
	 */
	protected function shows($style)
	{
		if (!isset($this->styles[$style])) {
			return false;
		}
		$look = $this->styles[$style];

		return isset($look['g']) || isset($look['bt']) || isset($look['br']) || isset($look['bb']) || isset($look['bl']);
	}

	/**
	 * Keep a merged cell
	 *
	 * @param int $sheet Sheet
	 * @param int $row   First row, 0 based
	 * @param int $col   First column, 0 based
	 * @param int $rows  Rows it covers
	 * @param int $cols  Columns it covers
	 * @return void
	 */
	protected function merge($sheet, $row, $col, $rows, $cols)
	{
		if ($row < 0 || $col < 0 || $row >= self::MAX_ROWS || $col >= self::MAX_COLS || $rows < 1 || $cols < 1 || $rows * $cols < 2) {
			return;
		}
		if (count($this->sheets[$sheet]['merges']) >= self::MAX_MERGES) {
			return;
		}
		$this->sheets[$sheet]['merges'][] = array($row, $col, min($rows, self::MAX_ROWS - $row), min($cols, self::MAX_COLS - $col));
	}

	/**
	 * Index of a look, the same for all the cells that share it. Only what is known is kept, each value checked: the
	 * page writes them in its styles
	 *
	 * @param array<string,mixed> $look b (bold), i (italic), u (underlined), k (struck), w (wrapped), c and g (colour of
	 *                                  the text and of the background, #rrggbb), a (l, c, r), va (t, m; bottom is the
	 *                                  rule), z (size of the font in points), bt, br, bb, bl (border: array(width 1
	 *                                  to 3, colour, line))
	 * @return int -1: nothing to show
	 */
	protected function styleId(array $look)
	{
		$clean = array();
		foreach (array('b', 'i', 'u', 'k', 'w') as $key) {
			if (!empty($look[$key])) {
				$clean[$key] = 1;
			}
		}
		foreach (array('c', 'g') as $key) {
			if (isset($look[$key]) && is_string($look[$key]) && preg_match('/^#[0-9a-f]{6}$/', $look[$key])) {
				$clean[$key] = $look[$key];
			}
		}
		if (isset($clean['c']) && $clean['c'] === '#000000') {
			// The ink of the sheet
			unset($clean['c']);
		}
		if (isset($look['a']) && in_array($look['a'], array('l', 'c', 'r'), true)) {
			$clean['a'] = $look['a'];
		}
		if (isset($look['va']) && in_array($look['va'], array('t', 'm'), true)) {
			$clean['va'] = $look['va'];
		}
		if (!empty($look['z']) && is_numeric($look['z'])) {
			// Against the size of the font of the workbook: the page has its own
			$ratio = round((float) $look['z'] / ($this->baseSize > 0 ? $this->baseSize : 11.0), 2);
			if (abs($ratio - 1) >= 0.05) {
				$clean['z'] = max(0.5, min(4.0, $ratio));
			}
		}
		foreach (array('bt', 'br', 'bb', 'bl') as $key) {
			if (!empty($look[$key]) && is_array($look[$key]) && !empty($look[$key][0])) {
				$colour = isset($look[$key][1]) && is_string($look[$key][1]) && preg_match('/^#[0-9a-f]{6}$/', $look[$key][1]) ? $look[$key][1] : '#000000';
				$line = isset($look[$key][2]) && in_array($look[$key][2], array('dashed', 'dotted', 'double'), true) ? $look[$key][2] : 'solid';
				$clean[$key] = array(max(1, min(3, (int) $look[$key][0])), $colour, $line);
			}
		}
		if (!$clean) {
			return -1;
		}
		$key = (string) json_encode($clean);
		if (isset($this->styleKeys[$key])) {
			return $this->styleKeys[$key];
		}
		if (count($this->styles) >= self::MAX_STYLES) {
			return -1;
		}
		$this->styles[] = $clean;

		return $this->styleKeys[$key] = count($this->styles) - 1;
	}

	/**
	 * A colour written RRGGBB, AARRGGBB or #RRGGBB
	 *
	 * @param string|null $value Colour
	 * @return string|null #rrggbb, null when it is not a colour
	 */
	protected static function hex($value)
	{
		return preg_match('/^#?(?:[0-9a-f]{2})?([0-9a-f]{6})$/', strtolower(trim((string) $value)), $m) ? '#'.$m[1] : null;
	}

	/**
	 * A colour made lighter (tint above 0) or darker (under 0), as Excel does with the colours of a theme
	 *
	 * @param string $hex  #rrggbb
	 * @param float  $tint -1 to 1
	 * @return string #rrggbb
	 */
	protected static function tint($hex, $tint)
	{
		$tint = max(-1.0, min(1.0, (float) $tint));
		if ($tint == 0.0) {
			return $hex;
		}
		$r = hexdec(substr($hex, 1, 2)) / 255;
		$g = hexdec(substr($hex, 3, 2)) / 255;
		$b = hexdec(substr($hex, 5, 2)) / 255;
		$max = max($r, $g, $b);
		$min = min($r, $g, $b);
		$l = ($max + $min) / 2;
		$h = 0.0;
		$sat = 0.0;
		if ($max > $min) {
			$d = $max - $min;
			$sat = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
			if ($max == $r) {
				$h = ($g - $b) / $d + ($g < $b ? 6 : 0);
			} elseif ($max == $g) {
				$h = ($b - $r) / $d + 2;
			} else {
				$h = ($r - $g) / $d + 4;
			}
			$h /= 6;
		}
		$l = $tint < 0 ? $l * (1 + $tint) : $l * (1 - $tint) + $tint;
		if ($sat == 0.0) {
			$r = $g = $b = $l;
		} else {
			$q = $l < 0.5 ? $l * (1 + $sat) : $l + $sat - $l * $sat;
			$p = 2 * $l - $q;
			$part = function ($t) use ($p, $q) {
				$t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
				if ($t < 1 / 6) {
					return $p + ($q - $p) * 6 * $t;
				}
				if ($t < 1 / 2) {
					return $q;
				}
				return $t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p;
			};
			$r = $part($h + 1 / 3);
			$g = $part($h);
			$b = $part($h - 1 / 3);
		}

		return sprintf('#%02x%02x%02x', (int) round(max(0, min(1, $r)) * 255), (int) round(max(0, min(1, $g)) * 255), (int) round(max(0, min(1, $b)) * 255));
	}

	/**
	 * Pixels of a width counted in characters (Excel), within what a column may take on the page
	 *
	 * @param float $chars Characters
	 * @return int
	 */
	protected static function charsToPixels($chars)
	{
		return (int) max(12, min(600, round((float) $chars * 7 + 5)));
	}

	/**
	 * Text of a cell: valid UTF-8, no control character but line breaks and tabs, bounded
	 *
	 * @param string $text Text
	 * @param int    $max  Characters kept
	 * @return string
	 */
	protected function clean($text, $max)
	{
		$text = (string) $text;
		if ($text === '') {
			return '';
		}
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
		if (mb_strlen($text, 'UTF-8') > $max) {
			$text = mb_substr($text, 0, $max, 'UTF-8').'…';
		}

		return $text;
	}

	/**
	 * One more node or record read; true when the file asked too much work
	 *
	 * @return bool
	 */
	protected function over()
	{
		$this->nodes++;
		if ($this->nodes > self::MAX_NODES || (($this->nodes & 0x3FF) === 0 && microtime(true) - $this->started > self::MAX_SECONDS)) {
			$this->truncated = true;
			return true;
		}

		return false;
	}

	/**
	 * What a number format of Excel asks, enough for a preview
	 *
	 * @param string $code Format code (« #,##0.00 », « d/m/yyyy »...)
	 * @return array{type:string,min:int,max:int,thousands:bool,seconds:bool,before:string,after:string} type = general,
	 *         number, percent, scientific, day, dayhour, hour, duration; before and after = currency or unit written
	 *         around a number
	 */
	protected function formatOf($code)
	{
		$code = (string) $code;
		if (isset($this->formatCache[$code])) {
			return $this->formatCache[$code];
		}
		$out = array('type' => 'general', 'min' => 0, 'max' => 0, 'thousands' => false, 'seconds' => false, 'before' => '', 'after' => '');
		// The first section (positive numbers), without its texts, colours, conditions, spaces of alignment
		$section = explode(';', $code)[0];
		$elapsed = (bool) preg_match('/\[(h+|m+|s+)\]/i', $section);
		$clean = strtolower((string) preg_replace('/"[^"]*"|\\\\.|_.|\*.|\[[^\]]*\]/u', '', $section));
		if ($clean === '' || strpos($clean, 'general') !== false || strpos($clean, '@') !== false) {
			if (!$elapsed) {
				return $this->formatCache[$code] = $out;
			}
		}
		$digits = (bool) preg_match('/[0#?]/', $clean);
		if ($elapsed || (preg_match('/[ydhsm]/', $clean) && (!$digits || preg_match('/s\.0/', $clean)) && !preg_match('/e[+-]/', $clean))) {
			$time = $elapsed || preg_match('/[hs]/', $clean);
			$date = preg_match('/[yd]/', $clean) || (!$time && strpos($clean, 'm') !== false);
			$out['seconds'] = strpos($clean, 's') !== false;
			$out['type'] = $date ? ($time ? 'dayhour' : 'day') : ($elapsed ? 'duration' : 'hour');
		} elseif ($digits) {
			if (preg_match('/\.([0#?]+)/', $clean, $m)) {
				$out['max'] = strlen($m[1]);
				$out['min'] = substr_count($m[1], '0');
			}
			$out['thousands'] = (bool) preg_match('/[0#?],[0#?]/', $clean);
			if (preg_match('/e[+-]/', $clean)) {
				$out['type'] = 'scientific';
			} elseif (strpos($clean, '%') !== false) {
				$out['type'] = 'percent';
			} elseif (strpos($clean, '/') !== false) {
				// A fraction: the number as it is
				$out['type'] = 'general';
			} else {
				$out['type'] = 'number';
				list($out['before'], $out['after']) = self::around($section);
			}
		}

		return $this->formatCache[$code] = $out;
	}

	/**
	 * What a number format writes before and after the digits: currency of a locale « [$€-40C] », quoted text « "kg" »,
	 * escaped character, currency sign, space
	 *
	 * @param string $section First section of the format code
	 * @return array{0:string,1:string} Before and after
	 */
	protected static function around($section)
	{
		$before = '';
		$after = '';
		$digits = false;
		if (preg_match_all('/\[\$([^\]-]*)[^\]]*\]|\[[^\]]*\]|"([^"]*)"|\\\\(.)|_.|\*.|([0#?])|(.)/su', (string) $section, $tokens, PREG_SET_ORDER)) {
			foreach ($tokens as $m) {
				if (isset($m[4]) && $m[4] !== '') {
					// Only what follows the last digit is written after the number
					$digits = true;
					$after = '';
					continue;
				}
				$text = (isset($m[1]) ? $m[1] : '').(isset($m[2]) ? $m[2] : '').(isset($m[3]) ? $m[3] : '');
				if (isset($m[5]) && preg_match('/^[€$£¥\x{20}\x{A0}\x{202F}]$/u', $m[5])) {
					$text .= $m[5];
				}
				if ($digits) {
					$after .= $text;
				} else {
					$before .= $text;
				}
			}
		}

		return array(mb_substr($before, 0, 12, 'UTF-8'), mb_substr($after, 0, 12, 'UTF-8'));
	}

	/**
	 * A number of a sheet as its format shows it
	 *
	 * @param float  $value Number
	 * @param string $code  Format code
	 * @return array{0:string,1:string} Text and type (n or d)
	 */
	protected function formatNumber($value, $code)
	{
		$value = (float) $value;
		$f = $this->formatOf($code);
		if (!is_finite($value)) {
			return array('', 'n');
		}
		switch ($f['type']) {
			case 'day':
			case 'dayhour':
			case 'hour':
			case 'duration':
				$text = $this->formatDate($value, $f['type'], $f['seconds']);
				if ($text !== null) {
					return array($text, 'd');
				}
				return array($this->general($value), 'n');
			case 'percent':
				return array($this->fixed($value * 100, $f['min'], $f['max'], $f['thousands']).'%', 'n');
			case 'number':
				return array(rtrim($f['before'].$this->fixed($value, $f['min'], $f['max'], $f['thousands']).$f['after']), 'n');
			case 'scientific':
				return array(str_replace('.', $this->decimal, sprintf('%.'.max(0, min(10, $f['max'])).'E', $value)), 'n');
		}

		return array($this->general($value), 'n');
	}

	/**
	 * A number without format: as many decimals as it has, at most 10 (the noise of the floating point is rounded)
	 *
	 * @param float $value Number
	 * @return string
	 */
	protected function general($value)
	{
		$abs = abs($value);
		if ($abs !== 0.0 && ($abs >= 1e15 || $abs < 1e-9)) {
			$text = (string) preg_replace('/\.?0+E/', 'E', sprintf('%.9E', $value));
		} elseif ($value == floor($value)) {
			$text = number_format($value, 0, '.', '');
		} else {
			$text = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
		}

		return str_replace('.', $this->decimal, $text === '-0' ? '0' : $text);
	}

	/**
	 * A number with the decimals of its format
	 *
	 * @param float $value     Number
	 * @param int   $min       Decimals always shown
	 * @param int   $max       Decimals shown when they are not zero
	 * @param bool  $thousands Thousands separated
	 * @return string
	 */
	protected function fixed($value, $min, $max, $thousands)
	{
		$max = max(0, min(10, $max));
		$text = number_format($value, $max, '.', $thousands ? "\x01" : '');
		if ($max > $min && strpos($text, '.') !== false) {
			$cut = strlen($text);
			$least = strpos($text, '.') + $min + ($min > 0 ? 1 : 0);
			while ($cut > $least && ($text[$cut - 1] === '0' || $text[$cut - 1] === '.')) {
				$cut--;
				if ($text[$cut] === '.') {
					break;
				}
			}
			$text = substr($text, 0, $cut);
		}
		if (preg_match('/^-[0.\x01]*$/', $text)) {
			$text = substr($text, 1);
		}

		return str_replace(array('.', "\x01"), array($this->decimal, $this->thousand), $text);
	}

	/**
	 * A date or a time kept as a number of days
	 *
	 * @param float  $serial  Days since 1900 (or 1904), the time in the decimals
	 * @param string $kind    day, dayhour, hour, duration
	 * @param bool   $seconds Seconds shown
	 * @return string|null null: not a date the calendar knows
	 */
	protected function formatDate($serial, $kind, $seconds)
	{
		if ($serial < 0 || $serial >= 2958466) {
			return null;
		}
		if ($kind === 'duration' || ($kind === 'hour' && $serial >= 1)) {
			$total = (int) round($serial * 86400);
			return sprintf($seconds ? '%d:%02d:%02d' : '%d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
		}
		if ($this->date1904) {
			$timestamp = (int) round(($serial - 24107) * 86400);
		} else {
			// Excel counts a 29 February 1900 that never was: the days before it are one day late
			$timestamp = (int) round(($serial - ($serial < 61 ? 25568 : 25569)) * 86400);
		}
		if ($kind === 'hour') {
			$timestamp = (int) round(($serial - floor($serial)) * 86400);
		} elseif ($kind === 'dayhour' && $serial < 1) {
			// A time alone in a column of dates and times
			$kind = 'hour';
			$timestamp = (int) round($serial * 86400);
		}
		$kind = $kind === 'day' ? 'day' : ($kind === 'hour' ? ($seconds ? 'hoursec' : 'hour') : ($seconds ? 'dayhoursec' : 'dayhour'));
		if ($this->dateFormatter !== null) {
			return (string) call_user_func($this->dateFormatter, $timestamp, $kind);
		}
		$formats = array('day' => 'Y-m-d', 'dayhour' => 'Y-m-d H:i', 'dayhoursec' => 'Y-m-d H:i:s', 'hour' => 'H:i', 'hoursec' => 'H:i:s');

		return gmdate($formats[$kind], $timestamp);
	}

	// ---------- Zip: xlsx and ods ----------

	/**
	 * @param string $content Content of the file
	 * @return void
	 */
	protected function readZip($content)
	{
		$tmp = tempnam(sys_get_temp_dir(), 'ibxs');
		if ($tmp === false) {
			$this->error = 'unreadable';
			return;
		}
		$zip = new ZipArchive();
		try {
			if (file_put_contents($tmp, $content) === false || $zip->open($tmp) !== true) {
				$this->error = 'unreadable';
				return;
			}
			if ($zip->locateName('content.xml') !== false && $zip->locateName('xl/workbook.xml') === false) {
				$manifest = $zip->locateName('META-INF/manifest.xml') !== false ? (string) $this->part($zip, 'META-INF/manifest.xml', 1048576) : '';
				if (strpos($manifest, 'encryption-data') !== false) {
					$this->error = 'protected';
				} else {
					$this->readOds($tmp, $zip->locateName('styles.xml') !== false);
				}
			} else {
				$this->readXlsx($zip, $tmp);
			}
		} finally {
			@$zip->close();
			@unlink($tmp);
		}
	}

	/**
	 * A small part of the zip, in memory
	 *
	 * @param ZipArchive $zip  Zip
	 * @param string     $name Entry
	 * @param int        $max  Bytes read at most
	 * @return string|null null when the entry is missing
	 */
	protected function part(ZipArchive $zip, $name, $max)
	{
		$stream = $zip->getStream($name);
		if (!$stream) {
			return null;
		}
		$data = '';
		while (!feof($stream) && strlen($data) < $max) {
			$chunk = fread($stream, min(65536, $max - strlen($data)));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		fclose($stream);

		return $data;
	}

	/**
	 * XML reader on a part of the zip, read as a stream: never the whole part in memory, no entity, no network
	 *
	 * @param string $tmp  File of the zip
	 * @param string $name Entry
	 * @return XMLReader|null
	 */
	protected function xml($tmp, $name)
	{
		$xml = new XMLReader();
		// Before PHP 8, a page that turned the loading of entities off also forbids opening a file: allowed for this call
		// only (entities are never expanded here, with or without it)
		$off = PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader') && libxml_disable_entity_loader(false);
		$opened = @$xml->open('zip://'.$tmp.'#'.$name, null, LIBXML_NONET | LIBXML_COMPACT);
		if ($off) {
			libxml_disable_entity_loader(true);
		}

		return $opened ? $xml : null;
	}

	/**
	 * Path of a part named by a relation
	 *
	 * @param string $base   Folder of the part that holds the relation (« xl »)
	 * @param string $target Target of the relation
	 * @return string
	 */
	protected static function partPath($base, $target)
	{
		$target = (string) $target;
		$path = strpos($target, '/') === 0 ? substr($target, 1) : ($base !== '' ? $base.'/' : '').$target;
		$out = array();
		foreach (explode('/', $path) as $piece) {
			if ($piece === '..') {
				array_pop($out);
			} elseif ($piece !== '' && $piece !== '.') {
				$out[] = $piece;
			}
		}

		return implode('/', $out);
	}

	/**
	 * Office Open XML workbook
	 *
	 * @param ZipArchive $zip Zip
	 * @param string     $tmp File of the zip
	 * @return void
	 */
	protected function readXlsx(ZipArchive $zip, $tmp)
	{
		// Where the workbook is
		$workbook = 'xl/workbook.xml';
		$xml = $zip->locateName('_rels/.rels') !== false ? $this->xml($tmp, '_rels/.rels') : null;
		if ($xml) {
			while (@$xml->read() && !$this->over()) {
				if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'Relationship' && preg_match('~/officeDocument$~', (string) $xml->getAttribute('Type'))) {
					$workbook = self::partPath('', (string) $xml->getAttribute('Target'));
					break;
				}
			}
			$xml->close();
		}
		if ($zip->locateName($workbook) === false) {
			$this->error = 'unreadable';
			return;
		}
		$base = strpos($workbook, '/') !== false ? substr($workbook, 0, strrpos($workbook, '/')) : '';

		// Its sheets, in the order of the tabs
		$declared = array();
		$xml = $this->xml($tmp, $workbook);
		while ($xml && @$xml->read() && !$this->over()) {
			if ($xml->nodeType !== XMLReader::ELEMENT) {
				continue;
			}
			if ($xml->localName === 'workbookPr') {
				$this->date1904 = in_array((string) $xml->getAttribute('date1904'), array('1', 'true'), true);
			} elseif ($xml->localName === 'sheet' && count($declared) < 500) {
				$rid = '';
				// The relation id is in the namespace of the relations, whatever its prefix
				if ($xml->moveToFirstAttribute()) {
					do {
						if ($xml->localName === 'id' && $xml->prefix !== '') {
							$rid = $xml->value;
						}
					} while ($xml->moveToNextAttribute());
					$xml->moveToElement();
				}
				$declared[] = array('name' => (string) $xml->getAttribute('name'), 'rid' => $rid);
			}
		}
		if ($xml) {
			$xml->close();
		}

		// The parts they are in
		$targets = array();
		$shared = '';
		$styles = '';
		$theme = '';
		$rels = ($base !== '' ? $base.'/' : '').'_rels/'.basename($workbook).'.rels';
		$xml = $zip->locateName($rels) !== false ? $this->xml($tmp, $rels) : null;
		while ($xml && @$xml->read() && !$this->over()) {
			if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'Relationship') {
				$type = (string) $xml->getAttribute('Type');
				$path = self::partPath($base, (string) $xml->getAttribute('Target'));
				$targets[(string) $xml->getAttribute('Id')] = $path;
				if (preg_match('~/sharedStrings$~', $type)) {
					$shared = $path;
				} elseif (preg_match('~/styles$~', $type)) {
					$styles = $path;
				} elseif (preg_match('~/theme$~', $type)) {
					$theme = $path;
				}
			}
		}
		if ($xml) {
			$xml->close();
		}

		$colours = $theme !== '' && $zip->locateName($theme) !== false ? $this->xlsxTheme($tmp, $theme) : self::THEME;
		$formats = $styles !== '' && $zip->locateName($styles) !== false ? $this->xlsxStyles($tmp, $styles, $colours) : array();

		$wanted = array();
		foreach ($declared as $d) {
			if (!isset($targets[$d['rid']]) || $zip->locateName($targets[$d['rid']]) === false || !preg_match('~worksheets/~', $targets[$d['rid']])) {
				// A chart sheet, a macro sheet, a missing part
				continue;
			}
			$index = $this->addSheet($d['name']);
			if ($index === false) {
				break;
			}
			$this->xlsxSheet($tmp, $targets[$d['rid']], $index, $formats, $wanted);
		}
		if ($wanted) {
			$strings = $shared !== '' && $zip->locateName($shared) !== false ? $this->xlsxStrings($tmp, $shared, $wanted) : array();
			$this->resolveStrings($strings);
		}
	}

	/**
	 * The cells waiting for a shared string get their text
	 *
	 * @param array<int,string> $strings Index => text
	 * @return void
	 */
	protected function resolveStrings(array $strings)
	{
		foreach ($this->sheets as $s => $sheet) {
			foreach ($sheet['cells'] as $r => $row) {
				foreach ($row as $c => $cell) {
					if (!isset($cell['p'])) {
						continue;
					}
					unset($this->sheets[$s]['cells'][$r][$c]);
					$this->cells--;
					$this->put($s, $r, $c, isset($strings[$cell['p']]) ? $strings[$cell['p']] : '', '', isset($cell['s']) ? $cell['s'] : -1);
				}
			}
		}
	}

	/**
	 * Colours of the theme of a workbook, in the order the cells name them
	 *
	 * @param string $tmp  File of the zip
	 * @param string $name Part of the theme
	 * @return array<int,string> #rrggbb
	 */
	protected function xlsxTheme($tmp, $name)
	{
		$slots = array('lt1' => 0, 'dk1' => 1, 'lt2' => 2, 'dk2' => 3, 'accent1' => 4, 'accent2' => 5, 'accent3' => 6, 'accent4' => 7, 'accent5' => 8, 'accent6' => 9, 'hlink' => 10, 'folHlink' => 11);
		$out = self::THEME;
		$slot = -1;
		$xml = $this->xml($tmp, $name);
		while ($xml && @$xml->read() && !$this->over()) {
			if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'clrScheme') {
				break;
			}
			if ($xml->nodeType !== XMLReader::ELEMENT) {
				continue;
			}
			if (isset($slots[$xml->localName])) {
				$slot = $slots[$xml->localName];
			} elseif ($slot >= 0 && ($xml->localName === 'srgbClr' || $xml->localName === 'sysClr')) {
				$colour = self::hex($xml->localName === 'srgbClr' ? $xml->getAttribute('val') : $xml->getAttribute('lastClr'));
				if ($colour !== null) {
					$out[$slot] = $colour;
				}
				$slot = -1;
			}
		}
		if ($xml) {
			$xml->close();
		}

		return $out;
	}

	/**
	 * A colour as a part of a workbook names it: by its value, by its place in the theme or in the numbered colours
	 *
	 * @param XMLReader $xml Reader on the element of the colour
	 * @return array{rgb:string,theme:string,indexed:string,tint:string}
	 */
	protected static function xlsxColourOf(XMLReader $xml)
	{
		return array(
			'rgb' => (string) $xml->getAttribute('rgb'), 'theme' => (string) $xml->getAttribute('theme'),
			'indexed' => (string) $xml->getAttribute('indexed'), 'tint' => (string) $xml->getAttribute('tint'),
		);
	}

	/**
	 * @param array<string,string>|null $spec    Colour named by xlsxColourOf()
	 * @param array<int,string>         $theme   Colours of the theme
	 * @param array<int,string>         $palette Numbered colours of the workbook, when it has its own
	 * @return string|null #rrggbb, null for the colour of the window (black ink, no background)
	 */
	protected static function xlsxColour($spec, array $theme, array $palette)
	{
		if (!is_array($spec)) {
			return null;
		}
		$colour = null;
		if ($spec['rgb'] !== '') {
			$colour = self::hex($spec['rgb']);
		} elseif ($spec['theme'] !== '' && ctype_digit($spec['theme'])) {
			$colour = isset($theme[(int) $spec['theme']]) ? $theme[(int) $spec['theme']] : null;
		} elseif ($spec['indexed'] !== '' && ctype_digit($spec['indexed'])) {
			$i = (int) $spec['indexed'];
			$colour = isset($palette[$i]) ? $palette[$i] : (isset(self::INDEXED[$i]) ? '#'.self::INDEXED[$i] : null);
		}
		if ($colour !== null && $spec['tint'] !== '' && is_numeric($spec['tint'])) {
			$colour = self::tint($colour, (float) $spec['tint']);
		}

		return $colour;
	}

	/**
	 * Number format and look of each cell style of a workbook
	 *
	 * @param string            $tmp   File of the zip
	 * @param string            $name  Part of the styles
	 * @param array<int,string> $theme Colours of the theme
	 * @return array<int,array{0:string,1:int}> Style index => format code, look (styleId())
	 */
	protected function xlsxStyles($tmp, $name, array $theme)
	{
		$custom = array();
		$fonts = array();
		$fills = array();
		$borders = array();
		$xfs = array();
		$palette = array();
		$section = '';
		$side = '';
		$yes = function ($value) {
			return $value !== '0' && $value !== 'false';
		};
		$xml = $this->xml($tmp, $name);
		while ($xml && @$xml->read() && !$this->over()) {
			$depth = $xml->depth;
			if ($xml->nodeType !== XMLReader::ELEMENT) {
				continue;
			}
			$tag = $xml->localName;
			if ($depth <= 1) {
				// fonts, fills, borders, cellXfs... the same elements are found again in the other lists (styles of the
				// conditions, named styles): only the list they are in says what they are
				$section = $tag;
				continue;
			}
			if ($section === 'numFmts') {
				if ($tag === 'numFmt' && count($custom) < 5000) {
					$custom[(int) $xml->getAttribute('numFmtId')] = (string) $xml->getAttribute('formatCode');
				}
			} elseif ($section === 'fonts') {
				if ($depth === 2 && $tag === 'font' && count($fonts) < 5000) {
					$fonts[] = array();
				} elseif ($depth === 3 && $fonts) {
					$i = count($fonts) - 1;
					$value = (string) $xml->getAttribute('val');
					if ($tag === 'b' || $tag === 'i') {
						$fonts[$i][$tag] = $yes($value);
					} elseif ($tag === 'strike') {
						$fonts[$i]['k'] = $yes($value);
					} elseif ($tag === 'u') {
						$fonts[$i]['u'] = $value !== 'none';
					} elseif ($tag === 'sz') {
						$fonts[$i]['z'] = (float) $value;
					} elseif ($tag === 'color') {
						$fonts[$i]['c'] = self::xlsxColourOf($xml);
					}
				}
			} elseif ($section === 'fills') {
				if ($depth === 2 && $tag === 'fill' && count($fills) < 5000) {
					$fills[] = array('pattern' => '', 'colour' => null);
				} elseif ($fills) {
					$i = count($fills) - 1;
					if ($depth === 3 && $tag === 'patternFill') {
						$fills[$i]['pattern'] = (string) $xml->getAttribute('patternType');
					} elseif ($depth === 3 && $tag === 'gradientFill') {
						$fills[$i]['pattern'] = 'gradient';
					} elseif ($fills[$i]['colour'] === null && (($depth === 4 && $tag === 'fgColor' && $fills[$i]['pattern'] === 'solid') || ($tag === 'color' && $fills[$i]['pattern'] === 'gradient'))) {
						// A plain background; of a gradient, its first colour
						$fills[$i]['colour'] = self::xlsxColourOf($xml);
					}
				}
			} elseif ($section === 'borders') {
				if ($depth === 2 && $tag === 'border' && count($borders) < 5000) {
					$borders[] = array();
					$side = '';
				} elseif ($borders) {
					$i = count($borders) - 1;
					if ($depth === 3) {
						$sides = array('top' => 'bt', 'right' => 'br', 'end' => 'br', 'bottom' => 'bb', 'left' => 'bl', 'start' => 'bl');
						$line = (string) $xml->getAttribute('style');
						$side = isset($sides[$tag]) && isset(self::XLSX_BORDERS[$line]) ? $sides[$tag] : '';
						if ($side !== '') {
							$borders[$i][$side] = array(self::XLSX_BORDERS[$line][0], null, self::XLSX_BORDERS[$line][1]);
						}
					} elseif ($depth === 4 && $tag === 'color' && $side !== '') {
						$borders[$i][$side][1] = self::xlsxColourOf($xml);
					}
				}
			} elseif ($section === 'cellXfs') {
				if ($depth === 2 && $tag === 'xf' && count($xfs) < 65536) {
					$xfs[] = array(
						'format' => (int) $xml->getAttribute('numFmtId'), 'font' => (int) $xml->getAttribute('fontId'),
						'fill' => (int) $xml->getAttribute('fillId'), 'border' => (int) $xml->getAttribute('borderId'), 'a' => '', 'va' => '', 'w' => false,
					);
				} elseif ($depth === 3 && $tag === 'alignment' && $xfs) {
					$i = count($xfs) - 1;
					$across = array('left' => 'l', 'center' => 'c', 'centerContinuous' => 'c', 'right' => 'r');
					$down = array('top' => 't', 'center' => 'm');
					$xfs[$i]['a'] = isset($across[(string) $xml->getAttribute('horizontal')]) ? $across[(string) $xml->getAttribute('horizontal')] : '';
					$xfs[$i]['va'] = isset($down[(string) $xml->getAttribute('vertical')]) ? $down[(string) $xml->getAttribute('vertical')] : '';
					$xfs[$i]['w'] = in_array((string) $xml->getAttribute('wrapText'), array('1', 'true'), true);
				}
			} elseif ($section === 'colors') {
				if ($tag === 'rgbColor' && count($palette) < 64) {
					// Numbered colours changed by the author, written after the styles that use them
					$colour = self::hex($xml->getAttribute('rgb'));
					$palette[] = $colour !== null ? $colour : '#000000';
				}
			}
		}
		if ($xml) {
			$xml->close();
		}

		$this->baseSize = isset($fonts[0]['z']) && $fonts[0]['z'] >= 4 ? (float) $fonts[0]['z'] : 11.0;
		$out = array();
		$looks = array();
		foreach ($xfs as $xf) {
			$key = $xf['font'].':'.$xf['fill'].':'.$xf['border'].':'.$xf['a'].$xf['va'].($xf['w'] ? 'w' : '');
			if (!isset($looks[$key])) {
				$look = isset($fonts[$xf['font']]) ? $fonts[$xf['font']] : array();
				$look['c'] = self::xlsxColour(isset($look['c']) ? $look['c'] : null, $theme, $palette);
				if (isset($fills[$xf['fill']])) {
					$look['g'] = self::xlsxColour($fills[$xf['fill']]['colour'], $theme, $palette);
				}
				foreach (isset($borders[$xf['border']]) ? $borders[$xf['border']] : array() as $side => $border) {
					$border[1] = self::xlsxColour($border[1], $theme, $palette);
					$look[$side] = $border;
				}
				$look['a'] = $xf['a'];
				$look['va'] = $xf['va'];
				$look['w'] = $xf['w'];
				$looks[$key] = $this->styleId($look);
			}
			$code = isset($custom[$xf['format']]) ? $custom[$xf['format']] : (isset(self::BUILTIN_FORMATS[$xf['format']]) ? self::BUILTIN_FORMATS[$xf['format']] : 'General');
			$out[] = array($code, $looks[$key]);
		}

		return $out;
	}

	/**
	 * One sheet of a workbook
	 *
	 * @param string            $tmp     File of the zip
	 * @param string            $name    Part of the sheet
	 * @param int               $sheet   Index of the sheet
	 * @param array<int,array{0:string,1:int}> $formats Style index => format code, look
	 * @param array<int,bool>                  $wanted  Shared strings to read, completed
	 * @return void
	 */
	protected function xlsxSheet($tmp, $name, $sheet, array $formats, array &$wanted)
	{
		$xml = $this->xml($tmp, $name);
		if (!$xml) {
			return;
		}
		$row = -1;
		$col = -1;
		$beyond = false;
		$more = @$xml->read();
		while ($more && !$this->over()) {
			if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'sheetData') {
				break;
			}
			if ($xml->nodeType !== XMLReader::ELEMENT) {
				$more = @$xml->read();
				continue;
			}
			if ($row < 0 && $xml->localName === 'sheetFormatPr') {
				if (is_numeric($xml->getAttribute('defaultColWidth'))) {
					$this->sheets[$sheet]['width'] = self::charsToPixels((float) $xml->getAttribute('defaultColWidth'));
				}
			} elseif ($row < 0 && $xml->localName === 'col') {
				// Widths of the columns, before the cells; a hidden column is shown, at the width of the others
				$from = max(1, (int) $xml->getAttribute('min'));
				$to = min(self::MAX_COLS, (int) $xml->getAttribute('max'));
				if (is_numeric($xml->getAttribute('width')) && (float) $xml->getAttribute('width') > 0 && !in_array((string) $xml->getAttribute('hidden'), array('1', 'true'), true)) {
					for ($c = $from; $c <= $to; $c++) {
						$this->sheets[$sheet]['widths'][$c - 1] = self::charsToPixels((float) $xml->getAttribute('width'));
					}
				}
			} elseif ($xml->localName === 'row') {
				$r = (int) $xml->getAttribute('r');
				$row = $r > 0 ? $r - 1 : $row + 1;
				$col = -1;
				// Rows come in order: the rest of the sheet is not shown
				$beyond = $row >= self::MAX_ROWS;
			} elseif ($xml->localName === 'c' && $beyond) {
				// A cell with a value under the part shown: the sheet is cut. Cells kept for their look alone (a coloured
				// column that goes down the sheet) do not say so
				$depth = $xml->depth;
				$more = !$xml->isEmptyElement && @$xml->read();
				while ($more && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
					if ($xml->nodeType === XMLReader::ELEMENT && ($xml->localName === 'v' || $xml->localName === 't') && $xml->readString() !== '') {
						$this->sheets[$sheet]['truncated'] = true;
						break 2;
					}
					$more = @$xml->read();
				}
			} elseif ($xml->localName === 'c') {
				$ref = (string) $xml->getAttribute('r');
				$col = preg_match('/^([A-Za-z]{1,3})/', $ref, $m) ? self::columnIndex($m[1]) : $col + 1;
				$type = (string) $xml->getAttribute('t');
				$style = (int) $xml->getAttribute('s');
				$value = null;
				if (!$xml->isEmptyElement) {
					$depth = $xml->depth;
					$inline = '';
					// Its value <v>, or the text typed in the cell <is><t>; never the phonetic guide <rPh>
					$more = @$xml->read();
					while ($more && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
						if ($xml->nodeType === XMLReader::ELEMENT) {
							if ($xml->localName === 'rPh' || $xml->localName === 'f') {
								$more = @$xml->next();
								continue;
							}
							if ($xml->localName === 'v') {
								$value = $xml->readString();
							} elseif ($xml->localName === 't') {
								$inline .= $xml->readString();
							}
						}
						$more = @$xml->read();
					}
					if ($value === null && $inline !== '') {
						$value = $inline;
						$type = 'inlineStr';
					}
				}
				$format = isset($formats[$style]) ? $formats[$style] : array('General', -1);
				if ($value === null || $value === '') {
					// A cell without text, kept when its look shows
					$this->decorate($sheet, $row, $col, $format[1]);
				} elseif ($row >= 0 && $this->room($sheet, $row, $col)) {
					$this->xlsxCell($sheet, $row, $col, $type, $value, $format[0], $wanted, $format[1]);
				}
			}
			$more = @$xml->read();
		}
		// After the cells, the merged ones: the rows left out are passed over without being read
		while ($more && !$this->over()) {
			if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'mergeCells') {
				$depth = $xml->depth;
				$inside = !$xml->isEmptyElement;
				while ($inside && @$xml->read() && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
					if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'mergeCell' && preg_match('/^([A-Za-z]{1,3})(\d{1,7}):([A-Za-z]{1,3})(\d{1,7})$/', (string) $xml->getAttribute('ref'), $m)) {
						$this->merge($sheet, (int) $m[2] - 1, self::columnIndex($m[1]), (int) $m[4] - (int) $m[2] + 1, self::columnIndex($m[3]) - self::columnIndex($m[1]) + 1);
					}
				}
				break;
			}
			$more = @$xml->next();
		}
		$xml->close();
	}

	/**
	 * @param int             $sheet  Sheet
	 * @param int             $row    Row
	 * @param int             $col    Column
	 * @param string          $type   Type of the cell (attribute t)
	 * @param string          $value  Value
	 * @param string          $format Format code of its style
	 * @param array<int,bool> $wanted Shared strings to read, completed
	 * @param int             $style  Look of its style
	 * @return void
	 */
	protected function xlsxCell($sheet, $row, $col, $type, $value, $format, array &$wanted, $style = -1)
	{
		switch ($type) {
			case 's':
				if (ctype_digit($value)) {
					$wanted[(int) $value] = true;
					if (!isset($this->sheets[$sheet]['cells'][$row][$col])) {
						$this->cells++;
					}
					$this->sheets[$sheet]['cells'][$row][$col] = array('p' => (int) $value, 's' => $style);
				}
				return;
			case 'b':
				$this->put($sheet, $row, $col, $value === '1' ? 'TRUE' : 'FALSE', '', $style);
				return;
			case 'd':
				$this->put($sheet, $row, $col, str_replace('T', ' ', (string) preg_replace('/(\.\d+)?Z?$/', '', $value)), 'd', $style);
				return;
			case 'str':
			case 'inlineStr':
			case 'e':
				$this->put($sheet, $row, $col, self::unescape($value), '', $style);
				return;
		}
		if (is_numeric($value)) {
			$f = $this->formatNumber((float) $value, $format);
			$this->put($sheet, $row, $col, $f[0], $f[1], $style);
		} else {
			$this->put($sheet, $row, $col, $value, '', $style);
		}
	}

	/**
	 * Shared strings of a workbook, only the ones the cells shown use
	 *
	 * @param string          $tmp    File of the zip
	 * @param string          $name   Part of the shared strings
	 * @param array<int,bool> $wanted Indexes to read
	 * @return array<int,string>
	 */
	protected function xlsxStrings($tmp, $name, array $wanted)
	{
		$out = array();
		$last = max(array_keys($wanted));
		$index = -1;
		$xml = $this->xml($tmp, $name);
		$more = $xml ? @$xml->read() : false;
		while ($more && !$this->over()) {
			if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si') {
				$more = @$xml->read();
				continue;
			}
			$index++;
			if ($index > $last) {
				break;
			}
			if (!isset($wanted[$index]) || $xml->isEmptyElement) {
				$more = @$xml->next();
				continue;
			}
			$depth = $xml->depth;
			$text = '';
			$more = @$xml->read();
			while ($more && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
				if ($xml->nodeType === XMLReader::ELEMENT) {
					if ($xml->localName === 'rPh') {
						$more = @$xml->next();
						continue;
					}
					if ($xml->localName === 't' && strlen($text) < self::MAX_CELL_LENGTH * 4) {
						$text .= $xml->readString();
					}
				}
				$more = @$xml->read();
			}
			$out[$index] = self::unescape($text);
			$more = @$xml->read();
		}
		if ($xml) {
			$xml->close();
		}

		return $out;
	}

	/**
	 * Characters Excel writes as _xHHHH_ (line breaks, control characters)
	 *
	 * @param string $text Text
	 * @return string
	 */
	protected static function unescape($text)
	{
		if (strpos($text, '_x') === false) {
			return $text;
		}

		return (string) preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', function ($m) {
			$code = hexdec($m[1]);
			return $code === 0x5F ? '_' : ($code < 0xD800 || $code > 0xDFFF ? mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE') : '');
		}, $text);
	}

	/**
	 * Column of a cell reference: A = 0, Z = 25, AA = 26
	 *
	 * @param string $letters Letters
	 * @return int
	 */
	public static function columnIndex($letters)
	{
		$n = 0;
		foreach (str_split(strtoupper($letters)) as $letter) {
			$n = $n * 26 + (ord($letter) - 64);
		}

		return $n - 1;
	}

	/**
	 * OpenDocument spreadsheet
	 *
	 * @param string $tmp    File of the zip
	 * @param bool   $styles The zip has a part styles.xml
	 * @return void
	 */
	protected function readOds($tmp, $styles = false)
	{
		$this->baseSize = 10.0;
		// The named styles the cells inherit from, and the size of the font of the document
		$xml = $styles ? $this->xml($tmp, 'styles.xml') : null;
		while ($xml && @$xml->read() && !$this->over()) {
			if ($xml->nodeType === XMLReader::ELEMENT && $xml->namespaceURI === self::NS_STYLE && ($xml->localName === 'style' || $xml->localName === 'default-style')) {
				$this->odsStyle($xml);
			}
		}
		if ($xml) {
			$xml->close();
		}

		$xml = $this->xml($tmp, 'content.xml');
		if (!$xml) {
			$this->error = 'unreadable';
			return;
		}
		$sheet = false;
		$tableDepth = -1;
		$row = 0;
		$column = 0;
		$more = @$xml->read();
		while ($more && !$this->over()) {
			if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $tableDepth && $xml->localName === 'table') {
				$tableDepth = -1;
				$sheet = false;
			}
			if ($sheet === false && $xml->nodeType === XMLReader::ELEMENT && $xml->namespaceURI === self::NS_STYLE && $xml->localName === 'style') {
				// The styles of the cells and of the columns of this file, written before its sheets
				$this->odsStyle($xml);
			}
			if ($xml->nodeType !== XMLReader::ELEMENT || $xml->namespaceURI !== self::NS_TABLE) {
				$more = @$xml->read();
				continue;
			}
			if ($xml->localName === 'table') {
				if ($tableDepth >= 0 || count($this->sheets) >= self::MAX_SHEETS) {
					// A table inside a cell, or one sheet too many
					$this->truncated = $this->truncated || $tableDepth < 0;
					$more = @$xml->next();
					continue;
				}
				$sheet = $this->addSheet((string) $xml->getAttributeNs('name', self::NS_TABLE));
				$tableDepth = $xml->isEmptyElement ? -1 : $xml->depth;
				$row = 0;
				$column = 0;
			} elseif ($xml->localName === 'table-column' && $sheet !== false) {
				$repeat = max(1, (int) $xml->getAttributeNs('number-columns-repeated', self::NS_TABLE));
				$key = 'table-column:'.(string) $xml->getAttributeNs('style-name', self::NS_TABLE);
				$width = isset($this->odsStyles[$key]) ? $this->odsStyles[$key]['width'] : 0;
				for ($i = 0; $width > 0 && $i < $repeat && $column + $i < self::MAX_COLS; $i++) {
					$this->sheets[$sheet]['widths'][$column + $i] = $width;
				}
				$column = min($column + $repeat, 100000);
			} elseif ($xml->localName === 'table-row' && $sheet !== false) {
				$repeat = max(1, (int) $xml->getAttributeNs('number-rows-repeated', self::NS_TABLE));
				$cells = $xml->isEmptyElement ? array() : $this->odsRow($xml);
				$filled = false;
				foreach ($cells as $cell) {
					if ($cell[0] !== '') {
						$filled = true;
						break;
					}
				}
				if ($cells && $row < self::MAX_ROWS) {
					// A row repeated: each copy shown, as far as the preview goes
					for ($i = 0; $i < $repeat && $row + $i < self::MAX_ROWS; $i++) {
						foreach ($cells as $col => $cell) {
							if ($cell[0] === '') {
								$this->decorate($sheet, $row + $i, $col, $cell[2]);
							} elseif ($this->room($sheet, $row + $i, $col)) {
								$this->put($sheet, $row + $i, $col, $cell[0], $cell[1], $cell[2]);
							}
							if ($cell[3] > 1 || $cell[4] > 1) {
								$this->merge($sheet, $row + $i, $col, $cell[4], $cell[3]);
							}
						}
						if (!$filled && $this->decor >= self::MAX_DECOR) {
							break;
						}
					}
				}
				if ($filled && $row + $repeat > self::MAX_ROWS) {
					$this->sheets[$sheet]['truncated'] = true;
				}
				// The empty rows that end a sheet are repeated up to its last line: they only move the count
				$row = min($row + $repeat, 2000000);
			}
			$more = @$xml->read();
		}
		$xml->close();
	}

	/**
	 * Cells of a row, the reader on its opening tag; it ends on its closing tag
	 *
	 * @param XMLReader $xml Reader
	 * @return array<int,array{0:string,1:string,2:int,3:int,4:int}> Column => text, type, look, columns and rows it
	 *         covers when it is merged; the columns out of the preview are left out
	 */
	protected function odsRow(XMLReader $xml)
	{
		$out = array();
		$depth = $xml->depth;
		$col = 0;
		$more = @$xml->read();
		while ($more && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
			if ($xml->nodeType === XMLReader::ELEMENT && $xml->namespaceURI === self::NS_TABLE && ($xml->localName === 'table-cell' || $xml->localName === 'covered-table-cell')) {
				$repeat = max(1, (int) $xml->getAttributeNs('number-columns-repeated', self::NS_TABLE));
				// Read before its text: the reader leaves the opening tag
				$own = $xml->localName === 'table-cell';
				$style = $own ? $this->odsLook((string) $xml->getAttributeNs('style-name', self::NS_TABLE)) : -1;
				$across = $own ? max(1, (int) $xml->getAttributeNs('number-columns-spanned', self::NS_TABLE)) : 1;
				$down = $own ? max(1, (int) $xml->getAttributeNs('number-rows-spanned', self::NS_TABLE)) : 1;
				$cell = $own ? $this->odsCell($xml) : null;
				if ($cell === null && !$xml->isEmptyElement) {
					// A cell covered by a merged one: nothing of its own to show
					$more = @$xml->next();
					$col = min($col + $repeat, 100000);
					continue;
				}
				if ($cell !== null && ($cell[0] !== '' || $this->shows($style))) {
					$cell = array($cell[0], $cell[1], $style, $across, $down);
					for ($i = 0; $i < $repeat && $col + $i < self::MAX_COLS; $i++) {
						$out[$col + $i] = $cell;
					}
					if ($cell[0] !== '' && $col + $repeat > self::MAX_COLS) {
						// Kept out of the row: room() marks the sheet when a cell is refused
						$out[self::MAX_COLS] = $cell;
					}
				}
				$col = min($col + $repeat, 100000);
			}
			$more = @$xml->read();
		}

		return $out;
	}

	/**
	 * Keep a style of an OpenDocument file (cells: look; columns: width), the reader on its opening tag
	 *
	 * @param XMLReader $xml Reader
	 * @return void
	 */
	protected function odsStyle(XMLReader $xml)
	{
		$family = (string) $xml->getAttributeNs('family', self::NS_STYLE);
		if (($family !== 'table-cell' && $family !== 'table-column') || count($this->odsStyles) >= 8000) {
			return;
		}
		// The style of the document has no name: every other one ends on it
		$default = $xml->localName === 'default-style';
		$name = $default ? '' : (string) $xml->getAttributeNs('name', self::NS_STYLE);
		$style = array('parent' => (string) $xml->getAttributeNs('parent-style-name', self::NS_STYLE), 'look' => array(), 'width' => 0);
		$depth = $xml->depth;
		$more = !$xml->isEmptyElement;
		// A value written is kept even when it says « nothing » (transparent, normal): it hides the one of the parent
		while ($more && @$xml->read() && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
			if ($xml->nodeType !== XMLReader::ELEMENT || $xml->namespaceURI !== self::NS_STYLE) {
				continue;
			}
			if ($xml->localName === 'table-column-properties') {
				$style['width'] = self::lengthToPixels((string) $xml->getAttributeNs('column-width', self::NS_STYLE));
			} elseif ($xml->localName === 'table-cell-properties') {
				$value = (string) $xml->getAttributeNs('background-color', self::NS_FO);
				if ($value !== '') {
					$style['look']['g'] = self::hex($value);
				}
				$all = (string) $xml->getAttributeNs('border', self::NS_FO);
				foreach (array('bt' => 'border-top', 'br' => 'border-right', 'bb' => 'border-bottom', 'bl' => 'border-left') as $side => $attribute) {
					$value = (string) $xml->getAttributeNs($attribute, self::NS_FO);
					$value = $value !== '' ? $value : $all;
					if ($value !== '') {
						$style['look'][$side] = self::odsBorder($value);
					}
				}
				$value = (string) $xml->getAttributeNs('wrap-option', self::NS_FO);
				if ($value !== '') {
					$style['look']['w'] = $value === 'wrap';
				}
				$value = (string) $xml->getAttributeNs('vertical-align', self::NS_STYLE);
				if ($value !== '') {
					$style['look']['va'] = $value === 'top' ? 't' : ($value === 'middle' ? 'm' : '');
				}
			} elseif ($xml->localName === 'paragraph-properties') {
				$value = (string) $xml->getAttributeNs('text-align', self::NS_FO);
				if ($value !== '') {
					$sides = array('start' => 'l', 'left' => 'l', 'center' => 'c', 'end' => 'r', 'right' => 'r');
					$style['look']['a'] = isset($sides[$value]) ? $sides[$value] : '';
				}
			} elseif ($xml->localName === 'text-properties') {
				$value = (string) $xml->getAttributeNs('font-weight', self::NS_FO);
				if ($value !== '') {
					$style['look']['b'] = $value === 'bold' || (int) $value >= 600;
				}
				$value = (string) $xml->getAttributeNs('font-style', self::NS_FO);
				if ($value !== '') {
					$style['look']['i'] = $value === 'italic' || $value === 'oblique';
				}
				$value = (string) $xml->getAttributeNs('color', self::NS_FO);
				if ($value !== '') {
					$style['look']['c'] = self::hex($value);
				}
				if (preg_match('/^([\d.]+)pt$/', (string) $xml->getAttributeNs('font-size', self::NS_FO), $m)) {
					$style['look']['z'] = (float) $m[1];
				}
				$value = (string) $xml->getAttributeNs('text-underline-style', self::NS_STYLE);
				if ($value !== '') {
					$style['look']['u'] = $value !== 'none';
				}
				$value = (string) $xml->getAttributeNs('text-line-through-style', self::NS_STYLE);
				if ($value !== '') {
					$style['look']['k'] = $value !== 'none';
				}
			}
		}
		$this->odsStyles[$family.':'.$name] = $style;
		if ($family === 'table-cell' && ($default || $name === 'Default') && !empty($style['look']['z']) && $style['look']['z'] >= 4) {
			// The size the cells have when nothing is said: the one of the style « Default » when it gives one
			$this->baseSize = $style['look']['z'];
		}
	}

	/**
	 * Look of a style of cells of an OpenDocument file, with what it inherits
	 *
	 * @param string $name Name of the style
	 * @return int Look (styleId()), -1 for none
	 */
	protected function odsLook($name)
	{
		if ($name === '') {
			return -1;
		}
		if (isset($this->odsLooks[$name])) {
			return $this->odsLooks[$name];
		}
		$look = array();
		$key = 'table-cell:'.$name;
		for ($i = 0; $i < 10 && isset($this->odsStyles[$key]); $i++) {
			// The nearest says first: what it writes is not replaced by its parents
			$look += $this->odsStyles[$key]['look'];
			if ($this->odsStyles[$key]['parent'] === '') {
				break;
			}
			$key = 'table-cell:'.$this->odsStyles[$key]['parent'];
		}
		if (isset($this->odsStyles['table-cell:'])) {
			$look += $this->odsStyles['table-cell:']['look'];
		}
		$id = $this->styleId($look);
		if (count($this->odsLooks) < 8000) {
			$this->odsLooks[$name] = $id;
		}

		return $id;
	}

	/**
	 * A border as OpenDocument writes it (« 0.74pt solid #000000 », « none »)
	 *
	 * @param string $text Border
	 * @return array{0:int,1:string,2:string}|null Width 1 to 3, colour, line; null for no border
	 */
	protected static function odsBorder($text)
	{
		$text = strtolower(trim((string) $text));
		if ($text === '' || strpos($text, 'none') !== false || strpos($text, 'hidden') !== false) {
			return null;
		}
		$points = 0.75;
		$units = array('pt' => 1, 'cm' => 28.35, 'mm' => 2.835, 'in' => 72, 'px' => 0.75);
		if (preg_match('/([\d.]+)\s*(pt|cm|mm|in|px)/', $text, $m)) {
			$points = (float) $m[1] * $units[$m[2]];
		} elseif (strpos($text, 'thick') !== false) {
			$points = 3;
		} elseif (strpos($text, 'medium') !== false) {
			$points = 1.5;
		}
		$line = preg_match('/(dash|dotted|double)/', $text, $m) ? ($m[1] === 'dash' ? 'dashed' : $m[1]) : 'solid';

		return array($points < 1.2 ? 1 : ($points < 2.3 ? 2 : 3), preg_match('/#[0-9a-f]{6}/', $text, $m) ? $m[0] : '#000000', $line);
	}

	/**
	 * Pixels of a length of OpenDocument (« 2.258cm »), within what a column may take on the page
	 *
	 * @param string $text Length
	 * @return int 0 when it is not a length
	 */
	protected static function lengthToPixels($text)
	{
		$units = array('cm' => 37.795, 'mm' => 3.7795, 'in' => 96, 'pt' => 1.3333, 'px' => 1);
		if (!preg_match('/^([\d.]+)(cm|mm|in|pt|px)$/', strtolower(trim((string) $text)), $m)) {
			return 0;
		}

		return (int) max(12, min(600, round((float) $m[1] * $units[$m[2]])));
	}

	/**
	 * Text of a cell, the reader on its opening tag; it ends on its closing tag (or stays on an empty tag)
	 *
	 * @param XMLReader $xml Reader
	 * @return array{0:string,1:string} Text and type
	 */
	protected function odsCell(XMLReader $xml)
	{
		$valueType = (string) $xml->getAttributeNs('value-type', self::NS_OFFICE);
		$type = in_array($valueType, array('float', 'percentage', 'currency'), true) ? 'n' : (in_array($valueType, array('date', 'time'), true) ? 'd' : '');
		// Kept for a cell written without its text (files made by scripts)
		$fallback = '';
		if ($valueType === 'float' || $valueType === 'currency' || $valueType === 'percentage') {
			$raw = (string) $xml->getAttributeNs('value', self::NS_OFFICE);
			$fallback = is_numeric($raw) ? ($valueType === 'percentage' ? $this->general((float) $raw * 100).'%' : $this->general((float) $raw)) : '';
		} elseif ($valueType === 'date') {
			$fallback = str_replace('T', ' ', (string) $xml->getAttributeNs('date-value', self::NS_OFFICE));
		} elseif ($valueType === 'time') {
			$fallback = preg_match('/^PT(\d+)H(\d+)M(\d+)/', (string) $xml->getAttributeNs('time-value', self::NS_OFFICE), $m) ? sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3]) : '';
		} elseif ($valueType === 'boolean') {
			$fallback = $xml->getAttributeNs('boolean-value', self::NS_OFFICE) === 'true' ? 'TRUE' : 'FALSE';
		} elseif ($valueType === 'string') {
			$fallback = (string) $xml->getAttributeNs('string-value', self::NS_OFFICE);
		}
		if ($xml->isEmptyElement) {
			return array($fallback, $type);
		}

		$depth = $xml->depth;
		$text = '';
		$paragraphs = 0;
		$inParagraph = -1;
		$more = @$xml->read();
		while ($more && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) && !$this->over()) {
			if ($xml->nodeType === XMLReader::ELEMENT) {
				$name = $xml->localName;
				if ($name === 'annotation' || $name === 'table' || $name === 'frame' || $name === 'shapes') {
					// The comment of a cell, a table or a picture inside it: not its text
					$more = @$xml->next();
					continue;
				}
				if ($xml->namespaceURI === self::NS_TEXT && strlen($text) < self::MAX_CELL_LENGTH * 4) {
					if (($name === 'p' || $name === 'h') && $inParagraph < 0) {
						$text .= $paragraphs++ ? "\n" : '';
						$inParagraph = $xml->isEmptyElement ? -1 : $xml->depth;
					} elseif ($name === 's') {
						$text .= str_repeat(' ', max(1, min(100, (int) $xml->getAttributeNs('c', self::NS_TEXT))));
					} elseif ($name === 'tab') {
						$text .= "\t";
					} elseif ($name === 'line-break') {
						$text .= "\n";
					}
				}
			} elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $inParagraph) {
				$inParagraph = -1;
			} elseif ($inParagraph >= 0 && in_array($xml->nodeType, array(XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::WHITESPACE), true)) {
				if (strlen($text) < self::MAX_CELL_LENGTH * 4) {
					$text .= preg_replace('/[ \t\r\n]+/', ' ', $xml->value);
				}
			}
			$more = @$xml->read();
		}

		return array($paragraphs ? $text : $fallback, $type);
	}

	// ---------- OLE: xls ----------

	/**
	 * Excel 97-2003 workbook
	 *
	 * @param string $content Content of the file
	 * @return void
	 */
	protected function readOle($content)
	{
		$cfb = new InboxCfbReader();
		if (!$cfb->load($content)) {
			$this->error = 'unreadable';
			return;
		}
		$streams = array();
		foreach ($cfb->children($cfb->root()) as $name => $index) {
			$streams[strtolower((string) $name)] = $index;
		}
		if (isset($streams['encryptedpackage'])) {
			// An xlsx with a password
			$this->error = 'protected';
			return;
		}
		$index = isset($streams['workbook']) ? $streams['workbook'] : (isset($streams['book']) ? $streams['book'] : null);
		$data = $index === null ? '' : $cfb->stream($index);
		unset($cfb);
		if (strlen($data) < 8) {
			$this->error = 'unreadable';
			return;
		}
		$this->readBiff($data);
	}

	/**
	 * Records of the workbook stream ([MS-XLS])
	 *
	 * @param string $data Stream
	 * @return void
	 */
	protected function readBiff($data)
	{
		$length = strlen($data);
		$biff8 = true;
		$codepage = 1252;
		$customFormats = array();
		$xfs = array();
		$records = array();
		$fonts = array();
		$palette = array();
		$bound = array();
		$sst = array();
		$first = true;
		$pos = 0;
		while ($pos + 4 <= $length && !$this->over()) {
			$id = InboxCfbReader::u16($data, $pos);
			$size = InboxCfbReader::u16($data, $pos + 2);
			$at = $pos + 4;
			$pos = $at + $size;
			if ($pos > $length) {
				break;
			}
			if ($first) {
				// The stream begins with the BOF of the workbook, which says the version
				if ($id !== 0x0809 || $size < 4) {
					$this->error = 'unreadable';
					return;
				}
				$version = InboxCfbReader::u16($data, $at);
				if ($version !== 0x0600 && $version !== 0x0500) {
					$this->error = 'unreadable';
					return;
				}
				$biff8 = $version === 0x0600;
				$first = false;
				continue;
			}
			switch ($id) {
				case 0x002F:	// FILEPASS: the records that follow are encrypted
					$this->error = 'protected';
					return;
				case 0x0042:	// CODEPAGE
					$codepage = $size >= 2 ? InboxCfbReader::u16($data, $at) : 1252;
					break;
				case 0x0022:	// DATEMODE
					$this->date1904 = $size >= 2 && InboxCfbReader::u16($data, $at) === 1;
					break;
				case 0x041E:	// FORMAT
					if ($size >= 5 && count($customFormats) < 5000) {
						$customFormats[InboxCfbReader::u16($data, $at)] = $biff8
							? self::biffString(substr($data, $at + 2, $size - 2), 2)
							: self::bytes(substr($data, $at + 3, ord($data[$at + 2])), $codepage);
					}
					break;
				case 0x00E0:	// XF: the number format and the look of a cell style
					if ($size >= 4 && count($xfs) < 65536) {
						$xfs[] = InboxCfbReader::u16($data, $at + 2);
						// The look is read from Excel 97 on; before, the record is another one
						$records[] = $biff8 && $size >= 20 ? substr($data, $at, 20) : '';
					}
					break;
				case 0x0031:	// FONT
					if ($size >= 10 && count($fonts) < 5000) {
						$bits = InboxCfbReader::u16($data, $at + 2);
						$fonts[] = array(
							'z' => InboxCfbReader::u16($data, $at) / 20, 'i' => (bool) ($bits & 0x02), 'k' => (bool) ($bits & 0x08),
							'c' => InboxCfbReader::u16($data, $at + 4), 'b' => InboxCfbReader::u16($data, $at + 6) >= 600, 'u' => $size >= 11 && ord($data[$at + 10]) !== 0,
						);
					}
					break;
				case 0x0092:	// PALETTE: the numbered colours 8 to 63 changed by the author
					for ($i = 0; $size >= 2 && $i < 56 && $i < InboxCfbReader::u16($data, $at) && $at + 6 + $i * 4 <= $pos; $i++) {
						$palette[8 + $i] = sprintf('#%02x%02x%02x', ord($data[$at + 2 + $i * 4]), ord($data[$at + 3 + $i * 4]), ord($data[$at + 4 + $i * 4]));
					}
					break;
				case 0x0085:	// BOUNDSHEET
					if ($size >= 8 && count($bound) < 500) {
						$name = $biff8 ? self::biffString(substr($data, $at + 6, $size - 6), 1) : self::bytes(substr($data, $at + 7, ord($data[$at + 6])), $codepage);
						$bound[] = array('offset' => InboxCfbReader::u32($data, $at), 'type' => ord($data[$at + 5]), 'name' => $name);
					}
					break;
				case 0x00FC:	// SST, continued in the CONTINUE records that follow
					$sst = array(array($at, $size));
					while ($pos + 4 <= $length && InboxCfbReader::u16($data, $pos) === 0x003C && count($sst) < 100000) {
						$more = InboxCfbReader::u16($data, $pos + 2);
						if ($pos + 4 + $more > $length) {
							break;
						}
						$sst[] = array($pos + 4, $more);
						$pos += 4 + $more;
					}
					break;
				case 0x000A:	// EOF of the workbook globals
					break 2;
			}
		}

		$formats = array();
		foreach ($xfs as $i => $formatIndex) {
			$formats[$i] = isset($customFormats[$formatIndex]) ? $customFormats[$formatIndex] : (isset(self::BUILTIN_FORMATS[$formatIndex]) ? self::BUILTIN_FORMATS[$formatIndex] : 'General');
		}
		$this->baseSize = isset($fonts[0]) && $fonts[0]['z'] >= 4 ? (float) $fonts[0]['z'] : 10.0;
		$looks = array();
		$known = array();
		foreach ($records as $i => $record) {
			if ($record !== '' && !isset($known[$record])) {
				$known[$record] = $this->biffLook($record, $fonts, $palette);
			}
			$looks[$i] = $record !== '' ? $known[$record] : -1;
		}

		$wanted = array();
		foreach ($bound as $b) {
			if ($b['type'] !== 0 || $b['offset'] <= 0 || $b['offset'] + 4 > $length) {
				// A chart, a macro sheet
				continue;
			}
			$index = $this->addSheet($b['name']);
			if ($index === false) {
				break;
			}
			$this->biffSheet($data, $b['offset'], $index, $biff8, $codepage, $formats, $wanted, $looks);
		}
		if ($wanted) {
			$this->resolveStrings($sst ? $this->biffStrings($data, $sst, $wanted) : array());
		}
	}

	/**
	 * Look of a cell style of Excel 97-2003
	 *
	 * @param string                         $xf      Its XF record (20 bytes)
	 * @param array<int,array<string,mixed>> $fonts   Fonts of the workbook
	 * @param array<int,string>              $palette Numbered colours changed by the author
	 * @return int Look (styleId()), -1 for none
	 */
	protected function biffLook($xf, array $fonts, array $palette)
	{
		// A numbered colour; the ones of the window (64, 65, 32767) are the ink and the paper of the page
		$colour = function ($index) use ($palette) {
			return isset($palette[$index]) ? $palette[$index] : (isset(self::INDEXED[$index]) ? '#'.self::INDEXED[$index] : null);
		};
		// The font number 4 does not exist: the ones after it are named one further
		$font = InboxCfbReader::u16($xf, 0);
		$font = $font > 4 ? $font - 1 : $font;
		$look = isset($fonts[$font]) ? $fonts[$font] : array();
		$look['c'] = isset($look['c']) ? $colour($look['c']) : null;

		$align = ord($xf[6]);
		$across = array(1 => 'l', 2 => 'c', 3 => 'r', 6 => 'c');
		$down = array(0 => 't', 1 => 'm');
		$look['a'] = isset($across[$align & 7]) ? $across[$align & 7] : '';
		$look['va'] = isset($down[($align >> 4) & 7]) ? $down[($align >> 4) & 7] : '';
		$look['w'] = (bool) ($align & 8);

		$lines = InboxCfbReader::u32($xf, 10);
		$more = InboxCfbReader::u32($xf, 14);
		$sides = array(
			'bl' => array($lines & 0xF, ($lines >> 16) & 0x7F), 'br' => array(($lines >> 4) & 0xF, ($lines >> 23) & 0x7F),
			'bt' => array(($lines >> 8) & 0xF, $more & 0x7F), 'bb' => array(($lines >> 12) & 0xF, ($more >> 7) & 0x7F),
		);
		foreach ($sides as $side => $border) {
			if (isset(self::BIFF_BORDERS[$border[0]])) {
				$look[$side] = array(self::BIFF_BORDERS[$border[0]][0], $colour($border[1]), self::BIFF_BORDERS[$border[0]][1]);
			}
		}
		if ((($more >> 26) & 0x3F) === 1) {
			// A plain background
			$look['g'] = $colour(InboxCfbReader::u16($xf, 18) & 0x7F);
		}

		return $this->styleId($look);
	}

	/**
	 * Cells of one sheet
	 *
	 * @param string            $data     Stream
	 * @param int               $pos      Place of the BOF of the sheet
	 * @param int               $sheet    Index of the sheet
	 * @param bool              $biff8    Excel 97 and later
	 * @param int               $codepage Code page of the texts before Excel 97
	 * @param array<int,string> $formats  Style index => format code
	 * @param array<int,bool>   $wanted   Shared strings to read, completed
	 * @param array<int,int>    $looks    Style index => look
	 * @return void
	 */
	protected function biffSheet($data, $pos, $sheet, $biff8, $codepage, array $formats, array &$wanted, array $looks = array())
	{
		$length = strlen($data);
		$depth = 0;
		// The cell of a formula whose result is a text: the text is in the next STRING record
		$pending = null;
		while ($pos + 4 <= $length && !$this->over()) {
			$id = InboxCfbReader::u16($data, $pos);
			$size = InboxCfbReader::u16($data, $pos + 2);
			$at = $pos + 4;
			$pos = $at + $size;
			if ($pos > $length) {
				break;
			}
			if ($id === 0x0809) {
				$depth++;
				continue;
			}
			if ($id === 0x000A) {
				$depth--;
				if ($depth <= 0) {
					break;
				}
				continue;
			}
			if ($depth === 1 && $id === 0x0055 && $size >= 2) {
				// DEFCOLWIDTH: the width of the columns no COLINFO names, in characters
				$this->sheets[$sheet]['width'] = self::charsToPixels(InboxCfbReader::u16($data, $at));
				continue;
			}
			if ($depth !== 1 || $size < 6) {
				// Records of a chart placed in the sheet, or too short for a cell
				continue;
			}
			$row = InboxCfbReader::u16($data, $at);
			$col = InboxCfbReader::u16($data, $at + 2);
			$format = function ($offset) use ($data, $formats) {
				$xf = InboxCfbReader::u16($data, $offset);
				return isset($formats[$xf]) ? $formats[$xf] : 'General';
			};
			$look = function ($offset) use ($data, $looks) {
				$xf = InboxCfbReader::u16($data, $offset);
				return isset($looks[$xf]) ? $looks[$xf] : -1;
			};
			switch ($id) {
				case 0x007D:	// COLINFO: width of some columns, in 256th of a character; a hidden one is shown as the others
					if ($size >= 10 && InboxCfbReader::u16($data, $at + 4) > 0 && !(InboxCfbReader::u16($data, $at + 8) & 1)) {
						for ($c = $row; $c <= $col && $c < self::MAX_COLS; $c++) {
							$this->sheets[$sheet]['widths'][$c] = self::charsToPixels(InboxCfbReader::u16($data, $at + 4) / 256);
						}
					}
					break;
				case 0x00E5:	// MERGEDCELLS: first row, last row, first column, last column of each
					for ($i = 0; $i < $row && $at + 10 + $i * 8 <= $pos; $i++) {
						$first = InboxCfbReader::u16($data, $at + 2 + $i * 8);
						$left = InboxCfbReader::u16($data, $at + 6 + $i * 8);
						$this->merge($sheet, $first, $left, InboxCfbReader::u16($data, $at + 4 + $i * 8) - $first + 1, InboxCfbReader::u16($data, $at + 8 + $i * 8) - $left + 1);
					}
					break;
				case 0x0201:	// BLANK: a cell without text, kept when its look shows
					$this->decorate($sheet, $row, $col, $look($at + 4));
					break;
				case 0x00BE:	// MULBLANK: several of them in the same row
					for ($i = 0; $at + 4 + $i * 2 + 2 <= $pos - 2; $i++) {
						$this->decorate($sheet, $row, $col + $i, $look($at + 4 + $i * 2));
					}
					break;
				case 0x00FD:	// LABELSST: a text of the shared strings
					if ($size >= 10 && $this->room($sheet, $row, $col)) {
						$isst = InboxCfbReader::u32($data, $at + 6);
						$wanted[$isst] = true;
						if (!isset($this->sheets[$sheet]['cells'][$row][$col])) {
							$this->cells++;
						}
						$this->sheets[$sheet]['cells'][$row][$col] = array('p' => $isst, 's' => $look($at + 4));
					}
					break;
				case 0x0204:	// LABEL
				case 0x00D6:	// RSTRING
					if ($size >= 8 && $this->room($sheet, $row, $col)) {
						$text = $biff8 ? self::biffString(substr($data, $at + 6, $size - 6), 2) : self::bytes(substr($data, $at + 8, InboxCfbReader::u16($data, $at + 6)), $codepage);
						$this->put($sheet, $row, $col, $text, '', $look($at + 4));
					}
					break;
				case 0x0203:	// NUMBER
					if ($size >= 14 && $this->room($sheet, $row, $col)) {
						$f = $this->formatNumber(self::double(substr($data, $at + 6, 8)), $format($at + 4));
						$this->put($sheet, $row, $col, $f[0], $f[1], $look($at + 4));
					}
					break;
				case 0x027E:	// RK: a number packed in 4 bytes
					if ($size >= 10 && $this->room($sheet, $row, $col)) {
						$f = $this->formatNumber(self::rk(InboxCfbReader::u32($data, $at + 6)), $format($at + 4));
						$this->put($sheet, $row, $col, $f[0], $f[1], $look($at + 4));
					}
					break;
				case 0x00BD:	// MULRK: several RK of the same row
					for ($i = 0; $at + 4 + $i * 6 + 6 <= $pos - 2; $i++) {
						if ($this->room($sheet, $row, $col + $i)) {
							$f = $this->formatNumber(self::rk(InboxCfbReader::u32($data, $at + 6 + $i * 6)), $format($at + 4 + $i * 6));
							$this->put($sheet, $row, $col + $i, $f[0], $f[1], $look($at + 4 + $i * 6));
						}
					}
					break;
				case 0x0006:	// FORMULA: the result kept in the file
					$pending = null;
					if ($size >= 14 && $this->room($sheet, $row, $col)) {
						if (InboxCfbReader::u16($data, $at + 12) === 0xFFFF) {
							$kind = ord($data[$at + 6]);
							$byte = ord($data[$at + 8]);
							if ($kind === 0) {
								$pending = array($row, $col, $look($at + 4));
							} elseif ($kind === 1) {
								$this->put($sheet, $row, $col, $byte ? 'TRUE' : 'FALSE', '', $look($at + 4));
							} elseif ($kind === 2) {
								$this->put($sheet, $row, $col, isset(self::BIFF_ERRORS[$byte]) ? self::BIFF_ERRORS[$byte] : '#N/A', '', $look($at + 4));
							} else {
								$this->decorate($sheet, $row, $col, $look($at + 4));
							}
						} else {
							$f = $this->formatNumber(self::double(substr($data, $at + 6, 8)), $format($at + 4));
							$this->put($sheet, $row, $col, $f[0], $f[1], $look($at + 4));
						}
					}
					break;
				case 0x0205:	// BOOLERR
					if ($size >= 8 && $this->room($sheet, $row, $col)) {
						$byte = ord($data[$at + 6]);
						$this->put($sheet, $row, $col, ord($data[$at + 7]) ? (isset(self::BIFF_ERRORS[$byte]) ? self::BIFF_ERRORS[$byte] : '#N/A') : ($byte ? 'TRUE' : 'FALSE'), '', $look($at + 4));
					}
					break;
				case 0x0207:	// STRING: the text of the formula just before (no row nor column of its own)
					if ($pending !== null) {
						$text = $biff8 ? self::biffString(substr($data, $at, $size), 2) : self::bytes(substr($data, $at + 2, InboxCfbReader::u16($data, $at)), $codepage);
						$this->put($sheet, $pending[0], $pending[1], $text, '', $pending[2]);
						$pending = null;
					}
					break;
			}
		}
	}

	/**
	 * Shared strings of a workbook, only the ones the cells shown use
	 *
	 * @param string                        $data   Stream
	 * @param array<int,array{0:int,1:int}> $chunks Place and size of the SST record and of its CONTINUE records
	 * @param array<int,bool>               $wanted Indexes to read
	 * @return array<int,string>
	 */
	protected function biffStrings($data, array $chunks, array $wanted)
	{
		$out = array();
		$last = max(array_keys($wanted));
		$chunk = 0;
		$pos = $chunks[0][0] + 8;
		$end = $chunks[0][0] + $chunks[0][1];
		// Bytes of the header of a string: they may cross a CONTINUE record, which then adds nothing
		$take = function ($count) use ($data, $chunks, &$chunk, &$pos, &$end) {
			$bytes = '';
			while ($count > 0) {
				if ($pos >= $end) {
					if (!isset($chunks[$chunk + 1])) {
						return null;
					}
					$chunk++;
					$pos = $chunks[$chunk][0];
					$end = $pos + $chunks[$chunk][1];
					continue;
				}
				$n = min($count, $end - $pos);
				$bytes .= substr($data, $pos, $n);
				$pos += $n;
				$count -= $n;
			}
			return $bytes;
		};
		for ($i = 0; $i <= $last && !$this->over(); $i++) {
			$head = $take(3);
			if ($head === null) {
				break;
			}
			$chars = InboxCfbReader::u16($head, 0);
			$flags = ord($head[2]);
			$runs = 0;
			$extra = 0;
			if ($flags & 0x08) {
				$b = $take(2);
				if ($b === null) {
					break;
				}
				$runs = InboxCfbReader::u16($b, 0);
			}
			if ($flags & 0x04) {
				$b = $take(4);
				if ($b === null) {
					break;
				}
				$extra = InboxCfbReader::u32($b, 0);
			}
			$keep = isset($wanted[$i]);
			$text = '';
			$wide = (bool) ($flags & 0x01);
			while ($chars > 0) {
				if ($pos >= $end) {
					// The characters go on in a CONTINUE record, which begins by saying again if they are 16 bit
					if (!isset($chunks[$chunk + 1])) {
						break 2;
					}
					$chunk++;
					$pos = $chunks[$chunk][0];
					$end = $pos + $chunks[$chunk][1];
					if ($pos >= $end) {
						continue;
					}
					$wide = (bool) (ord($data[$pos]) & 0x01);
					$pos++;
					continue;
				}
				$n = min($chars, intdiv($end - $pos, $wide ? 2 : 1));
				if ($n <= 0) {
					// One byte left of a 16 bit character: the file is wrong
					break 2;
				}
				if ($keep && strlen($text) < self::MAX_CELL_LENGTH * 4) {
					$text .= mb_convert_encoding(substr($data, $pos, $n * ($wide ? 2 : 1)), 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
				}
				$pos += $n * ($wide ? 2 : 1);
				$chars -= $n;
			}
			if ($keep) {
				$out[$i] = $text;
			}
			// Formats of parts of the text and phonetic guide: not shown
			if ($runs * 4 + $extra > 0 && $take($runs * 4 + $extra) === null) {
				break;
			}
		}

		return $out;
	}

	/**
	 * A text of a BIFF8 record (not continued): count, flags, characters
	 *
	 * @param string $bytes     Bytes from the count
	 * @param int    $countSize Bytes of the count: 1 or 2
	 * @return string UTF-8
	 */
	protected static function biffString($bytes, $countSize)
	{
		if (strlen($bytes) < $countSize + 1) {
			return '';
		}
		$chars = $countSize === 1 ? ord($bytes[0]) : InboxCfbReader::u16($bytes, 0);
		$flags = ord($bytes[$countSize]);
		$offset = $countSize + 1 + (($flags & 0x08) ? 2 : 0) + (($flags & 0x04) ? 4 : 0);
		if ($flags & 0x01) {
			return (string) mb_convert_encoding(substr($bytes, $offset, $chars * 2), 'UTF-8', 'UTF-16LE');
		}

		return (string) mb_convert_encoding(substr($bytes, $offset, $chars), 'UTF-8', 'ISO-8859-1');
	}

	/**
	 * A text of Excel 5/95, in the code page of the workbook
	 *
	 * @param string $bytes    Bytes
	 * @param int    $codepage Code page
	 * @return string UTF-8
	 */
	protected static function bytes($bytes, $codepage)
	{
		if ($bytes === '') {
			return '';
		}
		if ($codepage === 1200) {
			return (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
		}
		$from = $codepage === 10000 ? 'MACINTOSH' : ($codepage === 32768 ? 'MACINTOSH' : 'CP'.((int) $codepage ?: 1252));
		$text = function_exists('iconv') ? @iconv($from, 'UTF-8//IGNORE', $bytes) : false;

		return $text !== false && $text !== '' ? $text : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
	}

	/**
	 * @param string $bytes 8 bytes, little endian
	 * @return float
	 */
	protected static function double($bytes)
	{
		if (strlen($bytes) < 8) {
			return 0.0;
		}
		$v = unpack('e', $bytes);

		return (float) $v[1];
	}

	/**
	 * A number packed in 4 bytes: 30 bits of an integer or of the top of a double, sometimes divided by 100
	 *
	 * @param int $rk Value read
	 * @return float
	 */
	protected static function rk($rk)
	{
		if ($rk & 0x02) {
			$v = $rk >> 2;
			if ($v & 0x20000000) {
				$v -= 0x40000000;
			}
			$v = (float) $v;
		} else {
			$v = self::double("\0\0\0\0".pack('V', $rk & 0xFFFFFFFC));
		}

		return ($rk & 0x01) ? $v / 100 : $v;
	}
}
