<?php
declare(strict_types=1);

namespace Shipard\Api;

class Request
{
	private function __construct(
		private string $method,
		private string $path,
		private array $queryParams,
		private ?array $body,
		private array $headers,
		private string $host,
		private ?string $clientIp,
	) {}

	public static function fromGlobals(): self
	{
		return self::fromArray(
			$_SERVER['REQUEST_METHOD'] ?? 'GET',
			$_SERVER['REQUEST_URI'] ?? '/',
			$_GET,
			(string) file_get_contents('php://input'),
			$_SERVER,
		);
	}

	public static function fromArray(string $method, string $uri, array $queryParams, string $rawBody, array $server): self
	{
		$method = strtoupper($method);

		$path = parse_url($uri, PHP_URL_PATH) ?? $uri;
		if ($path === false || $path === null) {
			$path = $uri;
		}

		$body = null;
		if ($rawBody !== '') {
			$decoded = json_decode($rawBody, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				$body = $decoded;
			}
		}

		$headers = [];
		foreach ($server as $key => $value) {
			if (str_starts_with($key, 'HTTP_')) {
				$name = substr($key, 5);
				$name = strtolower(str_replace('_', '-', $name));
				$headers[$name] = $value;
			} elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
				$name = strtolower(str_replace('_', '-', $key));
				$headers[$name] = $value;
			}
		}

		$host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';
		$clientIp = $server['REMOTE_ADDR'] ?? null;

		return new self($method, $path, $queryParams, $body, $headers, $host, $clientIp);
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getQueryParams(): array
	{
		return $this->queryParams;
	}

	public function getBody(): ?array
	{
		return $this->body;
	}

	public function getHeader(string $name): ?string
	{
		$key = strtolower($name);
		return $this->headers[$key] ?? null;
	}

	public function getHost(): string
	{
		return $this->host;
	}

	public function getClientIp(): ?string
	{
		return $this->clientIp;
	}
}
