<?php

use App\Exceptions\InvalidDomainException;
use App\Support\DomainNormalizer;

it('normalises domains, emails and urls', function (string $input, string $expected) {
    expect((new DomainNormalizer)->normalize($input))->toBe($expected);
})->with([
    'plain' => ['example.com', 'example.com'],
    'uppercase and spaces' => ['  Example.COM ', 'example.com'],
    'email' => ['John.Doe@Mail.Example.com', 'mail.example.com'],
    'email in angle brackets' => ['<user@example.com>', 'example.com'],
    'url with path' => ['https://www.example.com/path?q=1#x', 'www.example.com'],
    'url with port' => ['http://example.com:8080/', 'example.com'],
    'trailing dot' => ['example.com.', 'example.com'],
    'idn' => ['bücher.de', 'xn--bcher-kva.de'],
]);

it('rejects invalid input', function (string $input) {
    (new DomainNormalizer)->normalize($input);
})->with([
    'empty' => [''],
    'no tld' => ['localhost'],
    'ip address' => ['192.168.1.1'],
    'spaces inside' => ['exa mple.com'],
    'bad label' => ['-bad-.com'],
    'email without domain' => ['user@'],
])->throws(InvalidDomainException::class);

it('returns null from tryNormalize instead of throwing', function () {
    expect((new DomainNormalizer)->tryNormalize('nope'))->toBeNull();
});
