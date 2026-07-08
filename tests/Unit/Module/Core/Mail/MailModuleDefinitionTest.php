<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleLoader;

/**
 * Guards declarative contracts of the real core.mail module.jsonc that
 * runtime code silently depends on. `keepOnReset` protects AI profiles
 * across `ds-reset` — without it a reset wipes the profile and the
 * analyzer fails with NO_PROFILE until someone runs a manual bootstrap.
 */
class MailModuleDefinitionTest extends TestCase
{
    public function testAiProfilesSurviveReset(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . '/modules/core/mail');

        $this->assertSame('core.mail', $module->id);
        $this->assertContains('core_mail_ai_profiles', $module->keepOnReset);
    }
}
