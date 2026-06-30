<?php

namespace App\Tests;

use App\Command\DatabaseInitCommand;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DatabaseInitCommandTest extends UnitTestCase
{
    public function testDatabaseInitAlwaysRunsMigrations(): void
    {
        $connection = $this->createMock(Connection::class);

        $command = new class($connection) extends DatabaseInitCommand {
            public bool $ranMigrations = false;

            protected function runMigrations(string $command): int
            {
                $this->ranMigrations = true;

                return 0;
            }
        };

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($command->ranMigrations);
    }
}
