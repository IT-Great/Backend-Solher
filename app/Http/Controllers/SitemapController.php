<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function index()
    {
        // Gunakan cache agar server tidak jebol saat Googlebot merayapi berkali-kali
        $xml = Cache::remember('dynamic-sitemap', 3600, function () {
            $baseUrl = 'https://www.solher.co.id';

            // 1. Daftar Halaman Statis
            $staticPages = [
                '/',
                '/best-sellers',
                '/collections',
                '/events',
                '/contact',
                '/about-us',
                '/affiliate',
                '/customer-care',
                '/faq',
                '/terms',
                '/privacy',
                '/shipping-policy',
                '/refund-policy'
            ];

            // 2. Ambil semua produk yang aktif saja
            $products = Product::where('status', 'active')
                ->latest()
                ->get(['slug', 'updated_at']);

            // 3. Mulai merakit struktur XML
            $xmlString = '<?xml version="1.0" encoding="UTF-8"?>';
            $xmlString .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            // Masukkan Halaman Statis ke XML
            foreach ($staticPages as $page) {
                $xmlString .= '<url>';
                $xmlString .= '<loc>' . $baseUrl . $page . '</loc>';
                $xmlString .= '<changefreq>' . ($page === '/' ? 'daily' : 'weekly') . '</changefreq>';
                $xmlString .= '<priority>' . ($page === '/' ? '1.0' : '0.8') . '</priority>';
                $xmlString .= '</url>';
            }

            // Masukkan Halaman Produk Dinamis ke XML
            foreach ($products as $product) {
                $xmlString .= '<url>';
                // Sesuai catatan Anda: menggunakan /collections/nama-produk
                $xmlString .= '<loc>' . $baseUrl . '/collections/' . htmlspecialchars($product->slug) . '</loc>';
                // Beritahu Google kapan produk ini terakhir diupdate
                $xmlString .= '<lastmod>' . $product->updated_at->toAtomString() . '</lastmod>';
                $xmlString .= '<changefreq>weekly</changefreq>';
                $xmlString .= '<priority>0.9</priority>'; // Prioritas tinggi untuk produk
                $xmlString .= '</url>';
            }

            $xmlString .= '</urlset>';

            return $xmlString;
        });

        // Kembalikan sebagai format file XML asli
        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
