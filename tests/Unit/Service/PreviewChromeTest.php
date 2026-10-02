<?php

declare(strict_types=1);

namespace OCA\SafeHtmlViewer\Tests\Unit\Service;

use OCA\SafeHtmlViewer\Service\PreviewChrome;
use PHPUnit\Framework\TestCase;

class PreviewChromeTest extends TestCase {

	private PreviewChrome $chrome;

	protected function setUp(): void {
		parent::setUp();
		$this->chrome = new PreviewChrome();
	}

	public function testShowsTheSuppliedNoticeOutsideTheIframe(): void {
		$notice = 'Scripts in this preview are running. They cannot access your Nextcloud session.';
		$html = $this->chrome->html('/apps/safe_html_viewer/raw/1', $notice);

		$this->assertStringStartsWith('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('<meta charset="utf-8">', $html);
		$this->assertStringContainsString($notice, $html);
		$this->assertMatchesRegularExpression(
			'/<p class="notice">' . preg_quote($notice, '/') . '<\/p>\s*<iframe\b/',
			$html
		);
		$noticeAt = strpos($html, $notice);
		$iframeAt = strpos($html, '<iframe');
		$this->assertNotFalse($noticeAt);
		$this->assertNotFalse($iframeAt);
		$this->assertLessThan($iframeAt, $noticeAt);
	}

	public function testIframeSandboxIsExactlyScriptsAndPopups(): void {
		$html = $this->chrome->html('/apps/safe_html_viewer/raw/1', 'A notice');

		$this->assertSame(1, substr_count($html, '<iframe'));
		$this->assertStringContainsString('sandbox="allow-scripts allow-popups"', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/sandbox="(?!allow-scripts allow-popups")[^"]*"/',
			$html
		);
		$this->assertStringNotContainsString('allow-same-origin', $html);
		$this->assertStringNotContainsString('allow-top-navigation', $html);
		$this->assertStringNotContainsString('allow-popups-to-escape-sandbox', $html);
		$this->assertDoesNotMatchRegularExpression('/<script\b/i', $html);
	}

	public function testEscapesIframeSrcThatContainsQuoteAndLessThan(): void {
		$src = 'https://example.test/preview?q="<' . 'injected>';
		$html = $this->chrome->html($src, 'A notice');
		$escaped = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$this->assertStringContainsString('src="' . $escaped . '"', $html);
		$this->assertStringNotContainsString($src, $html);
		$this->assertStringNotContainsString('"<', $html);
		$this->assertSame(1, substr_count($html, '<iframe'));
		$this->assertStringNotContainsString('allow-same-origin', $html);
		$this->assertDoesNotMatchRegularExpression('/<script\b/i', $html);
	}

	public function testEscapesNoticeSoItCannotInjectMarkup(): void {
		$notice = 'Careful <script>alert(1)</script> "quoted"';
		$html = $this->chrome->html('/apps/safe_html_viewer/raw/1', $notice);
		$escaped = htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$this->assertStringContainsString($escaped, $html);
		$this->assertStringNotContainsString($notice, $html);
		$this->assertDoesNotMatchRegularExpression('/<script\b/i', $html);
	}

	public function testDoesNotEchoOrReturnASampleFileBody(): void {
		$sampleBody = '<p>SAMPLE-FILE-BODY-9c2e secret paragraph</p>';
		ob_start();
		try {
			$html = $this->chrome->html('https://example.test/raw/1', 'Visible notice only');
		} finally {
			$echoed = ob_get_clean();
		}

		$this->assertSame('', $echoed);
		$this->assertStringContainsString('Visible notice only', $html);
		$this->assertStringNotContainsString('SAMPLE-FILE-BODY-9c2e', $html);
		$this->assertStringNotContainsString($sampleBody, $html);
	}

	public function testReturnsSampleFileBodyOnlyWhenItIsTheNotice(): void {
		$sampleBody = 'SAMPLE-FILE-BODY-9c2e secret paragraph';
		ob_start();
		try {
			$html = $this->chrome->html('https://example.test/raw/1', $sampleBody);
		} finally {
			$echoed = ob_get_clean();
		}

		$this->assertSame('', $echoed);
		$this->assertStringContainsString($sampleBody, $html);
	}
}
