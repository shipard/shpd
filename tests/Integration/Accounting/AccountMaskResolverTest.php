<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Module\Economy\Accounting\AccountMaskResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Maskové dohledání nad reálným dev DS — linkable states: archivní účet (70)
 * je jen fallback za aktivními (i číselně vyšší aktivní vyhrává), smazaný
 * (90) se nedohledá nikdy. Dočasné účty pod alfanumerickým prefixem 899IT
 * (v reálném rozvrhu neexistuje), úklid v tearDown.
 */
class AccountMaskResolverTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';
    private const MASK = '899IT';

    /** @var list<int> */
    private array $createdAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $existing = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number LIKE %s LIMIT 1',
            self::MASK . '%',
        );
        if ($existing !== null) {
            $this->markTestSkipped('Dev DS už má účty ' . self::MASK . '* — prefix testu není volný');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdAccounts as $id) {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
        }
    }

    private function insertAccount(string $number, int $docState, int $docStateMain): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accounting_accounts', array_merge(
            AccountDocument::deriveStructure($number),
            [
                'number'       => $number,
                'name'         => "IT test účet {$number}",
                'short_name'   => $number,
                'account_kind' => 1,
                'docState'     => $docState,
                'docStateMain' => $docStateMain,
            ],
        ))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdAccounts[] = $id;
        return $id;
    }

    /** @return array{id: int, number: string}|null Čerstvá instance — resolver má per-instance cache. */
    private function resolve(): ?array
    {
        return (new AccountMaskResolver($this->db->getDibiConnection()))
            ->resolve(self::MASK, self::ACC_DATE);
    }

    public function testActivePreferredOverNumericallyLowerArchived(): void
    {
        $this->insertAccount(self::MASK . '100', 70, 4);
        $activeId = $this->insertAccount(self::MASK . '200', 40, 3);

        $found = $this->resolve();

        $this->assertNotNull($found);
        $this->assertSame(self::MASK . '200', $found['number'], 'Aktivní účet má přednost i když je číselně vyšší');
        $this->assertSame($activeId, $found['id']);
    }

    public function testArchivedIsFallbackWhenNoActiveMatches(): void
    {
        $archivedId = $this->insertAccount(self::MASK . '100', 70, 4);

        $found = $this->resolve();

        $this->assertNotNull($found, 'Archivní účet se musí dohledat jako fallback');
        $this->assertSame($archivedId, $found['id']);
    }

    public function testDeletedNeverResolves(): void
    {
        $this->insertAccount(self::MASK . '100', 90, 5);

        $this->assertNull($this->resolve(), 'Smazaný účet (90) se nesmí dohledat');
    }
}
