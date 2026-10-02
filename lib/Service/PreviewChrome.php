<?php

declare(strict_types=1);

namespace OCA\SafeHtmlViewer\Service;

/**
 * Parent document around one sandboxed preview iframe.
 *
 * The notice is body text in this document, outside the iframe, so it stays
 * visible while the framed page runs scripts. Callers pass only the iframe
 * URL and the notice; this class never receives file content or a file name.
 */
final class PreviewChrome {

	public function html(string $iframeSrc, string $notice): string {
		$safeNotice = htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$safeSrc = htmlspecialchars($iframeSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return <<<HTML
		<!DOCTYPE html>
		<html>
		<head>
		<meta charset="utf-8">
		<style>
		html, body { margin: 0; height: 100%; }
		body { display: flex; flex-direction: column; font: 14px/1.4 sans-serif; color: #222; background: #fff; }
		.notice { margin: 0; padding: 10px 14px; background: #fff6d8; border-bottom: 1px solid #e2d3a2; }
		iframe { flex: 1 1 auto; width: 100%; border: 0; }
		</style>
		</head>
		<body>
		<p class="notice">{$safeNotice}</p>
		<iframe sandbox="allow-scripts allow-popups" src="{$safeSrc}"></iframe>
		</body>
		</html>
		HTML;
	}
}
