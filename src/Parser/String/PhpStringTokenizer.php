<?php

declare(strict_types=1);

namespace PHPStanCompiler\Parser\String;

use Nette\Utils\TokenIterator;
use Nette\Utils\Tokenizer;

class PhpStringTokenizer
{

	const ESCAPED_ENCAPSULATION = 'escaped_encapsulation';
	const ENCAPSULATION = 'encapsulation';
	const TYPE_NAME = 'type_name';
	const WHITESPACE = 'whitespace';
	const WORD = 'word';
	const OTHER = 'other';

	public function tokenize(int $type, string $input): TokenIterator
	{
		$tokenizer = $this->createTokenizer($type, $input);
		return new TokenIterator($tokenizer->tokenize($input));
	}

	private function createTokenizer(int $type, string $input): Tokenizer
	{
		// num of slashes
		$sl = ($type === T_CONSTANT_ENCAPSED_STRING && $input[0] === "'")
			? '1'
			: '1,2';

		return new Tokenizer([
			self::ESCAPED_ENCAPSULATION => ($input[0] === "'") ? '\\\\\\\'' : '\\\\\\"',
			self::ENCAPSULATION => ($input[0] === "'") ? "'" : '"',
			self::WHITESPACE => '[\\s\\n]+',
			self::TYPE_NAME => '(?:\\\\{' . $sl . '})?(?:' . \Nette\PhpGenerator\Helpers::PHP_IDENT . '\\\\{' . $sl . '})+' . \Nette\PhpGenerator\Helpers::PHP_IDENT,
			self::WORD => '\\w+',
			self::OTHER => '.',
		]);
	}

}
