<?php

use App\Support\BulkUploadParser;
use App\Support\DomainNormalizer;

function parseContents(string $contents, int $max = 100): array
{
    $path = tempnam(sys_get_temp_dir(), 'bulk');
    file_put_contents($path, $contents);

    try {
        return (new BulkUploadParser(new DomainNormalizer))->parse($path, $max);
    } finally {
        unlink($path);
    }
}

it('reads one domain or email per line from a txt file', function () {
    $result = parseContents("example.com\nuser@Test.org\r\nhttps://foo.io/x\n");

    expect(array_column($result['valid'], 'domain'))->toBe(['example.com', 'test.org', 'foo.io']);
});

it('uses the first csv column and skips a header row', function () {
    $result = parseContents("domain,owner\nexample.com,Alice\ntest.org,Bob\n");

    expect(array_column($result['valid'], 'domain'))->toBe(['example.com', 'test.org']);
});

it('supports semicolon-delimited csv and a utf-8 bom', function () {
    $result = parseContents("\xEF\xBB\xBFemail;name\nuser@example.com;Alice\n");

    expect(array_column($result['valid'], 'domain'))->toBe(['example.com']);
});

it('removes duplicates and counts them', function () {
    $result = parseContents("example.com\nEXAMPLE.com\nuser@example.com\nother.org\n");

    expect($result['valid'])->toHaveCount(2)->and($result['duplicates'])->toBe(2);
});

it('collects invalid lines instead of failing the whole file', function () {
    $result = parseContents("example.com\nnot a domain\n192.168.0.1\n\n# comment\n");

    expect($result['valid'])->toHaveCount(1)->and($result['invalid'])->toHaveCount(2);
});

it('stops at the row limit and flags truncation', function () {
    $result = parseContents("a.com\nb.com\nc.com\n", max: 2);

    expect($result['valid'])->toHaveCount(2)->and($result['truncated'])->toBeTrue();
});
