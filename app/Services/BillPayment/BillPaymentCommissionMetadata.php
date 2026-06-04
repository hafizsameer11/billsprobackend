<?php

namespace App\Services\BillPayment;

use App\Models\Transaction;
use App\Services\Admin\TransactionPricingSnapshotService;

class BillPaymentCommissionMetadata
{
    public function __construct(
        protected BillCommissionResolver $commissions,
        protected TransactionPricingSnapshotService $snapshots,
    ) {}

    public function applyOnCompleted(Transaction $transaction): void
    {
        $meta = is_array($transaction->metadata) ? $transaction->metadata : [];
        $category = (string) ($meta['categoryCode'] ?? $transaction->category ?? '');

        if (! in_array(strtolower($category), ['airtime', 'data', 'betting'], true)) {
            $this->snapshots->attachToTransaction($transaction);

            return;
        }

        $scene = strtolower($category) === 'betting' ? 'betting' : (strtolower($category) === 'data' ? 'data' : 'airtime');
        $entity = (string) ($meta['providerName'] ?? $meta['providerCode'] ?? '');
        if ($entity === '') {
            $entity = (string) ($transaction->bank_name ?? '');
        }

        $resolved = $this->commissions->resolveCommission($scene, $entity);
        if ($resolved) {
            $amount = (float) $transaction->amount;
            $commissionNgn = $this->commissions->estimatedCommissionNgn($amount, $resolved['commission_pct']);
            $meta['commission_pct'] = $resolved['commission_pct'];
            $meta['commission_tier_key'] = $resolved['tier_key'];
            $meta['commission_entity_key'] = $resolved['entity_key'];
            $meta['commission_scene'] = $resolved['scene'];
            $meta['estimated_commission_ngn'] = $commissionNgn;
            $transaction->update(['metadata' => $meta]);
        }

        $this->snapshots->attachToTransaction($transaction->fresh());
    }
}
