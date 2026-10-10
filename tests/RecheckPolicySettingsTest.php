<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Service\RecheckPolicy;
use App\Service\SettingsService;
use App\Value\RetestResult;
use App\Value\ReviewState;

final class RecheckPolicySettingsTest extends DatabaseTestCase
{
    public function testDefaultCadenceIsFourteenDaysWithAThreeDayErrorBackoff(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $policy = self::getContainer()->get(RecheckPolicy::class);

        self::assertSame(14, $settings->getRecheckIntervalDays());
        self::assertSame(3, $settings->getRecheckErrorBackoffDays());
        self::assertSame(14, $policy->intervalDays());
        self::assertSame(3, $policy->errorBackoffDays());
    }

    public function testConfiguredCadenceDrivesNextDueSlotsAndIsClampedToSafeLimits(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->save([
            'recheck.interval_days' => '7',
            'recheck.error_backoff_days' => '5',
        ]);
        $policy = self::getContainer()->get(RecheckPolicy::class);
        $finding = (new Finding())->setDomain((new \App\Entity\Domain())->setHostname('cadence.invalid'))->setStatus('reported');
        $now = new \DateTimeImmutable();

        self::assertSame(7, $policy->intervalDays());
        self::assertSame(5, $policy->errorBackoffDays());
        self::assertEquals($now->modify('+7 days'), $policy->nextDueAfter($finding, RetestResult::STILL_VULNERABLE, $now));
        self::assertEquals($now->modify('+5 days'), $policy->nextDueAfter($finding, RetestResult::ERROR, $now));

        $settings->save(['recheck.interval_days' => '0', 'recheck.error_backoff_days' => '999']);
        self::assertSame(1, $settings->getRecheckIntervalDays());
        self::assertSame(30, $settings->getRecheckErrorBackoffDays());

        $settings->save(['recheck.interval_days' => '91']);
        self::assertSame(90, $settings->getRecheckIntervalDays());
    }

    public function testManualCheckingRemainsOutsideTheScheduleAndLosesItsSlot(): void
    {
        $policy = self::getContainer()->get(RecheckPolicy::class);
        $finding = (new Finding())->setDomain((new \App\Entity\Domain())->setHostname('cadence.invalid'))->setStatus('reported');
        $finding->setReviewState(ReviewState::MANUAL_CHECKING);
        $now = new \DateTimeImmutable();

        self::assertNull($policy->nextDueAfter($finding, null, $now));
        $finding->setNextDueAt($now->modify('+7 days'));
        $policy->ensureNextDue($finding, $now);
        self::assertNull($finding->getNextDueAt());
    }
}
