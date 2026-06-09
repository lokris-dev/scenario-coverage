<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Extension\Subscriber;

use Lokris\ScenarioCoverage\Coverage\ScenarioStore;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Déclenché juste avant l'exécution de chaque méthode de test.
 * Lance la capture Xdebug si le test appartient à une classe annotée #[Scenario].
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private readonly ScenarioStore $store)
    {
    }

    public function notify(Prepared $event): void
    {
        // Démarrer la capture inconditionnellement (on filtrera post-exécution)
        $this->store->beginScenario();
    }
}
