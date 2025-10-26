<?php

namespace App\Console\Commands;

use App\Support\MemoryMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateSyntheticDataset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dataset:generate
                            {rows=10000 : Number of rows to generate}
                            {features=10 : Number of features}
                            {--name=synthetic : Dataset filename (without .csv)}
                            {--memory-test : Generate extra large dataset for memory testing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a synthetic dataset for testing model training performance';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $monitor = new MemoryMonitor('dataset-generation');

        $rows = (int) $this->argument('rows');
        $features = (int) $this->argument('features');
        $name = $this->option('name');
        $isMemoryTest = $this->option('memory-test');

        if ($isMemoryTest) {
            $rows = 100000; // 100k rows
            $features = 50;  // 50 features
            $name = 'memory-test-large';
            $this->info('Memory test mode: Generating large dataset (100k rows, 50 features)');
        }

        $filename = "{$name}.csv";
        $path = "datasets/synthetic/{$filename}";

        $this->info("Generating synthetic dataset: {$rows} rows, {$features} features");
        $this->info("Output: storage/app/{$path}");

        $monitor->snapshot('start');

        // Create directory
        $disk = Storage::disk('local');
        $disk->makeDirectory('datasets/synthetic');

        // Generate header
        $header = ['timestamp', 'latitude', 'longitude'];
        for ($i = 1; $i <= $features; $i++) {
            $header[] = "feature_{$i}";
        }
        $header[] = 'category';
        $header[] = 'risk_score';
        $header[] = 'label';

        // Open file for writing
        $fullPath = $disk->path($path);
        $handle = fopen($fullPath, 'w');

        if ($handle === false) {
            $this->error('Failed to open file for writing');

            return self::FAILURE;
        }

        // Write header
        fputcsv($handle, $header);

        // Write rows in chunks to manage memory
        $chunkSize = 1000;
        $progressBar = $this->output->createProgressBar($rows);
        $progressBar->start();

        for ($row = 0; $row < $rows; $row++) {
            $data = [
                // Timestamp: random date in 2024
                date('Y-m-d H:i:s', strtotime('2024-01-01') + rand(0, 365 * 24 * 60 * 60)),
                // Latitude: UK bounds (approximately)
                number_format(50.0 + (rand(0, 1000) / 1000) * 10, 6, '.', ''),
                // Longitude: UK bounds (approximately)
                number_format(-6.0 + (rand(0, 1000) / 1000) * 10, 6, '.', ''),
            ];

            // Features: random normalized values
            for ($f = 0; $f < $features; $f++) {
                $data[] = number_format((rand(0, 10000) / 10000), 4, '.', '');
            }

            // Category: random categorical value
            $categories = ['TypeA', 'TypeB', 'TypeC', 'TypeD'];
            $data[] = $categories[array_rand($categories)];

            // Risk score: derived from features
            $riskScore = 0;
            for ($f = 0; $f < min(3, $features); $f++) {
                $riskScore += (float) $data[3 + $f];
            }
            $riskScore = min(1.0, $riskScore / 3);
            $data[] = number_format($riskScore, 4, '.', '');

            // Label: binary classification based on risk
            $data[] = $riskScore > 0.5 ? '1' : '0';

            fputcsv($handle, $data);

            $progressBar->advance();

            // Periodic memory check
            if ($row > 0 && $row % $chunkSize === 0) {
                if (MemoryMonitor::exceedsThreshold(200 * 1024 * 1024)) {
                    $this->newLine();
                    $this->warn('High memory usage detected, forcing garbage collection');
                    MemoryMonitor::gc();
                }
            }
        }

        $progressBar->finish();
        $this->newLine();

        fclose($handle);

        $fileSize = filesize($fullPath);
        $monitor->snapshot('complete');

        $this->info('Dataset generated successfully!');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows', number_format($rows)],
                ['Features', $features],
                ['File Size', MemoryMonitor::formatBytes($fileSize)],
                ['Path', $path],
                ['Peak Memory', MemoryMonitor::formatBytes(MemoryMonitor::peakUsage())],
            ]
        );

        return self::SUCCESS;
    }
}
