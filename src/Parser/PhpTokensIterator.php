<?php

declare(strict_types=1);

namespace PHPStanCompiler\Parser;

use Nette\Utils\Tokenizer;

define('T_IMPORT', 1000);
define('T_TYPE_REFERENCE', 1001);
define('T_SCALAR_TYPE_REFERENCE', 1002);
define('T_NAMESPACE_NAME', 1003);

/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class PhpTokensIterator extends TokenIterator
{

	const TYPE_NAME = 4;
	const FULL_TYPE_REFERENCE = 5;
	const IMPORTS = 6;

	public function __construct(array $tokens)
	{
		$this->ignored = [T_WHITESPACE];
		parent::__construct($tokens);
	}

	/**
	 * @return static
	 */
	public function reset()
	{
		parent::reset();
		return $this;
	}

	public static function fromCode(string $code): PhpTokensIterator
	{
		$tokens = [];
		foreach (token_get_all($code, TOKEN_PARSE) as $token) {
			$tokens[] = is_array($token)
				? self::createTokenFromArray($token)
				: self::createTokenFromValue($token);
		}

		return new PhpTokensIterator($tokens);
	}

	public function finishStatement(string ...$extra): string
	{
		return $this->joinUntil(';', ...$extra) . $this->joinAll(';', ...$extra);
	}

	public function isTypehintNext(): bool
	{
		$pos = $this->position;
		try {
			if ($this->isNext('?')) {
				$this->nextValue();
			}

			return $this->isNext(T_ARRAY, T_STRING, T_NS_SEPARATOR);

		} finally {
			$this->position = $pos;
		}
	}

	public function isTypehintCurrent(): bool
	{
		$pos = $this->position;
		try {
			if ($this->isCurrent('?')) {
				$this->nextValue();
			}

			return $this->isCurrent(T_ARRAY, T_STRING, T_NS_SEPARATOR);

		} finally {
			$this->position = $pos;
		}
	}

	public function skipOptionalHint(): void
	{
		if ($this->isCurrent('?')) {
			$this->nextValue();
		}
	}

	public function parseTypehint(): string
	{
		if ($this->isNext(T_ARRAY)) {
			return (string) $this->nextValue();
		}

		return $this->joinAll(T_STRING, T_NS_SEPARATOR);
	}

	public function parseImport(): array
	{
		$this->nextToken(T_USE);
		$this->nextAll(T_WHITESPACE);

		$imports = [];
		while ($name = $this->joinAll(T_STRING, T_NS_SEPARATOR)) {
			$name = ltrim($name, '\\');
			if ($this->nextValue('{')) {
				while ($suffix = $this->joinAll(T_STRING, T_NS_SEPARATOR)) {
					if (trim($this->joinAll(T_AS)) !== "") {
						$imports[$this->nextValue(T_STRING)] = $name . $suffix;
					} else {
						$tmp = explode('\\', $suffix);
						$imports[end($tmp)] = $name . $suffix;
					}
					if (!$this->isNext(',')) {
						break;
					}
				}

			} elseif (trim($this->joinAll(T_AS)) !== "") {
				$imports[$this->nextValue(T_STRING)] = $name;

			} else {
				$tmp = explode('\\', $name);
				$imports[end($tmp)] = $name;
			}
			if (!$this->isNext(',')) {
				break;
			}
		}

		$this->finishStatement();

		return array_change_key_case($imports, CASE_LOWER);
	}

	public static function namespaceNameToken(string $namespaceName, ?int $line): array
	{
		return [
			self::VALUE => $namespaceName,
			self::TYPE => T_NAMESPACE_NAME,
			self::TYPE_NAME => 'T_NAMESPACE_NAME',
			self::LINE => $line,
		];
	}

	public static function typeImportToken(string $value, int $line, array $resolvedImports): array
	{
		return [
			self::VALUE => $value,
			self::TYPE => T_IMPORT,
			self::TYPE_NAME => 'T_IMPORT',
			self::LINE => $line,
			self::IMPORTS => $resolvedImports,
		];
	}

	public static function typeRefToken(string $value, int $line, string $resolvedType): array
	{
		$isNative = array_key_exists($resolvedType, PhpParser::$ignoreTypes);

		return [
			self::VALUE => $value,
			self::TYPE => $isNative ? T_SCALAR_TYPE_REFERENCE : T_TYPE_REFERENCE,
			self::TYPE_NAME => $isNative ? 'T_SCALAR_TYPE_REFERENCE' : 'T_TYPE_REFERENCE',
			self::LINE => $line,
			self::FULL_TYPE_REFERENCE => $resolvedType,
		];
	}

	public static function createToken(string $value, int $type, int $line): array
	{
		return [
			self::VALUE => $value,
			self::TYPE => $type,
			self::TYPE_NAME => token_name($type),
			self::LINE => $line,
		];
	}

	private static function createTokenFromArray(array $token): array
	{
		return [
			self::VALUE => $token[1],
			self::TYPE => $token[0],
			self::TYPE_NAME => token_name($token[0]),
			self::LINE => $token[2],
		];
	}

	private static function createTokenFromValue(string $token): array
	{
		return [
			self::VALUE => $token,
			self::TYPE => NULL,
		];
	}
}
