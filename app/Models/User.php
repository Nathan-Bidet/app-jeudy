<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'mobile_phone',
        'directory_phones',
        'internal_number',
        'photo_path',
        'glpi_url',
        'depot_address',
        'depot_id',
        'birthday',
        'driving_license_valid_until',
        'fimo_valid_until',
        'adr_valid_until',
        'fco_valid_until',
        'caces_valid_until',
        'certiphyto_valid_until',
        'nacelle_valid_until',
        'eco_conduite_valid_until',
        'operations_comment',
        'display_order',
        'occupational_health_valid_until',
        'sst_valid_until',
        'password',
        'sector_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'totp_recovery_codes',
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
            'totp_enabled_at' => 'datetime',
            'totp_locked_until' => 'datetime',
            'birthday' => 'date',
            'directory_phones' => 'array',
            'depot_id' => 'integer',
            'driving_license_valid_until' => 'date',
            'fimo_valid_until' => 'date',
            'adr_valid_until' => 'date',
            'fco_valid_until' => 'date',
            'caces_valid_until' => 'date',
            'certiphyto_valid_until' => 'date',
            'nacelle_valid_until' => 'date',
            'eco_conduite_valid_until' => 'date',
            'display_order' => 'integer',
            'occupational_health_valid_until' => 'date',
            'sst_valid_until' => 'date',
        ];
    }

    public function securityAuditLogs(): HasMany
    {
        return $this->hasMany(SecurityAuditLog::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function depots(): BelongsToMany
    {
        return $this->belongsToMany(Depot::class)->withTimestamps();
    }

    public function accessExceptions(): HasMany
    {
        return $this->hasMany(AccessException::class);
    }

    public function directoryFiles(): HasMany
    {
        return $this->hasMany(UserFile::class);
    }

    public function uploadedDirectoryFiles(): HasMany
    {
        return $this->hasMany(UserFile::class, 'uploaded_by_user_id');
    }
}
