<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends Command
{
	private const DATA_SOURCES_DIR = '/opt/shipard/data-sources';
	private const DOMAINS_FILE     = '/etc/shipard/domains.json';

	public function __construct(
		private readonly ?string $domainsFile    = null,
		private readonly ?string $dataSourcesDir = null,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('domain-list')
			->setDescription('List all host → data source mappings');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$domainsFile    = $this->domainsFile    ?? self::DOMAINS_FILE;
		$dataSourcesDir = $this->dataSourcesDir ?? self::DATA_SOURCES_DIR;

		if (!file_exists($domainsFile)) {
			$output->writeln('<comment>No domains file found. Use domain-add to create mappings.</comment>');
			return Command::SUCCESS;
		}

		$content = file_get_contents($domainsFile);
		$map     = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($map)) {
			$output->writeln('<error>Invalid JSON in domains file</error>');
			return Command::FAILURE;
		}

		if (empty($map)) {
			$output->writeln('<comment>No domain mappings configured.</comment>');
			return Command::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['Host', 'DS ID', 'DS Name']);

		foreach ($map as $host => $dsId) {
			$dsName = $this->resolveDsName($dataSourcesDir, $dsId);
			$table->addRow([$host, $dsId, $dsName]);
		}

		$table->render();
		return Command::SUCCESS;
	}

	protected function resolveDsName(string $dataSourcesDir, string $dsId): string
	{
		$configFile = $dataSourcesDir . '/' . $dsId . '/config/main.json';
		if (!file_exists($configFile)) {
			return '<unknown>';
		}

		$data = json_decode(file_get_contents($configFile), true);
		return $data['name'] ?? '<unknown>';
	}
}
