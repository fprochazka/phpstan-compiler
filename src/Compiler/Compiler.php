<?php declare(strict_types=1);

namespace PHPStanCompiler\Compiler;

use Nette\Utils\Json;
use PHPStanCompiler\Executable;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Compiler
{

	const PATCHES_DIR = __DIR__ . '/../../patches';

	/**
	 * @var array package => [List of namespaces not to be prefixed]
	 */
	private static $extensions = [
		'phpstan/phpstan-doctrine' => [
			'Doctrine\\',
		],
		'phpstan/phpstan-guzzle' => [
			'GuzzleHttp\\',
			'Psr\\Http\\',
		],
		'phpstan/phpstan-nette' => [
			'Nette\\',
			'Tracy\\',
			'Latte\\',
		],
		'phpstan/phpstan-dibi' => [
			'Dibi\\',
		],
	];

	/** @var array */
	private static $forcePrefixPhpStanDependencyNamespaces = [
		'Nette\\',
		'Symfony\\',
		'Tracy\\',
	];

	/** @var array */
	private static $phpstanVendorPatchesRepository = [
		'type' => 'package',
		'package' => [
			'type' => 'metapackage',
			'name' => 'phpstan/package-patches',
			'version' => '1.0.0',
			'require' => [
				'netresearch/composer-patches-plugin' => '~1.0',
			],
			'extra' => [
				'patches' => [
					'nikic/php-parser' => [
						[
							'title' => 'Prepare for prefixing',
							'url' => self::PATCHES_DIR . '/nikic/php-parser/prefixing.patch',
						],
					],
				],
			],
		],
	];

	/** @var \Symfony\Component\Filesystem\Filesystem */
	private $fs;

	/** @var \Symfony\Component\Console\Output\OutputInterface */
	private $out;

	public function __construct(OutputInterface $output)
	{
		$this->fs = new Filesystem();
		$this->out = $output;
	}

	public function compile(
		?string $version,
		bool $noExtensions,
		string $phpStanRepository
	): void
	{
		$tempDir = dirname(__DIR__) . '/../tmp';
		$buildDir = $tempDir . '/build';

		if ($this->fs->exists($buildDir)) {
			$this->fs->remove($buildDir);
		}

		// clone
		$this->out->write((new Executable('git', $tempDir))->exec('clone', $phpStanRepository, 'build'));

		$git = new Executable('git', $buildDir);
		$composer = new Executable('composer', $buildDir);

		// checkout required version
		if ($version === null) {
			$version = trim($git->exec('describe --abbrev=0 --tags'));
		}
		$this->out->write($git->exec('checkout --force', $version));
		$commit = rtrim($git->exec('log --pretty="%H" -n1 HEAD'));

		// remove dev stuff
		$this->fs->remove($buildDir . '/tests');
		$this->fs->remove($buildDir . '/build');

		// fix composer.json
		$composerMeta = Json::decode(file_get_contents($buildDir . '/composer.json'), Json::FORCE_ARRAY);
		// remove dev dependencies (they create conflicts)
		unset($composerMeta['require-dev'], $composerMeta['autoload-dev']);
		// extra paranoid autoloader conflicts prevention
		$composerMeta['autoloader-suffix'] = 'PhpStanPhar' . $commit;

		// make sure dg/composer-cleaner doesn't remove important files
		foreach (self::$extensions as $extensionName => $_) {
			$composerMeta['config']['cleaner-ignore'][$extensionName] = [
				'extension.neon',
				'rules.neon',
			];
		}

		// configure patches
		$composerMeta['repositories'][] = self::$phpstanVendorPatchesRepository;

		// force classmap autoload of everything in vendor
		$composerMeta['autoload']['classmap'] = ['vendor'];
		file_put_contents($buildDir . '/composer.json', Json::encode($composerMeta, Json::PRETTY));

		// get list of phpstan dependencies
		$this->out->write($composer->exec('update --no-dev'));
		$phpstanDependencies = array_filter(preg_split("~[\n\r\t ]+~", $composer->exec('show --name-only')));

		// install extensions and do a cleanup
		$this->out->write($composer->exec('require --no-update', 'dg/composer-cleaner:^1.0', 'phpstan/package-patches:^1.0'));
		if ($noExtensions === false) {
			$this->out->write($composer->exec('require --no-update', ...array_keys(self::$extensions)));
		}
		$this->out->write($composer->exec('update --no-dev --optimize-autoloader --classmap-authoritative'));

		// version everything to see a diff
		// $this->out->write($git->exec('add -f .'));

		// prefix dependencies
		(new AutoPrefixer($this->out))->prefix(
			$buildDir,
			$phpstanDependencies,
			self::$forcePrefixPhpStanDependencyNamespaces,
			($noExtensions === false) ? self::$extensions : []
		);

		// remove unnecessary packages
		$finder = new Finder();
		$finder->directories()
			->ignoreVCS(true)
			->name('*')
			->exclude('composer')
			->depth(1)
			->in($buildDir . '/vendor')
			->filter(
				function (SplFileInfo $file) use ($phpstanDependencies): bool {
					return !array_key_exists($file->getRelativePathname(), self::$extensions)
						&& !in_array($file->getRelativePathname(), $phpstanDependencies, true);
				}
			);
		foreach ($finder as $directory) {
			$this->out->writeln(sprintf('Removing <info>%s</info>', $directory->getRelativePathname()));
			$this->fs->remove($directory->getPathname());
		}

		// rebuild classmap
		$this->out->write($composer->exec('dump-autoload --optimize --classmap-authoritative'));

		// build executable phar
		if ($this->fs->exists($pharFile = $tempDir . '/phpstan-' . $version . '.phar')) {
			$this->fs->remove($pharFile);
		}
		(new PharPackager($this->out))->package($buildDir, $pharFile);
	}

}
