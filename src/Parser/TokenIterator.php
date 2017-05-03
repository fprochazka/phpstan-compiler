<?php

declare(strict_types=1);

namespace PHPStanCompiler\Parser;

use Nette\Utils\Tokenizer;

class TokenIterator extends \Nette\Utils\TokenIterator
{

	const VALUE = Tokenizer::VALUE;
	const OFFSET = Tokenizer::OFFSET;
	const TYPE = Tokenizer::TYPE;
	const LINE = 3;

	public function replace(int $offset, ?int $length, ?array $replacement): void
	{
		if ($replacement !== null) {
			if (is_array($replacement) && isset($replacement[0]) && !is_array($replacement[0])) {
				$replacement = [$replacement];
			}

			array_splice($this->tokens, $offset, $length, $replacement);
			if ($length !== count($replacement)) {
				$this->position += count($replacement) - $length;
			}

		} else {
			array_splice($this->tokens, $offset, $length);
			$this->position -= $length;
		}
	}

	public function replaceWith(\Closure $closure): void
	{
		$begin = $this->position + 1;
		$result = $closure($this);
		$end = max($this->position, $begin);
		$this->replace($begin, $end - $begin + 1, $result);
	}

	public function replaceCurrentValue(string $newValue): void
	{
		$this->tokens[$this->position][self::VALUE] = $newValue;
	}

	public function replaceFrom(int $begin, ?array $replacement): void
	{
		$end = $this->position;
		$this->replace($begin, $end - $begin + 1, $replacement);
	}

	public function dropCurrent(): void
	{
		$this->replace($this->position, 1, null);
	}

	public function currentLine(): ?int
	{
		return isset($this->tokens[$this->position])
			? $this->tokens[$this->position][self::LINE]
			: null;
	}

	public function currentType(): ?int
	{
		return isset($this->tokens[$this->position])
			? $this->tokens[$this->position][self::TYPE]
			: null;
	}

	/**
	 * Returns prev token.
	 *
	 * @param  int|string $arg (optional) desired token type or value
	 * @return array|NULL
	 */
	public function prevToken(): ?array
	{
		return $this->scan(func_get_args(), true, false); // onlyFirst, advance
	}

	/**
	 * Returns prev token value.
	 *
	 * @param  int|string $arg (optional) desired token type or value
	 * @return string|NULL
	 */
	public function prevValue(): ?string
	{
		return $this->scan(func_get_args(), true, false, true); // onlyFirst, advance, strings
	}

	/**
	 * Returns all prev tokens.
	 *
	 * @param  int|string $arg (optional) desired token type or value
	 * @return array[]
	 */
	public function prevAll(): array
	{
		return $this->scan(func_get_args(), false, false); // advance
	}

	/**
	 * Returns all prev tokens until it sees a given token type or value.
	 *
	 * @param  int|string $arg token type or value to stop before
	 * @return array[]
	 */
	public function prevUntil($arg): array
	{
		return $this->scan(func_get_args(), false, false, false, true); // advance, until
	}

}
