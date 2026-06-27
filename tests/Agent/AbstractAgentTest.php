<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentResponse;
use Claw\Agent\BackoffAgentRetryPolicy;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\ServerErrorException;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedRetryAgent;

final class AbstractAgentTest
{
    #[Test]
    public function retriesTransientThenSucceeds(): void
    {
        $agent = $this->agent(new ServerErrorException('5xx'), $this->ok());

        Assert::same($agent->send($this->request())->text, 'ok');
        Assert::same($agent->attempts, 2);
    }

    #[Test]
    public function doesNotRetryPermanentError(): void
    {
        $agent = $this->agent(new AuthException('bad key'));

        $this->assertThrows($agent);
        Assert::same($agent->attempts, 1);
    }

    #[Test]
    public function doesNotRetryQuotaExhaustion(): void
    {
        $agent = $this->agent(new QuotaExceededException('out of credits'));

        $this->assertThrows($agent);
        Assert::same($agent->attempts, 1);
    }

    #[Test]
    public function retriesAfterRateLimitResumeTime(): void
    {
        $agent = $this->agent(new RateLimitException('rl', 5), $this->ok());

        Assert::same($agent->send($this->request())->text, 'ok');
        Assert::same($agent->attempts, 2);
    }

    #[Test]
    public function givesUpWhenRateLimitTooFarAway(): void
    {
        $agent = $this->agent(new RateLimitException('rl', 999_000));

        $this->assertThrows($agent);
        Assert::same($agent->attempts, 1);
    }

    #[Test]
    public function rethrowsAfterExhaustion(): void
    {
        $agent = new ScriptedRetryAgent(
            [new ServerErrorException('a'), new ServerErrorException('b')],
            new BackoffAgentRetryPolicy(maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 1),
        );

        $this->assertThrows($agent);
        Assert::same($agent->attempts, 2);
    }

    private function agent(AgentResponse|\Throwable ...$outcomes): ScriptedRetryAgent
    {
        return new ScriptedRetryAgent($outcomes, new BackoffAgentRetryPolicy(baseDelayMs: 1, maxDelayMs: 1));
    }

    private function assertThrows(ScriptedRetryAgent $agent): void
    {
        $threw = false;

        try {
            $agent->send($this->request());
        } catch (\Claw\Exceptions\AgentException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }

    private function ok(): AgentResponse
    {
        return new AgentResponse([new TextBlock('ok')], [], StopReason::EndTurn, new Usage(), 'ok');
    }

    private function request(): \Claw\Agent\AgentRequest
    {
        return new \Claw\Agent\AgentRequest('m', [\Claw\Agent\Message::userText('x')]);
    }
}
