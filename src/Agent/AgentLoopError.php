<?php

declare(strict_types=1);

namespace Aldervast\Agent;

use RuntimeException;

/**
 * A run of AgentLoop that failed before the model gave an answer. $kind says why, so that the eval
 * can keep plumbing failures apart from wrong answers:
 *
 *   api_error       the API kept failing (after retries), or refused the request outright
 *   timeout         the run went past its time limit
 *   model_mismatch  the response came from a different model than the one requested
 *
 * $usage holds the tokens already billed before the failure, so that the cost still counts.
 */
final class AgentLoopError extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly array $usage = [])
    {
        parent::__construct($message);
    }
}
