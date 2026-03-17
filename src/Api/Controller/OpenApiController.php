<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\OpenApi\SpecGenerator;
use Shipard\Api\Response;
use Shipard\Core\Database\TableDefinition;

class OpenApiController
{
	/**
	 * @param  array<string, TableDefinition>  $tableDefinitions
	 */
	public function spec(
		AuthContext $auth,
		bool $openApiPublic,
		array $tableDefinitions,
		string $baseUrl,
	): Response {
		if (!$openApiPublic && !$auth->isAuthenticated) {
			return Response::error('UNAUTHORIZED', 'Authentication required', 401);
		}

		$generator = new SpecGenerator();
		$spec      = $generator->generate($tableDefinitions, $baseUrl);

		return Response::raw($spec);
	}
}
