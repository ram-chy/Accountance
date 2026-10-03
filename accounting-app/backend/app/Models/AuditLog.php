<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable audit record.
 *
 * THE GUARD IS THE POINT
 *
 * Ordinary code must never change or delete a row here - that is the whole
 * promise of an audit trail. Rather than rely on "there is no controller that
 * does it", the model refuses the operation at the Eloquent layer, so a future
 * route, a tinker session or a stray mass update fails loudly instead of
 * silently rewriting history. There is deliberately no update or delete API, and
 * this guard is the backstop under that absence.
 *
 * The guard fires for real model writes only; RefreshDatabase truncates tables
 * with the query builder, so test teardown is unaffected.
 *
 * NO UPDATED_AT
 *
 * The table has created_at only. Setting UPDATED_AT to null tells Eloquent not
 * to manage or write one, which is honest: the record has no second timestamp
 * because it never changes.
 */
#[Fillable([
    'company_id',
    'actor_id',
    'action',
    'auditable_type',
    'auditable_id',
    'request_id',
    'ip_address',
    'user_agent',
    'before_data',
    'after_data',
    'metadata',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'before_data' => 'array',
            'after_data' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Audit records are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Audit records are immutable and cannot be deleted.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
