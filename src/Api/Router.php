<?php
declare(strict_types=1);

namespace Shipard\Api;

class Router
{
	private const string PREFIX = '/api/v1';

	public function resolve(string $path, string $method): Route|Response
	{

		if (!str_starts_with($path, self::PREFIX)) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		$subpath = substr($path, strlen(self::PREFIX));

		// Exact special endpoints
		if ($subpath === '/_openapi.json') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('openapi', 'spec');
		}

		if ($subpath === '/_meta/tables') {
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('meta', 'tables');
		}

		if (str_starts_with($subpath, '/_meta/tables/')) {
			$table = substr($subpath, strlen('/_meta/tables/'));
			if ($table === '' || str_contains($table, '/')) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}
			if ($method !== 'GET') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('meta', 'table', $table);
		}

		if ($subpath === '/_auth/login') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'login');
		}

		if ($subpath === '/_auth/refresh') {
			if ($method !== 'POST') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'refresh');
		}

		if ($subpath === '/_auth/logout') {
			if ($method !== 'DELETE') {
				return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
			}
			return new Route('auth', 'logout');
		}

		// Generic CRUD: /api/v1/{table} or /api/v1/{table}/{id}
		if (!str_starts_with($subpath, '/')) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}

		$rest = substr($subpath, 1);
		$parts = explode('/', $rest, 3);

		if (count($parts) === 1 && $parts[0] !== '') {
			$table = $parts[0];
			return match ($method) {
				'GET'  => new Route('crud', 'list', $table),
				'POST' => new Route('crud', 'create', $table),
				default => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
			$table = $parts[0];
			$rawId = $parts[1];

			if (!ctype_digit($rawId) || (int) $rawId <= 0) {
				return Response::error('NOT_FOUND', 'Not found', 404);
			}

			$id = (int) $rawId;
			return match ($method) {
				'GET'    => new Route('crud', 'show', $table, $id),
				'PUT'    => new Route('crud', 'update', $table, $id),
				'PATCH'  => new Route('crud', 'patch', $table, $id),
				'DELETE' => new Route('crud', 'delete', $table, $id),
				default  => Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405),
			};
		}

		return Response::error('NOT_FOUND', 'Not found', 404);
	}
}
