<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Expense;
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

        if ($text === '/rekap' || $text === '/bulanini') {
            $this->sendMonthlyRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/hariini') {
            $this->sendDailyRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/kategori') {
            $this->sendCategoryRecap($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/hapus' || $text === '/undo') {
            $this->deleteLastExpense($chatId);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '/help' || $text === '/bantuan') {
            $this->sendHelpMessage($chatId);
            return response()->json(['status' => 'ok']);
        }

        $parsedData = $this->parseWithGemini($text);

        if ($parsedData['nominal']) {
            Expense::create([
                'chat_id' => $chatId,
                'description' => $parsedData['deskripsi'],
                'amount' => $parsedData['nominal'],
                'category' => $parsedData['kategori'],
            ]);
        }

        $replyText = "Berhasil dicatat ke database:\n" .
                     "- Keterangan: " . $parsedData['deskripsi'] . "\n" .
                     "- Nominal: Rp " . $parsedData['nominal'] . "\n" .
                     "- Kategori: " . $parsedData['kategori'];

        $this->sendTelegramMessage($chatId, $replyText);

        return response()->json(['status' => 'ok']);
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
            $summary .= "- " . $expense->description . " (Rp " . $expense->amount . ") [" . $expense->category . "]\n";
        }

        $summary .= "\nTotal Pengeluaran: Rp " . $total;

        $this->sendTelegramMessage($chatId, $summary);
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
            $summary .= "- " . $expense->description . " (Rp " . $expense->amount . ") [" . $expense->category . "]\n";
        }

        $summary .= "\nTotal Pengeluaran: Rp " . $total;

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
            $summary .= "Kategori: " . $category . " (Total: Rp " . $subtotal . ")\n";
        }

        $this->sendTelegramMessage($chatId, $summary);
    }

    private function deleteLastExpense($chatId)
    {
        $expense = Expense::where('chat_id', $chatId)->latest()->first();

        if (!$expense) {
            $this->sendTelegramMessage($chatId, "Tidak ada catatan pengeluaran untuk dihapus.");
            return;
        }

        $expense->delete();
        $this->sendTelegramMessage($chatId, "Catatan terakhir berhasil dihapus.");
    }

    private function sendHelpMessage($chatId)
    {
        $helpText = "Daftar Perintah Bot:\n\n" .
                    "- Kirim teks pengeluaran langsung untuk mencatat\n" .
                    "- /rekap atau /bulanini - Melihat rekap bulan ini\n" .
                    "- /hariini - Melihat rekap hari ini\n" .
                    "- /kategori - Melihat rekap per kategori\n" .
                    "- /hapus atau /undo - Menghapus catatan terakhir\n" .
                    "- /help atau /bantuan - Menampilkan pesan ini";

        $this->sendTelegramMessage($chatId, $helpText);
    }

    private function parseWithGemini($text)
    {
        $apiKey = env('GEMINI_API_KEY');

        // 1. Ambil nominal menggunakan regex yang lebih spesifik untuk mencari angka (bisa ribuan/k/rb)
        $nominal = 0;
        $cleanedText = str_replace(['rb', 'ribu', 'k', '.'], ['', '', '', ''], strtolower($text));
        
        // Cari semua angka dalam teks, ambil yang paling masuk akal sebagai nominal (biasanya angka terakhir atau terbesar jika ada dua)
        if (preg_match_all('/\d+/', $cleanedText, $matches)) {
            // Jika ada angka, ambil angka terakhir (biasanya format: "makan 5000" -> angka ada di belakang)
            $allNumbers = $matches[0];
            $nominal = intval(end($allNumbers));
        }

        // 2. Gunakan Gemini HANYA untuk mendeteksi kategori dan deskripsi
        $response = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => "Tentukan kategori pengeluaran (contoh: Makanan, Transport, Belanja, Umum) dan deskripsi singkat dari teks ini. Jika teks hanya berupa angka, berikan deskripsi 'Pengeluaran Umum'. Berikan HANYA format JSON valid tanpa markdown dengan struktur: {\"deskripsi\": \"...\", \"kategori\": \"...\"}. Teks: \"{$text}\""]
                    ]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json'
            ]
        ]);

        $deskripsi = $text;
        $kategori = 'Umum';

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

            if (is_array($data)) {
                $deskripsi = $data['deskripsi'] ?? $text;
                $kategori = $data['kategori'] ?? 'Umum';
            }
        }

        // Antisipasi jika deskripsi dari Gemini ternyata sama persis dengan nominal angka saja
        if (is_numeric($deskripsi)) {
            $deskripsi = 'Pengeluaran Umum';
        }

        return [
            'deskripsi' => $deskripsi,
            'nominal' => $nominal,
            'kategori' => $kategori,
        ];
    }

    private function sendTelegramMessage($chatId, $message)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
        ]);
    }
}