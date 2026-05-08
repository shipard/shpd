<?php
declare(strict_types=1);

namespace Shipard\Api;

class Response
{
	private array $headers = [];
	private string $bodyType = 'json';

	private function __construct(
		private int $status,
		private mixed $payload,
	) {}

	public static function success(mixed $data, int $status = 200, ?array $meta = null): self
	{
		$payload = ['success' => true, 'data' => $data];
		if ($meta !== null) {
			$payload['meta'] = $meta;
		}
		return new self($status, $payload);
	}

	public static function raw(mixed $data, int $status = 200): self
	{
		return new self($status, $data);
	}

	public static function error(string $code, string $message, int $status, array $details = []): self
	{
		$error = ['code' => $code, 'message' => $message];
		if ($details !== []) {
			$error['details'] = $details;
		}
		return new self($status, ['success' => false, 'error' => $error]);
	}

	public static function html(string $body, int $status = 200): self
	{
		$resp = new self($status, $body);
		$resp->bodyType = 'html';
		return $resp;
	}

	public static function redirect(string $location, int $status = 302): self
	{
		$resp = new self($status, '');
		$resp->bodyType = 'redirect';
		$resp->headers['Location'] = $location;
		return $resp;
	}

	public function withHeader(string $name, string $value): static
	{
		$clone          = clone $this;
		$clone->headers[$name] = $value;
		return $clone;
	}

	public function getPayload(): mixed
	{
		return $this->payload;
	}

	public function getHeaders(): array
	{
		return $this->headers;
	}

	public function send(): void
	{
		foreach ($this->headers as $name => $value) {
			header("{$name}: {$value}");
		}

		http_response_code($this->status);

		if ($this->status === 204 || $this->bodyType === 'redirect') {
			return;
		}

		if ($this->bodyType === 'html') {
			header('Content-Type: text/html; charset=utf-8');
			echo $this->payload;
			return;
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
