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
}
