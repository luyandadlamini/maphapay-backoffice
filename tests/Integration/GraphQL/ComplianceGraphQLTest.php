<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\ComplianceAlert;
use App\Models\User;

describe('GraphQL Compliance API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ complianceAlert(id: "test-uuid") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries compliance alert by id with authentication', function () {
        $user = User::factory()->create();
        $alert = ComplianceAlert::create([
            'type'        => 'transaction',
            'severity'    => 'high',
            'status'      => 'open',
            'title'       => 'Suspicious Transaction Detected',
            'description' => 'Large cross-border transfer flagged',
            'source'      => 'system',
            'entity_type' => 'transaction',
            'entity_id'   => 'txn-001',
            'risk_score'  => 85.5,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        complianceAlert(id: $id) {
                            id
                            type
                            severity
                            status
                            title
                            risk_score
                        }
                    }
                ',
                'variables' => ['id' => $alert->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.complianceAlert');
        expect($data['severity'])->toBe('high');
        expect($data['status'])->toBe('open');
        expect($data['title'])->toBe('Suspicious Transaction Detected');
    });

    it('paginates compliance alerts', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            ComplianceAlert::create([
                'type'        => 'pattern',
                'severity'    => 'medium',
                'status'      => 'open',
                'title'       => "Alert {$i}",
                'source'      => 'rule',
                'entity_type' => 'account',
                'entity_id'   => "acc-{$i}",
                'risk_score'  => 50.0 + $i,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        complianceAlerts(first: 10, page: 1) {
                            data {
                                id
                                type
                                severity
                                status
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.complianceAlerts');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('submits a KYC document via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: SubmitKycDocumentInput!) {
                        submitKycDocument(input: $input) {
                            id
                            type
                            status
                            document_type
                            document_country
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'user_id'          => $user->id,
                        'type'             => 'identity',
                        'document_type'    => 'passport',
                        'document_country' => 'US',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.submitKycDocument');
        expect($data['status'])->toBe('pending');
        expect($data['document_type'])->toBe('passport');
    });
});
