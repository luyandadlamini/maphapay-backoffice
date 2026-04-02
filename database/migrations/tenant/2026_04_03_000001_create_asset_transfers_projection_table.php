<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('asset_transfers')) {
            Schema::create('asset_transfers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('reference')->nullable()->index();
                $table->string('transfer_id')->nullable()->index();
                $table->string('hash', 128)->nullable()->index();
                $table->uuid('from_account_uuid')->index();
                $table->uuid('to_account_uuid')->index();
                $table->string('from_asset_code', 20)->index();
                $table->string('to_asset_code', 20)->index();
                $table->unsignedBigInteger('from_amount');
                $table->unsignedBigInteger('to_amount');
                $table->decimal('exchange_rate', 20, 10)->nullable();
                $table->string('status')->default('initiated');
                $table->string('description')->nullable();
                $table->text('failure_reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();

                $table->index(['from_account_uuid', 'status'], 'asset_transfers_from_status_idx');
                $table->index(['to_account_uuid', 'status'], 'asset_transfers_to_status_idx');
                $table->index(['created_at', 'status'], 'asset_transfers_created_status_idx');
            });
        }

        if (
            Schema::hasTable('transfers')
            && Schema::hasColumn('transfers', 'uuid')
            && Schema::hasColumn('transfers', 'from_account_uuid')
            && Schema::hasColumn('transfers', 'to_account_uuid')
        ) {
            $rows = DB::table('transfers')->get();

            foreach ($rows as $row) {
                $metadata = json_decode((string) ($row->metadata ?? 'null'), true);
                if (! is_array($metadata)) {
                    $metadata = [];
                }

                DB::table('asset_transfers')->updateOrInsert(
                    ['uuid' => $row->uuid],
                    [
                        'reference'         => $row->reference,
                        'transfer_id'       => $metadata['transfer_id'] ?? $row->reference,
                        'hash'              => $metadata['hash'] ?? null,
                        'from_account_uuid' => $row->from_account_uuid,
                        'to_account_uuid'   => $row->to_account_uuid,
                        'from_asset_code'   => $metadata['from_asset_code'] ?? ($row->currency ?? 'USD'),
                        'to_asset_code'     => $metadata['to_asset_code'] ?? ($row->currency ?? 'USD'),
                        'from_amount'       => (int) round((float) $row->amount),
                        'to_amount'         => (int) round((float) $row->amount),
                        'exchange_rate'     => $row->exchange_rate,
                        'status'            => $row->status ?? 'initiated',
                        'description'       => $row->description,
                        'failure_reason'    => $metadata['failure_reason'] ?? null,
                        'metadata'          => json_encode($metadata),
                        'initiated_at'      => $row->created_at,
                        'completed_at'      => $row->completed_at,
                        'failed_at'         => ($row->status ?? null) === 'failed' ? ($row->completed_at ?? $row->updated_at) : null,
                        'created_at'        => $row->created_at,
                        'updated_at'        => $row->updated_at,
                    ],
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_transfers');
    }
};
