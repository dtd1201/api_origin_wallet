<?php

namespace App\Data\Aml;

final readonly class AmlScreeningRequest
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        public int $userId,
        public string $subjectType,
        public ?int $subjectId,
        public string $subjectName,
        public ?string $subjectRole,
        public array $attributes,
    ) {}
}
