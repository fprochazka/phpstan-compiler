<?php declare(strict_types=1);

namespace PHPStanCompiler;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Executable
{

	/** @var string */
	private $executable;

	/** @var string */
	private $cwd;

	public function __construct(string $program, string $cwd)
	{
		$this->executable = (new ExecutableFinder())->find($program);
		$this->cwd = $cwd;
	}

	public function exec(string $command, string ...$args): string
	{
		$commandLine = $this->executable . ' ' . $command . ' ' . implode(' ', array_map('escapeshellarg', $args));
		$process = new Process($commandLine, $this->cwd);
		$process->mustRun();
		return $process->getOutput() . $process->getErrorOutput();
	}

}
