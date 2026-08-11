<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Server\DomainsFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends Command
{
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

		$domainsFile    = DomainsFile::effectiveDomainsFile($this->domainsFile);
		$dataSourcesDir = DomainsFile::effectiveDataSourcesDir($this->dataSourcesDir);

		// Validate DS exists
		$dsDir = $dataSourcesDir . '/' . $dsId;
		if (!is_dir($dsDir) || !file_exists($dsDir . '/config/main.json')) {
			$output->writeln("<error>Data source '{$dsId}' does not exist</error>");
			return Command::FAILURE;
		}

		// Selhání čtení/zápisu = FAILURE s hláškou — agent hosting-sync pak
		// korektně confirmne failed, místo dřívějšího tichého SUCCESS
		// s nezapsanou doménou (nález č. 3 z adopce).
		try {
			$map = $this->loadDomainsFile($domainsFile);

			if (isset($map[$host])) {
				// Idempotence (D3): stejný host → stejný DS je no-op, agent
				// hosting-sync smí krok bezpečně opakovat. Jiný DS zůstává chybou.
				if ($map[$host] === $dsId) {
					$output->writeln("<info>Already mapped:</info> <comment>{$host}</comment> → <comment>{$dsId}</comment>");
					return Command::SUCCESS;
				}
				$output->writeln("<error>Host '{$host}' is already mapped to data source '{$map[$host]}'</error>");
				return Command::FAILURE;
			}

			$map[$host] = $dsId;
			$this->saveDomainsFile($domainsFile, $map);
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln("<info>Added:</info> <comment>{$host}</comment> → <comment>{$dsId}</comment>");
		return Command::SUCCESS;
	}

	protected function loadDomainsFile(string $path): array
	{
		return DomainsFile::load($path);
	}

	protected function saveDomainsFile(string $path, array $map): void
	{
		DomainsFile::save($path, $map);
	}
}
