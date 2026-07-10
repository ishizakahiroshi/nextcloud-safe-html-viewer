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

	public function testRedactsPrivateUrlWithIpHost(): void {
		$html = '<a href="http://192.168.1.100/admin">internal</a>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-PRIVATE-URL]', $out);
		$this->assertStringNotContainsString('192.168.1.100', $out);
	}

	public function testRedactsLocalhostUrl(): void {
		$html = '<a href="http://localhost:8080/path">x</a>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-PRIVATE-URL]', $out);
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
}
