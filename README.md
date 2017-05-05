# PHPStan phar compiler

[![Build Status](https://travis-ci.org/fprochazka/phpstan-compiler.svg?branch=master)](https://travis-ci.org/fprochazka/phpstan-compiler)

## Why?

PHPStan uses native PHP reflection to read class metadata of the project it inspects, which means it is loading the code it inspects into it's runtime.

If you'd wanted to inspect for example `symfony/console`, you'll have to

1) pray that it loads the version you're testing and not the version it has in it's vendor,
2) pray that it is actually compatible, with the version provided.

Even after that, you're still inspecting the code, that is inspecting your code. Doesn't sound right, does it?

## How?

This project does the following

* Clones the PHPStan
* Checks out the specified version
* Optionally installs phpstan extensions (that it knows of)
* Prefixes all the vendor libraries, that PHPStan uses, to make sure you can test libraries, that PHPStan depends on
* When you've chosen to also include the extensions, it makes sure not to prefix libraries that they depend on. For example `phpstan/phpstan-doctrine` has references for `Doctrine` namespace in it - we don't wanna prefix them, but only everything else.
* Packages everything into a Phar file for you

## Install phpstan.phar as a Composer package

The repository [phpstan/phpstan-shim](https://github.com/phpstan/phpstan-shim) contains compiled phars.
It would be awesome if you'd help us test them, but keep in mind this is not production ready.

## Compile it yourself

```bash
git clone https://github.com/fprochazka/phpstan-compiler
cd phpstan-compiler
composer install
php -dphar.readonly=0 bin/compiler [--no-extensions] [version]
```

Generated phar will be in `tmp/`

## Thank you!

This project was inspired by

* [Compilation process of Composer](https://github.com/composer/composer)
* [Build tools of Nette Framework](https://github.com/nette) (now removed, but [preserved in forks](https://github.com/fprochazka/nette-build-tools/blob/20861f8fc0f716e9dbd1a59420fbfeb9b70cd126/tasks/convert52.php#L53))

Thank you!
