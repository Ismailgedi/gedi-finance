<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * Record an audit event using the existing audit_logs table.
     *
     * $auditable may be any model (or null for events with no clear
     * subject, e.g. a failed authorization check). Never pass password
     * values (plaintext or hashed) in $oldValues/$newValues.
     */
    public static function record(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): self {
        return static::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : 'system',
            'auditable_id' => $auditable ? (string) $auditable->getKey() : '0',
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => RequestFacade::ip(),
            'user_agent' => RequestFacade::userAgent(),
        ]);
    }

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
