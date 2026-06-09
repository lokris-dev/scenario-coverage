<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Extension\Subscriber;

use Lokris\ScenarioCoverage\Coverage\ScenarioStore;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * Déclenché quand le test est ignoré (markTestSkipped / prérequis non remplis).
 * Marque le test courant comme "skipped" avant l'event Finished.
 */
final class TestSkippedSubscriber implements SkippedSubscriber
{
    public function __construct(private readonly ScenarioStore $store)
    {
    }

    public function notify(Skipped $event): void
    {
        $this->store->markStatus('skipped');
    }
}
