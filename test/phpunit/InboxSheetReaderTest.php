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
 * \file    inbox/test/phpunit/InboxSheetReaderTest.php
 * \ingroup inbox
 * \brief   1.152.0: spreadsheets (xlsx, ods, xls) read for the preview of an attachment, with their look
 */

// From the command line only, never from the web: test/ stays in the Git branches
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsheetreader.class.php');

/**
 * The files are built here, as a sender would: no file of a real person in the tests
 */
class InboxSheetReaderTest extends InboxTestCase
{
	const XLSX_NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

	const ODS_NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
		.' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"';

	/**
	 * No database in these tests
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
	}

	/**
	 * A zip made of these parts
	 *
	 * @param array<string,string> $parts Name => content
	 * @return string
	 */
	private static function zip(array $parts)
	{
		$tmp = tempnam(sys_get_temp_dir(), 'ibxt');
		$zip = new ZipArchive();
		$zip->open($tmp, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		$data = (string) file_get_contents($tmp);
		unlink($tmp);

		return $data;
	}

	/**
	 * An xlsx workbook
	 *
	 * @param array<string,string|array{0:string,1:string,2:string}> $sheets Name => content of <sheetData>, or what is
	 *                                                                       written before it, its content, what is after
	 * @param string               $strings Content of <sst>
	 * @param string               $styles  Content of <styleSheet>
	 * @param string               $props   Attributes of <workbookPr>
	 * @return string
	 */
	private static function xlsx(array $sheets, $strings = '', $styles = '', $props = '')
	{
		$parts = array(
			'_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/sharedStrings.xml' => '<sst '.self::XLSX_NS.'>'.$strings.'</sst>',
			'xl/styles.xml' => '<styleSheet '.self::XLSX_NS.'>'.$styles.'</styleSheet>',
		);
		$list = '';
		$rels = '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
			.'<Relationship Id="rIdY" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="/xl/styles.xml"/>';
		$n = 0;
		foreach ($sheets as $name => $data) {
			$n++;
			$list .= '<sheet name="'.htmlspecialchars($name, ENT_QUOTES).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
			$rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
			$data = is_array($data) ? $data : array('', $data, '');
			$parts['xl/worksheets/sheet'.$n.'.xml'] = '<worksheet '.self::XLSX_NS.'>'.$data[0].'<sheetData>'.$data[1].'</sheetData>'.$data[2].'</worksheet>';
		}
		$parts['xl/workbook.xml'] = '<workbook '.self::XLSX_NS.'><workbookPr '.$props.'/><sheets>'.$list.'</sheets></workbook>';
		$parts['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';

		return self::zip($parts);
	}

	/**
	 * An ods spreadsheet
	 *
	 * @param string $tables   The <table:table> elements
	 * @param string $manifest Content of the manifest
	 * @param string $styles   Styles of this file (content of <office:automatic-styles>)
	 * @param string $named    Named styles (content of <office:styles> in styles.xml)
	 * @return string
	 */
	private static function ods($tables, $manifest = '', $styles = '', $named = '')
	{
		return self::zip(array(
			'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
			'styles.xml' => '<office:document-styles '.self::ODS_NS.'><office:styles>'.$named.'</office:styles></office:document-styles>',
			'content.xml' => '<office:document-content '.self::ODS_NS.'><office:automatic-styles>'.$styles.'</office:automatic-styles><office:body><office:spreadsheet>'.$tables
				.'</office:spreadsheet></office:body></office:document-content>',
			'META-INF/manifest.xml' => '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0">'.$manifest.'</manifest:manifest>',
		));
	}

	/**
	 * An OLE file holding one stream ([MS-CFB] version 3, sectors of 512 bytes, no mini stream)
	 *
	 * @param string $name   Name of the stream
	 * @param string $stream Content
	 * @return string
	 */
	private static function ole($name, $stream)
	{
		// At least 4096 bytes: under it, a stream goes to the mini stream
		$size = max(4096, strlen($stream));
		$stream = str_pad($stream, (int) ceil($size / 512) * 512, "\0");
		$sectors = (int) (strlen($stream) / 512);
		$header = InboxCfbReader::SIGNATURE.str_repeat("\0", 16).pack('vvvvv', 0x003E, 3, 0xFFFE, 9, 6).str_repeat("\0", 6)
			.pack('VVVVVVVVV', 0, 1, 1, 0, 4096, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0).pack('V', 0).str_repeat("\xFF", 108 * 4);
		$fat = pack('VV', 0xFFFFFFFD, 0xFFFFFFFE);
		for ($i = 0; $i < $sectors; $i++) {
			$fat .= pack('V', $i === $sectors - 1 ? 0xFFFFFFFE : 3 + $i);
		}
		$fat = str_pad($fat, 512, "\xFF");
		$entry = function ($entryName, $type, $child, $start, $length) {
			$utf16 = mb_convert_encoding($entryName, 'UTF-16LE', 'UTF-8');
			return str_pad($utf16, 64, "\0").pack('v', $entryName === '' ? 0 : strlen($utf16) + 2).chr($type).chr(1)
				.pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, $child).str_repeat("\0", 36).pack('VVV', $start, $length, 0);
		};
		$directory = $entry('Root Entry', 5, 1, 0xFFFFFFFE, 0).$entry($name, 2, 0xFFFFFFFF, 2, $size)
			.$entry('', 0, 0xFFFFFFFF, 0, 0).$entry('', 0, 0xFFFFFFFF, 0, 0);

		return $header.$fat.$directory.$stream;
	}

	/**
	 * One record of a workbook stream
	 *
	 * @param int    $id   Record
	 * @param string $data Content
	 * @return string
	 */
	private static function rec($id, $data = '')
	{
		return pack('vv', $id, strlen($data)).$data;
	}

	/**
	 * A workbook stream of Excel 97-2003 with one sheet
	 *
	 * @param string $globals Records of the workbook, after its BOF
	 * @param string $cells   Records of the sheet
	 * @param string $name    Name of the sheet, in the bytes of the version
	 * @param int    $version 0x0600 (Excel 97) or 0x0500 (Excel 5/95)
	 * @return string
	 */
	private static function biff($globals, $cells, $name = "\x06\x00Sheet1", $version = 0x0600)
	{
		$bof = function ($type) use ($version) {
			return self::rec(0x0809, pack('vvvvVV', $version, $type, 0, 0, 0, 0));
		};
		$head = $bof(0x0005).$globals;
		$offset = strlen($head) + 4 + 6 + strlen($name) + 4;

		return $head.self::rec(0x0085, pack('V', $offset)."\0\0".$name).self::rec(0x000A).$bof(0x0010).$cells.self::rec(0x000A);
	}

	/**
	 * xlsx: shared and inline texts, formats, booleans, errors, holes, sheets in the order of the tabs
	 *
	 * @return void
	 */
	public function testXlsx()
	{
		$styles = '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00\ [$€-40C]"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy\ hh:mm"/></numFmts>'
			.'<cellStyleXfs count="1"><xf numFmtId="9"/></cellStyleXfs>'
			.'<cellXfs count="6"><xf numFmtId="0"/><xf numFmtId="164"/><xf numFmtId="14"/><xf numFmtId="165"/><xf numFmtId="10"/><xf numFmtId="3"/></cellXfs>';
		$strings = '<si><t>Réf</t></si><si><r><t>Vanne </t></r><r><t xml:space="preserve">à boule</t></r><rPh sb="0" eb="1"><t>phonetic</t></rPh></si><si><t>not used</t></si><si><t>line 1_x000D_line 2</t></si>';
		$first = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>&lt;b&gt;typed&lt;/b&gt;</t></is></c></row>'
			.'<row r="2"><c r="A2" s="1"><v>1234.5</v></c><c r="B2" s="2"><v>46353</v></c><c r="C2" s="3"><v>46353.5460648148</v></c><c r="D2" s="4"><v>0.075</v></c><c r="E2" s="5"><v>1234567</v></c></row>'
			.'<row r="4"><c r="A4" t="b"><v>1</v></c><c r="B4" t="e"><v>#DIV/0!</v></c><c r="C4" t="str"><f>A1&amp;"x"</f><v>Réfx</v></c><c r="D4"><f>1+2</f><v>0.30000000000000004</v></c><c r="E4" t="s"><v>3</v></c><c r="F4"/></row>';
		$reader = new InboxSheetReader(array('decimal' => ',', 'thousand' => ' '));
		$res = $reader->read(self::xlsx(array('Tarifs été' => $first, 'Vide' => ''), $strings, $styles));

		$this->assertSame('', $reader->error);
		$this->assertFalse($res['truncated']);
		$this->assertSame(array('Tarifs été', 'Vide'), array_column($res['sheets'], 'name'));
		$rows = $res['sheets'][0]['rows'];
		$this->assertSame(array('Réf', 'Vanne à boule', '', '<b>typed</b>'), $rows[0], 'texts as they are (escaped by the page), the phonetic guide left out');
		$this->assertSame(array('v' => '1 234,50 €', 't' => 'n'), $rows[1][0]);
		$this->assertSame(array('v' => '2026-11-27', 't' => 'd'), $rows[1][1]);
		$this->assertSame(array('v' => '2026-11-27 13:06', 't' => 'd'), $rows[1][2]);
		$this->assertSame(array('v' => '7,50%', 't' => 'n'), $rows[1][3]);
		$this->assertSame(array('v' => '1 234 567', 't' => 'n'), $rows[1][4]);
		$this->assertSame(array(), $rows[2], 'an empty row keeps its place');
		$this->assertSame('TRUE', $rows[3][0]);
		$this->assertSame('#DIV/0!', $rows[3][1]);
		$this->assertSame('Réfx', $rows[3][2], 'the result kept for a formula');
		$this->assertSame(array('v' => '0,3', 't' => 'n'), $rows[3][3], 'noise of the floating point rounded');
		$this->assertSame("line 1\nline 2", $rows[3][4]);
		$this->assertSame(array(), $res['sheets'][1]['rows']);
	}

	/**
	 * Dates counted from 1904, and the days before the 29 February 1900 that Excel invented
	 *
	 * @return void
	 */
	public function testDates()
	{
		$styles = '<cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="46"/></cellXfs>';
		$data = '<row r="1"><c r="A1" s="1"><v>59</v></c><c r="B1" s="1"><v>61</v></c><c r="C1" s="2"><v>1.5208333333</v></c><c r="D1" s="1"><v>-3</v></c></row>';
		$reader = new InboxSheetReader();
		$rows = $reader->read(self::xlsx(array('d' => $data), '', $styles))['sheets'][0]['rows'];
		$this->assertSame('1900-02-28', $rows[0][0]['v']);
		$this->assertSame('1900-03-01', $rows[0][1]['v']);
		$this->assertSame('36:30:00', $rows[0][2]['v'], 'a duration over a day is not a date');
		$this->assertSame(array('v' => '-3', 't' => 'n'), $rows[0][3], 'a number no calendar knows stays a number');

		$rows = $reader->read(self::xlsx(array('d' => '<row r="1"><c r="A1" s="1"><v>0</v></c></row>'), '', $styles, 'date1904="1"'))['sheets'][0]['rows'];
		$this->assertSame('1904-01-01', $rows[0][0]['v']);

		// The language of the user
		$reader = new InboxSheetReader(array('date' => function ($timestamp, $kind) {
			return $kind.'@'.$timestamp;
		}));
		$rows = $reader->read(self::xlsx(array('d' => '<row r="1"><c r="A1" s="1"><v>25569</v></c></row>'), '', $styles))['sheets'][0]['rows'];
		$this->assertSame('day@0', $rows[0][0]['v']);
	}

	/**
	 * The preview stops at its limits and says so
	 *
	 * @return void
	 */
	public function testLimits()
	{
		$data = '';
		for ($r = 1; $r <= InboxSheetReader::MAX_ROWS + 50; $r++) {
			$data .= '<row r="'.$r.'"><c r="A'.$r.'"><v>'.$r.'</v></c></row>';
		}
		$wide = '<row r="1">';
		for ($c = 0; $c < InboxSheetReader::MAX_COLS + 20; $c++) {
			$wide .= '<c t="inlineStr"><is><t>c'.$c.'</t></is></c>';
		}
		$wide .= '</row>';
		$reader = new InboxSheetReader();
		$res = $reader->read(self::xlsx(array('long' => $data, 'wide' => $wide, 'short' => '<row><c><v>1</v></c></row>')));
		$this->assertTrue($res['truncated']);
		$this->assertTrue($res['sheets'][0]['truncated']);
		$this->assertCount(InboxSheetReader::MAX_ROWS, $res['sheets'][0]['rows']);
		$this->assertTrue($res['sheets'][1]['truncated']);
		$this->assertCount(InboxSheetReader::MAX_COLS, $res['sheets'][1]['rows'][0], 'cells without reference follow each other');
		$this->assertFalse($res['sheets'][2]['truncated']);

		// A text longer than a cell shows
		$long = str_repeat('é', InboxSheetReader::MAX_CELL_LENGTH + 10);
		$res = $reader->read(self::xlsx(array('s' => '<row><c t="inlineStr"><is><t>'.$long.'</t></is></c></row>')));
		$this->assertSame(InboxSheetReader::MAX_CELL_LENGTH + 1, mb_strlen($res['sheets'][0]['rows'][0][0], 'UTF-8'));

		// More sheets than tabs
		$many = array();
		for ($i = 0; $i < InboxSheetReader::MAX_SHEETS + 3; $i++) {
			$many['s'.$i] = '<row><c><v>'.$i.'</v></c></row>';
		}
		$res = $reader->read(self::xlsx($many));
		$this->assertCount(InboxSheetReader::MAX_SHEETS, $res['sheets']);
		$this->assertTrue($res['truncated']);
	}

	/**
	 * ods: the text shown by the author, repeated cells and rows, comments left out
	 *
	 * @return void
	 */
	public function testOds()
	{
		$tables = '<table:table table:name="Feuille 1">'
			.'<table:table-column table:number-columns-repeated="16384"/>'
			.'<table:table-row><table:table-cell office:value-type="string"><text:p>Réf</text:p></table:table-cell>'
			.'<table:table-cell table:number-columns-repeated="2"/>'
			.'<table:table-cell office:value-type="string"><office:annotation><text:p>a comment</text:p></office:annotation><text:p>a<text:s text:c="3"/>b<text:span> c</text:span></text:p><text:p>line 2</text:p></table:table-cell>'
			.'<table:table-cell table:number-columns-repeated="16380"/></table:table-row>'
			.'<table:table-row table:number-rows-repeated="2"><table:table-cell office:value-type="float" office:value="1234.5" table:number-columns-repeated="2"><text:p>1 234,50 €</text:p></table:table-cell>'
			.'<table:covered-table-cell/>'
			.'<table:table-cell office:value-type="date" office:date-value="2026-11-27"><text:p>27/11/2026</text:p></table:table-cell>'
			.'<table:table-cell office:value-type="float" office:value="0.5"/><table:table-cell office:value-type="boolean" office:boolean-value="true"/></table:table-row>'
			.'<table:table-row table:number-rows-repeated="1048570"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
			.'</table:table><table:table table:name="Stock"><table:table-row><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row></table:table>';
		$reader = new InboxSheetReader();
		$res = $reader->read(self::ods($tables));

		$this->assertSame('', $reader->error);
		$this->assertFalse($res['truncated'], 'the empty rows and cells that end a sheet are not a cut');
		$this->assertSame(array('Feuille 1', 'Stock'), array_column($res['sheets'], 'name'));
		$rows = $res['sheets'][0]['rows'];
		$this->assertCount(3, $rows);
		$this->assertSame(array('Réf', '', '', "a   b c\nline 2"), $rows[0]);
		$this->assertSame(array('v' => '1 234,50 €', 't' => 'n'), $rows[1][0]);
		$this->assertSame($rows[1][0], $rows[1][1], 'a cell repeated');
		$this->assertSame('', $rows[1][2], 'a cell covered by a merged one');
		$this->assertSame(array('v' => '27/11/2026', 't' => 'd'), $rows[1][3]);
		$this->assertSame(array('v' => '0.5', 't' => 'n'), $rows[1][4], 'a cell written without its text');
		$this->assertSame('TRUE', $rows[1][5]);
		$this->assertSame($rows[1], $rows[2], 'a row repeated');
		$this->assertSame(array(array('x')), $res['sheets'][1]['rows']);

		// A filled row repeated down to the end of the sheet
		$res = $reader->read(self::ods('<table:table table:name="t"><table:table-row table:number-rows-repeated="1048576"><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row></table:table>'));
		$this->assertCount(InboxSheetReader::MAX_ROWS, $res['sheets'][0]['rows']);
		$this->assertTrue($res['sheets'][0]['truncated']);
	}

	/**
	 * xls of Excel 97-2003: shared texts continued in other records, packed numbers, formulas
	 *
	 * @return void
	 */
	public function testXls()
	{
		// Font, format, and the text at the bottom of the cell as Excel writes it
		$xf = function ($format) {
			return self::rec(0x00E0, pack('vvvC', 0, $format, 0, 0x20).str_repeat("\0", 13));
		};
		// Shared texts: 16 bit; 8 bit cut by a CONTINUE; 8 bit that goes on in 16 bit; one with formats of parts of it
		$sst = pack('VV', 5, 5)
			.pack('vC', 3, 1).mb_convert_encoding('Réf', 'UTF-16LE', 'UTF-8')
			.pack('vC', 10, 0).'abcd';
		$continue1 = "\0".'efghij'.pack('vC', 5, 0).'ab';
		$continue2 = "\1".mb_convert_encoding('cdé', 'UTF-16LE', 'UTF-8')
			.pack('vCv', 4, 8, 1).'rich'.pack('vv', 0, 5)
			.pack('vC', 2, 0).'ok';
		$globals = self::rec(0x0042, pack('v', 1200)).self::rec(0x0022, pack('v', 0))
			.self::rec(0x041E, pack('v', 164).pack('vC', 9, 0).'0.00" kg"')
			.$xf(0).$xf(14).$xf(164).$xf(10)
			.self::rec(0x00FC, $sst).self::rec(0x003C, $continue1).self::rec(0x003C, $continue2);
		$cells = self::rec(0x00FD, pack('vvvV', 0, 0, 0, 0)).self::rec(0x00FD, pack('vvvV', 0, 1, 0, 1)).self::rec(0x00FD, pack('vvvV', 0, 2, 0, 2))
			.self::rec(0x00FD, pack('vvvV', 0, 3, 0, 3)).self::rec(0x00FD, pack('vvvV', 0, 4, 0, 4))
			.self::rec(0x0203, pack('vvv', 1, 0, 2).pack('e', 3.14159))
			.self::rec(0x027E, pack('vvvV', 1, 1, 0, (1234 << 2) | 2))
			.self::rec(0x027E, pack('vvvV', 1, 2, 0, ((12345 << 2) | 3)))
			.self::rec(0x027E, pack('vvvV', 1, 3, 0, ((-5 << 2) & 0xFFFFFFFC) | 2))
			.self::rec(0x00BD, pack('vv', 2, 0).pack('vV', 1, (46353 << 2) | 2).pack('vV', 3, unpack('V', substr(pack('e', 0.5), 4))[1]).pack('v', 1))
			.self::rec(0x0006, pack('vvv', 3, 0, 0)."\0\0\0\0\0\0\xFF\xFF".str_repeat("\0", 8)).self::rec(0x0207, pack('vC', 4, 1).mb_convert_encoding('été!', 'UTF-16LE', 'UTF-8'))
			.self::rec(0x0006, pack('vvv', 3, 1, 0)."\x02\0\x07\0\0\0\xFF\xFF".str_repeat("\0", 8))
			.self::rec(0x0006, pack('vvv', 3, 2, 0).pack('e', 42.0).str_repeat("\0", 8))
			.self::rec(0x0205, pack('vvvCC', 3, 3, 0, 1, 0))
			.self::rec(0x0204, pack('vvv', 3, 4, 0).pack('vC', 5, 0).'label')
			.self::rec(0x0809, pack('vvvvVV', 0x0600, 0x0020, 0, 0, 0, 0)).self::rec(0x0203, pack('vvv', 9, 9, 0).pack('e', 99.0)).self::rec(0x000A);
		$reader = new InboxSheetReader();
		$res = $reader->read(self::ole('Workbook', self::biff($globals, $cells)));

		$this->assertSame('', $reader->error);
		$this->assertSame('Sheet1', $res['sheets'][0]['name']);
		$rows = $res['sheets'][0]['rows'];
		$this->assertSame(array('Réf', 'abcdefghij', 'abcdé', 'rich', 'ok'), $rows[0]);
		$this->assertSame(array('v' => '3.14 kg', 't' => 'n'), $rows[1][0]);
		$this->assertSame(array('v' => '1234', 't' => 'n'), $rows[1][1]);
		$this->assertSame(array('v' => '123.45', 't' => 'n'), $rows[1][2], 'an integer divided by 100');
		$this->assertSame(array('v' => '-5', 't' => 'n'), $rows[1][3]);
		$this->assertSame(array('v' => '2026-11-27', 't' => 'd'), $rows[2][0]);
		$this->assertSame(array('v' => '50.00%', 't' => 'n'), $rows[2][1]);
		$this->assertSame(array('été!', '#DIV/0!', array('v' => '42', 't' => 'n'), 'TRUE', 'label'), $rows[3]);
		$this->assertCount(4, $rows, 'the records of a chart placed in the sheet are not cells');
	}

	/**
	 * Look of xlsx: fonts, backgrounds, borders, alignment of the cells, widths of the columns, merged cells
	 *
	 * @return void
	 */
	public function testXlsxLooks()
	{
		$styles = '<fonts count="3"><font><sz val="11"/><color theme="1"/></font><font><b/><i val="0"/><sz val="22"/><color rgb="FFFF0000"/></font>'
			.'<font><u/><strike/><sz val="11"/><color theme="4" tint="0.5"/></font></fonts>'
			.'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			.'<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill>'
			.'<fill><patternFill patternType="solid"><fgColor indexed="43"/></patternFill></fill></fills>'
			.'<borders count="2"><border><left/><right/><top/><bottom/></border><border><left style="thin"><color auto="1"/></left>'
			.'<right style="medium"><color rgb="FFC00000"/></right><top style="double"><color indexed="64"/></top><bottom style="dashed"/></border></borders>'
			.'<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
			.'<xf numFmtId="0" fontId="1" fillId="2" borderId="1"><alignment horizontal="center" vertical="top" wrapText="1"/></xf>'
			.'<xf numFmtId="2" fontId="2" fillId="3" borderId="0"/><xf numFmtId="0" fontId="0" fillId="1" borderId="0"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0"/></cellXfs>'
			// Looks of the conditions: the same elements, which are not the ones of the cells
			.'<dxfs count="1"><dxf><font><b/><color rgb="FF00FF00"/></font><fill><patternFill><bgColor rgb="FF00FF00"/></patternFill></fill></dxf></dxfs>'
			// Numbered colours changed by the author, written after the styles
			.'<colors><indexedColors>'.str_repeat('<rgbColor rgb="00000000"/>', 43).'<rgbColor rgb="00123456"/></indexedColors></colors>';
		$before = '<sheetFormatPr defaultColWidth="10"/><cols><col min="1" max="2" width="20" customWidth="1"/><col min="3" max="3" width="0" hidden="1"/></cols>';
		$data = '<row r="1"><c r="A1" s="1" t="inlineStr"><is><t>Title</t></is></c><c r="B1" s="1"/><c r="D1" s="2"><v>1234.5</v></c></row>'
			.'<row r="2"><c r="A2" s="3" t="inlineStr"><is><t>plain</t></is></c></row>'
			.'<row r="4"><c r="B4" s="1"></c></row>'
			// A background that goes down the sheet makes no row, and does not say that the sheet is cut
			.'<row r="99"><c r="Z99" s="4"/></row><row r="1200"><c r="A1200" s="4"></c><c r="B1200" s="4"/></row>';
		$after = '<mergeCells count="3"><mergeCell ref="A1:B1"/><mergeCell ref="A2:A3"/><mergeCell ref="bad"/></mergeCells>';
		$reader = new InboxSheetReader();
		$res = $reader->read(self::xlsx(array('s' => array($before, $data, $after)), '', $styles));

		$this->assertSame('', $reader->error);
		$sheet = $res['sheets'][0];
		$this->assertFalse($sheet['truncated']);
		$this->assertCount(4, $sheet['rows'], 'the framed cell left empty under the texts is kept, the background far below is not');
		$this->assertSame(array(145, 145, 75, 75), $sheet['widths'], 'a hidden column is shown at the width of the others');
		$this->assertSame(array(array(0, 0, 1, 2), array(1, 0, 2, 1)), $sheet['merges']);

		$title = $sheet['rows'][0][0];
		$this->assertSame('Title', $title['v']);
		$this->assertEquals(array(
			'b' => 1, 'w' => 1, 'c' => '#ff0000', 'g' => '#ddebf7', 'a' => 'c', 'va' => 't', 'z' => 2.0,
			'bt' => array(3, '#000000', 'double'), 'br' => array(2, '#c00000', 'solid'), 'bb' => array(1, '#000000', 'dashed'), 'bl' => array(1, '#000000', 'solid'),
		), $res['styles'][$title['s']]);
		$this->assertSame(array('v' => '', 's' => $title['s']), $sheet['rows'][0][1], 'a cell without text kept for its look');
		$this->assertSame('', $sheet['rows'][0][2]);
		$number = $sheet['rows'][0][3];
		$this->assertSame(array('1234.50', 'n'), array($number['v'], $number['t']));
		$look = $res['styles'][$number['s']];
		$this->assertSame(array(1, 1, '#123456'), array($look['u'], $look['k'], $look['g']), 'a numbered colour changed by the author');
		$this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $look['c']);
		$this->assertGreaterThan(0x4f, hexdec(substr($look['c'], 1, 2)), 'a colour of the theme made lighter');
		$this->assertSame(array('plain'), $sheet['rows'][1], 'the grey pattern of Excel is no background');
		$this->assertSame(array('', array('v' => '', 's' => $title['s'])), $sheet['rows'][3]);
		$this->assertStringNotContainsString('00ff00', (string) json_encode($res['styles']));
	}

	/**
	 * Look of ods: styles of the file and the named ones they inherit from, widths, merged cells
	 *
	 * @return void
	 */
	public function testOdsLooks()
	{
		$named = '<style:default-style style:family="table-cell"><style:text-properties fo:font-size="10pt"/></style:default-style>'
			.'<style:style style:name="Default" style:family="table-cell"><style:text-properties fo:font-size="11pt"/></style:style>'
			.'<style:style style:name="Heading" style:family="table-cell" style:parent-style-name="Default">'
			.'<style:table-cell-properties fo:background-color="#ddebf7"/><style:text-properties fo:font-weight="bold" fo:color="#ff0000" fo:font-size="22pt"/></style:style>';
		$styles = '<style:style style:name="co1" style:family="table-column"><style:table-column-properties style:column-width="2.54cm"/></style:style>'
			.'<style:style style:name="ce1" style:family="table-cell" style:parent-style-name="Heading">'
			.'<style:table-cell-properties fo:background-color="transparent" fo:border="0.74pt solid #000000" fo:border-bottom="none" fo:wrap-option="wrap" style:vertical-align="middle"/>'
			.'<style:paragraph-properties fo:text-align="end"/><style:text-properties fo:font-weight="normal" fo:font-style="italic" style:text-underline-style="solid"/></style:style>'
			.'<style:style style:name="ce2" style:family="table-cell" style:parent-style-name="Default"><style:table-cell-properties fo:background-color="#ffff00"/></style:style>';
		$tables = '<table:table table:name="t"><table:table-column table:style-name="co1" table:number-columns-repeated="2"/><table:table-column table:number-columns-repeated="1022"/>'
			.'<table:table-row><table:table-cell table:style-name="ce1" office:value-type="string" table:number-columns-spanned="2" table:number-rows-spanned="2"><text:p>Title</text:p></table:table-cell>'
			.'<table:covered-table-cell/><table:table-cell table:style-name="ce2" table:number-columns-repeated="1022"/></table:table-row>'
			.'<table:table-row><table:covered-table-cell table:number-columns-repeated="2"/><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row>'
			// A coloured sheet down to its last row
			.'<table:table-row table:number-rows-repeated="1048574"><table:table-cell table:style-name="ce2" table:number-columns-repeated="1024"/></table:table-row></table:table>';
		$reader = new InboxSheetReader();
		$res = $reader->read(self::ods($tables, '', $styles, $named));

		$this->assertSame('', $reader->error);
		$sheet = $res['sheets'][0];
		$this->assertFalse($sheet['truncated'], 'cells without text never say that a sheet is cut');
		$this->assertCount(2, $sheet['rows']);
		$this->assertSame(array(96, 96, InboxSheetReader::DEFAULT_WIDTH), $sheet['widths']);
		$this->assertSame(array(array(0, 0, 2, 2)), $sheet['merges']);
		$title = $sheet['rows'][0][0];
		$this->assertSame('Title', $title['v']);
		$this->assertEquals(array(
			'i' => 1, 'u' => 1, 'w' => 1, 'c' => '#ff0000', 'a' => 'r', 'va' => 'm', 'z' => 2.0,
			'bt' => array(1, '#000000', 'solid'), 'br' => array(1, '#000000', 'solid'), 'bl' => array(1, '#000000', 'solid'),
		), $res['styles'][$title['s']], 'what the style writes hides what its parents say: not bold, no background, no border below');
		$this->assertSame(array('g' => '#ffff00'), $res['styles'][$sheet['rows'][0][2]['s']]);
		$this->assertCount(3, $sheet['rows'][0], 'the background that goes on to the last column makes no column');
		$this->assertSame(array('', '', 'x'), $sheet['rows'][1]);
	}

	/**
	 * Look of xls: fonts, cell styles, colours changed by the author, widths, merged cells, cells without text
	 *
	 * @return void
	 */
	public function testXlsLooks()
	{
		$font = function ($height, $bits, $colour, $weight, $underline) {
			return self::rec(0x0031, pack('vvvvvCCCC', $height, $bits, $colour, $weight, 0, $underline, 0, 0, 0)."\x01\x00A");
		};
		$xf = function ($font, $align, $lines, $more, $fill) {
			return self::rec(0x00E0, pack('vvvCCCCVVv', $font, 0, 0, $align, 0, 0, 0, $lines, $more, $fill));
		};
		// Four plain fonts, then the one named 5 (there is no font 4): bold, italic, struck, underlined, 22 points
		$globals = str_repeat($font(220, 0, 0x7FFF, 400, 0), 4).$font(440, 0x02 | 0x08, 10, 700, 1)
			.$xf(0, 0x20, 0, 0, 0)
			// Centred, wrapped, in the middle; left thin in colour 10, right medium in the colour of the window, top double
			// in colour 8; plain background in colour 9
			.$xf(5, 0x02 | 0x08 | 0x10, 1 | (2 << 4) | (6 << 8) | (10 << 16) | (64 << 23), 8 | (1 << 26), 9)
			.self::rec(0x0092, pack('v', 3)."\x00\x00\x00\x00"."\xAB\xCD\xEF\x00"."\x12\x34\x56\x00");
		$cells = self::rec(0x0055, pack('v', 10)).self::rec(0x007D, pack('vvvvvv', 0, 1, 20 * 256, 0, 0, 0))
			.self::rec(0x0204, pack('vvv', 0, 0, 1).pack('vC', 5, 0).'Title').self::rec(0x0201, pack('vvv', 0, 1, 1))
			.self::rec(0x00BE, pack('vv', 1, 0).pack('vv', 1, 1).pack('v', 1))
			.self::rec(0x0203, pack('vvv', 1, 2, 0).pack('e', 2.5))
			.self::rec(0x00E5, pack('v', 1).pack('vvvv', 0, 0, 0, 1));
		$reader = new InboxSheetReader();
		$res = $reader->read(self::ole('Workbook', self::biff($globals, $cells)));

		$this->assertSame('', $reader->error);
		$sheet = $res['sheets'][0];
		$this->assertSame(array(145, 145, 75), $sheet['widths']);
		$this->assertSame(array(array(0, 0, 1, 2)), $sheet['merges']);
		$title = $sheet['rows'][0][0];
		$this->assertSame('Title', $title['v']);
		$this->assertEquals(array(
			'b' => 1, 'i' => 1, 'u' => 1, 'k' => 1, 'w' => 1, 'c' => '#123456', 'g' => '#abcdef', 'a' => 'c', 'va' => 'm', 'z' => 2.0,
			'bt' => array(3, '#000000', 'double'), 'br' => array(2, '#000000', 'solid'), 'bl' => array(1, '#123456', 'solid'),
		), $res['styles'][$title['s']]);
		$blank = array('v' => '', 's' => $title['s']);
		$this->assertSame(array($title, $blank), $sheet['rows'][0]);
		$this->assertSame(array($blank, $blank, array('v' => '2.5', 't' => 'n')), $sheet['rows'][1]);
		$this->assertCount(1, $res['styles']);
	}

	/**
	 * xls of Excel 5/95: texts in the code page of the workbook
	 *
	 * @return void
	 */
	public function testXlsBefore97()
	{
		$globals = self::rec(0x0042, pack('v', 1252)).self::rec(0x00E0, pack('vv', 0, 0).str_repeat("\0", 12));
		$cells = self::rec(0x0204, pack('vvv', 0, 0, 0).pack('v', 3)."\xC9t\xE9").self::rec(0x0203, pack('vvv', 0, 1, 0).pack('e', 2.5));
		$reader = new InboxSheetReader();
		$res = $reader->read(self::ole('Book', self::biff($globals, $cells, "\x05Tarif", 0x0500)));
		$this->assertSame('', $reader->error);
		$this->assertSame('Tarif', $res['sheets'][0]['name']);
		$this->assertSame(array(array('Été', array('v' => '2.5', 't' => 'n'))), $res['sheets'][0]['rows']);
	}

	/**
	 * A file with a password says so; a file that is not a spreadsheet, or cut, is refused without an error of PHP
	 *
	 * @return void
	 */
	public function testProtectedAndBroken()
	{
		$reader = new InboxSheetReader();

		$reader->read(self::ole('Workbook', self::biff(self::rec(0x002F, str_repeat("\0", 54)), '')));
		$this->assertSame('protected', $reader->error, 'xls with a password');
		$reader->read(self::ole('EncryptedPackage', str_repeat('x', 5000)));
		$this->assertSame('protected', $reader->error, 'xlsx with a password');
		$reader->read(self::ods('', '<manifest:file-entry manifest:full-path="content.xml"><manifest:encryption-data/></manifest:file-entry>'));
		$this->assertSame('protected', $reader->error, 'ods with a password');

		$good = self::xlsx(array('s' => '<row><c><v>1</v></c></row>'));
		$broken = array(
			'nothing' => '',
			'a text' => 'Ref;Qty',
			'a zip of something else' => self::zip(array('word/document.xml' => '<w:document/>')),
			'a zip cut' => substr($good, 0, (int) (strlen($good) / 2)),
			'an OLE file of something else' => self::ole('WordDocument', str_repeat('x', 5000)),
			'an OLE file cut' => substr(self::ole('Workbook', self::biff('', '')), 0, 700),
			'records cut' => self::ole('Workbook', substr(self::biff('', self::rec(0x0203, pack('vvv', 0, 0, 0).pack('e', 1.0))), 0, 60)),
			'a sheet that is not XML' => self::zip(array('xl/workbook.xml' => '<workbook '.self::XLSX_NS.'><sheets><sheet name="s" r:id="rId1"/></sheets></workbook>',
				'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>', 'xl/worksheets/sheet1.xml' => "\x00\xFF<<<")),
		);
		foreach ($broken as $what => $content) {
			$res = $reader->read($content);
			$this->assertIsArray($res['sheets'], $what);
			if (!$res['sheets']) {
				$this->assertSame('unreadable', $reader->error, $what);
			}
		}
		$res = $reader->read($broken['a text']);
		$this->assertSame(array(), $res['sheets']);
		$res = $reader->read($good);
		$this->assertSame('', $reader->error, 'the reader is ready for the next file');
		$this->assertCount(1, $res['sheets']);
	}

	/**
	 * Nothing of the file is run nor fetched: entities are not expanded, a file outside is not read
	 *
	 * @return void
	 */
	public function testEntities()
	{
		$secret = tempnam(sys_get_temp_dir(), 'ibxsecret');
		file_put_contents($secret, 'SECRET-OF-THE-SERVER');
		$doctype = '<!DOCTYPE x [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY file SYSTEM "file://'.$secret.'">]>';
		$parts = array(
			'xl/workbook.xml' => '<workbook '.self::XLSX_NS.'><sheets><sheet name="s" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Type="x/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
			'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?>'.$doctype.'<worksheet '.self::XLSX_NS.'><sheetData><row><c t="inlineStr"><is><t>[&file;][&c;]</t></is></c><c t="inlineStr"><is><t>after</t></is></c></row></sheetData></worksheet>',
		);
		$reader = new InboxSheetReader();
		$res = $reader->read(self::zip($parts));
		unlink($secret);
		$json = json_encode($res);
		$this->assertStringNotContainsString('SECRET-OF-THE-SERVER', $json);
		$this->assertStringNotContainsString('aaaaaaaaaaaaaaaaaaaa', $json);
		$this->assertLessThan(10000, strlen($json));
	}

	/**
	 * The files the preview takes, by signature and name
	 *
	 * @return void
	 */
	public function testAccepts()
	{
		foreach (array('a.xlsx', 'A.XLSM', 'a.xltx', 'a.ods', 'a.ots') as $name) {
			$this->assertTrue(InboxSheetReader::accepts('zip', $name), $name);
		}
		foreach (array('a.xls', 'a.XLT', 'protected.xlsx') as $name) {
			$this->assertTrue(InboxSheetReader::accepts('ole', $name), $name);
		}
		$this->assertFalse(InboxSheetReader::accepts('zip', 'a.docx'));
		$this->assertFalse(InboxSheetReader::accepts('zip', 'a.xls'));
		$this->assertFalse(InboxSheetReader::accepts('ole', 'a.msg'));
		$this->assertFalse(InboxSheetReader::accepts('ole', 'a.doc'));
		$this->assertFalse(InboxSheetReader::accepts('other', 'a.xlsx'), 'a text renamed is not a spreadsheet');
		$this->assertFalse(InboxSheetReader::accepts('zip', 'a.xlsb'));
		$this->assertSame(27, InboxSheetReader::columnIndex('AB'));
	}

	/**
	 * Numbers and dates written for the language of the user
	 *
	 * @return void
	 */
	public function testLanguageOfTheUser()
	{
		global $langs;

		if (!function_exists('dol_print_date') || !is_object($langs)) {
			$this->markTestSkipped('needs Dolibarr');
		}
		$options = InboxSheetReader::optionsOf($langs);
		$this->assertSame(array('decimal', 'thousand', 'date'), array_keys($options));
		$styles = '<cellXfs count="2"><xf numFmtId="4"/><xf numFmtId="22"/></cellXfs>';
		$reader = new InboxSheetReader($options);
		$rows = $reader->read(self::xlsx(array('s' => '<row><c s="0"><v>1234.5</v></c><c s="1"><v>46353.5</v></c></row>'), '', $styles))['sheets'][0]['rows'];
		$this->assertSame('1'.$options['thousand'].'234'.$options['decimal'].'50', $rows[0][0]['v']);
		$this->assertSame(dol_print_date(1795780800, 'dayhour', 'gmt', $langs), $rows[0][1]['v'], 'the time typed by the author, no time zone');
	}
}
