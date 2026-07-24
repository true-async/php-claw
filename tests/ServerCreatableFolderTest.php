<?php

declare(strict_types=1);

namespace Tests;

use Claw\Server;
use Testo\Assert;
use Testo\Test;

/**
 * The dashboard's "create the folder for me" policy: which typed path may become a project
 * folder, and where it lands. Pure path arithmetic — no filesystem is touched, which is the
 * point: the folder does not exist yet when the decision is made.
 */
final class ServerCreatableFolderTest
{
    private const string ROOT = '/opt/claw';
    private const string WORKSPACE = '/opt/claw/workspace';
    private const string PROJECTS = '/opt/claw/workspace/projects';

    private function resolve(string $requested): ?string
    {
        return Server::creatableProjectFolder($requested, self::ROOT, self::WORKSPACE, self::PROJECTS);
    }

    #[Test]
    public function anchorsEveryNaturalSpellingToTheWorkspace(): void
    {
        // the full path, the root-anchored spelling the dashboard user actually typed,
        // the root-relative one, and the bare name — all mean <workspace>/demo
        Assert::same($this->resolve('/opt/claw/workspace/demo'), self::WORKSPACE . '/demo');
        Assert::same($this->resolve('/workspace/demo'), self::WORKSPACE . '/demo');
        Assert::same($this->resolve('workspace/demo'), self::WORKSPACE . '/demo');
        Assert::same($this->resolve('demo'), self::WORKSPACE . '/demo');
    }

    #[Test]
    public function refusesAnythingOutsideTheWorkspace(): void
    {
        Assert::same($this->resolve('/etc/claw'), null);
        Assert::same($this->resolve('../outside'), null);
        Assert::same($this->resolve('/opt/claw/elsewhere/demo'), null);
    }

    #[Test]
    public function dotDotIsResolvedBeforeTheContainmentCheck(): void
    {
        // an escape spelled through the workspace must not pass
        Assert::same($this->resolve('/opt/claw/workspace/../secrets'), null);
        Assert::same($this->resolve('workspace/../../etc'), null);
        // while a harmless round-trip through .. still lands inside
        Assert::same($this->resolve('/opt/claw/workspace/../workspace/demo'), self::WORKSPACE . '/demo');
    }

    #[Test]
    public function neverHandsOutTheStateAreaOrTheWorkspaceItself(): void
    {
        Assert::same($this->resolve('projects'), null);              // the project dbs live there
        Assert::same($this->resolve('workspace/projects/demo'), null);
        Assert::same($this->resolve('/opt/claw/workspace'), null);   // a project is IN the workspace
    }
}
