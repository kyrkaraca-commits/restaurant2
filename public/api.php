<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE, PATCH");
header("Access-Control-Allow-Headers: Content-Type");

$veri_yolu = __DIR__ . '/veritabani.json';
$config_yolu = __DIR__ . '/config.json';
$uploads_dir = __DIR__ . '/uploads/';

if (!file_exists($veri_yolu)) file_put_contents($veri_yolu, '[]');
if (!file_exists($config_yolu)) file_put_contents($config_yolu, json_encode(['gemini_key' => '', 'restoran_adi' => 'Benim Restoranım']));
if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

$action = $_GET['action'] ?? '';

if ($action === 'get_menu') {
    echo file_get_contents($veri_yolu);
    exit;
}

// Ayarları ve Restoran Adını Getir
if ($action === 'get_config') {
    $config = json_decode(file_get_contents($config_yolu), true);
    echo json_encode([
        'mevcut_key' => (!empty($config['gemini_key']) ? '********' : ''),
        'restoran_adi' => $config['restoran_adi'] ?? 'Gurme Restoran'
    ]);
    exit;
}

// Ayarları Kaydet
if ($action === 'save_config') {
    $data = json_decode(file_get_contents('php://input'), true);
    $config = json_decode(file_get_contents($config_yolu), true);
    
    if (isset($data['key'])) $config['gemini_key'] = $data['key'];
    if (isset($data['restoran_adi'])) $config['restoran_adi'] = $data['restoran_adi'];
    
    file_put_contents($config_yolu, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['durum' => 'ok']);
    exit;
}

if ($action === 'upload') {
    if (!isset($_FILES['gorsel'])) { http_response_code(400); echo json_encode(['hata' => 'Dosya yuklenemedi']); exit; }
    $file = $_FILES['gorsel'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . '-' . rand(1000, 9999) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $uploads_dir . $filename)) {
        echo json_encode(['url' => 'uploads/' . $filename]);
    } else {
        http_response_code(500); echo json_encode(['hata' => 'Yazma hatasi']);
    }
    exit;
}

if ($action === 'add_kategori') {
    $data = json_decode(file_get_contents('php://input'), true);
    $menuler = json_decode(file_get_contents($veri_yolu), true);
    $menuler[] = ['kategori_id' => 'kat_' . time(), 'kategori_adi' => $data['isim'], 'urunler' => []];
    file_put_contents($veri_yolu, json_encode($menuler, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['durum' => 'ok']);
    exit;
}

if ($action === 'delete_kategori') {
    $data = json_decode(file_get_contents('php://input'), true);
    $menuler = json_decode(file_get_contents($veri_yolu), true);
    $menuler = array_values(array_filter($menuler, function($k) use ($data) { return $k['kategori_id'] !== $data['id']; }));
    file_put_contents($veri_yolu, json_encode($menuler, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['durum' => 'ok']);
    exit;
}

if ($action === 'save_urun') {
    $data = json_decode(file_get_contents('php://input'), true);
    $kategoriId = $data['kategoriId'];
    $urun = $data['urun'];
    $menuler = json_decode(file_get_contents($veri_yolu), true);
    foreach ($menuler as &$kat) {
        if ($kat['kategori_id'] === $kategoriId) {
            if (!empty($urun['urun_id'])) {
                foreach ($kat['urunler'] as &$u) {
                    if ($u['urun_id'] === $urun['urun_id']) { $urun['is_available'] = $u['is_available'] ?? true; $u = $urun; break; }
                }
            } else {
                $urun['urun_id'] = 'urun_' . time(); $urun['is_available'] = true; $kat['urunler'][] = $urun;
            }
            break;
        }
    }
    file_put_contents($veri_yolu, json_encode($menuler, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['durum' => 'ok']);
    exit;
}

if ($action === 'delete_urun') {
    $data = json_decode(file_get_contents('php://input'), true);
    $menuler = json_decode(file_get_contents($veri_yolu), true);
    foreach ($menuler as &$kat) {
        if ($kat['kategori_id'] === $data['katId']) {
            $kat['urunler'] = array_values(array_filter($kat['urunler'], function($u) use ($data) { return $u['urun_id'] !== $data['urunId']; }));
            break;
        }
    }
    file_put_contents($veri_yolu, json_encode($menuler, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['durum' => 'ok']);
    exit;
}

if ($action === 'update_stok') {
    $data = json_decode(file_get_contents('php://input'), true);
    $menuler = json_decode(file_get_contents($veri_yolu), true);
    foreach ($menuler as &$kat) {
        foreach ($kat['urunler'] as &$u) {
            if ($u['urun_id'] === $data['urunId']) { $u['is_available'] = (bool)$data['isAvailable']; break 2; }
        }
    }
    file_put_contents($veri_yolu, json_encode($menuler, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['durum' => 'ok']);
    exit;
}

if ($action === 'ai_yazdir') {
    $data = json_decode(file_get_contents('php://input'), true);
    $urunAdi = $data['urunAdi'];
    $config = json_decode(file_get_contents($config_yolu), true);
    $key = $config['gemini_key'] ?? '';
    if (empty($key)) { echo json_encode(['aciklama' => $urunAdi . " için özel harmanlanmış gurme sunumu."]); exit; }
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $key;
    $postData = json_encode(['contents' => [['parts' => [['text' => "Sen lüks bir restoranda gurme bir şefsin. Bana '" . $urunAdi . "' yemeği için müşterilerin iştahını açacak, maksimum 2 cümlelik, modern bir menü açıklaması yazar mısın? Fiyat veya emoji olmasın."]]]]]);
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch); curl_close($ch);
    $resData = json_decode($response, true);
    $text = $resData['candidates'][0]['content']['parts'][0]['text'] ?? "Gurme lezzetlerin harmanıyla hazırlanan özel sunum.";
    echo json_encode(['aciklama' => trim($text)]);
    exit;
}