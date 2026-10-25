<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Task;

use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;
use YouWillLikeIT\CareHome\Roster\Admin\RosterAdmin;
use YouWillLikeIT\CareHome\Roster\Control\Admin\PlanBoardController;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Service\LeaveBalanceService;
use YouWillLikeIT\CareHome\Roster\Service\RosterNotifier;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Daily cron, e.g.:
 *   15 6 * * *  www-data  cd /var/www/carehome && vendor/bin/sake tasks:carehome-roster-daily
 *
 * - planners: fixed-term contracts ending within RosterSettings.ContractWarningDays (once per contract end)
 * - planners: shifts still open in the next 2 days (once per day)
 * - leave entitlement records for the current year (with carry-over) for all active workers
 */
class RosterDailyTask extends BuildTask
{
    protected static string $commandName = 'carehome-roster-daily';

    protected string $title = 'CareHome raspored: dnevne provjere';

    protected static string $description = 'Upozorenja o isteku ugovora i otvorenim smjenama; kreira pravo na godišnji odmor za tekuću godinu.';

    private static array $permissions_for_browser_execution = ['ADMIN'];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        AuditContext::singleton()->setChannel(AuditContext::CHANNEL_CLI);
        $clock = Clock::singleton();
        $today = $clock->today();
        $notifier = RosterNotifier::singleton();
        $adminLink = Controller::join_links(AdminRootController::admin_url(), RosterAdmin::config()->get('url_segment'));

        try {
            $workers = WorkerDirectory::singleton()->workers(null, $today, $today);

            $contracts = 0;
            foreach ($workers as $member) {
                if ($member->contractEndsSoon($today)) {
                    $notifier->notifyPlanners(
                        _t(__CLASS__ . '.CONTRACT', 'Ugovor ističe: {name}', ['name' => $member->getName()]),
                        _t(__CLASS__ . '.CONTRACT_BODY', 'Ugovor na određeno ističe {date}. Nakon tog datuma radnik se ne može planirati.', [
                            'date' => date('d.m.Y.', strtotime((string) $member->EmploymentEnd)),
                        ]),
                        Controller::join_links(AdminRootController::admin_url(), 'security'),
                        'contract-end:' . $member->ID . ':' . $member->EmploymentEnd
                    );
                    $contracts++;
                }
            }

            $open = ShiftAssignment::get()->filter([
                'Status' => ShiftAssignment::STATUS_OPEN,
                'Date:GreaterThanOrEqual' => $today->format('Y-m-d'),
                'Date:LessThanOrEqual' => $today->modify('+2 days')->format('Y-m-d'),
            ]);
            $openCount = $open->count();
            if ($openCount > 0) {
                $notifier->notifyPlanners(
                    _t(__CLASS__ . '.OPEN', 'Nepopunjene smjene u naredna 2 dana: {count}', ['count' => $openCount]),
                    implode(', ', array_map(
                        static fn (ShiftAssignment $a): string => date('d.m.', strtotime((string) $a->Date)) . ' ' . $a->ShiftCode,
                        $open->toArray()
                    )),
                    PlanBoardController::monthLink((int) $today->format('Y'), (int) $today->format('n')),
                    'open-shifts:' . $today->format('Y-m-d')
                );
            }

            $year = (int) $today->format('Y');
            $balances = LeaveBalanceService::singleton();
            foreach ($workers as $member) {
                $balances->entitlementFor($member, $year);
            }

            $notifier->flush();
            $output->writeln(sprintf(
                'Contracts ending: %d, open shifts (2 days): %d, workers checked: %d. Admin: %s',
                $contracts,
                $openCount,
                count($workers),
                $adminLink
            ));
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
