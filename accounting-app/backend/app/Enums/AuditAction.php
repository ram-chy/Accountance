<?php

namespace App\Enums;

/**
 * The controlled vocabulary of auditable actions.
 *
 * WHY A NEW ENUM RATHER THAN THE EXISTING ONES
 *
 * JournalStatus, TransactionStatus, PaymentStatus, PeriodStatus and the rest
 * describe the STATE a record is in. An audit action describes the ACT that moved
 * it - "this journal was POSTED", "this period was CLOSED" - so none of the
 * existing enums can stand in for it without saying something else.
 *
 * The set is deliberately small. It names only actions the application actually
 * performs and records. A vocabulary that advertised actions nothing produces
 * would be a list of promises the audit trail does not keep.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 *   REVERSED
 *       There is no journal reversal in this application. JournalStatus is
 *       DRAFT/POSTED only and posting is one-way; a correction is a new,
 *       separate journal. See PHASE_13_REPORT.md for the documented limitation.
 *
 *   SUBMITTED / SENT
 *       There is no submission or send workflow, so there is no such event to
 *       record. APPROVED is present because Phase 16 introduced one approval
 *       workflow - a budget is approved, and that is the accounting-control act
 *       the phase is about.
 *
 * The distinction between a security action and a financial one is asked rather
 * than compared, so "which events may the audit report show" has one answer in
 * the codebase instead of one per call site.
 */
enum AuditAction: string
{
    // Record lifecycle.
    case Created = 'CREATED';
    case Updated = 'UPDATED';
    case Deleted = 'DELETED';

    // Financial posting, per the lifecycle the entry belongs to.
    case Posted = 'POSTED';
    case Capitalised = 'CAPITALISED';
    case Depreciated = 'DEPRECIATED';
    case Disposed = 'DISPOSED';
    case Reconciled = 'RECONCILED';

    // Accounting-control acts.
    case Closed = 'CLOSED';
    case Reopened = 'REOPENED';
    case Activated = 'ACTIVATED';
    case Deactivated = 'DEACTIVATED';

    /*
    | Phase 16. A budget draft becomes final. It is a lifecycle act like CLOSED,
    | not an update: the draft's fields do not change, its state does, and the
    | point of recording it is that somebody decided the plan was final.
    */
    case Approved = 'APPROVED';

    /*
    | Security events. These are not company-scoped in the accounting sense: they
    | concern a user and a credential, and the audit row attributes them to the
    | actor's own company (or none) rather than to the resource's.
    */
    case Login = 'LOGIN';
    case LoginFailed = 'LOGIN_FAILED';
    case Logout = 'LOGOUT';
    case PasswordReset = 'PASSWORD_RESET';
    case PasswordChanged = 'PASSWORD_CHANGED';

    /**
     * Is this a security event rather than a financial/business one?
     */
    public function isSecurity(): bool
    {
        return match ($this) {
            self::Login,
            self::LoginFailed,
            self::Logout,
            self::PasswordReset,
            self::PasswordChanged => true,
            default => false,
        };
    }

    /**
     * Is this a financial/business event? The complement of isSecurity().
     */
    public function isFinancial(): bool
    {
        return ! $this->isSecurity();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function financialValues(): array
    {
        return array_values(array_map(
            fn (self $action) => $action->value,
            array_filter(self::cases(), fn (self $action) => $action->isFinancial()),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function securityValues(): array
    {
        return array_values(array_map(
            fn (self $action) => $action->value,
            array_filter(self::cases(), fn (self $action) => $action->isSecurity()),
        ));
    }
}
