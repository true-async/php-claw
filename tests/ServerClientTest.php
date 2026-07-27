<?php

declare(strict_types=1);

namespace Tests;

use Claw\Exceptions\ClawException;
use Claw\Http\HttpResponse;
use Claw\ServerClient;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

/**
 * The CLI's thin-client side: how it reads the server's answer to a start, the address it targets, and
 * the shape of the command it would spawn a server with. The spawn itself needs the server extension
 * and is exercised elsewhere; here the pure decisions are pinned.
 */
final class ServerClientTest
{
    private string $workspace;

    public function __construct()
    {
        $this->workspace = sys_get_temp_dir() . '/claw-client-' . bin2hex(random_bytes(6));
        mkdir($this->workspace);
    }

    public function __destruct()
    {
        @rmdir($this->workspace);
    }

    private function client(HttpResponse $response): ServerClient
    {
        return new ServerClient(new FakeHttpClient($response), $this->workspace, '/opt/claw');
    }

    #[Test]
    public function aStartAcceptedReturnsQuietly(): void
    {
        $http = new FakeHttpClient(new HttpResponse(202, ''));
        // a 202 must return without throwing; that the POST was issued is the proof it ran
        new ServerClient($http, $this->workspace, '/opt/claw')
            ->startRun(['host' => '127.0.0.1', 'port' => 8787], 'proj', '7');

        Assert::same(str_ends_with((string) $http->lastUrl, '/issues/7/start'), true);
    }

    #[Test]
    public function anAlreadyActiveRunIsReportedNotSwallowed(): void
    {
        $threw = false;

        try {
            $this->client(new HttpResponse(409, '{"error":"a run for this issue is already active"}'))
                ->startRun(['host' => '127.0.0.1', 'port' => 8787], 'proj', '7');
        } catch (ClawException $e) {
            $threw = true;
            Assert::same(str_contains($e->getMessage(), 'already active'), true);
        }

        Assert::same($threw, true);
    }

    #[Test]
    public function aServerErrorIsSurfaced(): void
    {
        $threw = false;

        try {
            $this->client(new HttpResponse(500, ''))
                ->startRun(['host' => '127.0.0.1', 'port' => 8787], 'proj', '7');
        } catch (ClawException $e) {
            $threw = true;
            Assert::same(str_contains($e->getMessage(), '500'), true);
        }

        Assert::same($threw, true);
    }

    #[Test]
    public function theStartTargetsTheIssuesRunEndpointWithEscapedKeys(): void
    {
        $http = new FakeHttpClient(new HttpResponse(202, ''));
        new ServerClient($http, $this->workspace, '/opt/claw')
            ->startRun(['host' => '10.0.0.5', 'port' => 9000], 'a b/c', '7');

        Assert::same($http->lastUrl, 'http://10.0.0.5:9000/api/projects/a%20b%2Fc/issues/7/start');
    }

    #[Test]
    public function runningIsNullWhenNothingIsRecorded(): void
    {
        Assert::same($this->client(new HttpResponse(202, ''))->running(), null);
    }

    #[Test]
    public function theSpawnCommandCarriesTheExtensionHostAndPort(): void
    {
        // bin/claw must exist under the root for a command to be built, so point the root at the repo
        $root = \dirname(__DIR__);
        $command = ServerClient::spawnCommand($root, '127.0.0.1', 8787);

        Assert::notSame($command, null);
        Assert::same(str_contains((string) $command, 'extension='), true);
        Assert::same(str_contains((string) $command, 'serve --host'), true);
        Assert::same(str_contains((string) $command, '--port 8787'), true);
    }

    #[Test]
    public function noSpawnCommandWhenBinClawIsAbsent(): void
    {
        Assert::same(ServerClient::spawnCommand('/nowhere/at/all', '127.0.0.1', 8787), null);
    }
}
