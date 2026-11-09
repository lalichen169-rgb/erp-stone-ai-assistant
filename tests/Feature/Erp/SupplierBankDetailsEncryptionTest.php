<?php

namespace Tests\Feature\Erp;

use App\Models\Supplier;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierBankDetailsEncryptionTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    public function test_bank_details_are_encrypted_at_rest_and_decrypted_on_model(): void
    {
        $plainDetails = 'Account 123456789 — routing 987654321';

        $supplier = Supplier::query()->create([
            'name' => 'Secure Payments Supplier',
            'bank_details' => $plainDetails,
        ]);

        $stored = DB::table('suppliers')->where('id', $supplier->id)->value('bank_details');

        $this->assertIsString($stored);
        $this->assertNotSame($plainDetails, $stored);
        $this->assertSame($plainDetails, $supplier->fresh()->bank_details);
    }

    public function test_bank_details_are_hidden_from_model_array_serialization(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Hidden Bank Supplier',
            'bank_details' => 'Sensitive account data',
        ]);

        $array = $supplier->toArray();

        $this->assertArrayNotHasKey('bank_details', $array);
    }
}
