<?php

declare(strict_types=1);

namespace App\Support\Audit;

/** Result of AuditChain::verify(). */
final readonly class AuditChainReport
{
    /**
     * @param  list<string>  $problems  first problems found (Indonesian, for the operator)
     * @param  int  $unsealed  activity rows older than the grace period without a seal (sealer lagging)
     */
    public function __construct(
        public int $checked,
        public array $problems,
        public int $unsealed,
        public ?int $headSealId,
        public ?string $headHash,
    ) {}

    public function ok(): bool
    {
        return $this->problems === [];
    }
}
