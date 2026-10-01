<?php

use App\Enums\BlacklistStatus;
use App\Enums\BulkStatus;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Jobs\ProcessDomainCheck;
use App\Models\BulkCheck;
use App\Models\DomainCheck;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

function csv(string $contents, string $name = 'domains.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

it('queues one job per unique valid domain and returns the batch id', function () {
    Queue::fake();

    $response = $this->postJson('/api/checks/blacklist/bulk', [
        'file' => csv("domain\nexample.com\nEXAMPLE.com\nuser@test.org\nnot valid\n"),
        'dkim_selector' => 'google',
    ])->assertCreated()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('duplicates', 1)
        ->assertJsonPath('invalid_count', 1);

    $bulk = BulkCheck::where('uuid', $response->json('uuid'))->firstOrFail();

    expect($bulk->type)->toBe(CheckType::Blacklist)
        ->and($bulk->status)->toBe(BulkStatus::Pending)
        ->and($bulk->total)->toBe(2);

    expect(DomainCheck::where('bulk_check_id', $bulk->id)->pluck('status')->unique()->all())->toEqual([CheckStatus::Queued]);
    expect(DomainCheck::where('bulk_check_id', $bulk->id)->pluck('dkim_selector')->unique()->all())->toBe(['google']);

    Queue::assertPushed(ProcessDomainCheck::class, 2);
    Queue::assertPushedOn('checks', ProcessDomainCheck::class);
});

it('rejects files without any valid domain', function () {
    Queue::fake();

    $this->postJson('/api/checks/provider/bulk', ['file' => csv("nope\nalso nope\n")])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    Queue::assertNothingPushed();
    expect(BulkCheck::count())->toBe(0);
});

it('rejects unsupported file types', function () {
    $this->postJson('/api/checks/provider/bulk', [
        'file' => UploadedFile::fake()->create('domains.pdf', 10, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('returns the batch state for page loads and reconnects', function () {
    $bulk = BulkCheck::factory()->create(['total' => 2, 'processed_count' => 1]);
    DomainCheck::factory()->for($bulk)->create(['domain' => 'a.com']);
    DomainCheck::factory()->for($bulk)->completed()->create(['domain' => 'b.com']);

    $this->getJson("/api/bulk-checks/{$bulk->uuid}")
        ->assertOk()
        ->assertJsonPath('bulk.progress.total', 2)
        ->assertJsonPath('bulk.progress.processed', 1)
        ->assertJsonCount(2, 'checks')
        ->assertJsonPath('checks.0.status', 'queued')
        ->assertJsonMissingPath('checks.0.dns_records');
});

it('returns full DNS detail for a single row', function () {
    $bulk = BulkCheck::factory()->create();
    $check = DomainCheck::factory()->for($bulk)->completed()->create();

    $this->getJson("/api/bulk-checks/{$bulk->uuid}/checks/{$check->id}")
        ->assertOk()
        ->assertJsonPath('dns_records.MX.status', 'ok');
});

it('does not expose a row through another batch', function () {
    $bulk = BulkCheck::factory()->create();
    $other = DomainCheck::factory()->for(BulkCheck::factory()->create())->create();

    $this->getJson("/api/bulk-checks/{$bulk->uuid}/checks/{$other->id}")->assertNotFound();
});

it('exports the filtered rows as csv', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Blacklist]);
    DomainCheck::factory()->for($bulk)->completed()->create(['domain' => 'clean.com', 'blacklist_status' => BlacklistStatus::Clean]);
    DomainCheck::factory()->for($bulk)->completed()->create([
        'domain' => 'bad.com',
        'blacklist_status' => BlacklistStatus::Listed,
        'listed_on' => [['list' => 'zen.spamhaus.org', 'name' => 'Spamhaus ZEN', 'target' => '1.2.3.4', 'code' => '127.0.0.2']],
    ]);

    $all = $this->get("/api/bulk-checks/{$bulk->uuid}/export")->assertOk();
    expect($all->streamedContent())->toContain('clean.com')->toContain('bad.com');

    $listed = $this->get("/api/bulk-checks/{$bulk->uuid}/export?blacklist=listed")->assertOk();
    $content = $listed->streamedContent();
    expect($content)->toContain('bad.com')->toContain('Spamhaus ZEN (1.2.3.4)')->not->toContain('clean.com');
    expect($listed->headers->get('content-disposition'))->toContain('.csv');
});

it('neutralises spreadsheet formulas in exported values', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider]);
    DomainCheck::factory()->for($bulk)->completed()->create([
        'type' => CheckType::Provider,
        'domain' => 'evil.com',
        'evidence' => ['=HYPERLINK("http://x")'],
    ]);

    $content = $this->get("/api/bulk-checks/{$bulk->uuid}/export")->streamedContent();

    expect($content)->toContain("'=HYPERLINK");
});

it('rejects invalid export filters', function () {
    $bulk = BulkCheck::factory()->create();

    $this->getJson("/api/bulk-checks/{$bulk->uuid}/export?blacklist=bogus")->assertUnprocessable();
});
