<?php

namespace App\Integrations\Idcsmart;

class FinanceMutationAuthException extends FinanceException
{
    public function __construct(int $applicationStatus, array $safeContext = [])
    {
        parent::__construct(
            'Supplier authentication expired during a mutation. Its outcome must be verified before retrying.',
            200,
            $applicationStatus,
            $safeContext + ['outcome' => 'ambiguous', 'auth_required' => true],
            true,
        );
    }
}
