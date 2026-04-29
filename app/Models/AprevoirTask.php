<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AprevoirTask extends Model
{
    protected $table = 'a_prevoir_tasks';

    protected $fillable = [
        'date',
        'fin_date',
        'assignee_type',
        'assignee_id',
        'assignee_label_free',
        'vehicle_id',
        'remorque_id',
        'task',
        'loading_place',
        'delivery_place',
        'comment',
        'is_direct',
        'is_boursagri',
        'boursagri_contract_number',
        'indicators',
        'pointed',
        'pointed_at',
        'pointed_by_user_id',
        'position',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'fin_date' => 'date',
            'is_direct' => 'boolean',
            'is_boursagri' => 'boolean',
            'indicators' => 'array',
            'pointed' => 'boolean',
            'pointed_at' => 'datetime',
            'position' => 'integer',
            'vehicle_id' => 'integer',
            'remorque_id' => 'integer',
            'assignee_id' => 'integer',
            'assignee_label_free' => 'string',
            'pointed_by_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function remorque(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'remorque_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function pointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pointed_by_user_id');
    }

    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigneeDepot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'assignee_id');
    }

    public function assigneeTransporter(): BelongsTo
    {
        return $this->belongsTo(Transporter::class, 'assignee_id');
    }
}
