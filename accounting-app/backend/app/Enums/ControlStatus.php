<?php

namespace App\Enums;

/**
 * How serious an accounting-control finding is.
 *
 * WHY THREE STATES, NOT A BOOLEAN
 *
 * Some controls ask a question that is simply true or false - do this journal's
 * debits equal its credits - and a failure is a hard integrity error. Others
 * describe a situation that may be a deliberate configuration rather than a
 * fault: a posted journal dated outside every defined period is unusual, but a
 * company that has not yet opened the periods for a new year can produce one
 * legitimately while the data itself is consistent. Collapsing both into "fail"
 * would train a reader to ignore the report; collapsing both into "pass" would
 * hide the integrity errors that are its whole reason to exist. The middle state
 * is what lets the report say "look at this" without crying that the ledger is
 * broken.
 *
 * Pass is a real, reported state rather than the absence of a finding: a control
 * that ran and found nothing is information, and a report that only listed
 * problems could not distinguish "checked and clean" from "never checked".
 */
enum ControlStatus: string
{
    case Pass = 'PASS';
    case Warning = 'WARNING';
    case Fail = 'FAIL';

    public function isPass(): bool
    {
        return $this === self::Pass;
    }

    public function isWarning(): bool
    {
        return $this === self::Warning;
    }

    public function isFail(): bool
    {
        return $this === self::Fail;
    }
}
