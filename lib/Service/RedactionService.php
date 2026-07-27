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
		$out = $this->saveHtmlPreservingNonAscii($dom, $html);
		if ($out === null) {
			return $this->redactText($html);
		}

		// Remove the encoding PI used only for loadHTML (must not appear in previews)
		$out = str_replace('<?xml encoding="utf-8" ?>', '', $out);
		return $out;
	}

	/**
	 * Serialize the document without letting libxml mangle non-ASCII content.
	 *
	 * DOMDocument::saveHTML() escapes every code point >= 0x80 as a decimal numeric
	 * character reference ("択" -> "&#25246;"), even though the document was loaded as
	 * UTF-8 (setting $dom->encoding does not change this). Browsers decode those inside
	 * markup, but not inside <script>/<style> bodies, so Japanese previews break visibly.
	 *
	 * Decoding the references back afterwards is not viable: a literal "&#8364;" written
	 * in the source is indistinguishable from an escaped one, and libxml passes invalid
	 * references inside raw-text elements straight through, where mb_chr() fails on them.
	 *
	 * So every run of non-ASCII bytes is swapped for an ASCII placeholder before
	 * serializing and restored afterwards. libxml then has nothing to escape, and the
	 * restored runs contain no HTML-significant characters, so no context needs quoting.
	 *
	 * @return string|null null when serialization failed (caller falls back to text mode)
	 */
	private function saveHtmlPreservingNonAscii(\DOMDocument $dom, string $source): ?string {
		// The placeholder must not collide with content already in the document. It is
		// randomised rather than derived from the document on purpose: a fixed prefix
		// could be smuggled in entity-encoded ("&#83;HVNONASCII0E" parses to the literal
		// marker text, which a source scan cannot see), and growing a marker until it no
		// longer occurs is quadratic in the document size — both were reachable from a
		// crafted upload. An unpredictable marker makes the collision unconstructible.
		$marker = null;
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$candidate = 'SHVNA' . bin2hex(random_bytes(8));
			if (!str_contains($source, $candidate)) {
				$marker = $candidate;
				break;
			}
		}
		if ($marker === null) {
			return null;
		}

		$runs = [];
		$this->maskNonAscii($dom, $marker, $runs);

		$out = $dom->saveHTML();
		if ($out === false) {
			return null;
		}
		if ($runs === []) {
			return $out;
		}

		return preg_replace_callback(
			'/' . $marker . '(\d+)E/',
			static fn (array $m): string => isset($runs[(int)$m[1]])
				? self::reEncodeC1($runs[(int)$m[1]])
				: $m[0],
			$out
		);
	}

	/**
	 * Re-encode C1 code points (U+0080-U+009F) as numeric character references.
	 *
	 * These are unprintable controls in Unicode, but HTML5 requires parsers to map a
	 * numeric reference in that range to the corresponding Windows-1252 character — so
	 * "&#147;" renders as a curly quote, which is how Word and Outlook emit them.
	 * Restoring such a run as raw UTF-8 would render nothing at all, so the reference
	 * form (what libxml would have produced) is kept.
	 */
	private static function reEncodeC1(string $run): string {
		if (!str_contains($run, "\xC2")) {
			return $run;
		}
		return preg_replace_callback(
			'/\xC2([\x80-\x9F])/',
			static fn (array $m): string => '&#' . ord($m[1]) . ';',
			$run
		) ?? $run;
	}

	/**
	 * Replace non-ASCII runs in text, CDATA, comment nodes and attribute values with
	 * ASCII placeholders, collecting the original runs in $runs (indexed by position).
	 *
	 * Unlike redactNode() this deliberately descends into <script>/<style>: their bodies
	 * are escaped by saveHTML() too, and that is exactly what breaks non-Latin previews.
	 *
	 * @param list<string> $runs
	 */
	private function maskNonAscii(\DOMNode $node, string $marker, array &$runs): void {
		if ($node->nodeType === XML_TEXT_NODE
			|| $node->nodeType === XML_CDATA_SECTION_NODE
			|| $node->nodeType === XML_COMMENT_NODE) {
			$value = $node->nodeValue ?? '';
			$masked = $this->maskNonAsciiString($value, $marker, $runs);
			if ($masked !== $value) {
				$node->nodeValue = $masked;
			}
			return;
		}

		if ($node->nodeType === XML_ELEMENT_NODE && $node->attributes !== null) {
			/** @var \DOMElement $node */
			foreach (iterator_to_array($node->attributes) as $attr) {
				/** @var \DOMAttr $attr */
				$masked = $this->maskNonAsciiString($attr->value, $marker, $runs);
				if ($masked !== $attr->value) {
					// setAttribute() stores the string literally; assigning to
					// DOMAttr::$value re-parses entity references instead, which decodes
					// "&#12354;" written by the author and drops the whole value when it
					// contains a bare "&".
					$node->setAttribute($attr->name, $masked);
				}
			}
		}

		foreach (iterator_to_array($node->childNodes) as $child) {
			$this->maskNonAscii($child, $marker, $runs);
		}
	}

	/**
	 * @param list<string> $runs
	 */
	private function maskNonAsciiString(string $value, string $marker, array &$runs): string {
		if ($value === '' || preg_match('/[\x80-\xFF]/', $value) !== 1) {
			return $value;
		}

		// Byte-based on purpose: a run stays intact regardless of whether the input is
		// valid UTF-8, and contains no ASCII (so no HTML-significant) characters.
		return preg_replace_callback(
			'/[\x80-\xFF]+/',
			static function (array $m) use ($marker, &$runs): string {
				$runs[] = $m[0];
				// Trailing "E" terminates the index so following digits stay literal.
				return $marker . (count($runs) - 1) . 'E';
			},
			$value
		) ?? $value;
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
		// The character class allows spaces and parentheses, so a match can carry
		// leading padding ("... (2026-07-17"). That padding has to be split off before
		// the date check, otherwise dates in prose are misread as phone numbers; it is
		// re-emitted afterwards so surrounding spacing is preserved.
		$text = preg_replace_callback(
			'/(?<!\w)(?:\+?[\d\s\-()]{7,}\d)(?!\w)/',
			static function (array $m): string {
				$lead = '';
				$s = $m[0];
				if (preg_match('/^[\s(]+/', $s, $pad) === 1) {
					$lead = $pad[0];
					$s = substr($s, strlen($lead));
				}
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
					return $m[0];
				}
				return $lead . '[REDACTED-PHONE]';
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
