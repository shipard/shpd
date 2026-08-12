<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\Checks\MissingOwnBankAccountCheck;

class MissingOwnBankAccountCheckTest extends TestCase
{
    private function makeCheck(int $accountCount): MissingOwnBankAccountCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn($accountCount);

        return new MissingOwnBankAccountCheck(
            $db,
            $this->createMock(ConfigRuntime::class),
            'cs',
        );
    }

    public function testNoActiveAccountFires(): void
    {
        $findings = $this->makeCheck(0)->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('', $f->findingKey);
        $this->assertSame('warning', $f->severity);
        $this->assertSame('Chybí vlastní bankovní účet', $f->title);
        $this->assertSame('open_form', $f->actions[0]['kind']);
        $this->assertSame('economy_codebooks_bank_accounts', $f->actions[0]['target']['table']);
        $this->assertSame('create', $f->actions[0]['target']['mode']);
    }

    public function testActiveAccountIsSilent(): void
    {
        $this->assertSame([], $this->makeCheck(1)->run());
    }
}
