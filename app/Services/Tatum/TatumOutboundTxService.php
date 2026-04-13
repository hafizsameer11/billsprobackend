<?php

namespace App\Services\Tatum;

use App\Models\CryptoDepositAddress;
use App\Models\MasterWallet;
use App\Services\Crypto\KeyEncryptionService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tatum V3 on-chain sends: master wallet (user withdrawals) and deposit-address sweeps (flush to vendor).
 */
class TatumOutboundTxService
{
    public function __construct(
        protected KeyEncryptionService $encryption
    ) {}

    /**
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    public function sendExternalFromMasterWallet(
        MasterWallet $wallet,
        string $toAddress,
        string $amount,
        string $currency,
        string $blockchainNormalized
    ): array {
        $pk = $wallet->decryptedPrivateKey($this->encryption);
        $bc = strtolower(trim($blockchainNormalized));
        $cur = strtoupper(trim($currency));

        return match (true) {
            in_array($bc, ['ethereum', 'eth'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'ethereum', (string) $wallet->address, false),
            in_array($bc, ['bsc', 'binance', 'binancesmartchain'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'bsc', (string) $wallet->address, false),
            in_array($bc, ['polygon', 'matic'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'polygon', (string) $wallet->address, false),
            $bc === 'tron' => $this->sendTron($pk, $toAddress, $amount, $cur),
            $bc === 'bitcoin' => $this->sendUtxo($pk, (string) $wallet->address, $toAddress, $amount, 'bitcoin'),
            $bc === 'litecoin' => $this->sendUtxo($pk, (string) $wallet->address, $toAddress, $amount, 'litecoin'),
            $bc === 'dogecoin' || $bc === 'doge' => $this->sendUtxo($pk, (string) $wallet->address, $toAddress, $amount, 'dogecoin'),
            in_array($bc, ['xrp', 'ripple'], true) => $this->sendXrp($pk, $toAddress, $amount, $cur),
            $bc === 'solana' || $bc === 'sol' => $this->sendSolana($pk, (string) $wallet->address, $toAddress, $amount, $cur),
            default => throw new RuntimeException("On-chain send not implemented for blockchain: {$bc}. Add a master_wallet row and handler."),
        };
    }

    /**
     * Sweep from a user deposit address (encrypted key on CryptoDepositAddress) to vendor treasury.
     *
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    public function sendFromDepositAddress(
        CryptoDepositAddress $deposit,
        string $toAddress,
        string $amount,
        string $currency,
        string $blockchainNormalized
    ): array {
        if (empty($deposit->private_key_encrypted)) {
            throw new RuntimeException('Deposit address has no private key stored; cannot sweep on-chain.');
        }
        $pk = $this->encryption->decrypt($deposit->private_key_encrypted);
        $bc = strtolower(trim($blockchainNormalized));
        $cur = strtoupper(trim($currency));

        return match (true) {
            in_array($bc, ['ethereum', 'eth'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'ethereum', (string) $deposit->address, true),
            in_array($bc, ['bsc', 'binance', 'binancesmartchain'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'bsc', (string) $deposit->address, true),
            in_array($bc, ['polygon', 'matic'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'polygon', (string) $deposit->address, true),
            $bc === 'tron' => $this->sendTron($pk, $toAddress, $amount, $cur),
            $bc === 'bitcoin' => $this->sendUtxo($pk, $deposit->address, $toAddress, $amount, 'bitcoin'),
            $bc === 'litecoin' => $this->sendUtxo($pk, $deposit->address, $toAddress, $amount, 'litecoin'),
            $bc === 'dogecoin' || $bc === 'doge' => $this->sendUtxo($pk, $deposit->address, $toAddress, $amount, 'dogecoin'),
            in_array($bc, ['xrp', 'ripple'], true) => $this->sendXrp($pk, $toAddress, $amount, $cur),
            $bc === 'solana' || $bc === 'sol' => $this->sendSolana($pk, $deposit->address, $toAddress, $amount, $cur),
            default => throw new RuntimeException("Sweep not implemented for blockchain: {$bc}."),
        };
    }

    /**
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    protected function sendEvm(
        string $fromPrivateKey,
        string $toAddress,
        string $amount,
        string $currency,
        string $chain,
        ?string $sourceAddress = null,
        bool $allowGasTopUp = false
    ): array {
        $path = match ($chain) {
            'ethereum' => '/ethereum/transaction',
            'bsc' => '/bsc/transaction',
            'polygon' => '/polygon/transaction',
            default => throw new RuntimeException('Unknown EVM chain: '.$chain),
        };

        $isNative = match ($chain) {
            'ethereum' => in_array($currency, ['ETH'], true),
            'bsc' => in_array($currency, ['BNB', 'BSC'], true),
            'polygon' => in_array($currency, ['MATIC', 'POLYGON'], true),
            default => false,
        };

        $decimals = $isNative
            ? 18
            : $this->erc20DisplayDecimals($chain, $currency);

        if (! $isNative && $allowGasTopUp && $sourceAddress) {
            $this->ensureEvmGasTopUp($chain, $sourceAddress);
        }

        $payload = [
            'fromPrivateKey' => $fromPrivateKey,
            'to' => $toAddress,
            'amount' => $this->formatAmountForTatum($amount, $decimals),
        ];

        if ($isNative) {
            $payload['currency'] = match ($chain) {
                'ethereum' => 'ETH',
                'bsc' => 'BSC',
                'polygon' => 'MATIC',
                default => 'ETH',
            };
        } else {
            $contracts = config('tatum.contracts.'.$chain, []);
            $contract = $contracts[$currency] ?? null;
            if (! $contract) {
                throw new RuntimeException("No Tatum contract configured for {$chain}/{$currency}.");
            }
            $payload['currency'] = $currency;
            $payload['contractAddress'] = $contract;
            $defaultGasLimit = (int) config("tatum.evm_token_fee.{$chain}.gas_limit", 120000);
            if ($defaultGasLimit > 0) {
                $payload['fee'] = [
                    'gasLimit' => $defaultGasLimit,
                ];
            }
        }
        try {
            $raw = $this->postV3($path, $payload);
        } catch (RuntimeException $e) {
            if (! $isNative && str_contains(strtolower($e->getMessage()), 'intrinsic gas too low')) {
                $raw = $this->retryEvmTokenWithHigherGasLimit($path, $payload, $e);
            } else {
                throw $e;
            }
        }
        $txId = (string) ($raw['txId'] ?? $raw['tx_id'] ?? '');

        if ($txId === '') {
            throw new RuntimeException('Tatum returned no txId: '.json_encode($raw));
        }

        return ['txId' => $txId, 'fee' => isset($raw['fee']) ? (string) $raw['fee'] : null, 'raw' => $raw];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function retryEvmTokenWithHigherGasLimit(string $path, array $payload, RuntimeException $e): array
    {
        $message = strtolower($e->getMessage());
        $current = (int) data_get($payload, 'fee.gasLimit', 0);
        $want = 0;
        if (preg_match('/want\s+(\d+)/i', $message, $m) === 1) {
            $want = (int) ($m[1] ?? 0);
        }

        $multiplier = (float) config('tatum.evm_token_fee.retry_multiplier', 1.3);
        $minBump = (int) config('tatum.evm_token_fee.retry_min_bump', 25000);

        $retryGasLimit = max(
            $current + $minBump,
            (int) ceil($want > 0 ? $want * $multiplier : ($current > 0 ? $current * $multiplier : 120000))
        );

        $payload['fee'] = [
            'gasLimit' => $retryGasLimit,
        ];

        return $this->postV3($path, $payload);
    }

    protected function ensureEvmGasTopUp(string $chain, string $sourceAddress): void
    {
        if (! (bool) config('tatum.gas_topup.enabled', true)) {
            return;
        }

        $minBalance = (string) config("tatum.gas_topup.evm_min_native_balance.{$chain}", '0');
        $topUpAmount = (string) config("tatum.gas_topup.evm_topup_amount.{$chain}", '0');

        if ($this->compareDecimalStrings($minBalance, '0', 18) <= 0 || $this->compareDecimalStrings($topUpAmount, '0', 18) <= 0) {
            return;
        }

        $nativeBalance = $this->getEvmNativeBalance($chain, $sourceAddress);
        if ($this->compareDecimalStrings($nativeBalance, $minBalance, 18) >= 0) {
            return;
        }

        $master = MasterWallet::query()->where('blockchain', $chain)->first();
        if (! $master || ! $master->address) {
            throw new RuntimeException("Gas top-up failed: no master wallet configured for {$chain}.");
        }

        if (strtolower($master->address) === strtolower($sourceAddress)) {
            throw new RuntimeException("Gas top-up failed: source address equals {$chain} master wallet; fund native gas on treasury wallet.");
        }

        $masterPk = $master->decryptedPrivateKey($this->encryption);
        $this->sendEvm(
            $masterPk,
            $sourceAddress,
            $topUpAmount,
            $this->nativeCurrencyForEvmChain($chain),
            $chain,
            (string) $master->address,
            false
        );
    }

    protected function getEvmNativeBalance(string $chain, string $address): string
    {
        $path = match ($chain) {
            'ethereum' => '/ethereum/account/balance/',
            'bsc' => '/bsc/account/balance/',
            'polygon' => '/polygon/account/balance/',
            default => throw new RuntimeException('Unknown EVM chain for balance check: '.$chain),
        };

        $raw = $this->getV3($path.rawurlencode($address));
        $bal = (string) ($raw['balance'] ?? '0');
        if (! is_numeric($bal)) {
            return '0';
        }

        return $bal;
    }

    protected function nativeCurrencyForEvmChain(string $chain): string
    {
        return match ($chain) {
            'ethereum' => 'ETH',
            'bsc' => 'BSC',
            'polygon' => 'MATIC',
            default => 'ETH',
        };
    }

    /**
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    protected function sendTron(string $fromPrivateKey, string $toAddress, string $amount, string $currency): array
    {
        $usdtContract = config('tatum.contracts.tron.USDT');
        $feeLimit = (int) config('tatum.tron.fee_limit_sun', 100_000_000);

        if (in_array($currency, ['TRX', 'TRON'], true)) {
            $payload = [
                'fromPrivateKey' => $fromPrivateKey,
                'to' => $toAddress,
                'amount' => $this->formatAmountForTatum($amount, 6),
            ];
            $raw = $this->postV3('/tron/transaction', $payload);
        } elseif (in_array($currency, ['USDT', 'USDT_TRON'], true) && $usdtContract) {
            $payload = [
                'fromPrivateKey' => $fromPrivateKey,
                'to' => $toAddress,
                'amount' => $this->formatAmountForTatum($amount, 6),
                'tokenAddress' => $usdtContract,
                'feeLimit' => $feeLimit,
            ];
            $raw = $this->postV3('/tron/trc20/transaction', $payload);
        } else {
            throw new RuntimeException("TRON send not configured for currency: {$currency}");
        }

        $txId = (string) ($raw['txId'] ?? $raw['txID'] ?? '');
        if ($txId === '') {
            throw new RuntimeException('Tatum TRON: no txId: '.json_encode($raw));
        }

        return ['txId' => $txId, 'fee' => null, 'raw' => $raw];
    }

    /**
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    protected function sendXrp(string $fromSecret, string $toAddress, string $amount, string $currency): array
    {
        if (strtoupper($currency) !== 'XRP') {
            throw new RuntimeException('XRP network supports native XRP only on this path.');
        }

        $raw = $this->postV3('/xrp/transaction', [
            'fromSecret' => $fromSecret,
            'to' => $toAddress,
            'amount' => $this->formatAmountForTatum($amount, 6),
        ]);

        $txId = (string) ($raw['txId'] ?? $raw['tx_id'] ?? '');
        if ($txId === '') {
            throw new RuntimeException('Tatum XRP: no txId: '.json_encode($raw));
        }

        return ['txId' => $txId, 'fee' => isset($raw['fee']) ? (string) $raw['fee'] : null, 'raw' => $raw];
    }

    /**
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    protected function sendSolana(
        string $fromPrivateKey,
        string $fromAddress,
        string $toAddress,
        string $amount,
        string $currency
    ): array {
        $cur = strtoupper(trim($currency));

        if (in_array($cur, ['SOL', 'SOLANA'], true)) {
            $payload = [
                'from' => $fromAddress,
                'fromPrivateKey' => $fromPrivateKey,
                'to' => $toAddress,
                'amount' => $this->formatAmountForTatum($amount, 9),
            ];
        } else {
            $contracts = config('tatum.contracts.solana', []);
            $mint = $contracts[$cur] ?? null;
            if (! $mint) {
                throw new RuntimeException("No Solana mint configured for {$currency}.");
            }
            $payload = [
                'from' => $fromAddress,
                'fromPrivateKey' => $fromPrivateKey,
                'to' => $toAddress,
                'amount' => $this->formatAmountForTatum($amount, 9),
                'contractAddress' => $mint,
            ];
        }

        $raw = $this->postV3('/solana/transaction', $payload);
        $txId = (string) ($raw['txId'] ?? $raw['tx_id'] ?? '');
        if ($txId === '') {
            throw new RuntimeException('Tatum Solana: no txId: '.json_encode($raw));
        }

        return ['txId' => $txId, 'fee' => isset($raw['fee']) ? (string) $raw['fee'] : null, 'raw' => $raw];
    }

    /**
     * @return array{txId: string, fee?: string|null, raw: array<string, mixed>}
     */
    protected function sendUtxo(
        string $fromPrivateKey,
        string $fromAddress,
        string $toAddress,
        string $amount,
        string $chain
    ): array {
        $path = '/'.$chain.'/transaction';
        if ((float) $amount <= 0) {
            throw new RuntimeException('UTXO send amount must be positive.');
        }

        $feeBtc = match ($chain) {
            'bitcoin' => 0.00002,
            'litecoin' => 0.0001,
            'dogecoin' => 1.0,
            default => 0.0001,
        };

        $payload = [
            'fromAddress' => [
                [
                    'address' => $fromAddress,
                    'privateKey' => $fromPrivateKey,
                ],
            ],
            'to' => [
                [
                    'address' => $toAddress,
                    'value' => round((float) $amount, 8),
                ],
            ],
            'fee' => number_format($feeBtc, 8, '.', ''),
            'changeAddress' => $fromAddress,
        ];

        $raw = $this->postV3($path, $payload);
        $txId = (string) ($raw['txId'] ?? '');
        if ($txId === '') {
            throw new RuntimeException('Tatum UTXO: no txId: '.json_encode($raw));
        }

        return ['txId' => $txId, 'fee' => (string) $feeBtc, 'raw' => $raw];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postV3(string $path, array $body): array
    {
        $key = (string) config('tatum.api_key', '');
        if ($key === '') {
            throw new RuntimeException('TATUM_API_KEY is not configured.');
        }

        $url = rtrim((string) config('tatum.base_url_v3'), '/').$path;
        $response = Http::withHeaders([
            'x-api-key' => $key,
            'Content-Type' => 'application/json',
        ])
            ->timeout((int) config('tatum.timeout', 120))
            ->post($url, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                'Tatum POST '.$path.' failed ('.$response->status().'): '.$response->body()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getV3(string $path): array
    {
        $key = (string) config('tatum.api_key', '');
        if ($key === '') {
            throw new RuntimeException('TATUM_API_KEY is not configured.');
        }

        $url = rtrim((string) config('tatum.base_url_v3'), '/').$path;
        $response = Http::withHeaders([
            'x-api-key' => $key,
            'Content-Type' => 'application/json',
        ])
            ->timeout((int) config('tatum.timeout', 120))
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException(
                'Tatum GET '.$path.' failed ('.$response->status().'): '.$response->body()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Human-readable decimal string for Tatum (ethers BigNumber). Avoids float noise like
     * 5.323333329999999641 which triggers "invalid BigNumber string".
     */
    protected function formatAmountForTatum(string $amount, int $maxDecimals): string
    {
        $amount = trim($amount);
        if ($amount === '' || ! is_numeric($amount)) {
            throw new RuntimeException('Invalid numeric amount: '.$amount);
        }

        if (extension_loaded('bcmath')) {
            if (bccomp($amount, '0', $maxDecimals) <= 0) {
                throw new RuntimeException('Amount must be positive: '.$amount);
            }

            $scaled = bcadd($amount, '0', $maxDecimals);

            return $this->trimInsignificantFractionZeros($scaled);
        }

        $f = (float) $amount;
        if ($f <= 0) {
            throw new RuntimeException('Amount must be positive: '.$amount);
        }

        return $this->trimInsignificantFractionZeros(
            number_format($f, $maxDecimals, '.', '')
        );
    }

    protected function erc20DisplayDecimals(string $chain, string $currency): int
    {
        $map = config('tatum.evm_erc20_decimals.'.$chain, []);
        $key = strtoupper(trim($currency));
        if (isset($map[$key])) {
            return (int) $map[$key];
        }

        return (int) config('tatum.evm_erc20_decimals_default', 18);
    }

    protected function trimInsignificantFractionZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-' ? '0' : $value;
    }

    protected function compareDecimalStrings(string $a, string $b, int $scale): int
    {
        if (extension_loaded('bcmath')) {
            return bccomp((string) $a, (string) $b, $scale);
        }

        $af = (float) $a;
        $bf = (float) $b;
        if ($af === $bf) {
            return 0;
        }

        return $af > $bf ? 1 : -1;
    }
}
