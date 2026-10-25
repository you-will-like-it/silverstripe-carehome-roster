<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

/**
 * Result of a rule check. BLOCK can never be saved, WARN can be saved with a reason.
 * Messages are produced by the service layer (i18n) from code + params.
 */
final class Violation
{
    public const string BLOCK = 'block';
    public const string WARN = 'warn';

    public const string OVERLAP = 'overlap';
    public const string MAX_SHIFT = 'max_shift';
    public const string DAILY_REST = 'daily_rest';
    public const string WEEKLY_REST = 'weekly_rest';
    public const string WEEKLY_HOURS = 'weekly_hours';
    public const string CONSECUTIVE_DAYS = 'consecutive_days';

    /**
     * @param array<string,scalar> $params
     */
    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly array $params = [],
    ) {
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::BLOCK;
    }
}
