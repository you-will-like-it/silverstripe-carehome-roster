<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeInterface;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareHome\Roster\Model\PublicHoliday;

/**
 * Public holiday lookup, cached per request (reset on holiday writes).
 */
class HolidayCalendar
{
    use Injectable;

    /** @var array<string,string>|null Y-m-d => title */
    private ?array $fixed = null;

    /** @var array<string,string> m-d => title */
    private array $recurring = [];

    public function reset(): void
    {
        $this->fixed = null;
        $this->recurring = [];
    }

    public function isHoliday(DateTimeInterface $date): bool
    {
        return $this->titleFor($date) !== null;
    }

    public function titleFor(DateTimeInterface $date): ?string
    {
        $this->load();

        return $this->fixed[$date->format('Y-m-d')] ?? $this->recurring[$date->format('m-d')] ?? null;
    }

    /** @return callable(DateTimeInterface):bool */
    public function predicate(): callable
    {
        return fn (DateTimeInterface $date): bool => $this->isHoliday($date);
    }

    private function load(): void
    {
        if ($this->fixed !== null) {
            return;
        }
        $this->fixed = [];
        foreach (PublicHoliday::get() as $holiday) {
            if (!$holiday->Date) {
                continue;
            }
            $date = (string) $holiday->Date;
            if ($holiday->IsRecurring) {
                $this->recurring[substr($date, 5, 5)] = (string) $holiday->Title;
            } else {
                $this->fixed[$date] = (string) $holiday->Title;
            }
        }
    }
}
