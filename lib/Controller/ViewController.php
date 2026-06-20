<?php

declare(strict_types=1);

namespace OCA\SafeHtmlViewer\Controller;

use OCA\SafeHtmlViewer\Service\RedactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class ViewController extends Controller {

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

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($fileId);

		if (count($nodes) === 0) {
			return $this->errorResponse('File not found or access denied', 404);
		}

		$node = $nodes[0];
		if (!$node instanceof File || !$node->isReadable()) {
			return $this->errorResponse('Not a readable file', 403);
		}

		// Basic type guard (still serve .html even if mime detection is text/plain)
		$name = strtolower($node->getName());
		$mime = $node->getMimeType();
		$isHtml = str_ends_with($name, '.html')
			|| $mime === 'text/html'
			|| $mime === 'application/xhtml+xml';

		// For MVP we serve anyway if accessible (user explicitly clicked HTML file action)
		$content = $node->getContent();

		$redacted = $this->redactionService->redact($content);

		$response = new DataDisplayResponse($redacted, 200, [
			'Content-Type' => 'text/html; charset=utf-8',
		]);

		// The critical security header: sandbox without allow-same-origin
		$response->addHeader('Content-Security-Policy', 'sandbox allow-scripts allow-popups');

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
