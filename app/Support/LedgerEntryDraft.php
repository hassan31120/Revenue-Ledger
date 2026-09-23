<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use InvalidArgumentException;

final readonly class LedgerEntryDraft
{
    public function __construct(
        public AccountType $accountType,
        public ?int $instructorId,
        public LedgerEntryType $entryType,
        public int $amountMinor,
        public string $currency,
        public string $sourceType,
        public int $sourceId,
        public string $idempotencyKey,
    ) {
        if ($accountType === AccountType::Instructor && $instructorId === null) {
            throw new InvalidArgumentException('An instructor ledger entry must name an instructor.');
        }

        if ($accountType === AccountType::Platform && $instructorId !== null) {
            throw new InvalidArgumentException('A platform ledger entry must not name an instructor.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('A ledger entry must carry an idempotency key.');
        }
    }

    public static function forInstructor(
        int $instructorId,
        LedgerEntryType $entryType,
        int $amountMinor,
        string $currency,
        string $sourceType,
        int $sourceId,
        string $idempotencyKey,
    ): self {
        return new self(
            AccountType::Instructor, $instructorId, $entryType, $amountMinor,
            $currency, $sourceType, $sourceId, $idempotencyKey,
        );
    }

    public static function forPlatform(
        LedgerEntryType $entryType,
        int $amountMinor,
        string $currency,
        string $sourceType,
        int $sourceId,
        string $idempotencyKey,
    ): self {
        return new self(
            AccountType::Platform, null, $entryType, $amountMinor,
            $currency, $sourceType, $sourceId, $idempotencyKey,
        );
    }

    public function toRow(): array
    {
        return [
            'account_type' => $this->accountType->value,
            'instructor_id' => $this->instructorId,
            'entry_type' => $this->entryType->value,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'idempotency_key' => $this->idempotencyKey,
            'created_at' => now(),
        ];
    }
}
