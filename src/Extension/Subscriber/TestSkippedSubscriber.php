<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Extension\Subscriber;

use Lokris\TrajectoryCoverage\Coverage\TrajectoryStore;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * Déclenché quand le test est ignoré (markTestSkipped / prérequis non remplis).
 * Marque le test courant comme "skipped" avant l'event Finished.
 */
final class TestSkippedSubscriber implements SkippedSubscriber
{
    public function __construct(private readonly TrajectoryStore $store)
    {
    }

    public function notify(Skipped $event): void
    {
        $this->store->markStatus('skipped');
    }
}
