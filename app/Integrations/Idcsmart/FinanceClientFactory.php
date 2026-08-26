<?php

namespace App\Integrations\Idcsmart;

use App\Models\SupplierAccount;

class FinanceClientFactory
{
    public function make(SupplierAccount $account): FinanceClient
    {
        return new FinanceClient($account);
    }
}
