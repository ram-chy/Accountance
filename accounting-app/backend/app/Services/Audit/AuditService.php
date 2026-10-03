<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Http\Middleware\AssignRequestId;
use App\Models\AuditLog;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\CustomerReceipt;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetDisposal;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\CompanyContext;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;

/**
 * The single writer of the audit trail.
 *
 * Everything that records an auditable act goes through here, so the rules that
 * make the trail trustworthy are stated once rather than re-derived at each call
 * site:
 *
 *  1. WHO is never taken from the payload. The actor is the authenticated user
 *     (or the one explicitly handed in by a service that already resolved an
 *     actor inside its transaction). A client cannot claim an audit row.
 *  2. WHAT is a controlled AuditAction, never a free string.
 *  3. WHICH COMPANY is the subject's company where the subject has one. A
 *     financial event can therefore never be filed under a different company
 *     than the record it describes.
 *  4. WHAT CHANGED is a small, scrubbed subset of fields - never a secret. The
 *     deny-list is applied recursively because a nested metadata bag is just as
 *     capable of carrying a token as a top-level field.
 *
 * NO CONSTRUCTOR DEPENDENCIES, ON PURPOSE
 *
 * The service reads the current request and the authenticated user from the
 * framework rather than accepting them as collaborators. That keeps every call
 * site one line (`$audit->created($tax, $actor)`) and makes the service safe to
 * resolve anywhere - inside a locked transaction, a console command or a queued
 * job - without threading context through constructors. The explicit $actor
 * parameter is the escape hatch for the few call sites that have already
 * resolved the user and must name exactly that one.
 *
 * FINANCIAL VERSUS SECURITY
 *
 * Financial events are written inside the caller's transaction and are allowed
 * to fail: if the audit row cannot be written, the financial act should not be
 * considered done. Security events are best-effort - a login must not fail
 * because the audit insert did - so security() swallows and reports its own
 * failures.
 */
class AuditService
{
    /**
     * Field names whose values are replaced before anything is persisted. Case
     * insensitive and matched at every depth. This list is about credentials and
     * secrets specifically: it is a deny-list of things that must never be
     * written, not an allow-list of everything that may.
     */
    private const REDACTED_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'access_token',
        'refresh_token',
        'token',
        'otp',
        'secret',
        'api_key',
        'authorization',
    ];

    private const REDACTED_PLACEHOLDER = '[REDACTED]';

    /**
     * Record the creation of a model.
     */
    public function created(Model $subject, ?User $actor = null, array $metadata = []): AuditLog
    {
        return $this->record(
            AuditAction::Created,
            $subject->getMorphClass(),
            $this->keyOf($subject),
            $this->companyIdFor($subject),
            $actor,
            null,
            $subject->getAttributes(),
            $metadata,
        );
    }

    /**
     * Record an update, from the fields Eloquent actually changed.
     *
     * Null is returned when nothing changed, so a caller that saves a record
     * without altering it does not manufacture an audit row that says an update
     * happened when it did not.
     */
    public function updated(Model $subject, ?User $actor = null, array $metadata = []): ?AuditLog
    {
        $changes = $subject->getChanges();
        unset($changes['updated_at']);

        if ($changes === []) {
            return null;
        }

        $original = array_intersect_key($subject->getOriginal(), $changes);

        return $this->record(
            AuditAction::Updated,
            $subject->getMorphClass(),
            $this->keyOf($subject),
            $this->companyIdFor($subject),
            $actor,
            $original,
            $changes,
            $metadata,
        );
    }

    /**
     * Record the deletion of a model, keeping its last known field values.
     */
    public function deleted(Model $subject, ?User $actor = null, array $metadata = []): AuditLog
    {
        return $this->record(
            AuditAction::Deleted,
            $subject->getMorphClass(),
            $this->keyOf($subject),
            $this->companyIdFor($subject),
            $actor,
            $subject->getAttributes(),
            null,
            $metadata,
        );
    }

    /**
     * Record a state transition that is not a create/update/delete: close,
     * reopen, activate, deactivate. The caller passes the before and after
     * shapes it considers meaningful, because only it knows which columns define
     * the transition.
     */
    public function lifecycle(
        AuditAction $action,
        Model $subject,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): AuditLog {
        return $this->record(
            $action,
            $subject->getMorphClass(),
            $this->keyOf($subject),
            $this->companyIdFor($subject),
            $actor,
            $before,
            $after,
            $metadata,
        );
    }

    /**
     * Record a journal posting against the document that caused it.
     *
     * The interesting resource is the business document, not the journal: "this
     * invoice was posted" is the sentence a reader wants. The journal is named in
     * metadata. A manual or adjustment entry has no source document, so the
     * journal itself is the subject.
     */
    public function journalPosted(Journal $journal, User $actor): AuditLog
    {
        [$action, $auditableType, $auditableId] = $this->describePosting($journal);

        return $this->record(
            $action,
            $auditableType,
            $auditableId,
            $this->companyIdFor($journal),
            $actor,
            ['status' => JournalStatus::Draft->value],
            ['status' => JournalStatus::Posted->value],
            [
                'journal_id' => $journal->getKey(),
                'journal_number' => $journal->journal_number,
                'journal_date' => $journal->journal_date?->toDateString(),
                'source_type' => $journal->source_type?->value,
                'source_id' => $journal->source_id,
            ],
        );
    }

    /**
     * Record a security event. Best effort: a login or logout must not fail
     * because the audit write did, so a failure here is reported and swallowed.
     *
     * The company is the actor's own default company (or null when there is no
     * actor, as with a failed login for an unknown address). Security rows with a
     * null company are never returned by the company-scoped audit API - they are
     * not company accounting history.
     */
    public function security(
        AuditAction $action,
        ?User $actor = null,
        array $metadata = [],
        ?Company $company = null,
    ): ?AuditLog {
        if (! $action->isSecurity()) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not a security action.',
                $action->value,
            ));
        }

        try {
            $actor ??= Auth::user();
            $company ??= $this->defaultCompanyFor($actor);

            return $this->record(
                $action,
                $actor !== null ? $actor->getMorphClass() : null,
                $actor?->getKey() === null ? null : (int) $actor->getKey(),
                $company?->getKey() === null ? null : (int) $company->getKey(),
                $actor,
                null,
                null,
                $metadata,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The one place an audit row is inserted.
     */
    private function record(
        AuditAction $action,
        ?string $auditableType,
        ?int $auditableId,
        ?int $companyId,
        ?User $actor,
        ?array $before,
        ?array $after,
        array $metadata,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::query()->create([
            'company_id' => $companyId,
            'actor_id' => $actor?->getKey(),
            'action' => $action->value,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'request_id' => $this->requestId(),
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
            'before_data' => $this->prepare($before),
            'after_data' => $this->prepare($after),
            'metadata' => $this->prepare($metadata),
        ]);
    }

    /**
     * Translate a posted journal's source into the action and resource a reader
     * expects.
     *
     * @return array{0: AuditAction, 1: ?string, 2: ?int}
     */
    private function describePosting(Journal $journal): array
    {
        $sourceId = $journal->source_id === null ? null : (int) $journal->source_id;
        $journalId = $this->keyOf($journal);

        return match ($journal->source_type) {
            JournalSource::SalesInvoice => [AuditAction::Posted, SalesInvoice::class, $sourceId],
            JournalSource::PurchaseBill => [AuditAction::Posted, PurchaseBill::class, $sourceId],
            JournalSource::CustomerReceipt => [AuditAction::Posted, CustomerReceipt::class, $sourceId],
            JournalSource::SupplierPayment => [AuditAction::Posted, SupplierPayment::class, $sourceId],
            JournalSource::CashBankTransaction => [AuditAction::Posted, CashBankTransaction::class, $sourceId],
            JournalSource::CreditDebitNote => [AuditAction::Posted, CreditDebitNote::class, $sourceId],
            JournalSource::FixedAsset => [AuditAction::Capitalised, FixedAsset::class, $sourceId],
            JournalSource::FixedAssetDepreciation => [AuditAction::Depreciated, FixedAssetDepreciation::class, $sourceId],
            JournalSource::FixedAssetDisposal => [AuditAction::Disposed, FixedAssetDisposal::class, $sourceId],
            // Manual and Adjustment, and any future source that does not name a
            // document: the journal is itself the subject.
            default => [AuditAction::Posted, $journal->getMorphClass(), $journalId],
        };
    }

    /**
     * The subject's own company, or the active context as a fallback.
     */
    private function companyIdFor(Model $subject): ?int
    {
        $companyId = $subject->getAttribute('company_id');

        if ($companyId !== null) {
            return (int) $companyId;
        }

        try {
            $context = app(CompanyContext::class);

            return $context->has() ? $context->get()?->getKey() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function defaultCompanyFor(?User $actor): ?Company
    {
        if ($actor === null) {
            return null;
        }

        try {
            return $actor->defaultCompany();
        } catch (\Throwable) {
            return null;
        }
    }

    private function keyOf(Model $subject): ?int
    {
        $key = $subject->getKey();

        return $key === null ? null : (int) $key;
    }

    /**
     * Normalise and scrub a field bag before it is persisted. Null for an empty
     * bag so "nothing was recorded" is null rather than an empty JSON object.
     */
    private function prepare(?array $data): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }

        $normalised = $this->normalizeValue($data);

        return is_array($normalised) ? $this->scrub($normalised) : null;
    }

    /**
     * Turn values into things json_encode can carry and Eloquent's array cast can
     * round-trip: enum to its backing value, date to an ISO string, model to its
     * attributes, arbitrary object to its public shape.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Model) {
            return $this->normalizeValue($value->getAttributes());
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if (is_array($value)) {
            $normalised = [];

            foreach ($value as $key => $item) {
                $normalised[$key] = $this->normalizeValue($item);
            }

            return $normalised;
        }

        if (is_object($value)) {
            return $this->normalizeValue((array) $value);
        }

        return $value;
    }

    /**
     * Replace every value under a deny-listed key, at any depth, with a marker.
     */
    private function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_FIELDS, true)) {
                $clean[$key] = self::REDACTED_PLACEHOLDER;

                continue;
            }

            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }

    private function requestId(): ?string
    {
        try {
            return request()->attributes->get(AssignRequestId::ATTRIBUTE);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ipAddress(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            $agent = request()->userAgent();

            return is_string($agent) ? mb_substr($agent, 0, 255) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
