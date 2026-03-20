<?php

return [
    /*
    | Card initialization (creation) fee charged from user's naira or crypto wallet.
    | Fee matches previous app behavior: USD component + fixed NGN processing.
    */
    'creation_fee_usd' => (float) env('VIRTUAL_CARD_CREATION_FEE_USD', 3.0),
    'creation_processing_fee_ngn' => (float) env('VIRTUAL_CARD_CREATION_PROCESSING_FEE_NGN', 500.0),
    'usd_to_ngn_rate' => (float) env('VIRTUAL_CARD_USD_TO_NGN_RATE', 1500.0),
];
