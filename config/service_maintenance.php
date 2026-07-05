<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maintainable services catalog
    |--------------------------------------------------------------------------
    |
    | Each row is upserted into service_maintenance_settings. Per-network bill
    | payment rows (e.g. bill_payment.airtime.MTN) are synced from providers.
    |
    */
    'catalog' => [
        ['slug' => 'virtual_card.mastercard', 'group' => 'Virtual Cards', 'label' => 'Mastercard — all operations'],
        ['slug' => 'virtual_card.mastercard.create', 'group' => 'Virtual Cards', 'label' => 'Mastercard — create card'],
        ['slug' => 'virtual_card.mastercard.fund', 'group' => 'Virtual Cards', 'label' => 'Mastercard — fund card'],
        ['slug' => 'virtual_card.visa', 'group' => 'Virtual Cards', 'label' => 'Visa — all operations'],
        ['slug' => 'virtual_card.visa.create', 'group' => 'Virtual Cards', 'label' => 'Visa — create card'],
        ['slug' => 'virtual_card.visa.fund', 'group' => 'Virtual Cards', 'label' => 'Visa — fund card'],
        ['slug' => 'bill_payment.airtime', 'group' => 'Bill Payments', 'label' => 'Airtime — all networks'],
        ['slug' => 'bill_payment.data', 'group' => 'Bill Payments', 'label' => 'Data — all networks'],
        ['slug' => 'bill_payment.electricity', 'group' => 'Bill Payments', 'label' => 'Electricity'],
        ['slug' => 'bill_payment.cable_tv', 'group' => 'Bill Payments', 'label' => 'Cable TV'],
        ['slug' => 'bill_payment.internet', 'group' => 'Bill Payments', 'label' => 'Internet'],
        ['slug' => 'bill_payment.betting', 'group' => 'Bill Payments', 'label' => 'Betting'],
    ],
];
