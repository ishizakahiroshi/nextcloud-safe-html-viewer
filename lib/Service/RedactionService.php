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
		// A leading UTF-8 BOM would end up between the encoding PI below and the document,
		// which makes libxml stop right after it and return an otherwise empty preview.
		// Editors that default to BOM (Notepad, Excel's web export, PowerShell's
		// ConvertTo-Html) hit this on every file. The PI already forces UTF-8, and the
		// response declares charset=utf-8, so the BOM carries no information here.
		if (str_starts_with($html, "\xEF\xBB\xBF")) {
			$html = substr($html, 3);
		}

		$trimmed = trim($html);
		if ($trimmed === '') {
			return $html;
		}

		// libxml's HTML parser ends a <script>/<style> body at any "</", not only at that
		// element's own end tag, and discards the rest of the body. A closing tag written
		// inside a JavaScript string ("<button><i></i>X</button>") therefore vanishes and
		// every DOM the script builds afterwards comes out mis-nested; CSS loses the same
		// way in content:"</td>". Those bodies are never redacted (see redactNode()), so
		// they do not need to reach the DOM at all: they are stashed behind ASCII
		// placeholders and put back verbatim after serialization.
		//
		// If a placeholder does not come back, the document is redone without stashing.
		// libxml can put one where the placeholder stops being the body of its element —
		// "<script src=\"a.js\"/>" is read as self-closing, which leaves the placeholder in
		// the surrounding text where the long-token rule then redacts it — and serving that
		// would delete the stashed body without a trace. The unstashed pass loses closing
		// tags inside the body the way v0.1.3 did, but nothing disappears.
		$out = $this->redactRoundtrip($html, true);
		if ($out === null) {
			$out = $this->redactRoundtrip($html, false);
		}

		// Fall back to simple text redaction if the document cannot be parsed at all.
		return $out ?? $this->redactText($html);
	}

	/**
	 * Run one parse / redact / serialize cycle over the document.
	 *
	 * @param bool $stashRawText hold <script>/<style> bodies out of the DOM
	 *
	 * @return string|null null when the cycle could not be completed: the document did not
	 *   parse, serialization failed, or a stashed body did not survive the roundtrip
	 */
	private function redactRoundtrip(string $html, bool $stashRawText): ?string {
		$rawTexts = [];
		$rawTextMarker = null;
		$parsable = $html;

		if ($stashRawText) {
			$rawTextMarker = self::makeMarker('SHVRAW', $html);
			if ($rawTextMarker === null) {
				return null;
			}
			$parsable = $this->stashRawTextBodies($html, $rawTextMarker, $rawTexts);
		}

		// Parse with DOMDocument (tolerate real-world HTML)
		$dom = new \DOMDocument();
		libxml_use_internal_errors(true);

		// Prefix to force UTF-8 (stripped again after saveHTML)
		$loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $parsable, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		if (!$loaded || $dom->childNodes->length === 0) {
			return null;
		}

		// LIBXML_HTML_NOIMPLIED can leave multiple top-level siblings;
		// walk all of them so content after <script>/<style> is still redacted.
		foreach (iterator_to_array($dom->childNodes) as $child) {
			$this->redactNode($child);
		}

		// Save; note: may not perfectly roundtrip all HTML5 but sufficient for preview.
		// The collision check uses the original $html: it is a superset of everything that
		// can still surface in the output, and the two marker prefixes cannot overlap.
		$out = $this->saveHtmlPreservingNonAscii($dom, $html);
		if ($out === null) {
			return null;
		}

		// Remove the encoding PI used only for loadHTML (must not appear in previews)
		$out = str_replace('<?xml encoding="utf-8" ?>', '', $out);

		if ($rawTexts === []) {
			return $out;
		}

		// Restored after the PI is gone, so a script that spells that PI out keeps it.
		$restored = 0;
		$out = $this->restoreRawTextBodies($out, (string)$rawTextMarker, $rawTexts, $restored);
		return $restored === count($rawTexts) ? $out : null;
	}

	/**
	 * Build an ASCII placeholder prefix that content in $source cannot impersonate.
	 *
	 * It is randomised rather than derived from the document on purpose: a fixed prefix
	 * could be smuggled in entity-encoded ("&#83;HV..." parses to the literal marker text,
	 * which a scan of the source string cannot see), and growing a marker until it no
	 * longer occurs is quadratic in the document size — both were reachable from a
	 * crafted upload. An unpredictable marker makes the collision unconstructible.
	 *
	 * @return string|null null when no free candidate was found (caller falls back to text mode)
	 */
	private static function makeMarker(string $prefix, string $source): ?string {
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$candidate = $prefix . bin2hex(random_bytes(8));
			if (!str_contains($source, $candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Replace every <script>/<style> body with an ASCII placeholder.
	 *
	 * The document has to be walked the way a tokenizer would rather than searched for the
	 * bytes "<script": that sequence also occurs where HTML5 starts no element at all — in a
	 * comment ("<!-- <script> tags removed for the export -->"), in an attribute value
	 * ("<div data-tpl=\"<style>.x{}\">"), or in <textarea>/<title> text. Stashing from such a
	 * position hands a span of the document back into the output unparsed, which skips
	 * redaction over everything in it. So tags are consumed whole (their attribute values are
	 * never looked into) and comment-like constructs are stepped over.
	 *
	 * Scanned by hand rather than with one expression for a second reason: a lazy body
	 * pattern costs a full-document scan per start tag it cannot close, which a document full
	 * of unclosed <script> turns into a stall (or a silent PCRE backtrack-limit abort that
	 * would drop the protection exactly on the largest files).
	 *
	 * @param list<string> $bodies collected bodies, indexed by placeholder number
	 */
	private function stashRawTextBodies(string $html, string $marker, array &$bodies): string {
		$out = '';
		$emitted = 0;
		$scan = 0;

		while (($lt = strpos($html, '<', $scan)) !== false) {
			$token = $this->readTag($html, $lt);
			if ($token === null) {
				// A construct that never closes. Where it ends decides where every following
				// element sits, so guessing here is what produced the bypass described above.
				break;
			}

			[$name, $isEndTag, $scan] = $token;
			if ($name === null || $isEndTag) {
				continue;
			}

			$name = strtolower($name);
			$isRawText = $name === 'script' || $name === 'style';
			if (!$isRawText && $name !== 'textarea' && $name !== 'title') {
				continue;
			}

			// Everything up to this element's own end tag is text, not markup.
			$bodyStart = $scan;
			$bodyEnd = $this->findRawTextEnd($html, $name, $bodyStart);
			if ($bodyEnd === null) {
				// HTML5 runs an unclosed body to the end of the document. Stashing that far
				// would exempt the whole tail from redaction, and there would be no end tag
				// to hand it back at either, so scanning stops and libxml keeps the tail.
				break;
			}
			$scan = $bodyEnd;

			// <textarea>/<title> hold text too, but it is only skipped, never stashed: the
			// point is that a "<script" written inside them is not read as a start tag.
			if (!$isRawText || $bodyEnd === $bodyStart) {
				continue;
			}

			$bodies[] = substr($html, $bodyStart, $bodyEnd - $bodyStart);
			// Trailing "E" terminates the index so following digits stay literal.
			$out .= substr($html, $emitted, $bodyStart - $emitted)
				. $marker . (count($bodies) - 1) . 'E';
			$emitted = $bodyEnd;
		}

		return $out . substr($html, $emitted);
	}

	/**
	 * Read whatever construct starts at the "<" on offset $lt.
	 *
	 * @return array{0: ?string, 1: bool, 2: int}|null tag name (null when the construct opens
	 *   no element), whether it is an end tag, and the offset just past the construct;
	 *   null when the construct never closes
	 */
	private function readTag(string $html, int $lt): ?array {
		$next = $html[$lt + 1] ?? '';

		if ($next === '!' || $next === '?') {
			// Comments, doctypes, and the bogus comments browsers make of "<![CDATA[" runs and
			// of processing instructions all end without opening an element.
			if (substr($html, $lt, 4) === '<!--') {
				$end = strpos($html, '-->', $lt + 4);
				return $end === false ? null : [null, false, $end + 3];
			}
			$end = strpos($html, '>', $lt + 2);
			return $end === false ? null : [null, false, $end + 1];
		}

		// A quoted attribute value may contain ">", so the tag is consumed with an unrolled
		// quote-aware loop instead of a plain [^>]* run. Anchored at $lt.
		$isTagStart = $next === '/' || ($next !== '' && ctype_alpha($next));
		if (preg_match('#<(/?)([a-zA-Z][^\s/>]*)[^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>#A', $html, $m, 0, $lt) !== 1) {
			// A lone "<" is ordinary text; a tag whose ">" never arrives is unresolvable.
			return $isTagStart ? null : [null, false, $lt + 1];
		}

		return [$m[2], $m[1] === '/', $lt + strlen($m[0])];
	}

	/**
	 * Offset where a raw-text body ends, or null when the element is never closed.
	 *
	 * Follows the HTML5 rule that only this element's own end tag ends the body, so "</div>"
	 * in a string literal is body text — that difference from libxml is the whole point of
	 * stashing. The tag name must be followed by a tag terminator, so "</styles" is text too.
	 */
	private function findRawTextEnd(string $html, string $tag, int $from): ?int {
		$needle = '</' . $tag;
		$at = $from;

		while (($at = stripos($html, $needle, $at)) !== false) {
			$next = $html[$at + strlen($needle)] ?? '>';
			if ($next === '>' || $next === '/' || preg_match('/\s/', $next) === 1) {
				return $at;
			}
			$at += strlen($needle);
		}

		return null;
	}

	/**
	 * @param list<string> $bodies
	 * @param int $restored set to the number of placeholders that were substituted; the caller
	 *   discards the whole pass unless it matches the number of bodies stashed
	 */
	private function restoreRawTextBodies(string $out, string $marker, array $bodies, int &$restored): string {
		$restored = 0;

		// One pass, so a restored body is never rescanned for further placeholders.
		return preg_replace_callback(
			'/' . $marker . '(\d+)E/',
			static function (array $m) use ($bodies, &$restored): string {
				if (!isset($bodies[(int)$m[1]])) {
					return $m[0];
				}
				$restored++;
				return $bodies[(int)$m[1]];
			},
			$out
		) ?? $out;
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
		$marker = self::makeMarker('SHVNA', $source);
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
	 * Unlike redactNode() this deliberately descends into <script>/<style>: saveHTML()
	 * rewrites their attribute values too (a non-Latin media query comes back as numeric
	 * references, a non-Latin src percent-encoded). Their bodies never reach the document
	 * in the first place — stashRawTextBodies() holds those outside it.
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
	 * Apply textual redactions, leaving base64 `data:` URI payloads alone.
	 *
	 * Such a payload is encoded binary, not prose: the heuristics below cannot recognise
	 * a secret inside it, but they do match it by accident — a run of digits reads as a
	 * phone number and the payload as a whole reads as an opaque token — which replaces
	 * the image data and blanks out every inline image in the document. Data URIs that
	 * are not base64 stay in scope, since those carry readable text.
	 */
	private function redactText(string $text): string {
		if (stripos($text, ';base64,') === false) {
			return $this->redactPatterns($text);
		}

		$parts = preg_split(
			'/(data:[^\s"\'<>,)]*;base64,[A-Za-z0-9+\/=%]*)/i',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);
		if ($parts === false) {
			return $this->redactPatterns($text);
		}

		$out = '';
		foreach ($parts as $i => $part) {
			// Odd indices are the captured data: URIs; everything else is ordinary text.
			$out .= ($i % 2 === 1) ? $part : $this->redactPatterns($part);
		}
		return $out;
	}

	/**
	 * Apply textual redactions (order matters for overlapping patterns).
	 */
	private function redactPatterns(string $text): string {
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
		// The value is restricted to ASCII and bounded in length: credentials are ASCII in
		// practice, and Japanese prose has no ASCII whitespace to stop at, so an unbounded
		// class swallowed the rest of the text node — "本節では auth: の考え方を説明します。"
		// collapsed to "本節では auth=[REDACTED]".
		$text = preg_replace(
			'/\b(password|passwd|pwd|token|api[_-]?key|secret|auth|bearer)[=:][ \t]*[^&\s<>"\'\x80-\xFF]{3,256}/i',
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
