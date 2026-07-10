<?php

declare(strict_types=1);

namespace OCA\SafeHtmlViewer\Controller;

use OCA\SafeHtmlViewer\Service\RedactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

class ViewController extends Controller {

	/** Max preview size in bytes (MVP). Larger files are rejected to avoid memory/CPU DoS. */
	public const MAX_PREVIEW_BYTES = 5 * 1024 * 1024;

	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private RedactionService $redactionService;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IUserSession $userSession,
		RedactionService $redactionService
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->redactionService = $redactionService;
	}

	/**
	 * Serve the HTML file content with sandbox CSP and redaction applied.
	 *
	 * - ACL is enforced by fetching the file through the current user's folder view.
	 * - Original file on storage is never modified.
	 * - CSP deliberately omits allow-same-origin.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function raw(int $fileId): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse('Unauthorized', 401);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$nodes = $userFolder->getById($fileId);
		} catch (NotFoundException | NotPermittedException $e) {
			return $this->errorResponse('File not found or access denied', 404);
		} catch (\Throwable $e) {
			return $this->errorResponse('Unable to access file', 500);
		}

		if (count($nodes) === 0) {
			return $this->errorResponse('File not found or access denied', 404);
		}

		$node = $nodes[0];
		if (!$node instanceof File || !$node->isReadable()) {
			return $this->errorResponse('Not a readable file', 403);
		}

		// Only HTML (and .htm) — matches the Files file action and product scope
		$name = strtolower($node->getName());
		$mime = $node->getMimeType();
		$isHtml = str_ends_with($name, '.html')
			|| str_ends_with($name, '.htm')
			|| $mime === 'text/html'
			|| $mime === 'application/xhtml+xml';

		if (!$isHtml) {
			return $this->errorResponse('Only HTML files can be previewed', 415);
		}

		$size = $node->getSize();
		if ($size < 0 || $size > self::MAX_PREVIEW_BYTES) {
			return $this->errorResponse('File too large for preview', 413);
		}

		try {
			$content = $node->getContent();
		} catch (NotFoundException | NotPermittedException $e) {
			return $this->errorResponse('File not found or access denied', 404);
		} catch (\Throwable $e) {
			return $this->errorResponse('Unable to read file', 500);
		}

		$redacted = $this->redactionService->redact($content);

		$response = new DataDisplayResponse($redacted, 200, [
			'Content-Type'        => 'text/html; charset=utf-8',
			'Content-Disposition' => 'inline',
		]);

		// Critical security header: sandbox without allow-same-origin.
		// frame-ancestors/base-uri harden beyond sandbox alone (XFO remains as defense in depth).
		$response->addHeader(
			'Content-Security-Policy',
			"sandbox allow-scripts allow-popups; frame-ancestors 'self'; base-uri 'none'"
		);

		// Extra hardening headers
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('X-Frame-Options', 'SAMEORIGIN');

		return $response;
	}

	private function errorResponse(string $message, int $status): Response {
		$resp = new DataDisplayResponse($message, $status, [
			'Content-Type' => 'text/plain; charset=utf-8',
		]);
		return $resp;
	}
}
