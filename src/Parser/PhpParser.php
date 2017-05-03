<?php declare(strict_types=1);

namespace PHPStanCompiler\Parser;

use PHPStanCompiler\Parser\String\PhpStringTokenizer;

class PhpParser
{

	public static $ignoreTypes = [
		'parent' => true,
		'self' => true,
		'static' => true,
		'string' => true,
		'int' => true,
		'float' => true,
		'bool' => true,
		'array' => true,
		'callable' => true,
		'iterable' => true,
		'void' => true,
		'mixed' => true,
	];

	/** @var \PHPStanCompiler\Parser\String\PhpStringTokenizer */
	private $stringTokenizer;

	/** @var string */
	private $namespace;

	/** @var array */
	private $imports;

	/** @var \Closure */
	private $typeChecker;

	public function __construct()
	{
		$this->stringTokenizer = new PhpStringTokenizer();
	}

	public function escapeTokenValue(string $newValue, array $token): string
	{
		return (strpos($token[PhpTokensIterator::VALUE], '\\\\') !== false)
			? str_replace('\\', '\\\\', $newValue)
			: $newValue;
	}

	public function parse(string $code, \Closure $typeChecker): PhpTokensIterator
	{
		$this->namespace =  '';
		$this->imports = ['' => ''];
		$this->typeChecker = $typeChecker;

		$parser = PhpTokensIterator::fromCode($code);

		$inType = $inFunction = null;
		$typeScope = $functionScope = [];
		$scope = 0;
		while (($token = $parser->nextValue()) !== null) {
			if ($parser->isCurrent('{')) {
				$scope++;

			} elseif ($parser->isCurrent('}')) {
				if (isset($typeScope[$scope])) {
					unset($typeScope[$scope]);
					$inType = end($typeScope) ?: null;
				}

				if (isset($functionScope[$scope])) {
					unset($functionScope[$scope]);
					$inFunction = end($functionScope) ?: null;
				}

				$scope--;

			} elseif ($parser->isCurrent(T_CLASS, T_INTERFACE, T_TRAIT)) {
				$parser->nextAll(T_WHITESPACE);
				$typeScope[$scope + 1] = $inType = $this->typeResolve($parser->parseTypehint());

			} elseif ($parser->isCurrent(T_FUNCTION)) {
				$parser->nextAll(T_WHITESPACE);
				$functionScope[$scope + 1] = $inFunction = (string) $parser->nextValue(T_STRING);

			} elseif ($parser->isCurrent(T_DECLARE)) {
				$parser->finishStatement();

			} elseif ($parser->isCurrent(T_NAMESPACE)) {
				$this->parseNamespace($parser, $token);

			} elseif ($inFunction === null && $parser->isCurrent(T_USE)) {
				if ($inType === null) { // type imports
					$this->parseImports($parser, $token);

				} else { // trait usages
					$this->parseTraitUse($parser, $token);
				}

			} elseif ($parser->isCurrent(T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_NEW)) {
				$this->parseTypehintList($parser, $token);

			} elseif ($parser->isTypehintCurrent()) { // Class:: or typehint
				$this->parseTypehint($parser, $token);

			} elseif (isset($functionScope[$scope + 1]) && $functionScope[$scope + 1] === $inFunction && $parser->isCurrent(':') && $parser->isPrev(')')) { // return typehint
				$this->parseReturnTypehint($parser, $token);

			} elseif ($parser->isCurrent(T_DOC_COMMENT, T_COMMENT)) { // @var Class or \Class or Nm\Class or Class:: (preserves CLASS, @package)
				// $this->parseCommentTypehints($parser, $token);

			} elseif ($parser->isCurrent(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE)) { // strings like 'Nette\Object'
				$this->parseTypeReferenceInString($parser, $token);
			}
		}

		return $parser->reset();
	}

	private function parseNamespace(PhpTokensIterator $parser, string $token): void
	{
		$parser->nextAll(T_WHITESPACE);
		$parser->replaceWith(
			function (PhpTokensIterator $parser): array {
				$this->namespace = $parser->parseTypehint();
				return PhpTokensIterator::namespaceNameToken($this->namespace, $parser->currentLine());
			}
		);
		$parser->finishStatement();
	}

	private function parseImports(PhpTokensIterator $parser, string $token): void
	{
		$begin = $parser->position;
		$statementImports = $parser->parseImport();

		$parser->position = $begin - 1;
		$line = $parser->currentLine();
		$original = $parser->finishStatement();
		$parser->replaceFrom(
			$begin,
			PhpTokensIterator::typeImportToken($original, $line, $statementImports)
		);

		$this->imports = array_merge($this->imports, $statementImports);
	}

	private function parseTraitUse(PhpTokensIterator $parser, string $token): void
	{
		// todo: handle insteadof
		do {
			$parser->nextAll(T_WHITESPACE);
			if (!$parser->isTypehintNext()) {
				break;
			}

			$parser->replaceWith(
				function (PhpTokensIterator $parser): array {
					$type = $parser->parseTypehint();
					return PhpTokensIterator::typeRefToken($type, $parser->currentLine(), $this->typeResolve($type));
				}
			);

		} while ($token = $parser->nextValue(','));
	}

	private function parseTypehintList(PhpTokensIterator $parser, string $token): void
	{
		do {
			$parser->nextAll(T_WHITESPACE);
			if (!$parser->isTypehintNext()) { // might be a variable or something
				break;
			}

			$parser->replaceWith(
				function (PhpTokensIterator $parser): array {
					$type = $parser->parseTypehint();
					return PhpTokensIterator::typeRefToken($type, $parser->currentLine(), $this->typeResolve($type));
				}
			);

		} while ($token = $parser->nextValue(','));
	}

	private function parseTypehint(PhpTokensIterator $parser, string $token): void
	{
		$begin = $parser->position;
		$identifier = $token . $parser->parseTypehint();
		if ($parser->isNext(T_DOUBLE_COLON, T_VARIABLE, T_ELLIPSIS)) {
			$parser->replaceFrom(
				$begin,
				PhpTokensIterator::typeRefToken($identifier, $parser->currentLine(), $this->typeResolve($identifier))
			);
		}
	}

	private function parseReturnTypehint(PhpTokensIterator $parser, string $token): void
	{
		$parser->nextAll(T_WHITESPACE);
		$parser->skipOptionalHint();
		$parser->nextAll(T_WHITESPACE);
		$parser->replaceWith(
			function (PhpTokensIterator $parser): array {
				$type = $parser->parseTypehint();
				return PhpTokensIterator::typeRefToken($type, $parser->currentLine(), $this->typeResolve($type));
			}
		);
	}

	private function parseTypeReferenceInString(PhpTokensIterator $parser, string $token): void
	{
		$tokenType = $parser->currentType();
		$line = $parser->currentLine();
		$stringTokens = $this->stringTokenizer->tokenize($tokenType, $token);

		$newTokens = [];
		while (($stringToken = $stringTokens->nextValue()) !== null) {
			if ($stringTokens->isCurrent(PhpStringTokenizer::TYPE_NAME)) {
				$type = ltrim(str_replace('\\\\', '\\', $stringToken), '\\');
				if ($this->guessIfTypeExists($type)) {
					$newTokens[] = PhpTokensIterator::typeRefToken($stringToken, $parser->currentLine(), $type);
					continue;
				}
			}

			$stringToken .= $stringTokens->joinUntil(PhpStringTokenizer::TYPE_NAME);
			$newTokens[] = PhpTokensIterator::createToken($stringToken, $tokenType, $line);
		}
		$parser->replace($parser->position, 1, $newTokens);
	}

	private function parseCommentTypehints(PhpTokensIterator $parser, string $token): void
	{
		preg_replace_callback(
			'#((?:@var(?:\s+array of)?|returns?|param|throws|@link|property[\w-]*|@package)\s+)?(?<=\W)(\\\\?[A-Z][\w\\\\|]+)(::)?()#',
			function ($m) {
				if (substr($m[1], 0, 8) === '@package' || (!$m[1] && !$m[3] && strpos($m[2], '\\') === false)) {
					return $m[0];
				}
				$parts = [];
				foreach (explode('|', $m[2]) as $part) {
					$parts[] = preg_match('#[a-z]#', $part) ? $this->typeResolve($part) : $part;
				}
				return $m[1] . implode('|', $parts) . $m[3];
			},
			$token
		);
	}

	private function guessIfTypeExists(string $type): bool
	{
		return $this->isBuildInType($type) || call_user_func($this->typeChecker, $type);
	}

	private function typeResolve(string $type): string
	{
		if ($this->isBuildInType($type)) {
			return $type;
		}

		if (strpos($type, '\\') === 0) {
			return $type;
		}

		$segment = strtolower(substr($type, 0, strpos("$type\\", '\\')));
		$full = isset($this->imports[$segment])
			? $this->imports[$segment] . substr($type, strlen($segment))
			: $this->namespace . '\\' . $type;

		return '\\' . ltrim($full, '\\');
	}

	private function isBuildInType(string $type): bool
	{
		return array_key_exists($type, self::$ignoreTypes);
	}

}
