<?php

declare(strict_types=1);

namespace PHPStanCompiler\Compiler;

use Nette\Utils\Strings;
use Phar;
use Seld\PharUtils\Timestamps;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class PharPackager
{

	/** @var \Symfony\Component\Console\Output\OutputInterface */
	private $out;

	public function __construct(OutputInterface $output)
	{
		$this->out = $output;
	}

	public function package(string $buildDir, string $pharFile, array $phpstanDependencies, string $versionDate): void
	{
		$this->out->writeln("\nBuilding phpstan.phar");

		$phar = new Phar($pharFile, 0, 'phpstan.phar');
		$phar->setSignatureAlgorithm(\Phar::SHA1);
		$phar->startBuffering();

		$finder = new Finder();
		$finder->files()
			->ignoreVCS(true)
			->name('*')
			->in($buildDir)
			->sort(self::finderSort());

		$progress = new ProgressBar($this->out);
		if (!$this->out->isVerbose()) {
			$progress->start(count($finder));
			$progress->setRedrawFrequency($progress->getMaxSteps() / 10);
		}
		foreach ($finder as $file) {
			if (!$this->out->isVerbose()) {
				$progress->advance();
			} else {
				$this->out->writeln(sprintf('Adding %s', $file->getRelativePathname()));
			}
			$this->addFile($phar, $file);
		}
		if (!$this->out->isVerbose()) {
			$progress->finish();
			$this->out->writeln('');
		}

		$this->out->writeln('Adding bin/phpstan');
		$this->addPhpStanBin($phar, $buildDir);

		// Stubs
		$this->out->writeln('Setting stub');
		$phar->setStub($this->getStub());
		$phar->stopBuffering();
		unset($phar);

		// re-sign the phar with reproducible timestamp / signature
		$util = new Timestamps($pharFile);
		$util->updateTimestamps($versionDate);
		$util->save($pharFile, \Phar::SHA1);

		$this->out->writeln('Phar generated');
	}

	private function addFile(Phar $phar, SplFileInfo $file, bool $strip = true): void
	{
		$realPath = $file->getRealPath();
		$content = file_get_contents($realPath);

		if ('LICENSE' === basename($realPath)) {
			$content = "\n" . $content . "\n";
		}

		$phar->addFromString($file->getRelativePathname(), $content);
	}

	private function addPhpStanBin(Phar $phar, string $dir): void
	{
		$content = file_get_contents($dir . '/bin/phpstan');
		$content = preg_replace('~^#!/usr/bin/env php\s*~', '', $content);
		$content = preg_replace("~__DIR__\\s*\\.\\s*'\\/\\.\\.\\/~", "'phar://phpstan.phar/", $content);
		$phar->addFromString('bin/phpstan', $content);
	}

	private function getStub(): string
	{
		return <<<'EOF'
#!/usr/bin/env php
<?php
Phar::mapPhar('phpstan.phar');
require 'phar://phpstan.phar/bin/phpstan';
__HALT_COMPILER();
EOF;
	}

	private static function finderSort(): \Closure
	{
		return function (\SplFileInfo $a, \SplFileInfo $b): int {
			return strcmp(strtr($a->getRealPath(), '\\', '/'), strtr($b->getRealPath(), '\\', '/'));
		};
	}

}
