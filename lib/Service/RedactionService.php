<?php

declare(strict_types=1);

namespace OCA\SafeHtmlViewer\Service;

/**
 * Best-effort redaction of potentially sensitive strings in HTML content.
 *
 * - Applied only on display (original file is never modified).
 * - Operates primarily on text nodes and selected attribute values.
 * - Uses heuristics; not a security guarantee. See SECURITY.md and README.
 */
class RedactionService {

	/**
	 * Redact the provided HTML string.
	 */
	public function redact(string $html): string {
		$trimmed = trim($html);
		if ($trimmed === '') {
			return $html;
		}

		// Parse with DOMDocument (tolerate real-world HTML)
		$dom = new \DOMDocument();
		libxml_use_internal_errors(true);

		// Prefix to force UTF-8 (stripped again after saveHTML)
		$loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		if (!$loaded || $dom->childNodes->length === 0) {
			// Fallback to simple text redaction if parse fails
			return $this->redactText($html);
		}

		// LIBXML_HTML_NOIMPLIED can leave multiple top-level siblings;
		// walk all of them so content after <script>/<style> is still redacted.
		foreach (iterator_to_array($dom->childNodes) as $child) {
			$this->redactNode($child);
		}

		// Save; note: may not perfectly roundtrip all HTML5 but sufficient for preview
		$out = $dom->saveHTML();
		if ($out === false) {
			return $this->redactText($html);
		}

		// Remove the encoding PI used only for loadHTML (must not appear in previews)
		$out = str_replace('<?xml encoding="utf-8" ?>', '', $out);
		return $out;
	}

	private function redactNode(\DOMNode $node): void {
		if ($node->nodeType === XML_TEXT_NODE) {
			$original = $node->nodeValue ?? '';
			$node->nodeValue = $this->redactText($original);
			return;
		}

		if ($node->nodeType === XML_ELEMENT_NODE) {
			/** @var \DOMElement $node */
			$tag = strtolower($node->tagName);

			// Do not mangle content of script/style to avoid breaking functional HTML previews
			if (in_array($tag, ['script', 'style'], true)) {
				return;
			}

			// Redact common places where secrets leak in attributes
			$attrs = ['href', 'src', 'data-src', 'data-href', 'data-url', 'value', 'title', 'alt', 'placeholder'];
			foreach ($attrs as $attr) {
				if ($node->hasAttribute($attr)) {
					$val = $node->getAttribute($attr);
					$new = $this->redactText($val);
					if ($new !== $val) {
						$node->setAttribute($attr, $new);
					}
				}
			}

			// Recurse
			foreach (iterator_to_array($node->childNodes) as $child) {
				$this->redactNode($child);
			}
		}
	}

	/**
	 * Apply textual redactions (order matters for overlapping patterns).
	 */
	private function redactText(string $text): string {
		// 1. Email addresses
		$text = preg_replace(
			'/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
			'[REDACTED-EMAIL]',
			$text
		) ?? $text;

		// 2. Phone numbers (loose international / JP / US style).
		// Skip pure ISO dates (e.g. 2024-01-15) which otherwise false-positive.
		$text = preg_replace_callback(
			'/(?<!\w)(?:\+?[\d\s\-()]{7,}\d)(?!\w)/',
			static function (array $m): string {
				$s = $m[0];
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
					return $s;
				}
				return '[REDACTED-PHONE]';
			},
			$text
		) ?? $text;

		// 3. Private / localhost URLs (before IPv4 so full private URLs keep PRIVATE-URL label)
		// Host labels internal/intranet/localhost require a host boundary (not mere prefixes).
		$text = preg_replace(
			'/\bhttps?:\/\/(?:(?:localhost|127\.0\.0\.1|::1)(?=[:\/\s<>"\']|$)|(?:10\.\d{1,3}\.\d{1,3}\.\d{1,3})|(?:192\.168\.\d{1,3}\.\d{1,3})|(?:172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})|(?:internal|intranet)(?=[:\/\s<>"\']|$))[^\s<>"\']*/i',
			'[REDACTED-PRIVATE-URL]',
			$text
		) ?? $text;

		// 4. IPv4 addresses (bare; URLs with private hosts already replaced above)
		$text = preg_replace(
			'/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/',
			'[REDACTED-IPV4]',
			$text
		) ?? $text;

		// 5. Common credential patterns (query string, attributes, bare key=value).
		// Capturing group required so $1 keeps the parameter name.
		$text = preg_replace(
			'/\b(password|passwd|pwd|token|api[_-]?key|secret|auth|bearer)[=:]\s*[^&\s<>"\']{3,}/i',
			'$1=[REDACTED]',
			$text
		) ?? $text;

		// 6. Long opaque token-like strings (base64-ish, jwt-ish, etc) - heuristic.
		// Do not include '/' so URL paths are not swallowed as one token.
		$text = preg_replace(
			'/\b[A-Za-z0-9+_=-]{24,}\b/',
			'[REDACTED-SECRET]',
			$text
		) ?? $text;

		return $text;
	}
}
