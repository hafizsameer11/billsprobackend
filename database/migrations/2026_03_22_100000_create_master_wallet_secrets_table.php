<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_wallet_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_wallet_id')->unique()->constrained('master_wallets')->cascadeOnDelete();
            $table->text('mnemonic_encrypted')->nullable()->comment('AES: BIP39 mnemonic where applicable');
            $table->text('xpub_encrypted')->nullable()->comment('AES: extended public key where applicable');
            $table->text('private_key_encrypted')->comment('AES: signing key (hex/WIF/secret)');
            $table->timestamps();
        });

        if (Schema::hasColumn('master_wallets', 'private_key_encrypted')) {
            $rows = DB::table('master_wallets')->select('id', 'private_key_encrypted')->get();
            foreach ($rows as $row) {
                DB::table('master_wallet_secrets')->insert([
                    'master_wallet_id' => $row->id,
                    'mnemonic_encrypted' => null,
                    'xpub_encrypted' => null,
                    'private_key_encrypted' => $row->private_key_encrypted,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Schema::table('master_wallets', function (Blueprint $table) {
                $table->dropColumn('private_key_encrypted');
            });
        }
    }

    public function down(): void
    {
        Schema::table('master_wallets', function (Blueprint $table) {
            $table->text('private_key_encrypted')->nullable()->after('address');
        });

        $secrets = DB::table('master_wallet_secrets')->get();
        foreach ($secrets as $s) {
            DB::table('master_wallets')
                ->where('id', $s->master_wallet_id)
                ->update(['private_key_encrypted' => $s->private_key_encrypted]);
        }

        Schema::dropIfExists('master_wallet_secrets');
    }
};
