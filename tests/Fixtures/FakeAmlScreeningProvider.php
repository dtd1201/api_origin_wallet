<?php

namespace Tests\Fixtures;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Data\Aml\AmlScreeningRequest;
use App\Data\Aml\AmlScreeningResult;
use RuntimeException;

class FakeAmlScreeningProvider implements AmlScreeningProvider
{
    public function __construct(
        private readonly string $outcome = 'clear',
        private readonly bool $fails = false,
    ) {}

    public function name(): string
    {
        return 'fake-authoritative';
    }

    public function screen(AmlScreeningRequest $request): AmlScreeningResult
    {
        if ($this->fails) {
            throw new RuntimeException('Provider unavailable.');
        }

        $matches = $this->outcome === 'match' ? [[
            'list_type' => 'sanctions',
            'source' => 'fake-list',
            'matched_name' => $request->subjectName,
            'score' => 97,
        ]] : [];

        return new AmlScreeningResult(
            reference: 'fake-'.sha1($request->subjectType.'-'.$request->subjectId),
            outcome: $this->outcome,
            riskLevel: $this->outcome === 'match' ? 'high' : 'low',
            summary: ['categories' => $this->outcome === 'match' ? ['sanctions'] : []],
            matches: $matches,
        );
    }
}
