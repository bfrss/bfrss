# Big Fat RSS [![Build Status](https://travis-ci.org/bfrss/bfrss.svg?branch=master)](https://travis-ci.org/bfrss/bfrss)

This is a fork of *Tiny Tiny RSS*, which aims to clean up the code base
and introduce test cases.

## Code Style

All files should pass this test:

``` bash
phpcs --report-full --standard=PSR2 filename.php
```

## Dependencies

We use [Composer](https://getcomposer.org/) to manage the dependencies of bfrss.
If you haven't installed it, you can run the following command in the project
root to do so:

``` bash
curl -sS https://getcomposer.org/installer | php
```

Run the following command in your shell to install the dependencies under
`vendor/`:

``` bash
composer.phar install
```
