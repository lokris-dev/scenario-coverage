<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Extension\Subscriber;

use Lokris\TrajectoryCoverage\Coverage\TrajectoryStore;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Déclenché juste avant l'exécution de chaque méthode de test.
 * Lance la capture Xdebug si le test appartient à une classe annotée #[Trajectory].
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private readonly TrajectoryStore $store)
    {
    }

    public function notify(Prepared $event): void
    {
        // Démarrer la capture inconditionnellement (on filtrera post-exécution)
        $this->store->beginTrajectory();
    }
}
