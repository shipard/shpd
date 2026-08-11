<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Server\DomainsFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainRemoveCommand extends Command
{
	public function __construct(
		private readonly ?string $domainsFile = null,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('domain-remove')
			->setDescription('Remove a host → data source mapping')
			->addOption('host', null, InputOption::VALUE_REQUIRED, 'Hostname to remove');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$host = $input->getOption('host');

		if (empty($host)) {
			$output->writeln('<error>Option --host is required</error>');
			return Command::FAILURE;
		}

		$domainsFile = DomainsFile::effectiveDomainsFile($this->domainsFile);

		if (!file_exists($domainsFile)) {
			$output->writeln("<error>Host '{$host}' not found (domains file does not exist)</error>");
			return Command::FAILURE;
		}

		// Selhání čtení/zápisu = FAILURE s hláškou, nikdy tichý SUCCESS
		// (stejné hardening jako domain-add, nález č. 3 z adopce).
		try {
			$map = DomainsFile::load($domainsFile);

			if (!isset($map[$host])) {
				$output->writeln("<error>Host '{$host}' not found in domains file</error>");
				return Command::FAILURE;
			}

			$dsId = $map[$host];
			unset($map[$host]);

			$this->saveDomainsFile($domainsFile, $map);
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln("<info>Removed:</info> <comment>{$host}</comment> (was → <comment>{$dsId}</comment>)");
		return Command::SUCCESS;
	}

	protected function saveDomainsFile(string $path, array $map): void
	{
		DomainsFile::save($path, $map);
	}
}
