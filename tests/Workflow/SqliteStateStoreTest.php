<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Workflow\SqliteStateStore;
use Testo\Assert;
use Testo\Test;

final class SqliteStateStoreTest
{
    #[Test]
    public function persistsStateAndProgressAcrossARestart(): void
    {
        $path = self::tempDb();

        try {
            $store = new SqliteStateStore(new \PDO('sqlite:' . $path));
            $store->save('r1', ['x' => 1, 'y' => 'two'], ['alpha']);

            // A fresh store on a NEW connection to the same file = a process restart.
            $snap = (new SqliteStateStore(new \PDO('sqlite:' . $path)))->load('r1');

            Assert::same($snap['state'], ['x' => 1, 'y' => 'two']);
            Assert::same($snap['done'], ['alpha']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function saveOverwritesThePriorSnapshot(): void
    {
        $store = new SqliteStateStore(new \PDO('sqlite::memory:'));
        $store->save('r1', ['v' => 1], ['a']);
        $store->save('r1', ['v' => 2], ['a', 'b']);

        $snap = $store->load('r1');
        Assert::same($snap['state'], ['v' => 2]);
        Assert::same($snap['done'], ['a', 'b']);
    }

    #[Test]
    public function loadReturnsEmptyDefaultsForAnUnknownRun(): void
    {
        $store = new SqliteStateStore(new \PDO('sqlite::memory:'));

        Assert::same($store->load('nope'), ['state' => [], 'done' => []]);
    }

    #[Test]
    public function nextIdIsMonotonicAndSurvivesARestart(): void
    {
        $path = self::tempDb();

        try {
            $store = new SqliteStateStore(new \PDO('sqlite:' . $path));
            $a = (int) $store->nextId();
            $b = (int) $store->nextId();
            Assert::true($b > $a);

            $c = (int) (new SqliteStateStore(new \PDO('sqlite:' . $path)))->nextId();
            Assert::true($c > $b);   // keeps climbing after a restart, no id reuse
        } finally {
            @unlink($path);
        }
    }

    private static function tempDb(): string
    {
        return sys_get_temp_dir() . '/claw-state-' . uniqid('', true) . '.db';
    }
}
