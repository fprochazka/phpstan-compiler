<?php declare(strict_types=1);

namespace PHPStanCompiler\Compiler;

use Nette\Neon\Entity;
use Nette\Neon\Neon;
use Nette\Utils\Strings;
use PHPStanCompiler\Parser\PhpParser;
use PHPStanCompiler\Parser\PhpTokensIterator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class AutoPrefixer
{

	const PHPSTAN_NAMESPACE = 'PHPStan';
	const VENDOR_PREFIX = 'PHPStanVendor';

	/** @var \Symfony\Component\Console\Output\OutputInterface */
	private $out;

	/** @var \PHPStanCompiler\Parser\PhpParser */
	private $phpParser;

	public function __construct(OutputInterface $output)
	{
		$this->out = $output;
		$this->phpParser = new PhpParser();
	}

	public function prefix(string $projectDir, array $phpstanDependencies, array $phpstanForcePrefixNamespaces, array $notPrefixedExtensionNamespaces): void
	{
		$this->out->writeln("\nPrefixing vendor");

		$prefixClasses = $this->getPrefixClasses($projectDir, $phpstanDependencies);

		$phpStanPhpFiles = $this->findPhpStanPhpFiles($projectDir, $phpstanDependencies);
		$phpStanNeonFiles = $this->findPhpStanNeonFiles($projectDir, $phpstanDependencies);

		$progress = new ProgressBar($this->out);
		if (!$this->out->isVerbose()) {
			$progress->start(count($phpStanPhpFiles) + count($phpStanNeonFiles));
			$progress->setRedrawFrequency($progress->getMaxSteps() / 10);
		}
		foreach ($phpStanPhpFiles as $file) {
			if (!$this->out->isVerbose()) {
				$progress->advance();
			} else {
				$this->out->writeln(sprintf('Prefixing <info>%s</info>', $file->getRelativePathname()));
			}
			$this->prefixPhpFile($file->getPathname(), $prefixClasses, $phpstanForcePrefixNamespaces, []);
		}

		foreach ($phpStanNeonFiles as $file) {
			if (!$this->out->isVerbose()) {
				$progress->advance();
			} else {
				$this->out->writeln(sprintf('Prefixing <info>%s</info>', $file->getRelativePathname()));
			}
			$this->prefixNeonFile($file->getPathname(), $prefixClasses);
		}
		if (!$this->out->isVerbose()) {
			$progress->finish();
			$this->out->writeln('');
		}

		foreach ($notPrefixedExtensionNamespaces as $package => $notPrefixedNamespaces) {
			$this->out->writeln(sprintf("\nPrefixing %s", $package));
			$packagePhpFiles = $this->findAllPhpFiles($projectDir . '/' . ($vendorPackagePrefix = 'vendor/' . $package . '/'));
			if (!$this->out->isVerbose()) {
				$progress->start(count($packagePhpFiles));
				$progress->setRedrawFrequency($progress->getMaxSteps() / 10);
			}
			foreach ($packagePhpFiles as $file) {
				if (!$this->out->isVerbose()) {
					$progress->advance();
				} else {
					$this->out->writeln(sprintf('Prefixing <info>%s</info>', $vendorPackagePrefix . $file->getRelativePathname()));
				}
				$this->prefixPhpFile($file->getPathname(), $prefixClasses, [], $notPrefixedNamespaces);
			}
			if (!$this->out->isVerbose()) {
				$progress->finish();
				$this->out->writeln('');
			}
		}
	}

	private function prefixPhpFile(string $file, array $prefixClasses, array $forcePrefixNamespaces, array $forceIgnoreNamespaces): void
	{
		$orig = file_get_contents($file);

		$typeChecker = function (string $fullTypeName) use ($prefixClasses, $forcePrefixNamespaces): bool {
			if (array_key_exists($fullTypeName, $prefixClasses)) {
				return true;
			}

			foreach ($forcePrefixNamespaces as $namespace) {
				if (Strings::startsWith($fullTypeName, $namespace)) {
					return true;
				}
			}

			return false;
		};

		try {
			$parser = $this->phpParser->parse($orig, $typeChecker);
		} catch (\ParseError $e) {
			throw new \RuntimeException(sprintf("File %s cannot be parsed: %s", $file, $e->getMessage()), 0, $e);
		}

		while (($token = $parser->nextToken()) !== null) {
			if ($parser->isCurrent(T_IMPORT)) {
				foreach ($token[PhpTokensIterator::IMPORTS] as $import) {
					if (Strings::startsWith($import, self::PHPSTAN_NAMESPACE . '\\')) {
						continue 2;
					}
				}

				$parser->dropCurrent();
				if ($parser->isCurrent(T_WHITESPACE)) {
					$parser->dropCurrent(); // drop newline
				}

			} elseif ($parser->isCurrent(T_NAMESPACE_NAME)) {
				$namespaceName = $parser->currentValue();
				if ($namespaceName !== self::PHPSTAN_NAMESPACE && !Strings::startsWith($namespaceName, self::PHPSTAN_NAMESPACE . '\\')) {
					$parser->replaceCurrentValue(self::VENDOR_PREFIX . '\\' . $namespaceName);
				}

			} elseif ($parser->isCurrent(T_TYPE_REFERENCE)) {
				$fullTypeName = ltrim($token[PhpTokensIterator::FULL_TYPE_REFERENCE], '\\');
				$parser->replaceCurrentValue($this->phpParser->escapeTokenValue('\\' . $fullTypeName, $token));

				foreach ($forceIgnoreNamespaces as $namespace) {
					if (Strings::startsWith($fullTypeName, $namespace)) {
						continue 2;
					}
				}

				if (array_key_exists($fullTypeName, $prefixClasses)) {
					$parser->replaceCurrentValue($this->phpParser->escapeTokenValue('\\' . self::VENDOR_PREFIX . '\\' . $fullTypeName, $token));

				} else {
					foreach ($forcePrefixNamespaces as $namespace) {
						if (Strings::startsWith($fullTypeName, $namespace)) {
							$parser->replaceCurrentValue($this->phpParser->escapeTokenValue('\\' . self::VENDOR_PREFIX . '\\' . $fullTypeName, $token));
							continue 2;
						}
					}
				}
			}
		}

		$s = $parser->reset()->joinAll();

		if ($s !== $orig) {
			file_put_contents($file, $s);
		}
	}

	private function prefixNeonFile(string $file, array $prefixClasses): void
	{
		$orig = file_get_contents($file);

		$data = Neon::decode($orig);

		array_walk_recursive($data, $walker = function (&$value, string $key) use ($prefixClasses, &$walker): void {
			if ($value instanceof Entity) {
				$walker($value->value, 'value');
				array_walk_recursive($value->attributes, $walker);

			} elseif (is_string($value)) {
				if ($key === 'class') { // service type
					$fullTypeName = ltrim($value, '\\');
					if (array_key_exists($fullTypeName, $prefixClasses)) {
						$value = '\\' . self::VENDOR_PREFIX . '\\' . $fullTypeName;
					}

				} elseif ($m = Strings::match($value, '~^\\@([^:\\s]+)(.*)\\z~')) { // type or service reference
					$fullTypeName = ltrim($m[1], '\\');
					if (array_key_exists($fullTypeName, $prefixClasses)) {
						$value = '@\\' . self::VENDOR_PREFIX . '\\' . $fullTypeName . $m[2];
					}
				}
			}
		});

		$s = Neon::encode($data, Neon::BLOCK);

		if ($s !== $orig) {
			file_put_contents($file, $s);
		}
	}

	private function findPhpStanPhpFiles(string $dir, array $phpstanDependencies): Finder
	{
		$finder = new Finder();
		$finder->files()
			->ignoreVCS(true)
			->name('*.php')
			->name('phpstan')
			->in($dir)
			->filter(self::filterPhpstanDependencies($phpstanDependencies));

		return $finder;
	}

	private function findAllPhpFiles(string $packageDir): Finder
	{
		$finder = new Finder();
		$finder->files()
			->ignoreVCS(true)
			->name('*.php')
			->in($packageDir);

		return $finder;
	}

	private function findPhpStanNeonFiles(string $dir, array $phpstanDependencies): Finder
	{
		$finder = new Finder();
		$finder->files()
			->ignoreVCS(true)
			->name('*.neon')
			->in($dir)
			->filter(self::filterPhpstanDependencies($phpstanDependencies));

		return $finder;
	}

	private function getPrefixClasses(string $projectDir, array $phpstanDependencies): array
	{
		$allClasses = require $projectDir . '/vendor/composer/autoload_classmap.php';

		return array_filter(
			$allClasses,
			function (string $file) use ($phpstanDependencies): bool {
				foreach ($phpstanDependencies as $dependency) {
					if (Strings::contains($file, 'vendor/' . $dependency . '/')) {
						return true;
					}
				}

				return false;
			}
		);
	}

	private static function filterPhpstanDependencies($phpstanDependencies): \Closure
	{
		return function (SplFileInfo $file) use ($phpstanDependencies): bool {
			if (!Strings::startsWith($file->getRelativePathname(), 'vendor/')) {
				return true;
			}

			foreach ($phpstanDependencies as $dependency) {
				if (Strings::startsWith($file->getRelativePathname(), 'vendor/' . $dependency . '/')) {
					return true;
				}
			}

			return false;
		};
	}

}
