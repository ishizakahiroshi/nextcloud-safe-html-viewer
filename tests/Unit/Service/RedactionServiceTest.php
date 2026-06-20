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

	public function testRedactsIpv4(): void {
		$html = 'Server at 192.168.10.55 and also 10.0.0.1';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-IPV4]', $out);
		$this->assertStringNotContainsString('192.168.10.55', $out);
	}

	public function testRedactsPrivateUrl(): void {
		$html = '<a href="http://192.168.1.100/admin">internal</a>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-PRIVATE-URL]', $out);
		$this->assertStringNotContainsString('192.168.1.100', $out);
	}

	public function testRedactsCredentialPatterns(): void {
		$html = '<input value="token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9" />';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED]', $out);
		$this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $out);
	}

	public function testRedactsLongSecretToken(): void {
		$html = 'Bearer abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGH';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('[REDACTED-SECRET]', $out);
	}

	public function testLeavesOriginalHtmlStructure(): void {
		$html = '<!DOCTYPE html><html><body><p>hello</p><script>console.log("ok")</script></body></html>';
		$out = $this->service->redact($html);
		$this->assertStringContainsString('<p>', $out);
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
}
