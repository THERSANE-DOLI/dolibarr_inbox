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
 * \file    inbox/class/inboxsvg.class.php
 * \ingroup inbox
 * \brief   Cleaning of an SVG fetched out of Dolibarr (logos BIMI, 1.88.6)
 */

/**
 * An SVG rebuilt from an allow list (shapes, gradients, texts of SVG Tiny PS): no script, no event attribute, no
 * style sheet, no foreignObject, no reference out of the file, no entity. Pure PHP (no Imagick needed). The picture
 * is already served as an <img> with a CSP; this cleaning keeps the file harmless wherever it would be opened.
 */
class InboxSvg
{
	const NS = 'http://www.w3.org/2000/svg';
	const NS_XLINK = 'http://www.w3.org/1999/xlink';
	const MAX_NODES = 5000;

	/** @var string[] Elements kept (others dropped with their content) */
	const ELEMENTS = array(
		'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'linearGradient', 'radialGradient', 'stop', 'text', 'tspan', 'use', 'clipPath', 'mask', 'symbol',
	);

	/** @var string[] Elements whose text is kept */
	const TEXT_ELEMENTS = array('title', 'desc', 'text', 'tspan');

	/** @var string[] Attributes kept (without namespace) */
	const ATTRIBUTES = array(
		'id', 'version', 'baseProfile', 'viewBox', 'preserveAspectRatio', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
		'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points', 'transform', 'offset', 'gradientUnits', 'gradientTransform',
		'spreadMethod', 'clipPathUnits', 'maskUnits', 'maskContentUnits', 'fill', 'fill-opacity', 'fill-rule', 'stroke',
		'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
		'stroke-opacity', 'opacity', 'stop-color', 'stop-opacity', 'clip-path', 'clip-rule', 'mask', 'color', 'display',
		'visibility', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'letter-spacing', 'dx', 'dy',
		'style',
	);

	/** @var string[] Properties kept in a style attribute */
	const STYLE_PROPERTIES = array(
		'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
		'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'stop-color', 'stop-opacity', 'clip-rule',
		'color', 'display', 'visibility', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'letter-spacing',
	);

	/**
	 * Clean an SVG
	 *
	 * @param string $data SVG as fetched
	 * @return string Clean SVG, '' when it cannot be read or is not an SVG
	 */
	public static function clean($data)
	{
		$data = (string) $data;
		// No DOCTYPE: no entity at all (expansion bombs, external entities)
		if ($data === '' || stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false || !class_exists('DOMDocument')) {
			return '';
		}
		$in = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $in->loadXML($data, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$root = $ok ? $in->documentElement : null;
		if (!$root || $root->namespaceURI !== self::NS || $root->localName !== 'svg') {
			return '';
		}
		$out = new DOMDocument('1.0', 'UTF-8');
		$count = 0;
		$svg = self::copy($root, $out, $count);
		if (!$svg || $count > self::MAX_NODES) {
			return '';
		}
		$out->appendChild($svg);

		return (string) $out->saveXML($out->documentElement);
	}

	/**
	 * Copy of an element of the allow list with its allowed attributes and children
	 *
	 * @param DOMElement  $el    Source
	 * @param DOMDocument $out   Target document
	 * @param int         $count Nodes copied so far
	 * @return DOMElement|null
	 */
	protected static function copy(DOMElement $el, DOMDocument $out, &$count)
	{
		if ($el->namespaceURI !== self::NS || !in_array($el->localName, self::ELEMENTS, true) || ++$count > self::MAX_NODES) {
			return null;
		}
		$new = $out->createElementNS(self::NS, $el->localName);
		foreach ($el->attributes as $attr) {
			$name = $attr->localName;
			$value = (string) $attr->value;
			if ($attr->namespaceURI === self::NS_XLINK || ($attr->namespaceURI === null && $name === 'href')) {
				// A reference inside the file only (#id)
				if ($name === 'href' && preg_match('/^#[A-Za-z_][\w.\-]*$/', trim($value))) {
					$new->setAttribute('href', trim($value));
				}
				continue;
			}
			if ($attr->namespaceURI !== null || !in_array($name, self::ATTRIBUTES, true)) {
				continue;
			}
			$value = $name === 'style' ? self::style($value) : (self::safeValue($value) ? $value : '');
			if ($value !== '') {
				$new->setAttribute($name, $value);
			}
		}
		foreach ($el->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$c = self::copy($child, $out, $count);
				if ($c) {
					$new->appendChild($c);
				}
			} elseif (($child instanceof DOMText) && !($child instanceof DOMCdataSection) && in_array($el->localName, self::TEXT_ELEMENTS, true)) {
				$new->appendChild($out->createTextNode($child->data));
			}
		}

		return $new;
	}

	/**
	 * A value without reference out of the file: url() only towards #id, no escape, no CSS function
	 *
	 * @param string $value Value
	 * @return bool
	 */
	protected static function safeValue($value)
	{
		if (preg_match('/[\\\\<>]|expression\s*\(|javascript:|@import/i', $value)) {
			return false;
		}
		if (preg_match_all('/url\s*\(([^)]*)\)/i', $value, $m)) {
			foreach ($m[1] as $ref) {
				if (!preg_match('/^\s*[\'"]?#[A-Za-z_][\w.\-]*[\'"]?\s*$/', $ref)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Style attribute kept with the allowed properties only
	 *
	 * @param string $style Style
	 * @return string
	 */
	protected static function style($style)
	{
		$kept = array();
		foreach (explode(';', $style) as $decl) {
			$pos = strpos($decl, ':');
			if ($pos === false) {
				continue;
			}
			$prop = strtolower(trim(substr($decl, 0, $pos)));
			$value = trim(substr($decl, $pos + 1));
			if ($value !== '' && in_array($prop, self::STYLE_PROPERTIES, true) && self::safeValue($value)) {
				$kept[] = $prop.':'.$value;
			}
		}

		return implode(';', $kept);
	}
}
