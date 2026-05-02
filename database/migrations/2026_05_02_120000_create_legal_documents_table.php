<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('title', 255);
            $table->longText('body');
            $table->timestamps();
        });

        $now = now();
        DB::table('legal_documents')->insert([
            [
                'key' => 'signup_terms',
                'title' => 'Terms of use',
                'body' => <<<'TXT'
Welcome to Bills Pro. These terms govern your use of our mobile application and related services.

1. Eligibility
You must be able to form a binding contract and use the service only where it is lawful to do so.

2. Your account
You are responsible for maintaining the confidentiality of your credentials and for activity under your account.

3. Acceptable use
You agree not to misuse the service, attempt unauthorised access, or use Bills Pro for unlawful purposes.

4. Changes
We may update these terms. Material changes will be communicated as required. Continued use after notice may constitute acceptance where permitted by law.

5. Contact
Reach us through in-app support for questions about these terms.

— This is starter copy. Replace with your final legal text from the admin panel. —
TXT,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'signup_privacy',
                'title' => 'Privacy policy',
                'body' => <<<'TXT'
Bills Pro respects your privacy. This notice summarises how we handle personal data when you use our app.

• Data we process includes account, KYC, transaction, and device information needed to provide and secure the service.
• We use service providers and partners where necessary to operate payments and compliance.
• We retain data as required by law and for legitimate business purposes.
• You may contact us to exercise applicable privacy rights, subject to verification.

— This is starter copy. Replace with your final privacy text from the admin panel. —
TXT,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'virtual_card_terms',
                'title' => 'Virtual card — terms & conditions',
                'body' => <<<'TXT'
These terms apply only to BillsPro virtual cards (creation, funding, use, and closure).

1. Eligibility  
You must have an active BillsPro account and complete any required verification before creating a card.

2. Fees  
Card creation and funding may attract fees shown in the app before you confirm. Fees are non-refundable unless we state otherwise or where required by law.

3. Use of the card  
Your virtual card may be used for lawful online payments where Mastercard virtual cards are accepted. You are responsible for all transactions on your card.

4. Limits and security  
We may apply spending, velocity, or funding limits. Keep your credentials secure. Notify us promptly if you suspect unauthorised use.

5. Changes  
We may update these terms. Continued use of virtual cards after notice constitutes acceptance where permitted by law.

6. Contact  
For questions, use in-app support or the contact details in the main BillsPro terms.

— Placeholder summary. Replace with your final legal text when ready. —
TXT,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'virtual_card_privacy',
                'title' => 'Virtual card — privacy notice',
                'body' => <<<'TXT'
This notice describes how we handle information related to virtual cards in addition to our general privacy policy.

• We process card creation, billing address, and transaction data to provide the service and meet regulatory obligations.  
• We may share limited data with our card programme partners and networks only as needed to operate the card.  
• We retain records as required for compliance, dispute resolution, and fraud prevention.  
• You may access or correct certain data through support, subject to verification.

See the main BillsPro privacy policy for broader data practices.

— Placeholder summary. Replace with your final privacy text when ready. —
TXT,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
