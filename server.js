const express = require('express');
const multer = require('multer');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 5000;

app.use(express.json());
app.use(express.static('public'));

// Klasörleri Otomatik Oluşturma Güvencesi
const uploadsDir = path.join(__dirname, 'public', 'uploads');
if (!fs.existsSync(uploadsDir)) fs.mkdirSync(uploadsDir, { recursive: true });

const VERI_YOLU = path.join(__dirname, 'veritabanı.json');
const CONFIG_YOLU = path.join(__dirname, 'config.json');

if (!fs.existsSync(VERI_YOLU)) fs.writeFileSync(VERI_YOLU, '[]');
if (!fs.existsSync(CONFIG_YOLU)) fs.writeFileSync(CONFIG_YOLU, JSON.stringify({ gemini_key: "" }));

// --- FOTOĞRAF YÜKLEME (MULTER) AYARI ---
const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, uploadsDir),
  filename: (req, file, cb) => {
    const benzersizEk = Date.now() + '-' + Math.round(Math.random() * 1E9);
    cb(null, benzersizEk + path.extname(file.originalname));
  }
});
const upload = multer({ storage: storage });

// --- API ENDPOINT'LERİ ---

// Görsel Yükleme Api'si
app.post('/api/upload', upload.single('gorsel'), (req, res) => {
  if (!req.file) return res.status(400).json({ hata: 'Dosya yüklenemedi' });
  res.json({ url: `/uploads/${req.file.filename}` });
});

// API Key Al / Güncelle
app.get('/api/config', (req, res) => {
  const config = JSON.parse(fs.readFileSync(CONFIG_YOLU, 'utf8'));
  res.json({ mevcut_key: config.gemini_key ? "********" : "" });
});

app.post('/api/config', (req, res) => {
  const { key } = req.body;
  fs.writeFileSync(CONFIG_YOLU, JSON.stringify({ gemini_key: key }));
  res.json({ durum: 'ok' });
});

// Menü Verilerini Getir
app.get('/api/menu', (req, res) => {
  res.json(JSON.parse(fs.readFileSync(VERI_YOLU, 'utf8')));
});

// Kategori Ekle
app.post('/api/kategori', (req, res) => {
  const menuler = JSON.parse(fs.readFileSync(VERI_YOLU, 'utf8'));
  const yeniKat = {
    kategori_id: 'kat_' + Date.now(),
    kategori_adi: req.body.isim,
    urunler: []
  };
  menulereEkleVeKaydet(menuler, yeniKat, res);
});

// Kategori Sil
app.delete('/api/kategori/:id', (req, res) => {
  let menuler = JSON.parse(fs.readFileSync(VERI_YOLU, 'utf8'));
  menuler = menuler.filter(k => k.kategori_id !== req.params.id);
  fs.writeFileSync(VERI_YOLU, JSON.stringify(menuler, null, 2));
  res.json({ durum: 'ok' });
});

// Ürün Ekle / Düzenle
app.post('/api/urun', (req, res) => {
  const { kategoriId, urun } = req.body;
  let menuler = JSON.parse(fs.readFileSync(VERI_YOLU, 'utf8'));
  
  const kat = menuler.find(k => k.kategori_id === kategoriId);
  if (!kat) return res.status(404).json({ hata: 'Kategori bulunamadı' });

  if (urun.urun_id) {
    // Düzenleme modu
    const indeks = kat.urunler.findIndex(u => u.urun_id === urun.urun_id);
    if (indeks !== -1) {
      urun.is_available = kat.urunler[indeks].is_available; // stok durumunu koru
      kat.urunler[indeks] = urun;
    }
  } else {
    // Yeni ekleme modu
    urun.urun_id = 'urun_' + Date.now();
    urun.is_available = true;
    kat.urunler.push(urun);
  }

  fs.writeFileSync(VERI_YOLU, JSON.stringify(menuler, null, 2));
  res.json({ durum: 'ok' });
});

// Ürün Sil
app.delete('/api/urun/:katId/:urunId', (req, res) => {
  let menuler = JSON.parse(fs.readFileSync(VERI_YOLU, 'utf8'));
  const kat = menuler.find(k => k.kategori_id === req.params.katId);
  if (kat) {
    kat.urunler = kat.urunler.filter(u => u.urun_id !== req.params.urunId);
    fs.writeFileSync(VERI_YOLU, JSON.stringify(menuler, null, 2));
  }
  res.json({ durum: 'ok' });
});

// Stok Durumu Güncelle
app.patch('/api/stok-guncelle', (req, res) => {
  const { urunId, isAvailable } = req.body;
  let menuler = JSON.parse(fs.readFileSync(VERI_YOLU, 'utf8'));
  
  menuler.forEach(kat => {
    const urun = kat.urunler.find(u => u.urun_id === urunId);
    if (urun) urun.is_available = isAvailable;
  });

  fs.writeFileSync(VERI_YOLU, JSON.stringify(menuler, null, 2));
  res.json({ durum: 'ok' });
});

// AI Açıklama Sihirbazı (Gemini Native API entegrasyonu)
app.post('/api/ai-yazdir', async (req, res) => {
  const { urunAdi } = req.body;
  const config = JSON.parse(fs.readFileSync(CONFIG_YOLU, 'utf8'));
  
  if (!config.gemini_key) {
    return res.json({ aciklama: `${urunAdi} için harika bir tarif! (Lütfen çalışması için yukarından Gemini API Key kaydedin.)` });
  }

  try {
    const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${config.gemini_key}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{ parts: [{ text: `Sen lüks bir restoranda gurme bir şefsin. Bana '${urunAdi}' yemeği için müşterilerin iştahını açacak, maksimum 2 cümlelik, modern ve havalı bir menü açıklaması yazar mısın? İçinde fiyat veya emoji olmasın.` }] }]
      })
    });
    const data = await response.json();
    const text = data.candidates[0].content.parts[0].text.trim();
    res.json({ aciklama: text });
  } catch (error) {
    res.json({ aciklama: "Gurme lezzetlerin harmanıyla hazırlanan özel sunum." });
  }
});

function menulereEkleVeKaydet(menuler, yeniKat, res) {
  menuler.push(yeniKat);
  fs.writeFileSync(VERI_YOLU, JSON.stringify(menuler, null, 2));
  res.json({ durum: 'ok' });
}

app.listen(PORT, () => console.log(`Düz Hosting Sistemi Aktif: http://localhost:${PORT}`));