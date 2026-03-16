<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends Command
{
	private const DATA_SOURCES_DIR = '/opt/shipard/data-sources';
	private const DOMAINS_FILE     = '/etc/shipard/domains.json';

	public function __construct(
		private readonly ?string $domainsFile     = null,
		private readonly ?string $dataSourcesDir  = null,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('domain-add')
			->setDescription('Add a host → data source mapping')
			->addOption('host', null, InputOption::VALUE_REQUIRED, 'Hostname (e.g. firma1.shipard.cz)')
			->addOption('ds',   null, InputOption::VALUE_REQUIRED, 'Data source ID (e.g. a3f2-b8c1-d4e7-f9a0)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$host = $input->getOption('host');
		$dsId = $input->getOption('ds');

		if (empty($host)) {
			$output->writeln('<error>Option --host is required</error>');
			return Command::FAILURE;
		}

		if (empty($dsId)) {
			$output->writeln('<error>Option --ds is required</error>');
			return Command::FAILURE;
		}

		$domainsFile    = $this->domainsFile    ?? self::DOMAINS_FILE;
		$dataSourcesDir = $this->dataSourcesDir ?? self::DATA_SOURCES_DIR;

		// Validate DS exists
		$dsDir = $dataSourcesDir . '/' . $dsId;
		if (!is_dir($dsDir) || !file_exists($dsDir . '/config/main.json')) {
			$output->writeln("<error>Data source '{$dsId}' does not exist</error>");
			return Command::FAILURE;
		}

		// Load existing domains (create empty if missing)
		$map = $this->loadDomainsFile($domainsFile);

		if (isset($map[$host])) {
			$output->writeln("<error>Host '{$host}' is already mapped to data source '{$map[$host]}'</error>");
			return Command::FAILURE;
		}

		$map[$host] = $dsId;

		$this->saveDomainsFile($domainsFile, $map);

		$output->writeln("<info>Added:</info> <comment>{$host}</comment> → <comment>{$dsId}</comment>");
		return Command::SUCCESS;
	}

	protected function loadDomainsFile(string $path): array
	{
		if (!file_exists($path)) {
			return [];
		}

		$content = file_get_contents($path);
		$data    = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
			throw new \RuntimeException("Invalid JSON in domains file: {$path}");
		}

		return $data;
	}

	protected function saveDomainsFile(string $path, array $map): void
	{
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	}
}
