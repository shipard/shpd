<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainRemoveCommand extends Command
{
	private const DOMAINS_FILE = '/etc/shipard/domains.json';

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

		$domainsFile = $this->domainsFile ?? self::DOMAINS_FILE;

		if (!file_exists($domainsFile)) {
			$output->writeln("<error>Host '{$host}' not found (domains file does not exist)</error>");
			return Command::FAILURE;
		}

		$content = file_get_contents($domainsFile);
		$map     = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($map)) {
			$output->writeln('<error>Invalid JSON in domains file</error>');
			return Command::FAILURE;
		}

		if (!isset($map[$host])) {
			$output->writeln("<error>Host '{$host}' not found in domains file</error>");
			return Command::FAILURE;
		}

		$dsId = $map[$host];
		unset($map[$host]);

		$this->saveDomainsFile($domainsFile, $map);

		$output->writeln("<info>Removed:</info> <comment>{$host}</comment> (was → <comment>{$dsId}</comment>)");
		return Command::SUCCESS;
	}

	protected function saveDomainsFile(string $path, array $map): void
	{
		// Atomicky (tmp + rename) — soubor čte DataSourceResolver při každém
		// HTTP requestu, roztržený zápis by položil živý provoz.
		$tmp = $path . '.tmp';
		file_put_contents($tmp, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		rename($tmp, $path);
	}
}
