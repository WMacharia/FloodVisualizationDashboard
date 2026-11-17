<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
    'name',
    'email',
    'password',
    'google_id',
    'avatar',
    'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Roles: user (resident), responder (county / Red Cross staff), admin (system owner)
    public const ROLES = ['user', 'responder', 'admin'];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // Staff can issue advisories and see responder-only alerts
    public function isStaff(): bool
    {
        return in_array($this->role, ['responder', 'admin'], true);
    }

    public function roleLabel(): string
    {
        return ['admin' => 'System admin', 'responder' => 'Responder'][$this->role] ?? 'Resident';
    }

    public function places()
    {
        return $this->hasMany(SavedPlace::class);
    }

    // The system must always keep at least one administrator, whichever way a change is made
    // (console, command line or code), so it can never lock itself out.
    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('role') && $user->getOriginal('role') === 'admin' && static::isLastAdmin($user)) {
                throw new \DomainException('This is the only administrator account, so it cannot lose the admin role. Grant another administrator first.');
            }
        });
        static::deleting(function (User $user) {
            if ($user->getOriginal('role') === 'admin' && static::isLastAdmin($user)) {
                throw new \DomainException('This is the only administrator account, so it cannot be deleted. Grant another administrator first.');
            }
        });
    }

    private static function isLastAdmin(User $user): bool
    {
        return !static::where('role', 'admin')->whereKeyNot($user->getKey())->exists();
    }
}
