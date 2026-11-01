<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'summary', 'details', 'ip_address'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Record who did what; every staff action that changes what others see goes through here
    public static function record(string $action, string $summary, array $details = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'summary' => $summary,
            'details' => $details ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
