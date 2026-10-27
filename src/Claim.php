<?php

declare(strict_types=1);

namespace LaravelOutbox;

/** Internal lease handle; never forward the token to consumers. */
final readonly class Claim
{
    public function __construct(public Envelope $event, public string $token) {}
}
