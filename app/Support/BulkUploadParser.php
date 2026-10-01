<?php

namespace App\Support;

use SplFileObject;

/**
 * Reads a CSV/TXT upload in streaming mode and returns unique, normalised domains.
 * The first column (or the whole line for TXT) is used; a header row is skipped.
 */
final class BulkUploadParser
{
    public function __construct(private readonly DomainNormalizer $normalizer) {}

    /**
     * @return array{valid:list<array{input:string,domain:string}>, invalid:list<array{input:string,reason:string}>, duplicates:int, truncated:bool}
     */
    public function parse(string $path, int $maxRows): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($this->detectDelimiter($path));

        $valid = [];
        $invalid = [];
        $seen = [];
        $duplicates = 0;
        $truncated = false;
        $first = true;

        foreach ($file as $row) {
            if (! is_array($row)) {
                continue;
            }

            $raw = trim((string) ($row[0] ?? ''), " \t\n\r\0\x0B\xEF\xBB\xBF");

            if ($raw === '' || str_starts_with($raw, '#')) {
                continue;
            }

            $domain = $this->normalizer->tryNormalize($raw);

            // A first line that is not a domain is treated as a header ("domain", "email", ...).
            if ($first) {
                $first = false;
                if ($domain === null && preg_match('/^[a-z _-]+$/i', $raw) === 1) {
                    continue;
                }
            }

            if ($domain === null) {
                $invalid[] = ['input' => $raw, 'reason' => 'Not a valid domain or email'];

                continue;
            }

            if (isset($seen[$domain])) {
                $duplicates++;

                continue;
            }

            if (count($valid) >= $maxRows) {
                $truncated = true;
                break;
            }

            $seen[$domain] = true;
            $valid[] = ['input' => $raw, 'domain' => $domain];
        }

        return compact('valid', 'invalid', 'duplicates', 'truncated');
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $head = $handle ? (string) fgets($handle) : '';
        if ($handle) {
            fclose($handle);
        }

        return substr_count($head, ';') > substr_count($head, ',') ? ';' : ',';
    }
}
