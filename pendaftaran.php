<?php
// pendaftaran.php
// Single-file: Multi-step registration form + server handling (SQLite)
// Make sure: database/competitions.db exists and uploads/ is writable

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ---------- CONFIG -------------------------------------------------------
$dbFile = __DIR__ . '/database/competitions.db';
$uploadsDir = __DIR__ . '/uploads';

// create uploads dir if missing
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0777, true);
}

// connect DB
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo "<h2>Gagal koneksi database</h2><p>{$e->getMessage()}</p>";
    exit;
}

// fetch competitions table (if exists)
$kompetisis = [];
try {
    $stmt = $db->query("SELECT id, judul, deskripsi, registration_link, submission_link FROM kompetisi ORDER BY id ASC");
    $kompetisis = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // fallback: create local list if table missing
    $kompetisis = [
        ['id'=>1,'judul'=>'Essay Competition','deskripsi'=>'Perseorangan - menulis esai','registration_link'=>'#','submission_link'=>'#'],
        ['id'=>2,'judul'=>'Debate Competition','deskripsi'=>'Tim - debat','registration_link'=>'#','submission_link'=>null],
        ['id'=>3,'judul'=>'Innovation Case Competition','deskripsi'=>'Tim - inovasi kasus','registration_link'=>'#','submission_link'=>'#'],
        ['id'=>4,'judul'=>'Puzzle Competition','deskripsi'=>'Tim - teka-teki','registration_link'=>'#','submission_link'=>null],
    ];
}

// ---------- HELPERS ------------------------------------------------------
function safeEcho($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function allowed_ext($ext){
    $allow = ['jpg','jpeg','png','gif','webp'];
    return in_array(strtolower($ext), $allow, true);
}
function saveUploadedFiles(array $filesField, string $prefix, string $uploadsDir, array &$errors, int $maxFiles = 10, int $maxSize = 5 * 1024 * 1024){
    // $filesField expected structure from $_FILES['fieldname']
    $saved = [];
    if (!isset($filesField['name'])) return $saved;
    $count = is_array($filesField['name']) ? count($filesField['name']) : 0;
    if ($count === 0) return $saved;
    if ($count > $maxFiles) {
        $errors[] = "Maksimum $maxFiles file untuk {$prefix}. Kamu mengunggah $count file.";
        // we still process first $maxFiles
        $count = $maxFiles;
    }
    for ($i=0; $i<$count; $i++){
        if (empty($filesField['name'][$i])) continue;
        $tmp = $filesField['tmp_name'][$i];
        $orig = $filesField['name'][$i];
        $size = $filesField['size'][$i];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($size <= 0 || !is_uploaded_file($tmp)){
            $errors[] = "File '$orig' gagal diunggah.";
            continue;
        }
        if ($size > $maxSize){
            $errors[] = "File '$orig' melebihi batas ukuran " . ($maxSize/1024/1024) . "MB.";
            continue;
        }
        if (!allowed_ext($ext)){
            $errors[] = "Tipe file '$orig' tidak diizinkan. Hanya gambar (jpg/png/webp/gif).";
            continue;
        }
        // generate sanitized unique name
        $safe = preg_replace('/[^a-z0-9_\-\.]/i', '_', pathinfo($orig, PATHINFO_FILENAME));
        $uniq = $safe . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uniq;
        if (@move_uploaded_file($tmp, $dest)){
            $saved[] = $uniq;
        } else {
            $errors[] = "Gagal menyimpan file '$orig'.";
        }
    }
    return $saved;
}

// ---------- SERVER-SIDE SUBMIT -------------------------------------------
$formStatus = null;
$errors = [];
$stored_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_registration') {
    // gather POST
    $category = trim($_POST['category'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $teamname = trim($_POST['teamname'] ?? '');
    $leader   = trim($_POST['leader'] ?? '');
    $members  = array_values(array_filter(array_map('trim', $_POST['members'] ?? [])));
    $school   = trim($_POST['school'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $notes    = trim($_POST['note'] ?? '');

    // server-side validation (basics)
    if ($category === '') $errors[] = "Kategori lomba harus dipilih.";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email ketua tidak valid.";
    if ($leader === '') $errors[] = "Nama ketua wajib diisi.";
    // For essay (perseorangan) - ensure no members and only one leader
    $isEssay = stripos($category, 'essay') !== false;

    if ($isEssay) {
        // clear teamname and members
        $teamname = '';
        $members = [];
    } else {
        // team competitions allow up to 4 members
        if (count($members) > 4) {
            $errors[] = "Anggota maksimal 4 orang.";
        }
    }

    // Handle uploads: idcard, proof (follow IG), twibbon, transfer
    $uploaded = [];
    $uploaded['idcard'] = saveUploadedFiles($_FILES['idcard'] ?? [], 'Tanda Pengenal', $uploadsDir, $errors, 10);
    $uploaded['proof'] = saveUploadedFiles($_FILES['proof'] ?? [], 'Bukti Follow', $uploadsDir, $errors, 10);
    $uploaded['twibbon'] = saveUploadedFiles($_FILES['twibbon'] ?? [], 'Twibbon', $uploadsDir, $errors, 10);
    $uploaded['transfer'] = saveUploadedFiles($_FILES['transfer'] ?? [], 'Bukti Transfer', $uploadsDir, $errors, 10);

    // if no critical errors, save to DB
    if (empty($errors)) {
        // prepare data to store into pendaftaran table (keep compatibility with initial schema)
        // use name => leader, school, email, phone, category, note => JSON with extras
        $notePayload = [
            'teamname' => $teamname,
            'members' => array_values($members),
            'uploads' => $uploaded,
            'extra_note' => $notes,
            'submitted_at' => date('c'),
        ];
        try {
            $stmt = $db->prepare("INSERT INTO pendaftaran (name, school, email, phone, category, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $leader,
                $school,
                $email,
                $phone,
                $category,
                json_encode($notePayload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
            ]);
            $stored_id = $db->lastInsertId();
            $formStatus = "success";
            // store saved data in session for display on confirmation page
            $_SESSION['last_registration'] = [
                'id' => $stored_id,
                'leader' => $leader,
                'email' => $email,
                'school' => $school,
                'phone' => $phone,
                'category' => $category,
                'teamname' => $teamname,
                'members' => $members,
                'uploads' => $uploaded,
                'note' => $notes,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } catch (PDOException $e) {
            $errors[] = "Gagal menyimpan ke database: " . $e->getMessage();
        }
    }
}

// If user has just registered (stored_id), we will render confirmation page below using session data.
$justRegistered = ($formStatus === 'success' && isset($_SESSION['last_registration']));
$sessionData = $_SESSION['last_registration'] ?? null;

?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pendaftaran Kompetisi — Summit Of Stars</title>
  <meta name="description" content="Form pendaftaran multi-step untuk Summit Of Stars — Essay, Debate, Innovation Case, Puzzle">
  <link rel="preload" href="/fonts/HafferSQXH-Regular.woff" as="font" type="font/woff" crossorigin>
  <link rel="preload" href="/fonts/Telegraf-Regular.woff" as="font" type="font/woff" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="" crossorigin="anonymous" />
  <style>
    /* ===========================
       Clean modern design & animations
       =========================== */
    :root{
      --bg: #f7f6fb;
      --card: #ffffff;
      --muted: #666077;
      --accent1: #8A7CAC;
      --accent2: #FF9DAC;
      --glass-border: rgba(138,124,172,0.12);
      --radius: 14px;
      --maxw: 1060px;
      --success: #25a86f;
      --danger: #d64545;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      background: linear-gradient(180deg, #f8f7fb 0%, #f3eff9 100%);
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      color:#2b2435;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
      line-height:1.45;
      padding-bottom:50px;
    }

    .wrap{max-width:var(--maxw); margin:40px auto; padding:28px; position:relative;}
    .card{
      background:var(--card);
      border-radius:20px;
      padding:28px;
      box-shadow:0 10px 30px rgba(31,20,50,0.06);
      border:1px solid var(--glass-border);
      overflow:hidden;
    }

    /* header */
    .topbar{
      display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px;
    }
    .brand{
      display:flex; align-items:center; gap:14px;
    }
    .brand img{height:48px; width:auto; border-radius:8px; box-shadow:0 6px 20px rgba(138,124,172,0.12)}
    .brand h1{font-size:1.25rem; margin:0; font-weight:800; background:linear-gradient(90deg,var(--accent1),var(--accent2)); -webkit-background-clip:text; -webkit-text-fill-color:transparent}
    .subtitle{color:var(--muted); font-size:0.95rem}

    /* progress */
    .progress-wrap{margin:18px 0 8px}
    .progress{height:10px; background:linear-gradient(90deg,#ede9f6,#fbf0f3); border-radius:999px; overflow:hidden; border:1px solid rgba(0,0,0,0.03)}
    .progress-bar{height:100%; width:0%; background:linear-gradient(90deg,var(--accent1),var(--accent2)); transition:width .6s cubic-bezier(.2,.9,.2,1)}
    .steps-row{display:flex; gap:12px; margin-top:12px; justify-content:center; align-items:center}
    .step-dot{width:12px;height:12px;border-radius:50%; background:#eee; display:inline-block; box-shadow:0 2px 6px rgba(0,0,0,0.04)}
    .step-dot.active{background:linear-gradient(90deg,var(--accent1),var(--accent2)); transform:scale(1.12); box-shadow:0 8px 24px rgba(181,140,217,0.18)}

    /* category options */
    .category-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:16px; margin-top:8px}
    .cat-card{padding:18px;border-radius:12px;background:linear-gradient(180deg,#fff,#fbfaff); border:1px solid var(--glass-border); cursor:pointer; transition:transform .28s, box-shadow .28s}
    .cat-card:hover{transform:translateY(-6px); box-shadow:0 18px 40px rgba(138,124,172,0.08)}
    .cat-card h3{margin:0 0 8px; font-size:1.05rem}
    .cat-card p{margin:0;color:var(--muted); font-size:.92rem}

    /* form grid - inputs side-by-side on wide */
    form .grid{display:grid; grid-template-columns:repeat(2,1fr); gap:12px}
    .form-row{display:flex; gap:12px}
    .field{display:flex; flex-direction:column; gap:6px}
    .field label{font-weight:700; color:#3b3144; font-size:.9rem}
    .field input[type="text"], .field input[type="email"], .field input[type="tel"], .field select, .field input[type="url"]{
      padding:12px 14px; border-radius:10px; border:1px solid rgba(0,0,0,0.06); font-size:.98rem; background:#fff;
      transition:box-shadow .18s, border-color .18s;
    }
    .field input:focus, .field select:focus{outline:none; border-color:var(--accent1); box-shadow:0 8px 20px rgba(138,124,172,0.08)}

    /* file input (custom) */
    .file-row{display:grid; grid-template-columns:1fr; gap:10px}
    .file-box{padding:10px; border-radius:10px; border:1px dashed rgba(138,124,172,0.12); display:flex; align-items:center; gap:12px; background:linear-gradient(180deg,#fff,#fbfbff)}
    .file-box input[type="file"]{border:0;background:transparent; flex:1}
    .file-preview{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}
    .thumb{width:80px;height:60px;border-radius:8px; overflow:hidden;border:1px solid rgba(0,0,0,0.06); display:flex;align-items:center; justify-content:center; background:#fff}
    .thumb img{max-width:100%; max-height:100%; display:block}

    /* CTA row */
    .actions{display:flex; gap:12px; justify-content:space-between; align-items:center; margin-top:14px}
    .actions .left{color:var(--muted); font-size:.95rem}
    .btn{padding:12px 18px; border-radius:999px; border:0; cursor:pointer; font-weight:800; color:#fff; background:linear-gradient(90deg,var(--accent1),var(--accent2)); box-shadow:0 8px 28px rgba(138,124,172,0.14); transition:transform .18s}
    .btn.secondary{background:#fff; color:#443650; border:1px solid rgba(0,0,0,0.06); box-shadow:none}
    .btn:active{transform:translateY(2px)}

    /* responsive - single column for small screens */
    @media(max-width:880px){
      .category-grid{grid-template-columns:1fr}
      form .grid{grid-template-columns:1fr}
      .brand h1{font-size:1rem}
    }

    /* notification toast */
    .toast{
      position:fixed; right:20px; bottom:24px; z-index:9999; min-width:240px; max-width:360px;
      border-radius:12px; padding:12px 14px; color:#fff; display:flex; gap:12px; align-items:center; box-shadow:0 12px 30px rgba(0,0,0,0.12);
      transform:translateY(20px); opacity:0; pointer-events:none; transition:transform .34s, opacity .34s;
      background:linear-gradient(90deg,var(--accent1),var(--accent2));
    }
    .toast.show{transform:translateY(0); opacity:1; pointer-events:auto}
    .toast .t-body{font-weight:700}
    .toast .t-close{margin-left:auto; opacity:.9; cursor:pointer}

    /* confirmation layout */
    .confirm{
      display:grid; grid-template-columns:1fr 320px; gap:18px; align-items:start;
    }
    @media(max-width:980px){ .confirm{grid-template-columns:1fr} }

    .summary{padding:16px;border-radius:12px;background:linear-gradient(180deg,#fff,#fbfbff); border:1px solid rgba(0,0,0,0.04)}
    .summary h3{margin:0 0 8px}
    .summary p{margin:6px 0; color:var(--muted)}
    .bank{padding:12px;border-radius:12px;background:linear-gradient(90deg,#fbf5f9,#f9fbff); border:1px solid rgba(138,124,172,0.06)}
    .small{font-size:.9rem;color:var(--muted)}
    .success-badge{display:inline-block;padding:6px 10px;border-radius:999px;background:linear-gradient(90deg,#27c07a,#1f8e58); color:#fff; font-weight:700}
    .danger-badge{display:inline-block;padding:6px 10px;border-radius:999px;background:linear-gradient(90deg,#ff8a8a,#d64545); color:#fff; font-weight:700}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="topbar">
        <div class="brand">
          <img src="images/summitstars.png" alt="logo">
          <div>
            <h1>Summit Of Stars — Pendaftaran</h1>
            <div class="subtitle">Pilih cabang lomba → isi data → konfirmasi. Mudah, cepat, & aman.</div>
          </div>
        </div>
        <div class="subtitle">Tanggal puncak: <strong>18 Januari 2025</strong></div>
      </div>

      <?php if (!empty($errors)): ?>
        <div style="margin-bottom:12px;padding:10px;border-radius:10px;background:linear-gradient(90deg,#fff5f5,#ffeef0);border:1px solid rgba(214,69,69,0.08); color:var(--danger); font-weight:700">
          <i class="fa-solid fa-triangle-exclamation"></i>
          Terjadi <strong><?= count($errors) ?></strong> kesalahan:
          <ul style="margin:8px 0 0 18px;">
            <?php foreach($errors as $er): ?>
              <li><?= safeEcho($er) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($justRegistered && $sessionData): ?>
        <!-- ================= CONFIRMATION (SERVER RENDERED) ================== -->
        <div class="progress-wrap">
          <div class="progress"><div class="progress-bar" style="width:100%"></div></div>
          <div class="steps-row" aria-hidden="true">
            <span class="step-dot"></span>
            <span class="step-dot"></span>
            <span class="step-dot active"></span>
          </div>
        </div>

        <div style="margin-top:14px" class="confirm">
          <div class="summary">
            <h3>Konfirmasi Pendaftaran</h3>
            <p class="small">Terima kasih — pendaftaran Anda telah berhasil tersimpan.</p>

            <p><strong>ID Pendaftaran:</strong> <?= safeEcho($sessionData['id']) ?></p>
            <p><strong>Cabang Lomba:</strong> <?= safeEcho($sessionData['category']) ?></p>
            <p><strong>Nama Ketua:</strong> <?= safeEcho($sessionData['leader']) ?></p>
            <p><strong>Email Ketua:</strong> <?= safeEcho($sessionData['email']) ?></p>
            <?php if (!empty($sessionData['teamname'])): ?>
              <p><strong>Nama Tim:</strong> <?= safeEcho($sessionData['teamname']) ?></p>
            <?php endif; ?>
            <?php if (!empty($sessionData['members'])): ?>
              <p><strong>Anggota:</strong> <?= safeEcho(implode(', ', $sessionData['members'])) ?></p>
            <?php endif; ?>
            <p><strong>Asal/Instansi:</strong> <?= safeEcho($sessionData['school']) ?></p>

            <hr style="border:none;border-top:1px solid rgba(0,0,0,0.04); margin:12px 0">

            <h4 style="margin:8px 0">Unggahan</h4>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <?php
                $allUploads = $sessionData['uploads'] ?? [];
                foreach (['idcard','proof','twibbon','transfer'] as $f){
                  if (!empty($allUploads[$f])){
                    foreach ($allUploads[$f] as $fn){
                      $url = 'uploads/' . rawurlencode($fn);
                      echo "<div style='width:72px;height:56px;border-radius:8px;overflow:hidden;border:1px solid rgba(0,0,0,0.05);background:#fff;display:flex;align-items:center;justify-content:center'><img src=\"{$url}\" style=\"max-width:100%;max-height:100%;display:block\"></div>";
                    }
                  }
                }
              ?>
            </div>

            <hr style="border:none;border-top:1px solid rgba(0,0,0,0.04); margin:12px 0">

            <p class="small">Kami telah menyimpan data. Tim panitia akan menghubungi via email yang Anda daftarkan untuk verifikasi dan informasi lanjut.</p>
          </div>

          <aside>
            <div class="bank">
              <h4 style="margin:0 0 8px">Instruksi Pembayaran</h4>
              <p class="small">Silakan transfer biaya pendaftaran ke salah satu rekening berikut:</p>
              <p style="margin:8px 0"><strong>Bank Mandiri</strong><br>1130018161327<br><em>a.n. DELLA APRILIA</em></p>
              <p style="margin:8px 0"><strong>DANA</strong><br>082280943039<br><em>a.n. DELLA APRILIA</em></p>
              <p class="small" style="margin-top:10px">Unggah bukti transfer pada saat pendaftaran (opsional), atau kirimkan via email konfirmasi setelah transfer.</p>
            </div>

            <div style="margin-top:14px; padding:12px; border-radius:12px; background:linear-gradient(90deg,#fbfbff,#fffaf6); border:1px solid rgba(0,0,0,0.04)">
              <p style="margin:0 0 10px;"><strong>Butuh bantuan?</strong></p>
              <p class="small" style="margin:0">Hubungi kami: <a href="mailto:info@sumseyouthcomp.com">info@sumseyouthcomp.com</a> atau <a href="tel:+62711123456">+62 711 123 456</a></p>
              <p style="margin-top:8px"><a href="https://mhteams.my.id" target="_blank" class="btn" style="display:inline-block">Butuh Website?</a></p>
            </div>
          </aside>
        </div>

        <div style="margin-top:18px;display:flex;justify-content:center;gap:12px">
          <a href="pendaftaran.php" class="btn secondary">Daftar Lagi</a>
          <a href="#home" class="btn">Kembali ke Beranda</a>
        </div>

      <?php else: ?>
        <!-- ================= MULTI-STEP FORM (client side) ================== -->
        <div id="app">
          <div class="progress-wrap">
            <div class="progress"><div class="progress-bar" id="progressBar" style="width:33%"></div></div>
            <div class="steps-row" aria-hidden="true">
              <span class="step-dot active" id="dot1"></span>
              <span class="step-dot" id="dot2"></span>
              <span class="step-dot" id="dot3"></span>
            </div>
          </div>

          <form id="regForm" class="form" enctype="multipart/form-data" method="POST" action="pendaftaran.php" novalidate>
            <input type="hidden" name="action" value="submit_registration">

            <!-- STEP 1 -->
            <div class="step" data-step="1">
              <p class="small">Langkah 1 — Pilih cabang lomba. Essay = <strong>Perseorangan</strong>. Lainnya = <strong>Tim (max 4 anggota)</strong>.</p>
              <div class="category-grid" role="list">
                <?php foreach($kompetisis as $k): ?>
                  <label class="cat-card" role="listitem">
                    <input type="radio" name="category" value="<?= safeEcho($k['judul']) ?>" style="display:none">
                    <h3><?= safeEcho($k['judul']) ?></h3>
                    <p><?= safeEcho($k['deskripsi']) ?></p>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-top:18px">
                <div class="left small">Pilih satu kategori untuk melanjutkan.</div>
                <div>
                  <button type="button" class="btn secondary" id="resetBtn">Reset</button>
                  <button type="button" class="btn" id="toStep2">Lanjut →</button>
                </div>
              </div>
            </div>

            <!-- STEP 2 -->
            <div class="step" data-step="2" style="display:none">
              <p class="small">Langkah 2 — Isi data peserta. Form disusun dua kolom pada layar besar untuk tampilan rapi.</p>

              <div class="grid">
                <div class="field"><label>Email Ketua <span style="color:var(--danger)">*</span></label><input type="email" name="email" required placeholder="email@domain.com"></div>
                <div class="field"><label>Nomor HP Ketua</label><input type="tel" name="phone" placeholder="0812xxxxxxx"></div>

                <div class="field" id="teamNameField" style="display:none"><label>Nama Tim (untuk Puzzle)</label><input type="text" name="teamname" placeholder="Nama tim"></div>
                <div class="field"><label>Nama Ketua <span style="color:var(--danger)">*</span></label><input type="text" name="leader" required placeholder="Nama Ketua"></div>

                <div class="field" id="membersBlock" style="display:none">
                  <label>Anggota (maks 4)</label>
                  <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:8px">
                    <input type="text" name="members[]" placeholder="Anggota 1">
                    <input type="text" name="members[]" placeholder="Anggota 2">
                    <input type="text" name="members[]" placeholder="Anggota 3">
                    <input type="text" name="members[]" placeholder="Anggota 4">
                  </div>
                </div>

                <div class="field"><label>Instansi / Sekolah</label><input type="text" name="school" placeholder="Nama sekolah / instansi"></div>
                <div class="field"><label>Keterangan tambahan (opsional)</label><input type="text" name="note" placeholder="Catatan / kebutuhan khusus"></div>
              </div>

              <hr style="margin:12px 0;border:none;border-top:1px solid rgba(0,0,0,0.04)">

              <div class="file-row">
                <div>
                  <label class="small">Scan Tanda Pengenal (jpg/png/webp) — Maks 10 file</label>
                  <div class="file-box"><i class="fa-regular fa-id-card" style="color:var(--muted)"></i><input type="file" name="idcard[]" id="idcard" accept="image/*" multiple></div>
                  <div id="idPreview" class="file-preview"></div>
                </div>

                <div>
                  <label class="small">Bukti Follow (tangkapan layar) — youthranger.id, youthranger.sumsel, summitofstarsyri — Maks 10 file</label>
                  <div class="file-box"><i class="fa-brands fa-instagram" style="color:var(--muted)"></i><input type="file" name="proof[]" id="proof" accept="image/*" multiple></div>
                  <div id="proofPreview" class="file-preview"></div>
                </div>

                <div>
                  <label class="small">Bukti Upload Twibbon — Maks 10 file</label>
                  <div class="file-box"><i class="fa-regular fa-image" style="color:var(--muted)"></i><input type="file" name="twibbon[]" id="twibbon" accept="image/*" multiple></div>
                  <div id="twibbonPreview" class="file-preview"></div>
                </div>

                <div>
                  <label class="small">Bukti Transfer (jpg/png/webp) — Maks 10 file</label>
                  <div class="file-box"><i class="fa-solid fa-money-bill-transfer" style="color:var(--muted)"></i><input type="file" name="transfer[]" id="transfer" accept="image/*" multiple></div>
                  <div id="transferPreview" class="file-preview"></div>
                </div>
              </div>

              <div class="actions" style="margin-top:8px">
                <div class="left small">Semua file maksimal 5MB per file; gambar disarankan JPG/PNG/WEBP.</div>
                <div>
                  <button type="button" class="btn secondary" id="backTo1">← Kembali</button>
                  <button type="button" class="btn" id="toStep3">Lihat Ringkasan →</button>
                </div>
              </div>
            </div>

            <!-- STEP 3 (client preview) -->
            <div class="step" data-step="3" style="display:none">
              <p class="small">Langkah 3 — Konfirmasi data. Pastikan semua benar sebelum klik "Kirim Pendaftaran".</p>

              <div class="confirm" style="margin-top:12px">
                <div class="summary" id="previewSummary">
                  <!-- filled by JS -->
                  <h3>Ringkasan Pendaftaran</h3>
                  <p class="small">Periksa kembali semua data dan unggahan.</p>
                  <div id="summaryBody"></div>
                </div>
                <aside>
                  <div class="bank">
                    <h4 style="margin:0 0 8px">Cara Pembayaran</h4>
                    <p class="small">Transfer ke salah satu rekening:</p>
                    <p style="margin:8px 0"><strong>Bank Mandiri</strong><br>1130018161327<br><em>a.n. DELLA APRILIA</em></p>
                    <p style="margin:8px 0"><strong>DANA</strong><br>082280943039<br><em>a.n. DELLA APRILIA</em></p>
                    <p class="small" style="margin-top:10px">Unggah bukti transfer di form sebelumnya, atau kirim melalui email jika belum.</p>
                  </div>

                  <div style="margin-top:12px; padding:12px; border-radius:12px; background:linear-gradient(90deg,#fbfbff,#fffaf6); border:1px solid rgba(0,0,0,0.04)">
                    <p style="margin:0"><strong>Perlu bantuan?</strong></p>
                    <p class="small" style="margin:6px 0 0">Kontak: <a href="mailto:info@sumseyouthcomp.com">info@sumseyouthcomp.com</a></p>
                  </div>
                </aside>
              </div>

              <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:14px">
                <button type="button" class="btn secondary" id="backTo2">← Kembali</button>
                <button type="submit" class="btn" id="submitBtn">Kirim Pendaftaran</button>
              </div>
            </div>
          </form>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- toast -->
  <div id="toast" role="status" class="toast" aria-live="polite" style="display:none">
    <div class="t-body">Pesan</div>
    <div class="t-close" onclick="hideToast()" aria-hidden="true" style="margin-left:10px"><i class="fa-solid fa-xmark"></i></div>
  </div>

<script>
  // ======== Utilities ========
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  function showToast(message, timeout=3800){
    const t = document.getElementById('toast');
    t.querySelector('.t-body').textContent = message;
    t.style.display = 'flex';
    setTimeout(()=> t.classList.add('show'), 50);
    if (timeout>0){
      clearTimeout(t._timer);
      t._timer = setTimeout(()=>{ hideToast(); }, timeout);
    }
  }
  function hideToast(){
    const t = document.getElementById('toast');
    t.classList.remove('show');
    setTimeout(()=> t.style.display='none', 350);
  }

  // ======== Multi-step logic ========
  (function(){
    const steps = $$('.step');
    let current = 1;
    const total = steps.length;
    const progressBar = $('#progressBar');
    const dot1 = $('#dot1'), dot2=$('#dot2'), dot3=$('#dot3');

    function showStep(n){
      steps.forEach(s=> s.style.display='none');
      const el = document.querySelector('.step[data-step="'+n+'"]');
      if (el) el.style.display='block';
      // progress
      const pct = Math.round((n/3)*100);
      progressBar.style.width = pct + '%';
      dot1.classList.toggle('active', n>=1);
      dot2.classList.toggle('active', n>=2);
      dot3.classList.toggle('active', n>=3);
      current = n;
      window.scrollTo({top:0, behavior:'smooth'});
    }

    // pick category card
    document.querySelectorAll('.cat-card').forEach(card=>{
      card.addEventListener('click', ()=>{
        // set radio inside
        const r = card.querySelector('input[type="radio"]');
        if (r){ r.checked = true; }
        // visual
        document.querySelectorAll('.cat-card').forEach(c=> c.style.boxShadow='none');
        card.style.boxShadow = '0 18px 40px rgba(138,124,172,0.12)';
      });
    });

    $('#toStep2').addEventListener('click', ()=>{
      const chosen = document.querySelector('input[name="category"]:checked');
      if (!chosen){
        showToast('Pilih kategori lomba terlebih dahulu');
        return;
      }
      // reveal step 2 and adjust fields
      const cat = chosen.value.toLowerCase();
      const isEssay = cat.includes('essay');
      document.getElementById('teamNameField').style.display = isEssay ? 'none' : (cat.includes('puzzle')? 'block':'none');
      document.getElementById('membersBlock').style.display = isEssay ? 'none' : 'block';
      // mark radio values to a hidden field? we'll keep radios as is and let form submit
      showStep(2);
    });

    $('#backTo1').addEventListener('click', ()=> showStep(1));
    $('#resetBtn').addEventListener('click', ()=> {
      $$('input[name="category"]').forEach(r=> r.checked=false);
      document.querySelectorAll('.cat-card').forEach(c=> c.style.boxShadow='');
    });

    // Step2 -> Step3
    $('#toStep3').addEventListener('click', ()=>{
      // validate required fields on step2
      const email = document.querySelector('input[name="email"]').value.trim();
      const leader = document.querySelector('input[name="leader"]').value.trim();
      const chosen = document.querySelector('input[name="category"]:checked');
      if (!chosen) { showToast('Kategori belum dipilih'); showStep(1); return; }
      if (!email || !/^\S+@\S+\.\S+$/.test(email)) { showToast('Masukkan email ketua yang valid'); return; }
      if (!leader) { showToast('Masukkan nama ketua'); return; }

      // populate summary
      const summary = $('#summaryBody');
      const form = document.getElementById('regForm');
      const fd = new FormData(form);
      // we need to read some values
      function getVal(name){ return (fd.getAll(name).join(',')||'').toString() }
      let html = '';
      html += `<p><strong>Cabang:</strong> ${safe(getVal('category'))}</p>`;
      html += `<p><strong>Nama Ketua:</strong> ${safe(getVal('leader'))}</p>`;
      html += `<p><strong>Email Ketua:</strong> ${safe(getVal('email'))}</p>`;
      const teamname = safe(getVal('teamname'));
      if (teamname) html += `<p><strong>Nama Tim:</strong> ${teamname}</p>`;
      const members = fd.getAll('members[]').filter(v=>v && v.trim()).map(v=>safe(v));
      if (members.length) html += `<p><strong>Anggota:</strong> ${members.join(', ')}</p>`;
      html += `<p><strong>Instansi:</strong> ${safe(getVal('school'))}</p>`;
      html += `<p class="small">Periksa unggahan di bawah, pastikan semua gambar sudah benar.</p>`;
      // previews (client-side)
      function renderFileList(inputId){
        const inp = document.getElementById(inputId);
        if (!inp || !inp.files || inp.files.length===0) return '';
        let out = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">';
        for (let i=0;i<inp.files.length;i++){
          const f = inp.files[i];
          const url = URL.createObjectURL(f);
          out += `<div class="thumb"><img src="${url}" alt="${safe(f.name)}"></div>`;
        }
        out += '</div>';
        return out;
      }
      html += '<h4 style="margin-top:12px">Preview Unggahan</h4>';
      html += '<div class="small"><strong>Scan ID:</strong></div>' + renderFileList('idcard');
      html += '<div class="small"><strong>Bukti Follow:</strong></div>' + renderFileList('proof');
      html += '<div class="small"><strong>Twibbon:</strong></div>' + renderFileList('twibbon');
      html += '<div class="small"><strong>Bukti Transfer:</strong></div>' + renderFileList('transfer');

      summary.innerHTML = html;
      showStep(3);
    });

    $('#backTo2').addEventListener('click', ()=> showStep(2));

    // handle submit: normal form POST; show client toast while submitting
    $('#regForm').addEventListener('submit', function(e){
      // final checks (files count limits)
      const maxFiles = 10;
      const idcount = (document.getElementById('idcard').files||[]).length;
      const proofcount = (document.getElementById('proof').files||[]).length;
      const twcount = (document.getElementById('twibbon').files||[]).length;
      const trcount = (document.getElementById('transfer').files||[]).length;
      if (idcount>maxFiles || proofcount>maxFiles || twcount>maxFiles || trcount>maxFiles){
        e.preventDefault();
        showToast('Maksimum 10 file per unggahan. Kurangi jumlah file dan coba lagi.');
        showStep(2);
        return false;
      }
      showToast('Mengirim pendaftaran... Mohon tunggu', 6000);
      // allow form to submit (page reload will show server confirmation)
    });

    // previewing selected files in step2
    function attachPreview(inputId, previewId){
      const inp = document.getElementById(inputId);
      const box = document.getElementById(previewId);
      if (!inp || !box) return;
      inp.addEventListener('change', ()=>{
        box.innerHTML = '';
        for (let i=0;i<inp.files.length;i++){
          const f = inp.files[i];
          const url = URL.createObjectURL(f);
          const div = document.createElement('div');
          div.className = 'thumb';
          const img = document.createElement('img');
          img.src = url;
          img.alt = f.name;
          div.appendChild(img);
          box.appendChild(div);
        }
      });
    }
    attachPreview('idcard','idPreview');
    attachPreview('proof','proofPreview');
    attachPreview('twibbon','twibbonPreview');
    attachPreview('transfer','transferPreview');

    // safe text
    function safe(s){ return String(s).replace(/</g,'&lt;').replace(/>/g,'&gt;') }

    // init to step1
    showStep(1);
  })();
</script>
</body>
</html>
