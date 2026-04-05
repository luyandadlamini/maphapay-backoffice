<?php

declare(strict_types=1);

use App\Domain\Privacy\Models\DelegatedProofJob;
use App\Models\User;

describe('GraphQL Privacy API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ delegatedProofJob(id: "test-uuid") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries delegated proof job by id with authentication', function () {
        $user = User::factory()->create();
        $job = DelegatedProofJob::create([
            'user_id'                  => $user->id,
            'proof_type'               => 'age_verification',
            'network'                  => 'ethereum',
            'public_inputs'            => ['threshold' => 18],
            'encrypted_private_inputs' => 'encrypted-data-placeholder',
            'status'                   => 'queued',
            'progress'                 => 0,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        delegatedProofJob(id: $id) {
                            id
                            proof_type
                            network
                            status
                            progress
                        }
                    }
                ',
                'variables' => ['id' => $job->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.delegatedProofJob');
        expect($data['proof_type'])->toBe('age_verification');
        expect($data['network'])->toBe('ethereum');
        expect($data['status'])->toBe('queued');
    });

    it('paginates delegated proof jobs', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            DelegatedProofJob::create([
                'user_id'                  => $user->id,
                'proof_type'               => 'balance_proof',
                'network'                  => 'polygon',
                'public_inputs'            => ['min_balance' => 1000 + $i],
                'encrypted_private_inputs' => "encrypted-data-{$i}",
                'status'                   => 'queued',
                'progress'                 => 0,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        delegatedProofJobs(first: 10, page: 1) {
                            data {
                                id
                                proof_type
                                network
                                status
                                progress
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.delegatedProofJobs');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });
});
