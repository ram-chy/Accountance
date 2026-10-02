<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Models\TaxAccountMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxAccountMapping|null
 */
class TaxAccountMappingResource extends JsonResource
{
    /**
     * Null-safe throughout.
     *
     * GET /taxes/{tax}/account-mapping has to answer for a tax that has never been
     * mapped, and it must not 404 to do it: "this tax has no account mapping yet"
     * is the state the endpoint exists to report, and a 404 would be
     * indistinguishable from a tax that does not exist. So the resource renders a
     * mapping with both accounts null rather than a missing one.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tax_id' => $this->resource?->tax_id,
            'output_account_id' => $this->resource?->output_account_id,
            'input_account_id' => $this->resource?->input_account_id,
            /*
             * The accounts' identity alongside their ids. A client rendering a
             * mapping cannot show "2100 -> 1400" from ids alone, and forcing a
             * second request per account would be an N+1 over the HTTP layer - the
             * same problem the brief forbids in queries, in a worse place.
             */
            'output_account' => $this->account($this->resource?->outputAccount),
            'input_account' => $this->account($this->resource?->inputAccount),
            'created_by' => $this->resource?->created_by,
            'updated_by' => $this->resource?->updated_by,
            'created_at' => $this->resource?->created_at?->toIso8601String(),
            'updated_at' => $this->resource?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A nested account, or null when there is none or it was not eager loaded.
     *
     * `?->` on the relation rather than `whenLoaded`, because a null-safe chain on
     * an unloaded relation is not the same as a null one: an unloaded relation
     * returns null here, which renders the same as "not set". That is the correct
     * rendering for a caller that did not ask for the detail, and both controllers
     * that use this resource eager-load the accounts explicitly.
     *
     * @return array<string, mixed>|null
     */
    private function account(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type->value,
            'is_active' => $account->is_active,
        ];
    }
}
