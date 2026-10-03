<?php

namespace App\Enums;

/**
 * The lifecycle of a fixed asset.
 *
 * Four states, and the shape is deliberately the shortest one the accounting
 * actually requires:
 *
 *   DRAFT              recorded, not yet in the ledger. No journal exists.
 *   ACTIVE             capitalised. Carrying value, depreciating.
 *   FULLY_DEPRECIATED  carrying value has reached salvage. Still on the register,
 *                      still owned, still disposalable - not yet gone.
 *   DISPOSED           gone. Terminal.
 *
 * WHY NOT TransactionStatus
 *
 * A fixed asset is not a document a counterparty settles, so `PAID` and
 * `PARTIALLY_PAID` mean nothing here, and `POSTED` is the wrong word for a state
 * an asset never leaves - an asset stays in the ledger for years after it is
 * capitalised. The one concept Phase 5's enum *does* share, DRAFT meaning "not
 * yet part of the permanent record", is what `ACTIVE` continues here rather than
 * a second `POSTED`. So a new enum is the smaller change, not the larger one.
 *
 * WHY NOT HARD-CODED STRINGS
 *
 * Same reason as every other lifecycle enum in this application: the database
 * column is a plain string, and this is the application's contract for what may
 * appear in it. The status is never client-supplied - it is written by the
 * capitalisation, depreciation and disposal services - so these cases describe
 * transitions the server performs, not states a user picks.
 *
 * `isDepreciable()` and `isDisposable()` are asked rather than the raw status
 * compared, so the rule "a disposed asset takes no further depreciation" and "a
 * draft asset cannot be disposed" each have exactly one answer in the codebase
 * instead of one per call site.
 */
enum FixedAssetStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case FullyDepreciated = 'FULLY_DEPRECIATED';
    case Disposed = 'DISPOSED';

    /**
     * Is this asset recorded at all, in the sense a user means by "it exists"?
     */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Has the asset been capitalised into the ledger?
     *
     * True for every state after capitalisation, including DISPOSED. This is the
     * question "does this asset have a capitalisation journal?", which stays true
     * after disposal - the journal is permanent.
     */
    public function isCapitalised(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Does this asset still appear on the register as something the company owns?
     *
     * True for ACTIVE and FULLY_DEPRECIATED, false for a draft (never owned in the
     * accounting sense) and false for DISPOSED (no longer owned).
     */
    public function isOnRegister(): bool
    {
        return $this === self::Active || $this === self::FullyDepreciated;
    }

    /**
     * May a depreciation entry be posted for this asset?
     *
     * Only ACTIVE. A draft has no carrying value to depreciate, a fully
     * depreciated asset has none left to charge, and a disposed asset is gone -
     * which is exactly the rule in §23 of the brief, expressed once.
     */
    public function isDepreciable(): bool
    {
        return $this === self::Active;
    }

    /**
     * May this asset be disposed?
     *
     * Both ACTIVE and FULLY_DEPRECIATED. A fully depreciated asset is still owned
     * and still has a book value to dispose of; refusing it would leave a company
     * unable to sell a worn-out vehicle, which is the most common disposal there
     * is. A draft has nothing in the ledger to dispose of, and a disposed asset
     * has already been disposed of.
     */
    public function isDisposable(): bool
    {
        return $this->isOnRegister();
    }

    /**
     * Is the asset finished with, permanently?
     */
    public function isDisposed(): bool
    {
        return $this === self::Disposed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
