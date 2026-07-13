<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\MailTemplate;

class MailTemplateTest extends TestCase
{
	private MailTemplate $template;

	protected function setUp(): void
	{
		// Reálné šablony modulu core.system — testuje se i jejich obsah.
		$this->template = new MailTemplate(
			dirname(__DIR__, 4) . '/modules/core/system/mail',
		);
	}

	private function vars(): array
	{
		return [
			'full_name' => 'Jan Novák',
			'login'     => 'jan',
			'ds_name'   => 'Moje firma',
			'link'      => 'https://example.com/app/?auth_action=set-password&token=tok123',
			'ttl'       => '1 hodinu',
		];
	}

	public function testRendersCzechResetTemplate(): void
	{
		$rendered = $this->template->render('reset', 'cs', $this->vars());

		$this->assertSame('Obnovení hesla — Moje firma', $rendered['subject']);
		$this->assertStringNotContainsString('Subject:', $rendered['text']);
		foreach (['text', 'html'] as $part) {
			$this->assertStringContainsString('Jan Novák', $rendered[$part]);
			$this->assertStringContainsString('token=tok123', $rendered[$part]);
			$this->assertStringContainsString('1 hodinu', $rendered[$part]);
			// Žádný nenahrazený placeholder.
			$this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered[$part]);
		}
	}

	public function testRendersAllTemplateVariants(): void
	{
		foreach (['cs', 'en'] as $lang) {
			foreach (['reset', 'invite'] as $name) {
				$rendered = $this->template->render($name, $lang, $this->vars());
				$this->assertNotSame('', $rendered['subject'], "{$lang}/{$name}");
				$this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered['text'], "{$lang}/{$name}");
				$this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered['html'], "{$lang}/{$name}");
			}
		}
	}

	public function testUnknownLanguageFallsBackToEnglish(): void
	{
		$rendered = $this->template->render('invite', 'de', $this->vars());

		$this->assertSame('Invitation to Moje firma', $rendered['subject']);
	}

	public function testMissingTemplateThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->template->render('nonexistent', 'cs', $this->vars());
	}
}
