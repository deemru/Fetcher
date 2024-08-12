# Fetcher

[![packagist](https://img.shields.io/packagist/v/deemru/fetcher.svg)](https://packagist.org/packages/deemru/fetcher) [![php-v](https://img.shields.io/packagist/php-v/deemru/fetcher.svg)](https://packagist.org/packages/deemru/fetcher)   [![GitHub](https://img.shields.io/github/actions/workflow/status/deemru/Fetcher/php.yml?label=github%20actions)](https://github.com/deemru/Fetcher/actions/workflows/php.yml) [![codacy](https://img.shields.io/codacy/grade/94562bc5ffab447a9a8f0045502c24a6.svg?label=codacy)](https://app.codacy.com/gh/deemru/Fetcher/files) [![license](https://img.shields.io/packagist/l/deemru/fetcher.svg)](https://packagist.org/packages/deemru/fetcher)

[Fetcher](https://github.com/deemru/Fetcher) implements a simple wrapper around [cURL](https://www.php.net/manual/book.curl.php).

- Use `fetch()` for single/multi hosts served one-by-one
- Use `fetchMulti()` for multi hosts served simultaneous

## Usage

```php
$nodes =
[
    'https://example.com',
    'https://testnode1.wavesnodes.com',
    'https://testnode2.wavesnodes.com',
    'https://testnode3.wavesnodes.com',
    'https://testnode4.wavesnodes.com',
];

echo Fetcher::hosts( $nodes )->fetch( '/blocks/height' );
```

## Requirements

- [PHP](http://php.net) >= 5.6
- [cURL](https://www.php.net/manual/book.curl.php)

## Installation

Require through Composer:

```bash
composer require deemru/fetcher
```
