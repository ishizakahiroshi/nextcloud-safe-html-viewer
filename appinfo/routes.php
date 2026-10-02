<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'view#raw', 'url' => '/raw/{fileId}', 'verb' => 'GET'],
		['name' => 'view#preview', 'url' => '/preview/{fileId}', 'verb' => 'GET'],
	],
];
