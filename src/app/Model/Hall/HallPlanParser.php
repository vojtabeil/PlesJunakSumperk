<?php

declare(strict_types=1);

namespace App\Model\Hall;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;


/**
 * Reads an SVG of the hall (exported from CorelDRAW) and sanitizes it, because it is served from
 * the site and inlined into the seat picker.
 *
 * Conventions of the drawing (doc/plan.md, section 6.2):
 *   id="seat-87"        a seat (chair shape or group); the number is the identity and the label
 *   id="table-12"       a group of a table; the seats inside it belong to the table
 *   id="table-12-name"  optional text with the name of the table
 * Everything else is decoration.
 */
final class HallPlanParser
{
	private const SvgNs = 'http://www.w3.org/2000/svg';
	private const XlinkNs = 'http://www.w3.org/1999/xlink';

	/** SVG elements kept in the plan; anything else (script, foreignObject, metadata...) is removed. */
	private const Elements = [
		'svg', 'g', 'defs', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textPath', 'title', 'desc', 'style', 'linearGradient', 'radialGradient', 'stop',
		'pattern', 'clipPath', 'mask', 'marker', 'image',
	];

	/** Presentation of a seat comes from the site's CSS (its state), not from the drawing. */
	private const SeatPresentation = ['class', 'style', 'fill', 'stroke', 'fill-opacity', 'stroke-opacity', 'opacity'];


	public static function parse(string $svg): HallPlan
	{
		$doc = new DOMDocument;
		// No network, no entity substitution; libxml limits entity expansion by default.
		$previous = libxml_use_internal_errors(true);
		$loaded = $svg !== '' && $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$root = $loaded ? $doc->documentElement : null;
		if ($root === null || $root->namespaceURI !== self::SvgNs || $root->localName !== 'svg') {
			return new HallPlan('', [], [], ['Soubor není platný obrázek SVG.']);
		}

		self::sanitize($doc);
		[$tables, $seats, $errors] = self::read($root);
		return new HallPlan((string) $doc->saveXML($root), $tables, $seats, $errors);
	}


	/**
	 * @return array{array<int, array{svg_id: string, name: ?string}>, array<int, array{svg_id: string, table: int}>, list<string>}
	 */
	private static function read(DOMElement $root): array
	{
		$tables = $seats = $names = $errors = $seen = [];
		foreach (self::elements($root) as $element) {
			$id = $element->getAttribute('id');
			if ($id === '') {
				continue;
			}
			if (isset($seen[$id])) {
				$errors[] = "Id „{$id}“ je v plánku víckrát.";
				continue;
			}
			$seen[$id] = true;

			if (preg_match('~^table-(\d+)-name$~', $id, $m)) {
				$names[(int) $m[1]] = trim((string) preg_replace('~\s+~u', ' ', $element->textContent));
			} elseif (preg_match('~^table-(\d+)$~', $id, $m) && (int) $m[1] > 0) {
				$tables[(int) $m[1]] = ['svg_id' => $id, 'name' => null];
			} elseif (preg_match('~^seat-(\d+)$~', $id, $m) && (int) $m[1] > 0) {
				$table = self::tableOf($element);
				if ($table === null) {
					$errors[] = "Místo {$m[1]} neleží v žádném stole (skupině s id table-<číslo>).";
					continue;
				}
				$seats[(int) $m[1]] = ['svg_id' => $id, 'table' => $table];
				self::markSeat($element);
			}
		}

		foreach ($names as $number => $name) {
			if (isset($tables[$number]) && $name !== '') {
				$tables[$number]['name'] = mb_substr($name, 0, 255);
			}
		}
		if ($seats === []) {
			$errors[] = 'Plánek neobsahuje žádná místa (tvary s id seat-<číslo>).';
		}
		foreach (array_diff_key($tables, array_flip(array_column($seats, 'table'))) as $number => $table) {
			$errors[] = "Stůl {$number} nemá žádná místa.";
		}
		ksort($tables);
		ksort($seats);
		return [$tables, $seats, $errors];
	}


	/** Number of the table group the seat lies in, or null. */
	private static function tableOf(DOMElement $seat): ?int
	{
		for ($node = $seat->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
			if (preg_match('~^table-(\d+)$~', $node->getAttribute('id'), $m)) {
				return (int) $m[1];
			}
		}
		return null;
	}


	/** The site colours seats by their state: drop the drawing's colours, add class "seat". */
	private static function markSeat(DOMElement $seat): void
	{
		foreach ([$seat, ...self::elements($seat)] as $element) {
			foreach (self::SeatPresentation as $attribute) {
				$element->removeAttribute($attribute);
			}
		}
		$seat->setAttribute('class', 'seat');
	}


	private static function sanitize(DOMDocument $doc): void
	{
		// Processing instructions (xml-stylesheet), comments and the DOCTYPE are not needed.
		$root = $doc->documentElement;
		foreach (iterator_to_array($doc->childNodes) as $node) {
			if ($node !== $root) {
				$doc->removeChild($node);
			}
		}
		self::sanitizeAttributes($root);
		self::sanitizeNode($root);

		// Declarations of foreign namespaces (CorelDRAW, Inkscape...) are not needed once their content is gone.
		foreach ((new \DOMXPath($doc))->query('namespace::*', $root) ?: [] as $namespace) {
			if (!in_array($namespace->nodeValue, [self::SvgNs, self::XlinkNs, 'http://www.w3.org/XML/1998/namespace'], true)) {
				$root->removeAttributeNS((string) $namespace->nodeValue, (string) $namespace->localName);
			}
		}
	}


	private static function sanitizeNode(DOMNode $node): void
	{
		foreach (iterator_to_array($node->childNodes) as $child) {
			if ($child instanceof DOMElement) {
				if ($child->namespaceURI !== self::SvgNs || !in_array($child->localName, self::Elements, true)) {
					$node->removeChild($child);
					continue;
				}
				self::sanitizeAttributes($child);
				if ($child->localName === 'style') {
					$child->textContent = self::safeCss($child->textContent);
				}
				self::sanitizeNode($child);
			} elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
				$node->removeChild($child);
			}
		}
	}


	private static function sanitizeAttributes(DOMElement $element): void
	{
		/** @var list<DOMAttr> $attributes */
		$attributes = iterator_to_array($element->attributes);
		foreach ($attributes as $attribute) {
			$name = strtolower($attribute->localName ?? $attribute->name);
			$value = $attribute->value;
			$foreign = $attribute->namespaceURI !== null
				&& !in_array($attribute->namespaceURI, [self::XlinkNs, 'http://www.w3.org/XML/1998/namespace'], true);
			$remove = $foreign
				|| str_starts_with($name, 'on')
				|| preg_match('~(java|vb)script:|data:text|expression\(|@import~i', $value)
				|| ($name === 'href' && !self::isSafeReference($value))
				|| ($name === 'style' && self::safeCss($value) !== $value);
			if ($remove) {
				$element->removeAttributeNode($attribute);
			}
		}
	}


	/** A reference inside the document, or an embedded raster image. */
	private static function isSafeReference(string $value): bool
	{
		$value = trim($value);
		return str_starts_with($value, '#') || (bool) preg_match('~^data:image/(png|jpeg|gif|webp);base64,~i', $value);
	}


	/** CSS without @import and without url() pointing outside the document. */
	private static function safeCss(string $css): string
	{
		$css = (string) preg_replace('~@import[^;]*;?~i', '', $css);
		return (string) preg_replace('~url\(\s*([\'"]?)\s*(?!#)[^)]*\)~i', 'none', $css);
	}


	/** @return list<DOMElement> all descendant elements */
	private static function elements(DOMElement $root): array
	{
		$result = [];
		foreach ($root->getElementsByTagName('*') as $element) {
			$result[] = $element;
		}
		return $result;
	}
}
