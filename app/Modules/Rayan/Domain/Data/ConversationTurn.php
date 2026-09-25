<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * One thing said, as the assistant needs to see it.
 *
 * A deliberately thin view of a conversation message: who spoke, and what they
 * said. No uuid, no delivery state, no author user, no timestamps — none of
 * which helps a model answer a question, and all of which would be extra data
 * leaving the building for no reason (docs/27-RAYAN.md §9).
 *
 * This is also what keeps RAYAN from importing Conversations. The channel
 * builds these; RAYAN never sees a `Message` model and so can never write one.
 */
final readonly class ConversationTurn
{
    private function __construct(
        /** `customer` or `assistant`. */
        public string $role,
        public string $text,
    ) {}

    public static function fromCustomer(string $text): self
    {
        return new self('customer', $text);
    }

    public static function fromAssistant(string $text): self
    {
        return new self('assistant', $text);
    }

    public function isFromCustomer(): bool
    {
        return $this->role === 'customer';
    }
}
