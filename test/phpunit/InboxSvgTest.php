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
 * \file    inbox/test/phpunit/InboxSvgTest.php
 * \ingroup inbox
 * \brief   1.88.6: SVG of the BIMI logos rebuilt from an allow list
 */

require_once __DIR__.'/InboxTestCase.php';
dol_include_once('/inbox/class/inboxsvg.class.php');
dol_include_once('/inbox/class/inboxavatar.class.php');

/**
 * Cleaning of the SVG fetched out of Dolibarr
 */
class InboxSvgTest extends InboxTestCase
{
	const HEAD = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.2" baseProfile="tiny-ps" viewBox="0 0 100 100">';

	/**
	 * A logo keeps its title, shapes, gradients and inner references
	 *
	 * @return void
	 */
	public function testLogoKept()
	{
		$svg = self::HEAD.'<title>Acme</title><defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient></defs>'
			.'<rect width="100" height="100" fill="url(#g)"/><path id="p" d="M0 0L10 10" style="stroke:#000;stroke-width:2"/><use xlink:href="#p"/></svg>';
		$out = InboxSvg::clean($svg);
		$this->assertStringContainsString('<title>Acme</title>', $out);
		$this->assertStringContainsString('fill="url(#g)"', $out);
		$this->assertStringContainsString('style="stroke:#000;stroke-width:2"', $out);
		$this->assertStringContainsString('href="#p"', $out);
		$this->assertStringContainsString('baseProfile="tiny-ps"', $out);
	}

	/**
	 * Scripts, events, style sheets, foreignObject, outer references and links are dropped
	 *
	 * @return void
	 */
	public function testDangerousDropped()
	{
		$svg = self::HEAD.'<script>alert(1)</script><style>@import url(https://evil.test/a.css);</style>'
			.'<rect width="10" height="10" onload="alert(2)" fill="url(https://evil.test/x)"/>'
			.'<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'
			.'<a xlink:href="javascript:alert(3)"><circle r="5"/></a>'
			.'<use xlink:href="https://evil.test/s.svg#a"/><use href="data:image/svg+xml,x"/>'
			.'<circle r="4" style="fill:url(https://evil.test/y);stroke:red;behavior:url(x.htc)"/>'
			.'<image xlink:href="https://evil.test/p.png"/><animate attributeName="href" to="javascript:alert(4)"/></svg>';
		$out = InboxSvg::clean($svg);
		$this->assertNotSame('', $out);
		foreach (array('script', 'alert', 'style>', 'onload', 'evil.test', 'foreignObject', 'javascript', '<a', 'data:', 'image', 'animate', 'behavior') as $bad) {
			$this->assertStringNotContainsString($bad, $out, $bad);
		}
		$this->assertStringContainsString('style="stroke:red"', $out);
	}

	/**
	 * Entities, other roots and broken files are refused
	 *
	 * @return void
	 */
	public function testRefused()
	{
		$this->assertSame('', InboxSvg::clean('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaa">]>'.self::HEAD.'<title>&a;</title></svg>'));
		$this->assertSame('', InboxSvg::clean('<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>'));
		$this->assertSame('', InboxSvg::clean('<svg><rect></svg>'));
		$this->assertSame('', InboxSvg::clean('<svg width="1"/>'));
		$this->assertSame('', InboxSvg::clean(''));
	}

	/**
	 * A picture fetched out of Dolibarr: SVG cleaned, PNG untouched, other content refused
	 *
	 * @return void
	 */
	public function testPicture()
	{
		$pic = InboxAvatar::picture(self::HEAD.'<script>x</script><circle r="1"/></svg>');
		$this->assertSame('image/svg+xml', $pic['type']);
		$this->assertStringNotContainsString('script', $pic['data']);
		$png = "\x89PNG\r\n\x1a\n".str_repeat('x', 20);
		$this->assertSame(array('type' => 'image/png', 'data' => $png), InboxAvatar::picture($png));
		$this->assertNull(InboxAvatar::picture('<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>'));
		$this->assertNull(InboxAvatar::picture('<html></html>'));
	}
}
