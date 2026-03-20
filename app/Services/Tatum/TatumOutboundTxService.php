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
            in_array($bc, ['ethereum', 'eth'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'ethereum'),
            in_array($bc, ['bsc', 'binance', 'binancesmartchain'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'bsc'),
            in_array($bc, ['polygon', 'matic'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'polygon'),
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
            in_array($bc, ['ethereum', 'eth'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'ethereum'),
            in_array($bc, ['bsc', 'binance', 'binancesmartchain'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'bsc'),
            in_array($bc, ['polygon', 'matic'], true) => $this->sendEvm($pk, $toAddress, $amount, $cur, 'polygon'),
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
        string $chain
    ): array {
        $path = match ($chain) {
            'ethereum' => '/ethereum/transaction',
            'bsc' => '/bsc/transaction',
            'polygon' => '/polygon/transaction',
            default => throw new RuntimeException('Unknown EVM chain: '.$chain),
        };

        $payload = [
            'fromPrivateKey' => $fromPrivateKey,
            'to' => $toAddress,
            'amount' => $this->formatDecimalString($amount, 18),
        ];

        $isNative = match ($chain) {
            'ethereum' => in_array($currency, ['ETH'], true),
            'bsc' => in_array($currency, ['BNB', 'BSC'], true),
            'polygon' => in_array($currency, ['MATIC', 'POLYGON'], true),
            default => false,
        };

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
        }

        $raw = $this->postV3($path, $payload);
        $txId = (string) ($raw['txId'] ?? $raw['tx_id'] ?? '');

        if ($txId === '') {
            throw new RuntimeException('Tatum returned no txId: '.json_encode($raw));
        }

        return ['txId' => $txId, 'fee' => isset($raw['fee']) ? (string) $raw['fee'] : null, 'raw' => $raw];
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
                'amount' => $this->formatDecimalString($amount, 6),
            ];
            $raw = $this->postV3('/tron/transaction', $payload);
        } elseif (in_array($currency, ['USDT', 'USDT_TRON'], true) && $usdtContract) {
            $payload = [
                'fromPrivateKey' => $fromPrivateKey,
                'to' => $toAddress,
                'amount' => $this->formatDecimalString($amount, 6),
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
            'amount' => $this->formatDecimalString($amount, 6),
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
                'amount' => (float) $amount,
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
                'amount' => $this->formatDecimalString($amount, 9),
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

    protected function formatDecimalString(string $amount, int $maxDecimals): string
    {
        if (! is_numeric($amount)) {
            throw new RuntimeException('Invalid numeric amount: '.$amount);
        }

        return number_format((float) $amount, $maxDecimals, '.', '');
    }
}
