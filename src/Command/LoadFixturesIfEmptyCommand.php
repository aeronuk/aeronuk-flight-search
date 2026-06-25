<?php

namespace AeroNuk\FlightSearch\Command;

use AeroNuk\FlightSearch\Entity\Flight;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:load-fixtures-if-empty', description: 'Loads fixtures only in dev/test, and only if the flight table is empty')]
class LoadFixturesIfEmptyCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            $output->writeln(sprintf('Skipping fixtures: environment is "%s", not dev/test.', $this->environment));

            return Command::SUCCESS;
        }

        $count = (int) $this->em->createQuery('SELECT COUNT(f.id) FROM ' . Flight::class . ' f')->getSingleScalarResult();

        if ($count > 0) {
            $output->writeln('Skipping fixtures: flight table already has data.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Loading fixtures (environment: %s)...', $this->environment));

        $application = $this->getApplication();
        if ($application === null) {
            return Command::FAILURE;
        }

        $fixturesInput = new ArrayInput([]);
        // ArrayInput does not special-case --no-interaction the way Application::doRun() does when
        // parsing real argv, so it stays interactive unless explicitly told otherwise. Without this,
        // doctrine:fixtures:load's purge confirmation prompt blocks (and silently defaults to "no" in
        // non-tty contexts like tests), so fixtures never actually load.
        $fixturesInput->setInteractive(false);

        return $application->find('doctrine:fixtures:load')->run($fixturesInput, $output);
    }
}
