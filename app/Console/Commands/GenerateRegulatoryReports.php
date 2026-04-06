<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\Services\RegulatoryReportingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

class GenerateRegulatoryReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'compliance:generate-reports 
                            {--type= : Report type (ctr, sar, summary, kyc, all)}
                            {--date= : Date for the report (YYYY-MM-DD)}
                            {--month= : Month for monthly reports (YYYY-MM)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate regulatory compliance reports';

    /**
     * Execute the console command.
     */
    public function handle(RegulatoryReportingService $reportingService): int
    {
        $type = $this->option('type') ?? 'all';
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $month = $this->option('month') ? Carbon::parse($this->option('month') . '-01') : now();

        $this->info('🏛️ Generating Regulatory Reports');
        $this->info('================================');

        $reports = [];

        try {
            if ($type === 'ctr' || $type === 'all') {
                $this->info('📊 Generating Currency Transaction Report (CTR)...');
                $filename = $reportingService->generateCTR($date);
                $reports[] = "CTR: {$filename}";
                $this->info("✅ CTR generated: {$filename}");
            }

            if ($type === 'sar' || $type === 'all') {
                $this->info('🔍 Generating Suspicious Activity Report (SAR) candidates...');
                $startDate = $date->copy()->subDays(30);
                $endDate = $date;
                $filename = $reportingService->generateSARCandidates($startDate, $endDate);
                $reports[] = "SAR: {$filename}";
                $this->info("✅ SAR candidates generated: {$filename}");
            }

            if ($type === 'summary' || $type === 'all') {
                $this->info('📋 Generating Monthly Compliance Summary...');
                $filename = $reportingService->generateComplianceSummary($month);
                $reports[] = "Summary: {$filename}";
                $this->info("✅ Compliance summary generated: {$filename}");
            }

            if ($type === 'kyc' || $type === 'all') {
                $this->info('🆔 Generating KYC Compliance Report...');
                $filename = $reportingService->generateKycReport();
                $reports[] = "KYC: {$filename}";
                $this->info("✅ KYC report generated: {$filename}");
            }

            $this->newLine();
            $this->info('📁 Generated Reports:');
            foreach ($reports as $report) {
                $this->line("   • {$report}");
            }

            $this->newLine();
            $this->info('✅ All reports generated successfully!');

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('❌ Error generating reports: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
