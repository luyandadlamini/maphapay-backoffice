<?php

declare(strict_types=1);

use App\Domain\Lending\Models\LoanApplication;
use App\Models\User;


describe('GraphQL Lending API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ loanApplication(id: "test") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries loan application by id with authentication', function () {
        $user = User::factory()->create();
        $application = LoanApplication::create([
            'borrower_id'      => (string) $user->id,
            'requested_amount' => 50000.00,
            'term_months'      => 12,
            'purpose'          => 'Business expansion',
            'status'           => 'submitted',
            'submitted_at'     => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        loanApplication(id: $id) {
                            id
                            status
                            requested_amount
                            purpose
                        }
                    }
                ',
                'variables' => ['id' => $application->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.loanApplication');
        expect($data['status'])->toBe('submitted');
        expect($data['purpose'])->toBe('Business expansion');
    });

    it('paginates loan applications', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            LoanApplication::create([
                'borrower_id'      => (string) $user->id,
                'requested_amount' => 10000 + ($i * 5000),
                'term_months'      => 12,
                'purpose'          => "Loan purpose {$i}",
                'status'           => 'submitted',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        loanApplications(first: 10, page: 1) {
                            data {
                                id
                                status
                                requested_amount
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.loanApplications');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('applies for a loan via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: ApplyForLoanInput!) {
                        applyForLoan(input: $input) {
                            id
                            status
                            requested_amount
                            purpose
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'requested_amount' => 75000.0,
                        'term_months'      => 24,
                        'purpose'          => 'Equipment purchase',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.applyForLoan');
        expect($data['status'])->toBe('submitted');
        expect($data['purpose'])->toBe('Equipment purchase');
    });
});
