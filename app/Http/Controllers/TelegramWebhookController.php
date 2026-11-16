<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Expense;
use App\Models\Income;
use Carbon\Carbon;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();

        if (!isset($update['message'])) {
            return response()->json(['status' => 'ok']);
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        if ($text === '/halo') {
            $this->sendWelcomeMessage($chatId, $message);
            return response()->json(['status' => 'ok']);
        }
        if ($text === '/rekap' || $text === '/bulanini') {
            $this->sendMonthlyRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/pemasukan') {
            $this->sendIncomeRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/saldo') {
            $this->sendBalanceSummary($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/hariini') {
            $this->sendDailyRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        // /kategori dan /umum memanggil fungsi rekap kategori yang sama
        if ($text === '/kategori' || $text === '/umum') {
            $this->sendCategoryRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/hapus' || $text === '/undo') {
            $this->deleteLastTransaction($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/help' || $text === '/bantuan') {
            $this->sendHelpMessage($chatId);
            return response()->json(['status' => 'ok']);
        }

        // Jika bukan perintah, anggap sebagai transaksi baru (pemasukan/pengeluaran)
        $parsed = $this->parseWithGemini($text);

        if ($parsed['nominal'] > 0) {
            if ($parsed['type'] === 'income') {
                Income::create([
                    'chat_id' => $chatId,
                    'description' => $parsed['deskripsi'],
                    'amount' => $parsed['nominal'],
                    'category' => $parsed['kategori'],
                ]);
                $this->sendTelegramMessage($chatId, "Berhasil dicatat ke database (Pemasukan):\n- Keterangan: {$parsed['deskripsi']}\n- Nominal: Rp " . number_format($parsed['nominal'], 0, ',', '.') . "\n- Kategori: {$parsed['kategori']}");
            } else {
                Expense::create([
                    'chat_id' => $chatId,
                    'description' => $parsed['deskripsi'],
                    'amount' => $parsed['nominal'],
                    'category' => $parsed['kategori'],
                ]);
                $this->sendTelegramMessage($chatId, "Berhasil dicatat ke database (Pengeluaran):\n- Keterangan: {$parsed['deskripsi']}\n- Nominal: Rp " . number_format($parsed['nominal'], 0, ',', '.') . "\n- Kategori: {$parsed['kategori']}");
            }
        } else {
            $this->sendTelegramMessage($chatId, "Maaf, nominal tidak terdeteksi. Format contoh: *makan 15000*");
        }

        return response()->json(['status' => 'ok']);
    }

    private function parseWithGemini($text)
    {
        $apiKey = env('GEMINI_API_KEY');

        // 1. Ekstraksi nominal (100% Aman via PHP)
        $nominal = 0;
        $lowerText = strtolower($text);
        $lowerText = str_replace(['rb', 'ribu', 'k'], '000', $lowerText);
        $normalizedText = preg_replace('/(?<=\d)[.,](?=\d{3}\b)/', '', $lowerText);
        
        if (preg_match_all('/\d+/', $normalizedText, $matches)) {
            $numbers = array_map('intval', $matches[0]);
            $nominal = !empty($numbers) ? max($numbers) : 0;
        }

        // 2. Deteksi tipe transaksi (income/expense) via keyword PHP
        $type = 'expense';
        $incomeKeywords = ['terima', 'gaji', 'dapat', 'masuk', 'jual', 'bonus', 'kiriman'];
        foreach ($incomeKeywords as $keyword) {
            if (str_contains($lowerText, $keyword)) {
                $type = 'income';
                break;
            }
        }

        // 3. Deteksi Kategori via PHP Keyword untuk Mahasiswa
        $kategori = 'Umum';

        if (str_contains($lowerText, 'print') || str_contains($lowerText, 'fotokopi') || str_contains($lowerText, 'tugas') || str_contains($lowerText, 'buku') || str_contains($lowerText, 'kuliah') || str_contains($lowerText, 'praktikum') || str_contains($lowerText, 'alat tulis')) {
            $kategori = 'Akademik & Tugas';
        } elseif (str_contains($lowerText, 'bensin') || str_contains($lowerText, 'parkir') || str_contains($lowerText, 'ojol') || str_contains($lowerText, 'gojek') || str_contains($lowerText, 'grab') || str_contains($lowerText, 'pertalite') || str_contains($lowerText, 'pertamax')) {
            $kategori = 'Transportasi';
        } elseif (str_contains($lowerText, 'makan') || str_contains($lowerText, 'minum') || str_contains($lowerText, 'jajan') || str_contains($lowerText, 'warung') || str_contains($lowerText, 'kantin') || str_contains($lowerText, 'nasi') || str_contains($lowerText, 'mie') || str_contains($lowerText, 'ayam')) {
            $kategori = 'Makanan & Minuman';
        } elseif (str_contains($lowerText, 'ngopi') || str_contains($lowerText, 'cafe') || str_contains($lowerText, 'kafe') || str_contains($lowerText, 'nongkrong')) {
            $kategori = 'Nongkrong & Kafe';
        } elseif (str_contains($lowerText, 'indomaret') || str_contains($lowerText, 'alfamart') || str_contains($lowerText, 'belanja') || str_contains($lowerText, 'sabun')) {
            $kategori = 'Belanja & Kebutuhan';
        } elseif (str_contains($lowerText, 'bioskop') || str_contains($lowerText, 'nonton') || str_contains($lowerText, 'game') || str_contains($lowerText, 'topup') || str_contains($lowerText, 'steam')) {
            $kategori = 'Hiburan & Langganan';
        }

        // 4. Gemini digunakan untuk merapikan deskripsi teks saja
        $response = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => "Buat deskripsi singkat dan rapi dari teks transaksi berikut. Berikan HANYA format JSON valid tanpa markdown dengan struktur: {\"deskripsi\": \"...\"}. Teks: \"{$text}\""]
                    ]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json'
            ]
        ]);

        $deskripsi = $text;

        if ($response->successful()) {
            $result = $response->json();
            $candidates = $result['candidates'] ?? [];
            $candidate = reset($candidates);
            $content = $candidate['content'] ?? [];
            $parts = $content['parts'] ?? [];
            $part = reset($parts);
            $jsonString = $part['text'] ?? '{}';

            $jsonString = trim(str_replace(['```json', '```'], '', $jsonString));
            $data = json_decode($jsonString, true);

            if (is_array($data) && isset($data['deskripsi'])) {
                $deskripsi = $data['deskripsi'];
            }
        }

        return [
            'type' => $type,
            'deskripsi' => $deskripsi,
            'nominal' => $nominal,
            'kategori' => $kategori,
        ];
    }
    
    private function sendMonthlyRecap($chatId)
    {
        $expenses = Expense::where('chat_id', $chatId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->get();

        if ($expenses->isEmpty()) {
            $this->sendTelegramMessage($chatId, "Belum ada catatan pengeluaran bulan ini.");
            return;
        }

        $total = $expenses->sum('amount');
        $summary = "Rekap Pengeluaran Bulan Ini:\n\n";

        foreach ($expenses as $expense) {
            $summary .= "- " . $expense->description . " (Rp " . number_format($expense->amount, 0, ',', '.') . ") [" . $expense->category . "]\n";
        }

        $summary .= "\nTotal Pengeluaran: Rp " . number_format($total, 0, ',', '.');

        $this->sendTelegramMessage($chatId, $summary);
    }

    private function sendIncomeRecap($chatId)
    {
        $incomes = Income::where('chat_id', $chatId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->get();

        if ($incomes->isEmpty()) {
            $this->sendTelegramMessage($chatId, "Belum ada catatan pemasukan bulan ini.");
            return;
        }

        $total = $incomes->sum('amount');
        $summary = "Rekap Pemasukan Bulan Ini:\n\n";

        foreach ($incomes as $income) {
            $summary .= "- " . $income->description . " (Rp " . number_format($income->amount, 0, ',', '.') . ") [" . $income->category . "]\n";
        }

        $summary .= "\nTotal Pemasukan: Rp " . number_format($total, 0, ',', '.');

        $this->sendTelegramMessage($chatId, $summary);
    }

    private function sendBalanceSummary($chatId)
    {
        $totalIncome = Income::where('chat_id', $chatId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('amount');

        $totalExpense = Expense::where('chat_id', $chatId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('amount');

        $balance = $totalIncome - $totalExpense;

        $summary = "Laporan Saldo Bulan Ini:\n\n" .
                   "Total Pemasukan: Rp " . number_format($totalIncome, 0, ',', '.') . "\n" .
                   "Total Pengeluaran: Rp " . number_format($totalExpense, 0, ',', '.') . "\n" .
                   "----------------------------------\n" .
                   "Sisa Saldo: Rp " . number_format($balance, 0, ',', '.');

        $this->sendTelegramMessage($chatId, $summary);
    }

    private function sendDailyRecap($chatId)
    {
        $expenses = Expense::where('chat_id', $chatId)
            ->whereDate('created_at', Carbon::today())
            ->get();

        if ($expenses->isEmpty()) {
            $this->sendTelegramMessage($chatId, "Belum ada catatan pengeluaran hari ini.");
            return;
        }

        $total = $expenses->sum('amount');
        $summary = "Rekap Pengeluaran Hari Ini:\n\n";

        foreach ($expenses as $expense) {
            $summary .= "- " . $expense->description . " (Rp " . number_format($expense->amount, 0, ',', '.') . ") [" . $expense->category . "]\n";
        }

        $summary .= "\nTotal Pengeluaran: Rp " . number_format($total, 0, ',', '.');

        $this->sendTelegramMessage($chatId, $summary);
    }

    private function sendCategoryRecap($chatId)
    {
        $expenses = Expense::where('chat_id', $chatId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->get();

        if ($expenses->isEmpty()) {
            $this->sendTelegramMessage($chatId, "Belum ada catatan pengeluaran bulan ini.");
            return;
        }

        $grouped = $expenses->groupBy('category');
        $summary = "Rekap Pengeluaran Berdasarkan Kategori:\n\n";

        foreach ($grouped as $category => $items) {
            $subtotal = $items->sum('amount');
            $summary .= "Kategori: " . $category . " (Total: Rp " . number_format($subtotal, 0, ',', '.') . ")\n";
        }

        $this->sendTelegramMessage($chatId, $summary);
    }

    private function deleteLastTransaction($chatId)
    {
        $lastIncome = Income::where('chat_id', $chatId)->latest()->first();
        $lastExpense = Expense::where('chat_id', $chatId)->latest()->first();

        if (!$lastIncome && !$lastExpense) {
            $this->sendTelegramMessage($chatId, "Tidak ada catatan transaksi untuk dihapus.");
            return;
        }

        if ($lastIncome && $lastExpense) {
            if ($lastIncome->created_at > $lastExpense->created_at) {
                $lastIncome->delete();
                $this->sendTelegramMessage($chatId, "Catatan pemasukan terakhir berhasil dihapus.");
            } else {
                $lastExpense->delete();
                $this->sendTelegramMessage($chatId, "Catatan pengeluaran terakhir berhasil dihapus.");
            }
        } elseif ($lastIncome) {
            $lastIncome->delete();
            $this->sendTelegramMessage($chatId, "Catatan pemasukan terakhir berhasil dihapus.");
        } else {
            $lastExpense->delete();
            $this->sendTelegramMessage($chatId, "Catatan pengeluaran terakhir berhasil dihapus.");
        }
    }

    private function sendHelpMessage($chatId)
    {
        $helpText = "Daftar Perintah Bot:\n\n" .
                   "- Kirim teks pengeluaran (misal: *makan 15000*)\n" .
                   "- Kirim teks pemasukan (misal: *terima gaji 3000000*)\n" .
                   "- /saldo - Melihat total pemasukan, pengeluaran & sisa saldo\n" .
                   "- /pemasukan - Melihat rekap pemasukan bulan ini\n" .
                   "- /rekap atau /bulanini - Melihat rekap pengeluaran bulan ini\n" .
                   "- /hariini - Melihat rekap pengeluaran hari ini\n" .
                   "- /kategori atau /umum - Melihat rekap per kategori\n" .
                   "- /hapus atau /undo - Menghapus transaksi terakhir\n" .
                   "- /help atau /bantuan - Menampilkan pesan ini";

        $this->sendTelegramMessage($chatId, $helpText);
    }

    private function sendTelegramMessage($chatId, $message)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
        ]);
    }

    private function sendWelcomeMessage($chatId, $message)
    {
        $firstName = $message['from']['first_name'] ?? 'Sobat';

        $welcomeText = "Halo, *{$firstName}*! 👋 Selamat datang di *Catatin-Bot*.\n\n" .
                       "Asisten virtual pribadimu untuk mencatat keuangan harian mahasiswa dengan mudah dan praktis langsung dari Telegram.\n\n" .
                       "💡 *Cara Penggunaan:*\n" .
                       "• Catat pengeluaran: `makan nasi 15000` atau `print tugas 5rb`\n" .
                       "• Catat pemasukan: `terima gaji 500000` atau `dapat kiriman 300k`\n\n" .
                       "📊 *Perintah Cepat:*\n" .
                       "• /halo - Menampilkan pesan sambutan ini\n" .
                       "• /saldo - Cek total pemasukan, pengeluaran & sisa saldo\n" .
                       "• /umum atau /kategori - Rekap pengeluaran per kategori\n" .
                       "• /bulanini - Rekap pengeluaran bulan ini\n" .
                       "• /hariini - Rekap pengeluaran hari ini\n" .
                       "• /pemasukan - Rekap pemasukan bulan ini\n" .
                       "• /hapus - Membatalkan transaksi terakhir\n" .
                       "• /help - Menampilkan daftar lengkap bantuan\n\n" .
                       "Yuk, mulai catat keuanganmu sekarang! 🚀";

        $token = env('TELEGRAM_BOT_TOKEN');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $welcomeText,
            'parse_mode' => 'Markdown',
        ]);
    }
}