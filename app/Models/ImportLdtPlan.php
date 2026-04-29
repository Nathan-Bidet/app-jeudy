<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLdtPlan extends Model
{
    protected $table = 'import_ldt_plan';

    protected $fillable = [
        'legacy_id',
        'date_day',
        'driver_id',
        'driver_free',
        'vehicle_id',
        'vehicle_free',
        'task',
        'comments',
        'flag_paper',
        'flag_direct',
        'flag_boursagri',
        'boursagri_contract',
        'fin',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'sort_order',
        'sms_livre',
        'imported_at',
        'import_error',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'date_day' => 'date',
            'driver_id' => 'integer',
            'vehicle_id' => 'integer',
            'flag_paper' => 'boolean',
            'flag_direct' => 'boolean',
            'flag_boursagri' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'sort_order' => 'integer',
            'sms_livre' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }
}
