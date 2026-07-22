<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;

class AccountResolverTest extends TestCase
{
    public function testResolvesNumberInLinkableStatesWithArchivePreference(): void
    {
        // Dohledání jede přes LINKABLE_STATES (archiv 70 povolen, smazaný 90
        // ne) a při duplicitě čísla preferuje nearchivní řádek.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('economy_accounting_accounts'),
                    $this->stringContains('ORDER BY [docState] = 70'),
                ),
                '221101',
                [10, 40, 70, 80],
            )
            ->willReturn(new Row(['id' => 42]));

        $this->assertSame(42, (new AccountResolver($db))->resolve('221101'));
    }

    public function testUnknownNumberReturnsNull(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')->willReturn(null);

        $this->assertNull((new AccountResolver($db))->resolve('999999'));
    }

    public function testEmptyNumberReturnsNullWithoutQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $this->assertNull((new AccountResolver($db))->resolve('   '));
    }

    public function testPerRunCacheQueriesOnce(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')->willReturn(new Row(['id' => 7]));

        $resolver = new AccountResolver($db);
        $this->assertSame(7, $resolver->resolve('518100'));
        $this->assertSame(7, $resolver->resolve('518100'));
    }
}
