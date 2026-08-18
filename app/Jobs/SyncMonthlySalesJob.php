<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Models\MonthlySalesAggregate;

class SyncMonthlySalesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        // Query berat ini kini hanya berjalan di background (Worker), tidak ditunggu oleh user
        $sales = DB::table('transaction_details')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('transactions.status', ['completed', 'refund_rejected'])
            ->select(
                'products.id as product_id',
                'products.code as product_code',
                'products.name as product_name',
                'products.image as product_image',
                'categories.name as category_name',
                DB::raw('MONTH(transactions.created_at) as month'),
                DB::raw('YEAR(transactions.created_at) as year'),
                DB::raw('SUM(transaction_details.quantity) as total_sold'),
                DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
            )
            ->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name', 'month', 'year')
            ->get();

        // Upsert (Update or Create) ke tabel Data Warehouse kita
        foreach ($sales as $sale) {
            MonthlySalesAggregate::updateOrCreate(
                [
                    'product_id' => $sale->product_id,
                    'month'      => $sale->month,
                    'year'       => $sale->year,
                ],
                [
                    'product_code'  => $sale->product_code,
                    'product_name'  => $sale->product_name,
                    'product_image' => $sale->product_image,
                    'category_name' => $sale->category_name,
                    'total_sold'    => $sale->total_sold,
                    'total_revenue' => $sale->total_revenue,
                ]
            );
        }
    }
}
