<?php

declare(strict_types=1);

namespace Teletask;

/**
 * Base exception for all library errors.
 *
 * The optional context is printed by the CLI in debug mode and is useful when
 * diagnosing protocol or configuration problems.
 */
class TeletaskException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
