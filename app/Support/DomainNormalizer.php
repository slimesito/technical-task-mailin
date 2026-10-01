<?php

namespace App\Support;

use App\Exceptions\InvalidDomainException;

/** Turns user input (domain, email, URL) into a lowercase ASCII (punycode) domain, or rejects it. */
final class DomainNormalizer
{
    public function normalize(string $input): string
    {
        $value = trim($input, " \t\n\r\0\x0B\"'<>");

        if ($value === '') {
            throw new InvalidDomainException('Empty value');
        }

        // Email: keep what follows the last "@".
        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }

        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value); // scheme
        $value = (string) preg_replace('#[/?\#].*$#', '', $value);               // path, query, fragment
        $value = (string) preg_replace('#:\d+$#', '', $value);                   // port
        $value = strtolower(rtrim($value, '.'));

        if ($value === '') {
            throw new InvalidDomainException("No domain found in \"{$input}\"");
        }

        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidDomainException("Invalid internationalised domain \"{$input}\"");
            }
            $value = $ascii;
        }

        $this->assertValid($value, $input);

        return $value;
    }

    public function tryNormalize(string $input): ?string
    {
        try {
            return $this->normalize($input);
        } catch (InvalidDomainException) {
            return null;
        }
    }

    private function assertValid(string $domain, string $original): void
    {
        $labels = explode('.', $domain);

        if (strlen($domain) > 253 || count($labels) < 2) {
            throw new InvalidDomainException("\"{$original}\" is not a valid domain");
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                throw new InvalidDomainException("\"{$original}\" is not a valid domain");
            }
        }

        // The TLD must not be purely numeric (rules out IP addresses).
        if (preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', end($labels)) !== 1) {
            throw new InvalidDomainException("\"{$original}\" is not a valid domain");
        }
    }
}
