<?php declare(strict_types=1);

namespace PHPStanCompiler\Console;

use PHPStanCompiler\Compiler\Compiler;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CompileCommand extends \Symfony\Component\Console\Command\Command
{

	const NAME = 'compile';
	const ARGUMENT_VERSION = 'version';
	const OPTION_NO_EXTENSIONS = 'no-extensions';
	const OPTION_REPOSITORY = 'repository';

	protected function configure(): void
	{
		$this->setName(self::NAME)
			->setDescription('Compile executable phar')
			->addArgument(self::ARGUMENT_VERSION, InputArgument::OPTIONAL, 'Executable reference to build')
			->addOption(self::OPTION_NO_EXTENSIONS, null, InputOption::VALUE_NONE, 'Should the phar include extensions?')
			->addOption(self::OPTION_REPOSITORY, null, InputOption::VALUE_REQUIRED, 'For building from fork', 'https://github.com/phpstan/phpstan.git');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$compiler = new Compiler($output);
		$compiler->compile(
			$input->getArgument(self::ARGUMENT_VERSION),
			$input->getOption(self::OPTION_NO_EXTENSIONS),
			$input->getOption(self::OPTION_REPOSITORY)
		);

		return 0;
	}

}
