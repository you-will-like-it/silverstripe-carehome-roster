<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

/**
 * Result of a planning operation. When not saved because of warnings, the caller shows them
 * and may repeat the call with an override reason.
 */
final class PlanResult
{
    /** @var array<int|string,mixed> messages (list) or batch stats ('created', 'skipped') */
    public array $info = [];

    public function __construct(
        public bool $saved,
        public ValidationReport $report = new ValidationReport(),
    ) {
    }

    public static function saved(?ValidationReport $report = null): self
    {
        return new self(true, $report ?? new ValidationReport());
    }

    public static function rejected(ValidationReport $report): self
    {
        return new self(false, $report);
    }

    public static function error(string $message): self
    {
        return new self(false, (new ValidationReport())->block($message));
    }

    /** Needs only a reason to be saved. */
    public function needsReason(): bool
    {
        return !$this->saved && !$this->report->isBlocked() && $this->report->hasWarnings();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'saved' => $this->saved,
            'needsReason' => $this->needsReason(),
            'info' => $this->info,
        ] + $this->report->toArray();
    }
}
