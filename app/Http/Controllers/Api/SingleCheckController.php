<?php

namespace App\Http\Controllers\Api;

use App\Actions\Checks\RunDomainCheck;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Exceptions\InvalidDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SingleCheckRequest;
use App\Http\Resources\DomainCheckResource;
use App\Models\DomainCheck;
use App\Support\DomainNormalizer;
use Illuminate\Validation\ValidationException;

class SingleCheckController extends Controller
{
    /** Runs one check in the request cycle and returns the full result. */
    public function store(
        CheckType $type,
        SingleCheckRequest $request,
        DomainNormalizer $normalizer,
        RunDomainCheck $action,
    ): DomainCheckResource {
        try {
            $domain = $normalizer->normalize($request->validated('input'));
        } catch (InvalidDomainException $e) {
            throw ValidationException::withMessages(['input' => $e->getMessage()]);
        }

        $check = DomainCheck::create([
            'type' => $type,
            'input' => $request->validated('input'),
            'domain' => $domain,
            'dkim_selector' => $type === CheckType::Blacklist ? $request->validated('dkim_selector') : null,
            'status' => CheckStatus::Checking,
            'started_at' => now(),
            'attempts' => 1,
        ]);

        return DomainCheckResource::make($action->handle($check));
    }
}
