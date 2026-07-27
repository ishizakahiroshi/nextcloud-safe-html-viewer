<?php

declare(strict_types=1);

namespace OCA\SafeHtmlViewer\Tests\Unit\Service;

use OCA\SafeHtmlViewer\Service\RedactionService;
use PHPUnit\Framework\TestCase;

class RedactionServiceTest extends TestCase {

	private RedactionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new RedactionService();
	}

	public function testRedactsEmailInText(): void {
		$html = '<p>Contact me at alice@example.com please</p>';
		$out = $this->service->redact($html);
		$this->assertStringNotContainsString('alice@example.com', $out);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
	}

	public function testRedactsPhoneLike(): void {
		$html = '<div>Call +81-90-1234-5678</div>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-PHONE]', $out);
	}

	public function testDoesNotRedactIsoDateAsPhone(): void {
		$html = '<p>Release 2024-01-15 notes</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('2024-01-15', $out);
		$this->assertStringNotContainsString('[REDACTED-PHONE]', $out);
	}

	public function testRedactsIpv4(): void {
		$html = 'Server at 192.168.10.55 and also 10.0.0.1';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-IPV4]', $out);
		$this->assertStringNotContainsString('192.168.10.55', $out);
	}

	/**
	 * libxml percent-encodes URI attributes when serializing on some versions
	 * (2.9.x on Ubuntu 24.04 does, 2.11.x does not), so a label placed in href/src
	 * can arrive as "%5BREDACTED-PRIVATE-URL%5D". Both forms mean redacted.
	 */
	private const PRIVATE_URL_LABEL = '/(?:\[|%5B)REDACTED-PRIVATE-URL(?:\]|%5D)/';

	public function testRedactsPrivateUrlWithIpHost(): void {
		$html = '<a href="http://192.168.1.100/admin">internal</a>';
		$out = $this->service->redact($html);
		$this->assertMatchesRegularExpression(self::PRIVATE_URL_LABEL, $out);
		$this->assertStringNotContainsString('192.168.1.100', $out);
	}

	public function testRedactsLocalhostUrl(): void {
		$html = '<a href="http://localhost:8080/path">x</a>';
		$out = $this->service->redact($html);
		$this->assertMatchesRegularExpression(self::PRIVATE_URL_LABEL, $out);
	}

	public function testDoesNotRedactInternalAsMereHostPrefix(): void {
		$html = '<p>https://internal.example.com/docs</p>';
		$out = $this->service->redact($html);
		// Host is public DNS; only bare "internal"/"intranet" labels are private
		$this->assertStringContainsString('internal.example.com', $out);
		$this->assertStringNotContainsString('[REDACTED-PRIVATE-URL]', $out);
	}

	public function testRedactsCredentialInQueryString(): void {
		$html = '<p>https://public.example/api?token=secrettokenvalue123&amp;x=1</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('token=[REDACTED]', $out);
		$this->assertStringNotContainsString('secrettokenvalue123', $out);
		// Parameter name must be preserved (regression: was ?=[REDACTED])
		$this->assertStringNotContainsString('?=[REDACTED]', $out);
	}

	public function testRedactsBarePasswordEquals(): void {
		$html = '<p>password=supersecret99</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('password=[REDACTED]', $out);
		$this->assertStringNotContainsString('supersecret99', $out);
	}

	public function testRedactsCredentialInAttributeValue(): void {
		// Short secret so only credential rule fires (not long-token heuristic)
		$html = '<input value="token=short-secret-xyz" />';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('token=[REDACTED]', $out);
		$this->assertStringNotContainsString('short-secret-xyz', $out);
	}

	public function testRedactsLongSecretToken(): void {
		$html = 'Bearer abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGH';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-SECRET]', $out);
	}

	public function testDoesNotSwallowPublicUrlPathAsSecret(): void {
		$html = '<p>https://example.com/path/with-long-segment-abcdefghijklmnop</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('example.com', $out);
		// Path may still redact long segments without '/', but host+path must not collapse to one secret
		$this->assertStringNotContainsString('example.[REDACTED-SECRET]', $out);
	}

	public function testLeavesOriginalHtmlStructure(): void {
		$html = '<!DOCTYPE html><html><body><p>hello</p><script>console.log("ok")</script></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('<p>', $out);
		$this->assertStringContainsString('<script>', $out);
	}

	public function testDoesNotLeakXmlEncodingPi(): void {
		$html = '<p>hello</p>';
		$out = $this->service->redact($html);
		$this->assertStringNotContainsString('<?xml encoding="utf-8" ?>', $out);
		$this->assertStringContainsString('hello', $out);
	}

	public function testRedactsAllTopLevelSiblings(): void {
		$html = '<div>a@b.co</div><div>c@d.co</div>';
		$out = $this->service->redact($html);
		$this->assertStringNotContainsString('a@b.co', $out);
		$this->assertStringNotContainsString('c@d.co', $out);
		$this->assertSame(2, substr_count($out, '[REDACTED-EMAIL]'));
	}

	public function testRedactsContentAfterScriptSibling(): void {
		$html = '<script>var x=1</script><p>a@b.co</p>';
		$out = $this->service->redact($html);
		$this->assertStringNotContainsString('a@b.co', $out);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringContainsString('<script>', $out);
	}

	public function testDoesNotModifyPlainTextOutsideHtml(): void {
		$input = 'just some text with user@corp.test inside';
		$out = $this->service->redact($input);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
	}

	public function testEmptyInput(): void {
		$this->assertSame('', $this->service->redact(''));
		$this->assertSame('   ', $this->service->redact('   '));
	}

	public function testLeavesSecretsInsideScriptUnchanged(): void {
		// Intentional best-effort limitation: script bodies are not walked
		$html = '<script>const t="abcdefghijklmnopqrstuvwxyz012345";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('abcdefghijklmnopqrstuvwxyz012345', $out);
	}

	public function testPreservesNonAsciiTextInsteadOfNumericEntities(): void {
		// DOMDocument::saveHTML() re-escapes non-ASCII text as decimal numeric
		// character references. Regression test for that mangling
		// (e.g. Japanese "選択" becoming "&#36984;&#25246;").
		$html = '<p>選択してください</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('選択してください', $out);
		$this->assertDoesNotMatchRegularExpression('/&#\d+;/', $out);
	}

	public function testPreservesNonAsciiInsideScriptAndStyle(): void {
		// Browsers do not decode numeric character references inside <script>/<style>,
		// so escaping there renders the raw "&#21517;" text on screen and breaks the page.
		$html = '<script>var label = "名前を入力";</script>'
			. '<style>.step::after{content:"完了";}</style>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('名前を入力', $out);
		$this->assertStringContainsString('完了', $out);
		$this->assertDoesNotMatchRegularExpression('/&#\d+;/', $out);
	}

	public function testPreservesNonAsciiInAttributeValues(): void {
		$html = '<img src="/a.png" alt="図解の説明">';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('図解の説明', $out);
	}

	public function testPreservesAstralPlaneCharacters(): void {
		$html = '<p>done 🎉</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('🎉', $out);
		$this->assertTrue(mb_check_encoding($out, 'UTF-8'));
	}

	public function testKeepsHtmlSignificantEntitiesEncoded(): void {
		// Decoding these would turn escaped markup back into live markup.
		$html = '<p>a &amp; b &lt;tag&gt;</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('&amp;', $out);
		$this->assertStringContainsString('&lt;', $out);
		$this->assertStringContainsString('&gt;', $out);
	}

	public function testDoesNotDecodeAsciiNumericReferencesIntoMarkup(): void {
		$html = '<p>&#60;script&#62;alert(1)&#60;/script&#62;</p>';
		$out = $this->service->redact($html);
		$this->assertStringNotContainsString('<script>alert(1)', $out);
	}

	public function testLeavesLiteralNumericReferencesInsideScriptUnchanged(): void {
		// libxml passes raw-text element bodies through verbatim, so a reference the
		// author wrote by hand must not be rewritten into the character it denotes.
		$html = '<script>document.write("&#8364;");</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('&#8364;', $out);
	}

	public function testSurvivesInvalidNumericReferencesInsideScript(): void {
		// mb_chr() has no character for these; decoding them used to raise a TypeError
		// and take the whole preview down with a 500.
		$html = '<script>var s = "&#99999999;" + "&#55296;";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('&#99999999;', $out);
		$this->assertStringContainsString('&#55296;', $out);
	}

	public function testDoesNotRedactIsoDateSurroundedByNonAsciiText(): void {
		// The phone pattern allows spaces/parens, so a match carries that padding and
		// the ISO-date guard used to miss: "検証資料 2026-07-17" became "検証資料[REDACTED-PHONE]".
		$html = '<h1>検証資料 2026-07-17</h1>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('2026-07-17', $out);
		$this->assertStringNotContainsString('[REDACTED-PHONE]', $out);
	}

	public function testDoesNotRedactIsoDateInParenthesesOrRange(): void {
		$html = '<p>資料 (2026-07-17) 期間: 2026-07-01 〜 2026-07-17</p>';
		$out = $this->service->redact($html);
		$this->assertStringNotContainsString('[REDACTED-PHONE]', $out);
		$this->assertStringContainsString('(2026-07-17)', $out);
		$this->assertStringContainsString('2026-07-01', $out);
	}

	public function testStillRedactsPhoneAfterNonAsciiText(): void {
		$html = '<p>連絡先 090-1234-5678</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-PHONE]', $out);
		$this->assertStringNotContainsString('090-1234-5678', $out);
	}

	public function testPlaceholderIsNotForgeableFromDocumentContent(): void {
		// "&#83;HVNA..." parses into literal marker text that a scan of the source string
		// cannot see, so a predictable placeholder would let a document swap its own
		// content for an unrelated fragment.
		$html = '<p>&#83;HVNA00000000000000000E と 日本語</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('SHVNA00000000000000000E', $out);
		$this->assertStringContainsString('日本語', $out);
	}

	public function testPlaceholderSetupIsNotQuadraticInDocumentSize(): void {
		// Growing a placeholder until it stops occurring rescanned the whole document on
		// every step, so a crafted upload could pin a worker for hours.
		$html = '<p>SHVNA' . str_repeat('0', 60000) . ' 日本語</p>';
		$start = microtime(true);
		$out = $this->service->redact($html);
		$elapsed = microtime(true) - $start;
		$this->assertStringContainsString('日本語', $out);
		$this->assertLessThan(2.0, $elapsed);
	}

	public function testPreservesAmpersandsInAttributeValuesAlongsideNonAscii(): void {
		// Assigning to DOMAttr::$value re-parses entities: a bare "&" wiped the whole
		// attribute and "&#12354;" written by the author got decoded.
		$html = '<img alt="a &amp; b 日本語" src="/x.png">';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('&amp;', $out);
		$this->assertStringContainsString('日本語', $out);
	}

	public function testDoesNotDecodeAuthoredReferencesInAttributeValues(): void {
		$html = '<img alt="&amp;#12354; 日本語" src="/x.png">';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('&amp;#12354;', $out);
		$this->assertStringNotContainsString('あ', $out);
	}

	public function testPreviewSurvivesLeadingUtf8Bom(): void {
		// The BOM landed between the encoding PI and the document, so libxml stopped
		// after it and the preview came back empty. Notepad, Excel's web export and
		// PowerShell's ConvertTo-Html all emit a BOM by default.
		$html = "\xEF\xBB\xBF<!DOCTYPE html><html><head><title>T</title></head>"
			. '<body><p>レポート本文</p></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('レポート本文', $out);
		$this->assertStringNotContainsString("\xEF\xBB\xBF", $out);
	}

	public function testDoesNotRedactBase64DataUriPayload(): void {
		// The payload reads as an opaque token to the long-string heuristic, which
		// replaced the image data and blanked out every inline image.
		$payload = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
		$html = '<p>説明文</p><img src="data:image/png;base64,' . $payload . '">';
		$out = $this->service->redact($html);
		$this->assertStringContainsString($payload, $out);
		$this->assertStringNotContainsString('[REDACTED-SECRET]', $out);
	}

	public function testStillRedactsSecretsOutsideDataUris(): void {
		// The data: exemption must not spill over into the surrounding text.
		$payload = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
		$html = '<html><body><p>a@b.co</p>'
			. '<img src="data:image/png;base64,' . $payload . '">'
			. '<p>password=supersecret99</p></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString($payload, $out);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringContainsString('password=[REDACTED]', $out);
	}

	public function testStillRedactsPlainTextDataUri(): void {
		// Only base64 payloads are exempt; a readable data: URI stays in scope.
		$html = '<p>data:text/plain,password=supersecret99</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('password=[REDACTED]', $out);
		$this->assertStringNotContainsString('supersecret99', $out);
	}

	public function testCredentialRuleDoesNotSwallowNonAsciiProse(): void {
		// Japanese has no ASCII whitespace for the value pattern to stop at, so an
		// unbounded class consumed the rest of the text node and deleted the paragraph.
		$html = '<p>本節では auth: の考え方を説明します。認証はSAMLで行います。</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('認証はSAMLで行います。', $out);
		$this->assertStringNotContainsString('[REDACTED]', $out);
	}

	public function testKeepsClosingTagsInsideScriptBody(): void {
		// libxml ends a raw-text body at any "</", so closing tags written inside a JS
		// string used to be dropped and every DOM built from them came out mis-nested.
		$html = '<html><body><script>var t="<button><i></i>X</button>";</script></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('var t="<button><i></i>X</button>";', $out);
	}

	public function testKeepsClosingTagsInsideStyleBody(): void {
		$html = '<html><head><style>.step::after{content:"</td>";}</style></head><body>x</body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('content:"</td>";', $out);
	}

	public function testKeepsScriptBodyConsistingOnlyOfAClosingTag(): void {
		// The whole body sat after the first "</" and vanished entirely.
		$html = '<script>var t="</div>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('var t="</div>";', $out);
	}

	public function testKeepsClosingTagsAlongsideNonAsciiInsideScript(): void {
		$html = '<script>var t="<button><i></i>ラベル</button>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('var t="<button><i></i>ラベル</button>";', $out);
		$this->assertDoesNotMatchRegularExpression('/&#\d+;/', $out);
	}

	public function testKeepsBodyOfScriptWithAttributes(): void {
		$html = '<script type="module" defer>const t="<td></td>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('const t="<td></td>";', $out);
		$this->assertStringContainsString('type="module"', $out);
	}

	public function testKeepsBodyOfScriptWithGreaterThanInAttributeValue(): void {
		// A quoted attribute value may contain ">", which a plain [^>]* run would cut at.
		$html = '<script data-sel="li > a">var t="</span>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('var t="</span>";', $out);
	}

	public function testKeepsEscapedScriptEndTagInsideScript(): void {
		$html = '<script>var t="<\/script>";</script><p>a@b.co</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('var t="<\/script>";', $out);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
	}

	public function testStillRedactsAfterUnescapedScriptEndTagInsideString(): void {
		// HTML5 ends the element at the first "</script>" even inside a string literal, so
		// the remainder is markup. The preview must stay intact and keep redacting.
		$html = '<script>var t="</script>";</script><p>a@b.co</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringNotContainsString('a@b.co', $out);
	}

	public function testDoesNotEndScriptBodyAtASimilarlyNamedTag(): void {
		// "</scriptx" is body text: only the element's own end tag terminates it.
		$html = '<script>var t="</scriptx>" + "</i>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('var t="</scriptx>" + "</i>";', $out);
	}

	public function testKeepsBodyOfScriptWithoutSecretsUntouchedNextToSiblings(): void {
		$html = '<script>el.innerHTML="<tr><td>a</td></tr>";</script><p>password=supersecret99</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('el.innerHTML="<tr><td>a</td></tr>";', $out);
		$this->assertStringContainsString('password=[REDACTED]', $out);
	}

	public function testRawTextPlaceholderShapedTextIsServedUnchanged(): void {
		// Text that looks like a placeholder must survive as written. (That a real placeholder
		// cannot be forged rests on random_bytes() and is not observable from outside.)
		// Kept just under 24 characters so the long-token heuristic does not claim it first.
		$html = '<p>&#83;HVRAW0000000000000000E</p><script>var t="</i>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('SHVRAW0000000000000000E', $out);
		$this->assertStringContainsString('var t="</i>";', $out);
	}

	public function testPlaceholderNeverLeaksIntoTheOutput(): void {
		// Whatever happens to a placeholder, it must not reach the browser as text.
		$documents = [
			'<script>var t="</i>";</script>',
			'<html><body><script src="a.js"/><p>x</p><script>var t="</i>";</script></body></html>',
			'<style/><style>.a{content:"</td>"}</style>',
			'<div title="<script>"><script>var t="</i>";</script></div>',
			'<textarea><script>a</textarea><script>var t="</i>";</script>',
		];
		foreach ($documents as $html) {
			$out = $this->service->redact($html);
			$this->assertStringNotContainsString('SHVRAW', $out);
			$this->assertStringNotContainsString('SHVNA', $out);
		}
	}

	public function testSelfClosingScriptDoesNotSwallowTheDocument(): void {
		// libxml reads "<script src=... />" as self-closing, which leaves the placeholder in
		// the surrounding text where the long-token rule redacts it. Restoring then found
		// nothing and the whole tail of the document disappeared.
		$html = '<html><body><script src="a.js"/><h1>Report</h1><p>a@b.co</p>'
			. '<script>var t="</i>";</script></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('Report', $out);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringNotContainsString('[REDACTED-SECRET]', $out);
	}

	public function testSelfClosingStyleDoesNotSwallowTheDocument(): void {
		$html = '<html><body><style/><h1>Report</h1><p>a@b.co</p>'
			. '<style>.a{content:"</td>"}</style></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('Report', $out);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
	}

	public function testRedactsAfterACommentMentioningAScriptTag(): void {
		// "<script" inside a comment opens no element. Reading it as one stashed the rest of
		// the document, which both skipped redaction over it and left libxml an unterminated
		// comment — the preview came back as an empty <body>.
		$html = '<html><body><!-- <script> tags removed for the export -->'
			. '<h1>Minutes</h1><p>secret@example.com</p></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringNotContainsString('secret@example.com', $out);
		$this->assertStringContainsString('Minutes', $out);
	}

	public function testRedactsAfterAnAttributeValueMentioningAStyleTag(): void {
		// Same bypass through an attribute value: authoring tools park HTML snippets in
		// data-* / title attributes all the time.
		$html = '<html><body><div data-tpl="<style>.x{}"><b>Row</b></div>'
			. '<p>token=abcd1234efgh</p></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('token=[REDACTED]', $out);
		$this->assertStringNotContainsString('abcd1234efgh', $out);
	}

	public function testRedactsAfterTextareaContainingAStyleTag(): void {
		// <textarea> holds text, so the tag inside it is not an element either.
		$html = '<textarea><style>body{}</textarea><p>a@b.co</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringNotContainsString('a@b.co', $out);
	}

	public function testKeepsRealScriptBodyDespiteAScriptTagInAnAttribute(): void {
		$html = '<div title="<script>"><p>a@b.co</p></div><script>var t="</i>";</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringContainsString('var t="</i>";', $out);
	}

	public function testDoesNotStashPastAnUnclosedRawTextElement(): void {
		// HTML5 runs an unclosed body to the end of the document, but stashing that far would
		// exempt the whole tail from redaction, so the tail is deliberately left to libxml.
		$html = '<html><body><p>a@b.co</p><script>var t="</i>";';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-EMAIL]', $out);
		$this->assertStringNotContainsString('a@b.co', $out);
	}

	public function testUnclosedScriptDoesNotStall(): void {
		// Searching for an end tag that never comes must not cost one scan per start tag.
		$html = '<html><body>' . str_repeat('<script>', 2000) . str_repeat('x', 200000);
		$start = microtime(true);
		$out = $this->service->redact($html);
		$elapsed = microtime(true) - $start;
		$this->assertNotSame('', $out);
		$this->assertLessThan(2.0, $elapsed);
	}

	public function testManyScriptBlocksStayLinear(): void {
		$html = '<html><body>' . str_repeat('<script>var t="</i>";</script>', 2000) . '</body></html>';
		$start = microtime(true);
		$out = $this->service->redact($html);
		$elapsed = microtime(true) - $start;
		$this->assertSame(2000, substr_count($out, 'var t="</i>";'));
		$this->assertLessThan(5.0, $elapsed);
	}

	public function testKeepsXmlEncodingPiWrittenInsideScript(): void {
		// The PI is stripped from the serialized output; a script that spells it out as a
		// string must not lose it to that cleanup.
		$html = '<script>var pi = \'<?xml encoding="utf-8" ?>\';</script>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('<?xml encoding="utf-8" ?>', $out);
	}

	public function testKeepsC1ReferencesAsReferences(): void {
		// HTML5 maps numeric references in U+0080-U+009F to Windows-1252, which is how
		// Word and Outlook emit curly quotes. Emitting the raw code point renders nothing.
		$html = '<p>&#147;quoted&#148; &#151; dash</p>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('&#147;', $out);
		$this->assertStringContainsString('&#148;', $out);
		$this->assertStringContainsString('&#151;', $out);
	}
}
