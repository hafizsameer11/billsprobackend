<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceMaintenanceSetting extends Model
{
    protected $fillable = [
        'slug',
        'group',
        'label',
        'is_under_maintenance',
        'notice_title',
        'notice_message',
        'alternate_hint',
    ];

    protected function casts(): array
    {
        return [
            'is_under_maintenance' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'slug' => $this->slug,
            'group' => $this->group,
            'label' => $this->label,
            'notice_title' => $this->notice_title,
            'notice_message' => $this->notice_message,
            'alternate_hint' => $this->alternate_hint,
        ];
    }
}
