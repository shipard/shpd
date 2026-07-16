<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Registry;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\RegistryController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Shipard\Module\Base\Registry\FileFromMessageService;
use Shipard\Module\Base\Registry\RegistryImportService;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí `POST /_registry/import` — import dokumentu Spisovny
 * z migračního runneru (`wkf.docs`, design §10). Controller volaný přímo
 * se skutečnou DB; HTTP vrstva (router, auth) je pokrytá unit testy.
 *
 * Klíčové kontrakty: zachované historické `created`, cílový `docState`
 * s centrálně odvozeným `docStateMain`, idempotence podle `legacy.ndx`,
 * `BINDER_NOT_FOUND` warning bez zakládání šanonu.
 *
 * Testy si po sobě uklízí (prefix `IT-REGIMP:` v názvech).
 */
class RegistryImportEndpointTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-REGIMP:';

    private DocumentRegistry $documentRegistry;
    private ?ConfigRuntime $config = null;

    /** @var list<int> */
    private array $createdDocumentIds = [];
    /** @var list<int> */
    private array $createdBinderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $resolver);

        try {
            $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        } catch (\Throwable) {
            $this->config = null;
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdDocumentIds as $id) {
            $dibi->delete('base_registry_documents')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdBinderIds as $id) {
            $dibi->delete('base_registry_binders')->where('id = %i', $id)->execute();
        }
        // Pojistka pro testy padlé před zaevidováním id — dedupe by jinak
        // v dalším běhu vracel leftover dokumenty.
        $dibi->delete('base_registry_documents')->where('title LIKE %like~', self::PREFIX)->execute();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testImportCreatesDocumentWithHistoricCreatedAndTargetState(): void
    {
        $response = $this->invoke($this->payload([
            'docState' => 40,
            'created' => '2013-09-10T14:21:30+02:00',
        ]));

        $this->assertResponseStatus(201, $response);
        $payload = $response->getPayload();
        $id = (int) $payload['data']['id'];
        $this->createdDocumentIds[] = $id;
        $this->assertGreaterThan(0, $id);
        $this->assertArrayNotHasKey('existed', $payload['data']);

        $row = $this->db->fetchRow('SELECT * FROM base_registry_documents WHERE id = %i', $id);
        $this->assertNotNull($row);
        $this->assertSame(self::PREFIX . ' Smlouva o dílo', $row['title']);
        $this->assertSame('contract', $row['doc_kind']);
        $this->assertSame('import', $row['source_kind']);
        $this->assertNull($row['source_message']);
        $this->assertNull($row['created_by']);
        $this->assertSame(40, (int) $row['docState']);
        $this->assertSame(3, (int) $row['docStateMain'], 'docStateMain odvozený centrálně (40 → 3)');

        $created = $row['created'] instanceof \DateTimeInterface
            ? $row['created']->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s', (int) strtotime((string) $row['created']));
        $this->assertSame(
            date('Y-m-d H:i:s', (int) strtotime('2013-09-10T14:21:30+02:00')),
            $created,
            'historické created zachováno, audit hook ho nepřepsal',
        );
    }

    public function testImportWithDocState70AndValidity(): void
    {
        $response = $this->invoke($this->payload([
            'docState' => 70,
            'validFrom' => '2019-01-01',
            'validTo' => '2020-12-31',
            'legacy' => ['ndx' => 910702],
        ]));

        $this->assertResponseStatus(201, $response);
        $id = (int) $response->getPayload()['data']['id'];
        $this->createdDocumentIds[] = $id;

        $row = $this->db->fetchRow('SELECT * FROM base_registry_documents WHERE id = %i', $id);
        $this->assertSame(70, (int) $row['docState']);
        $this->assertSame(4, (int) $row['docStateMain'], 'docStateMain odvozený centrálně (70 → 4)');
        $this->assertSame('2019-01-01', $this->asDate($row['valid_from']));
        $this->assertSame('2020-12-31', $this->asDate($row['valid_to']));
    }

    public function testImportIsIdempotentByLegacyNdx(): void
    {
        $first = $this->invoke($this->payload());
        $this->assertResponseStatus(201, $first);
        $id = (int) $first->getPayload()['data']['id'];
        $this->createdDocumentIds[] = $id;

        $second = $this->invoke($this->payload(['title' => self::PREFIX . ' jiný název']));
        $this->assertResponseStatus(200, $second);
        $data = $second->getPayload()['data'];
        $this->assertSame($id, (int) $data['id']);
        $this->assertTrue($data['existed']);

        $count = $this->db->fetchRow(
            'SELECT COUNT(*) AS cnt FROM base_registry_documents'
            . ' WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, %s)) = %s AND source_kind = %s',
            '$.legacyNdx', (string) $this->payload()['legacy']['ndx'], 'import',
        );
        $this->assertSame(1, (int) $count['cnt'], 'druhé volání nic nezapsalo');

        $row = $this->db->fetchRow('SELECT title FROM base_registry_documents WHERE id = %i', $id);
        $this->assertSame(self::PREFIX . ' Smlouva o dílo', $row['title'], 'existující dokument beze změn');
    }

    public function testImportResolvesBinderCaseInsensitive(): void
    {
        $binderId = $this->createBinder(self::PREFIX . ' Smlouvy');

        $response = $this->invoke($this->payload([
            'binder' => mb_strtolower(self::PREFIX . ' Smlouvy'),
        ]));

        $this->assertResponseStatus(201, $response);
        $payload = $response->getPayload();
        $id = (int) $payload['data']['id'];
        $this->createdDocumentIds[] = $id;
        $this->assertArrayNotHasKey('warning', $payload['data']);

        $row = $this->db->fetchRow('SELECT binder FROM base_registry_documents WHERE id = %i', $id);
        $this->assertSame($binderId, (int) $row['binder']);
    }

    public function testImportUnknownBinderYieldsWarningAndNullBinder(): void
    {
        $response = $this->invoke($this->payload([
            'binder' => self::PREFIX . ' neexistující šanon',
        ]));

        $this->assertResponseStatus(201, $response);
        $payload = $response->getPayload();
        $id = (int) $payload['data']['id'];
        $this->createdDocumentIds[] = $id;
        $this->assertSame('BINDER_NOT_FOUND', $payload['data']['warning']);

        $row = $this->db->fetchRow('SELECT binder FROM base_registry_documents WHERE id = %i', $id);
        $this->assertNull($row['binder']);

        $binder = $this->db->fetchRow(
            'SELECT id FROM base_registry_binders WHERE name = %s',
            self::PREFIX . ' neexistující šanon',
        );
        $this->assertNull($binder, 'endpoint šanony nezakládá');
    }

    public function testImportStoresCompleteLegacyBlockInMetadata(): void
    {
        $response = $this->invoke($this->payload([
            'notice' => 'Poznámka ze starého systému',
            'legacy' => [
                'ndx' => 910705,
                'id' => 'SML-001',
                'kind' => 'Smlouva',
                'author' => 'Jana Nováková',
                'folder' => 'Mzdy / 1999',
            ],
        ]));

        $this->assertResponseStatus(201, $response);
        $id = (int) $response->getPayload()['data']['id'];
        $this->createdDocumentIds[] = $id;

        $row = $this->db->fetchRow('SELECT metadata, notice FROM base_registry_documents WHERE id = %i', $id);
        $metadata = json_decode((string) $row['metadata'], true);
        $this->assertSame([
            'legacyNdx' => 910705,
            'legacyId' => 'SML-001',
            'legacyKind' => 'Smlouva',
            'legacyAuthor' => 'Jana Nováková',
            'legacyFolder' => 'Mzdy / 1999',
        ], $metadata);
        $this->assertSame('Poznámka ze starého systému', $row['notice']);
    }

    public function testImportValidationErrorsReturn422(): void
    {
        if ($this->config === null) {
            $this->markTestSkipped('DS is missing compiled config — docKind validation needs cfgItems.');
        }

        $cases = [
            ['docKind' => 'no-such-kind-xyz'],
            ['title' => '  '],
            ['docState' => 55],
            ['created' => 'not-a-date'],
            ['legacy' => ['ndx' => 0]],
        ];
        foreach ($cases as $override) {
            $response = $this->invoke($this->payload($override));
            $this->assertResponseStatus(422, $response);
            $this->assertSame(
                'VALIDATION_ERROR',
                $response->getPayload()['error']['code'],
                'case: ' . json_encode($override),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'schema' => 'shpd.registry.document.v1',
            'docKind' => 'contract',
            'title' => self::PREFIX . ' Smlouva o dílo',
            'docState' => 40,
            'created' => '2013-09-10T14:21:30+02:00',
            'legacy' => ['ndx' => 910701, 'kind' => 'Smlouva'],
        ], $override);
    }

    private function invoke(array $body): \Shipard\Api\Response
    {
        $attachments = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $ctrl = new RegistryController(
            new FileFromMessageService($this->db, $this->documentRegistry, $attachments, $this->config),
            new ExtractedTextFiller($this->db, $attachments),
            $this->db,
            new RegistryImportService(
                $this->db,
                $this->documentRegistry,
                $this->tables['base_registry_documents'] ?? null,
                $this->config,
            ),
        );
        $auth = new AuthContext(true, 1, 'api_key', 'shpd_ak_importer');
        $request = Request::fromArray(
            'POST',
            '/_registry/import',
            [],
            (string) json_encode($body),
            ['HTTP_HOST' => 'test.local', 'REMOTE_ADDR' => '127.0.0.1'],
        );
        return $ctrl->import($auth, $request);
    }

    private function createBinder(string $name): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('base_registry_binders', [
            'name'         => $name,
            'docState'     => 40,
            'docStateMain' => 3,
            'created'      => date('Y-m-d H:i:s'),
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBinderIds[] = $id;
        return $id;
    }

    private function asDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (string) $value;
    }

    private function assertResponseStatus(int $expected, \Shipard\Api\Response $response): void
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        $actual = (int) $prop->getValue($response);
        if ($actual !== $expected) {
            $payload = $response->getPayload();
            $msg = is_array($payload) && isset($payload['error']['message'])
                ? $payload['error']['message']
                : json_encode($payload);
            $this->assertSame($expected, $actual, "Unexpected status with payload: {$msg}");
        } else {
            $this->assertSame($expected, $actual);
        }
    }
}
