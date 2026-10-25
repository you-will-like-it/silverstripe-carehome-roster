<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

/**
 * Outcome of planning checks: blocking errors and overridable warnings (human readable).
 */
final class ValidationReport
{
    /** @var list<string> */
    private array $blocks = [];

    /** @var list<string> */
    private array $warnings = [];

    public function block(string $message): self
    {
        $this->blocks[] = $message;

        return $this;
    }

    public function warn(string $message): self
    {
        $this->warnings[] = $message;

        return $this;
    }

    public function merge(self $other, string $prefix = ''): self
    {
        foreach ($other->blocks as $message) {
            $this->blocks[] = $prefix . $message;
        }
        foreach ($other->warnings as $message) {
            $this->warnings[] = $prefix . $message;
        }

        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->blocks !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function isClean(): bool
    {
        return !$this->isBlocked() && !$this->hasWarnings();
    }

    /** Saving is allowed when nothing blocks and every warning is acknowledged with a reason. */
    public function allowsSave(?string $overrideReason): bool
    {
        return !$this->isBlocked() && (!$this->hasWarnings() || trim((string) $overrideReason) !== '');
    }

    /** @return list<string> */
    public function blocks(): array
    {
        return array_values(array_unique($this->blocks));
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /** @return list<string> */
    public function messages(): array
    {
        return [...$this->blocks(), ...$this->warnings()];
    }

    /** @return array{blocks:list<string>,warnings:list<string>} */
    public function toArray(): array
    {
        return ['blocks' => $this->blocks(), 'warnings' => $this->warnings()];
    }
}
