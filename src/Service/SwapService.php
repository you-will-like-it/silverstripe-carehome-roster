<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use RuntimeException;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Admin\RosterAdmin;
use YouWillLikeIT\CareHome\Roster\Control\Portal\RosterPortalController;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftSwapRequest;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Worker-initiated shift takeover / exchange: request → colleague → planner.
 */
class SwapService
{
    use Injectable;

    public function __construct(
        private readonly AssignmentValidator $validator,
        private readonly PlanningService $planning,
        private readonly RosterNotifier $notifier,
    ) {
    }

    /**
     * @throws RuntimeException user-facing message
     */
    public function createByWorker(
        Member $requester,
        ShiftAssignment $assignment,
        Member $colleague,
        ?ShiftAssignment $counter,
        string $note,
    ): ShiftSwapRequest {
        $this->assertSwappable($assignment, $requester);
        if ((int) $colleague->ID === (int) $requester->ID || !$colleague->isRosterCandidate()) {
            throw new RuntimeException(_t(__CLASS__ . '.COLLEAGUE_INVALID', 'Odaberite kolegu koji radi u smjenama.'));
        }
        if ($counter) {
            $this->assertSwappable($counter, $colleague);
        }
        $active = ShiftSwapRequest::get()->filter([
            'AssignmentID' => (int) $assignment->ID,
            'Status' => [ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE, ShiftSwapRequest::STATUS_AWAITING_APPROVAL],
        ])->exists();
        if ($active) {
            throw new RuntimeException(_t(__CLASS__ . '.ALREADY', 'Za ovu smjenu već postoji otvoren zahtjev.'));
        }

        $request = ShiftSwapRequest::create([
            'AssignmentID' => (int) $assignment->ID,
            'CounterAssignmentID' => $counter ? (int) $counter->ID : 0,
            'RequestedByID' => (int) $requester->ID,
            'ColleagueID' => (int) $colleague->ID,
            'RequestNote' => mb_substr(trim($note), 0, 2000) ?: null,
            'Status' => ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE,
        ]);

        $preview = $this->preview($request);
        if ($preview['blocked']) {
            throw new RuntimeException(implode(' ', $preview['messages']));
        }

        $request->write();
        $this->notifier->notify(
            $colleague,
            _t(__CLASS__ . '.N_ASKED', '{name} traži zamjenu smjene', ['name' => $requester->getName()]),
            $request->getSummary() . ($request->RequestNote ? ' – ' . $request->RequestNote : ''),
            RosterPortalController::homeLink()
        );
        $this->notifier->flush();

        return $request;
    }

    /**
     * @throws RuntimeException
     */
    public function respond(ShiftSwapRequest $request, Member $colleague, bool $accept, string $note): void
    {
        if ((int) $request->ColleagueID !== (int) $colleague->ID || $request->Status !== ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_YOURS', 'Ovaj zahtjev nije moguće obraditi.'));
        }
        if ($accept) {
            $preview = $this->preview($request);
            if ($preview['blocked']) {
                throw new RuntimeException(implode(' ', $preview['messages']));
            }
        }
        $request->ColleagueNote = mb_substr(trim($note), 0, 2000) ?: null;
        $request->ColleagueRespondedAt = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
        $request->Status = $accept ? ShiftSwapRequest::STATUS_AWAITING_APPROVAL : ShiftSwapRequest::STATUS_DECLINED;
        $request->write();
    }

    /**
     * @throws RuntimeException
     */
    public function cancelByRequester(ShiftSwapRequest $request, Member $requester): void
    {
        if ((int) $request->RequestedByID !== (int) $requester->ID || $request->isFinal()) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_YOURS', 'Ovaj zahtjev nije moguće obraditi.'));
        }
        $request->Status = ShiftSwapRequest::STATUS_CANCELLED;
        $request->DecisionNote = _t(__CLASS__ . '.WITHDRAWN', 'Radnik je povukao zahtjev.');
        $request->write();
    }

    /**
     * All planning checks as they would apply now.
     *
     * @return array{blocked:bool,messages:list<string>,report:ValidationReport}
     */
    public function preview(ShiftSwapRequest $request): array
    {
        $report = new ValidationReport();
        $assignment = $request->Assignment();
        $counter = $request->CounterAssignmentID ? $request->CounterAssignment() : null;

        if (!$assignment->exists() || $assignment->isOpen() || (int) $assignment->MemberID !== (int) $request->RequestedByID) {
            $report->block(_t(__CLASS__ . '.CHANGED', 'Smjena se u međuvremenu promijenila.'));
        }
        if ($counter && (!$counter->exists() || $counter->isOpen() || (int) $counter->MemberID !== (int) $request->ColleagueID)) {
            $report->block(_t(__CLASS__ . '.COUNTER_CHANGED', 'Smjena kolege se u međuvremenu promijenila.'));
        }

        if (!$report->isBlocked()) {
            $ignore = array_values(array_filter([(int) $assignment->ID, $counter ? (int) $counter->ID : 0]));
            $report->merge($this->validator->checkAssignmentFor($request->Colleague(), $assignment, $ignore));
            if ($counter) {
                $report->merge($this->validator->checkAssignmentFor($request->RequestedBy(), $counter, $ignore));
            }
        }

        return ['blocked' => $report->isBlocked(), 'messages' => $report->messages(), 'report' => $report];
    }

    /**
     * Planner approval in the CMS: blocking problems prevent it, warnings need a decision note.
     */
    public function validate(ShiftSwapRequest $request, ValidationResult $result): void
    {
        if (!$request->AssignmentID || !$request->RequestedByID || !$request->ColleagueID) {
            $result->addError(_t(__CLASS__ . '.INCOMPLETE', 'Zahtjev je nepotpun.'));

            return;
        }
        if (!$request->isInDB() || !$request->isChanged('Status') || $request->Status !== ShiftSwapRequest::STATUS_APPROVED) {
            return;
        }
        $preview = $this->preview($request);
        if ($preview['blocked']) {
            foreach ($preview['report']->blocks() as $message) {
                $result->addError($message);
            }

            return;
        }
        if ($preview['report']->hasWarnings() && trim((string) $request->DecisionNote) === '') {
            $result->addFieldError('DecisionNote', _t(
                __CLASS__ . '.REASON_REQUIRED',
                'Zamjena krši pravila radnog vremena; upišite razlog odobrenja.'
            ));
        }
    }

    public function afterStatusChange(ShiftSwapRequest $request): void
    {
        $requester = $request->RequestedBy();
        $colleague = $request->Colleague();
        $summary = $request->getSummary();
        $link = RosterPortalController::homeLink();

        $this->planning->transaction(function () use ($request, $requester, $colleague, $summary, $link): void {
            switch ($request->Status) {
                case ShiftSwapRequest::STATUS_AWAITING_APPROVAL:
                    $this->notifier->notifyPlanners(
                        _t(__CLASS__ . '.N_TO_APPROVE', 'Zamjena smjene čeka odobrenje'),
                        _t(__CLASS__ . '.N_TO_APPROVE_BODY', '{from} → {to}: {summary}', [
                            'from' => $requester->getName(),
                            'to' => $colleague->getName(),
                            'summary' => $summary,
                        ]),
                        Controller::join_links(AdminRootController::admin_url(), RosterAdmin::config()->get('url_segment'), 'swaps')
                    );
                    $this->notifier->notify($requester, _t(__CLASS__ . '.N_ACCEPTED', '{name} je prihvatio/la zamjenu', ['name' => $colleague->getName()]), $summary, $link);
                    break;

                case ShiftSwapRequest::STATUS_DECLINED:
                    $this->notifier->notify($requester, _t(__CLASS__ . '.N_DECLINED', '{name} je odbio/la zamjenu', ['name' => $colleague->getName()]), $summary . ($request->ColleagueNote ? ' – ' . $request->ColleagueNote : ''), $link);
                    break;

                case ShiftSwapRequest::STATUS_APPROVED:
                    $preview = $this->preview($request);
                    $reason = $preview['report']->hasWarnings() ? (string) $request->DecisionNote : null;
                    $assignment = $request->Assignment();
                    $counter = $request->CounterAssignmentID ? $request->CounterAssignment() : null;
                    $this->planning->transfer($assignment, $colleague, $reason);
                    if ($counter) {
                        $this->planning->transfer($counter, $requester, $reason);
                    }
                    $this->notifier->notifyMany(
                        [(int) $requester->ID, (int) $colleague->ID],
                        _t(__CLASS__ . '.N_APPROVED', 'Zamjena smjene odobrena'),
                        $summary,
                        $link
                    );
                    break;

                case ShiftSwapRequest::STATUS_REJECTED:
                case ShiftSwapRequest::STATUS_CANCELLED:
                    $subject = $request->Status === ShiftSwapRequest::STATUS_REJECTED
                        ? _t(__CLASS__ . '.N_REJECTED', 'Zamjena smjene odbijena')
                        : _t(__CLASS__ . '.N_CANCELLED', 'Zahtjev za zamjenu je poništen');
                    $this->notifier->notifyMany(
                        [(int) $requester->ID, (int) $colleague->ID],
                        $subject,
                        $summary . ($request->DecisionNote ? ' – ' . $request->DecisionNote : ''),
                        $link
                    );
                    break;
            }
        });
    }

    /**
     * @throws RuntimeException
     */
    private function assertSwappable(ShiftAssignment $assignment, Member $owner): void
    {
        if (!$assignment->exists() || $assignment->isOpen() || (int) $assignment->MemberID !== (int) $owner->ID) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_OWN', 'Smjena ne pripada odabranom radniku.'));
        }
        if ($assignment->startsAt() <= Clock::singleton()->now()) {
            throw new RuntimeException(_t(__CLASS__ . '.PAST', 'Mijenjati se mogu samo buduće smjene.'));
        }
        $period = RosterPeriod::forDate($assignment->dateObject(), false);
        if (!$period || !$period->isPublished()) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_PUBLISHED', 'Raspored za taj mjesec nije objavljen ili je zaključan.'));
        }
    }
}
