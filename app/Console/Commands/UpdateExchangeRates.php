    <?php

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Cache;

    class UpdateExchangeRates extends Command
    {
        // Nama command yang akan dieksekusi di terminal atau scheduler
        protected $signature = 'currency:update-rates';

        // Deskripsi singkat
        protected $description = 'Fetch latest exchange rates from API and store in Cache';

        public function handle()
        {
            $this->info('Fetching exchange rates...');

            // Mengambil data dari API eksternal (Base: IDR)
            $response = Http::get('https://api.exchangerate-api.com/v4/latest/IDR');

            if ($response->successful()) {
                $rates = $response->json()['rates'];

                // Simpan ke Cache Laravel selama 12 jam (43200 detik)
                Cache::put('exchange_rates', $rates, 43200);

                $this->info('Exchange rates updated successfully.');
            } else {
                $this->error('Failed to fetch exchange rates. API returned an error.');
            }
        }
    }
