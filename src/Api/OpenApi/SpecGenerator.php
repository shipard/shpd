<?php
declare(strict_types=1);

namespace Shipard\Api\OpenApi;

use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\TableDefinition;

class SpecGenerator
{
	/**
	 * @param  array<string, TableDefinition>  $tableDefinitions  Indexed by table name
	 */
	public function generate(array $tableDefinitions, string $baseUrl): array
	{
		$paths     = $this->buildFixedPaths();
		$schemas   = $this->buildFixedSchemas();

		foreach ($tableDefinitions as $tableName => $def) {
			$paths   = array_merge($paths, $this->buildTablePaths($tableName, $def));
			$schemas = array_merge($schemas, $this->buildTableSchemas($tableName, $def));
		}

		return [
			'openapi' => '3.1.0',
			'info'    => [
				'title'   => 'Shipard API',
				'version' => '1.0.0',
			],
			'servers' => [
				['url' => rtrim($baseUrl, '/') . '/api/v1'],
			],
			'paths'      => $paths,
			'components' => [
				'schemas'         => $schemas,
				'securitySchemes' => [
					'bearerAuth' => [
						'type'   => 'http',
						'scheme' => 'bearer',
					],
				],
			],
			'security' => [
				['bearerAuth' => []],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Fixed paths (auth, meta, openapi)
	// -------------------------------------------------------------------------

	private function buildFixedPaths(): array
	{
		return [
			'/_auth/login' => [
				'post' => [
					'summary'   => 'Login',
					'tags'      => ['auth'],
					'security'  => [],
					'requestBody' => [
						'required' => true,
						'content'  => [
							'application/json' => [
								'schema' => ['$ref' => '#/components/schemas/LoginRequest'],
							],
						],
					],
					'responses' => [
						'200' => ['description' => 'Login successful', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LoginResponse']]]],
						'401' => ['description' => 'Invalid credentials', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
					],
				],
			],
			'/_auth/refresh' => [
				'post' => [
					'summary'   => 'Refresh session token',
					'tags'      => ['auth'],
					'responses' => [
						'200' => ['description' => 'Token refreshed'],
						'401' => ['description' => 'Unauthorized'],
					],
				],
			],
			'/_auth/logout' => [
				'delete' => [
					'summary'   => 'Logout',
					'tags'      => ['auth'],
					'responses' => [
						'204' => ['description' => 'Logged out'],
						'401' => ['description' => 'Unauthorized'],
					],
				],
			],
			'/_meta/tables' => [
				'get' => [
					'summary'   => 'List available tables',
					'tags'      => ['meta'],
					'responses' => [
						'200' => ['description' => 'Success'],
					],
				],
			],
			'/_meta/tables/{table}' => [
				'get' => [
					'summary'    => 'Get table metadata',
					'tags'       => ['meta'],
					'parameters' => [['name' => 'table', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
					'responses'  => [
						'200' => ['description' => 'Success'],
						'404' => ['description' => 'Table not found'],
					],
				],
			],
			'/_openapi.json' => [
				'get' => [
					'summary'  => 'OpenAPI 3.1 specification',
					'tags'     => ['openapi'],
					'security' => [],
					'responses' => [
						'200' => ['description' => 'OpenAPI spec'],
					],
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Fixed schemas
	// -------------------------------------------------------------------------

	private function buildFixedSchemas(): array
	{
		return [
			'ErrorResponse' => [
				'type'       => 'object',
				'properties' => [
					'success' => ['type' => 'boolean', 'example' => false],
					'error'   => [
						'type'       => 'object',
						'properties' => [
							'code'    => ['type' => 'string'],
							'message' => ['type' => 'string'],
							'details' => ['type' => 'array', 'items' => ['type' => 'object']],
						],
					],
				],
			],
			'LoginRequest' => [
				'type'       => 'object',
				'required'   => ['login', 'password'],
				'properties' => [
					'login'    => ['type' => 'string'],
					'password' => ['type' => 'string', 'format' => 'password'],
				],
			],
			'LoginResponse' => [
				'type'       => 'object',
				'properties' => [
					'success' => ['type' => 'boolean'],
					'data'    => [
						'type'       => 'object',
						'properties' => [
							'token'      => ['type' => 'string'],
							'expires_at' => ['type' => 'string', 'format' => 'date-time'],
							'user'       => ['type' => 'object'],
						],
					],
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Per-table paths
	// -------------------------------------------------------------------------

	private function buildTablePaths(string $tableName, TableDefinition $def): array
	{
		$listPath   = "/{$tableName}";
		$detailPath = "/{$tableName}/{id}";
		$tag        = $tableName;

		$listQueryParams = [
			['name' => 'limit',  'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
			['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 0]],
			['name' => 'sort',   'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'e.g. id:asc,name:desc'],
			['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated column list'],
		];

		$idParam = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];

		$itemRef    = "#/components/schemas/{$tableName}_item";
		$createRef  = "#/components/schemas/{$tableName}_create";
		$listRef    = "#/components/schemas/{$tableName}_list_response";
		$singleRef  = "#/components/schemas/{$tableName}_single_response";

		$jsonContent = fn(string $ref) => ['application/json' => ['schema' => ['$ref' => $ref]]];

		return [
			$listPath => [
				'get' => [
					'summary'    => "List {$tableName}",
					'tags'       => [$tag],
					'parameters' => $listQueryParams,
					'responses'  => [
						'200' => ['description' => 'Success', 'content' => $jsonContent($listRef)],
						'400' => ['description' => 'Bad request'],
						'401' => ['description' => 'Unauthorized'],
					],
				],
				'post' => [
					'summary'     => "Create {$tableName}",
					'tags'        => [$tag],
					'requestBody' => ['required' => true, 'content' => $jsonContent($createRef)],
					'responses'   => [
						'201' => ['description' => 'Created', 'content' => $jsonContent($singleRef)],
						'401' => ['description' => 'Unauthorized'],
						'422' => ['description' => 'Validation error', 'content' => $jsonContent('#/components/schemas/ErrorResponse')],
					],
				],
			],
			$detailPath => [
				'get' => [
					'summary'    => "Get {$tableName} by ID",
					'tags'       => [$tag],
					'parameters' => [$idParam],
					'responses'  => [
						'200' => ['description' => 'Success', 'content' => $jsonContent($singleRef)],
						'401' => ['description' => 'Unauthorized'],
						'404' => ['description' => 'Not found'],
					],
				],
				'put' => [
					'summary'     => "Update {$tableName}",
					'tags'        => [$tag],
					'parameters'  => [$idParam],
					'requestBody' => ['required' => true, 'content' => $jsonContent($createRef)],
					'responses'   => [
						'200' => ['description' => 'Updated', 'content' => $jsonContent($singleRef)],
						'401' => ['description' => 'Unauthorized'],
						'404' => ['description' => 'Not found'],
						'422' => ['description' => 'Validation error'],
					],
				],
				'patch' => [
					'summary'     => "Patch {$tableName}",
					'tags'        => [$tag],
					'parameters'  => [$idParam],
					'requestBody' => ['required' => true, 'content' => $jsonContent($itemRef)],
					'responses'   => [
						'200' => ['description' => 'Patched', 'content' => $jsonContent($singleRef)],
						'401' => ['description' => 'Unauthorized'],
						'404' => ['description' => 'Not found'],
					],
				],
				'delete' => [
					'summary'    => "Delete {$tableName}",
					'tags'       => [$tag],
					'parameters' => [$idParam],
					'responses'  => [
						'204' => ['description' => 'Deleted'],
						'401' => ['description' => 'Unauthorized'],
						'404' => ['description' => 'Not found'],
					],
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Per-table schemas
	// -------------------------------------------------------------------------

	private function buildTableSchemas(string $tableName, TableDefinition $def): array
	{
		$properties = [];
		$required   = [];
		$readOnly   = [];

		foreach ($def->columns as $col) {
			$schema = $this->columnToSchema($col);

			if ($col->primaryKey || $col->autoIncrement || in_array($col->id, ['created', 'modified'], true)) {
				$schema['readOnly'] = true;
				$readOnly[]         = $col->id;
			}

			if (!$col->nullable && $col->default === null && !$col->autoIncrement && !$col->primaryKey && !in_array($col->id, ['created', 'modified'], true)) {
				$required[] = $col->id;
			}

			$properties[$col->id] = $schema;
		}

		$itemSchema = [
			'type'       => 'object',
			'properties' => $properties,
		];

		$createProperties = array_filter($properties, fn($k) => !in_array($k, $readOnly, true), ARRAY_FILTER_USE_KEY);
		$createSchema     = ['type' => 'object', 'properties' => $createProperties];
		if ($required !== []) {
			$createSchema['required'] = $required;
		}

		$listResponseSchema = [
			'type'       => 'object',
			'properties' => [
				'success' => ['type' => 'boolean'],
				'data'    => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/{$tableName}_item"]],
				'meta'    => [
					'type'       => 'object',
					'properties' => [
						'total'  => ['type' => 'integer'],
						'limit'  => ['type' => 'integer'],
						'offset' => ['type' => 'integer'],
					],
				],
			],
		];

		$singleResponseSchema = [
			'type'       => 'object',
			'properties' => [
				'success' => ['type' => 'boolean'],
				'data'    => ['$ref' => "#/components/schemas/{$tableName}_item"],
			],
		];

		return [
			"{$tableName}_item"            => $itemSchema,
			"{$tableName}_create"          => $createSchema,
			"{$tableName}_list_response"   => $listResponseSchema,
			"{$tableName}_single_response" => $singleResponseSchema,
		];
	}

	private function columnToSchema(ColumnDefinition $col): array
	{
		return match ($col->type) {
			'int', 'bigint', 'smallint', 'tinyint', 'enumInt'
				=> ['type' => 'integer'],
			'numeric', 'float'
				=> ['type' => 'number'],
			'boolean'
				=> ['type' => 'boolean'],
			'date'
				=> ['type' => 'string', 'format' => 'date'],
			'datetime'
				=> ['type' => 'string', 'format' => 'date-time'],
			'time'
				=> ['type' => 'string', 'format' => 'time'],
			'json'
				=> ['type' => 'object'],
			'varchar'
				=> array_filter(['type' => 'string', 'maxLength' => $col->length]),
			default // text, longtext, enumString, ...
				=> ['type' => 'string'],
		};
	}
}
