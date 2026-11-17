<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    public const LEVELS = ['info', 'warning', 'severe'];

    protected $fillable = [
        'kind', 'level', 'audience', 'user_id', 'parent_id', 'title', 'body', 'areas', 'action',
        'dedupe_key', 'created_by', 'expires_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'action' => 'array',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('resolved_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    // Alerts the viewer is allowed to see (guests only see public ones)
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        return $q->where(function ($q) use ($user) {
            $q->where('audience', 'public');
            if ($user) {
                $q->orWhere(fn ($q) => $q->where('audience', 'user')->where('user_id', $user->id));
                if ($user->isStaff()) $q->orWhere('audience', 'staff');
                if ($user->isAdmin()) $q->orWhere('audience', 'admin');
            }
        });
    }

    public function resolve(?User $by = null): void
    {
        $this->update(['resolved_at' => now(), 'resolved_by' => $by?->id]);
        static::where('parent_id', $this->id)->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by' => $by?->id]);
    }
}
