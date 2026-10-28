<?php
session_start();
require_once __DIR__ . "/koneksi.php";

// PROSES TAMBAH, EDIT, DAN HAPUS
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $aksi = $_POST["aksi"] ?? "";

    if ($aksi === "tambah" || $aksi === "edit") {
        $nim = trim($_POST["nim"] ?? "");
        $nama = trim($_POST["namalengkap"] ?? "");
        $nilai = str_replace(",", ".", trim($_POST["nilaiakhir"] ?? ""));

        // validasi input kosong atau tidak valid
        if ($nim === "" || $nama === "" || $nilai === "") {
            $_SESSION["pesan"] = "NIM, nama, dan nilai tidak boleh kosong!";
            $_SESSION["tipe"] = "danger";
        } elseif (!ctype_digit($nim)) {
            $_SESSION["pesan"] = "NIM harus berupa angka!";
            $_SESSION["tipe"] = "danger";
        } elseif (!is_numeric($nilai) || $nilai < 0 || $nilai > 100) {
            $_SESSION["pesan"] = "Nilai harus antara 0 sampai 100!";
            $_SESSION["tipe"] = "danger";
        } else {
            $nilai = (float)$nilai;

            // Loogika penetuan Nilai Huruf dan Predikat otomatis
            if ($nilai >= 80.51) {
                $huruf = "A";
                $predikat = "Sangat Memuaskan";
            } elseif ($nilai >= 65.51) {
                $huruf = "B";
                $predikat = "Memuaskan";
            } elseif ($nilai >= 50.51) {
                $huruf = "C";
                $predikat = "Cukup";
            } elseif ($nilai >= 34.51) {
                $huruf = "D";
                $predikat = "Kurang";
            } else {
                $huruf = "E";
                $predikat = "Tidak Lulus";
            }

            // Proses simpan ke data base (INSERT atau UPDATE)
            if ($aksi === "tambah") {
                $stmt = $conn->prepare(
                    "INSERT INTO nilaimhsw
                    (nim, namalengkap, nilaiakhir, nilaihuruf, predikat)
                    VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    "ssdss", $nim, $nama, $nilai, $huruf, $predikat
                );
                $berhasil = $stmt->execute();
                $stmt->close();
                $pesanBerhasil = "Data nilai berhasil ditambahkan!";
            } else {
                $stmt = $conn->prepare(
                    "UPDATE nilaimhsw
                    SET namalengkap=?, nilaiakhir=?,
                        nilaihuruf=?, predikat=?
                    WHERE nim=?"
                );
                $stmt->bind_param(
                    "sdsss", $nama, $nilai, $huruf, $predikat, $nim
                );
                $berhasil = $stmt->execute();
                $stmt->close();
                $pesanBerhasil = "Data nilai berhasil diperbarui!";
            }

            if ($berhasil) {
                $_SESSION["pesan"] = $pesanBerhasil;
                $_SESSION["tipe"] = "success";
            } else {
                $_SESSION["pesan"] = "Gagal menyimpan data. Pastikan NIM tidak duplikat saat menambah.";
                $_SESSION["tipe"] = "danger";
            }
        }
    } elseif ($aksi === "hapus") {
        // Proses Hapus Data berdasarkan NIM
        $nim = trim($_POST["nim"] ?? "");

        $stmt = $conn->prepare("DELETE FROM nilaimhsw WHERE nim=?");
        $stmt->bind_param("s", $nim);
        $berhasil = $stmt->execute();
        $terhapus = $stmt->affected_rows;
        $stmt->close();

        if ($berhasil && $terhapus > 0) {
            $_SESSION["pesan"] = "Data nilai berhasil dihapus!";
            $_SESSION["tipe"] = "success";
        } else {
            $_SESSION["pesan"] = "Data gagal dihapus atau tidak ditemukan.";
            $_SESSION["tipe"] = "danger";
        }
    }

    header("Location: adminlte.php");
    exit();
}

// AMBIL notisikasi sementara dari session
$pesan = $_SESSION["pesan"] ?? "";
$tipe = $_SESSION["tipe"] ?? "success";
unset($_SESSION["pesan"], $_SESSION["tipe"]);

// ===================================================================
// Pencarian (WHERE), Sorting (ORDER BY), DAN Paging (LIMIT)
// ===================================================================

//Pencarian nama dan predikat
$cari = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$filter_predikat = isset($_GET['predikat']) ? trim($_GET['predikat']) : 'all';

// Pilihan limit baris per halaman
$allowed_limits = [7, 25, 50, 80, 100];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 7;
if (!in_array($limit, $allowed_limits)) {
    $limit = 7;
}

// Navigasi halaman aktif
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$start = ($page - 1) * $limit;

// Klausa WHERE Dinamis
$where_clauses = [];
$params = [];
$types = "";

if ($cari !== '') {
    $where_clauses[] = "namalengkap LIKE ?";
    $params[] = "%" . $cari . "%";
    $types .= "s";
}

if ($filter_predikat !== 'all') {
    $where_clauses[] = "predikat = ?";
    $params[] = $filter_predikat;
    $types .= "s";
}

$sql_where = "";
if (count($where_clauses) > 0) {
    $sql_where = " WHERE " . implode(" AND ", $where_clauses);
}

// Hitung Total Baris Data
$sql_count = "SELECT COUNT(*) as total FROM nilaimhsw" . $sql_where;
if (count($params) > 0) {
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $res_count = $stmt_count->get_result();
    $row_count = $res_count->fetch_assoc();
    $stmt_count->close();
} else {
    $res_count = mysqli_query($conn, $sql_count);
    $row_count = mysqli_fetch_assoc($res_count);
}
$total_rows = $row_count['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $start = ($page - 1) * $limit;
}

// Query Utama dengan WHERE, ORDER BY, dan LIMIT
$sql = "SELECT * FROM nilaimhsw" . $sql_where . " ORDER BY nilaihuruf ASC, nilaiakhir DESC LIMIT ?, ?";
$stmt_data = $conn->prepare($sql);

if (count($params) > 0) {
    $types_data = $types . "ii";
    $params_data = array_merge($params, [$start, $limit]);
    $stmt_data->bind_param($types_data, ...$params_data);
} else {
    $stmt_data->bind_param("ii", $start, $limit);
}

$stmt_data->execute();
$result = $stmt_data->get_result();
$current_rows = $result ? $result->num_rows : 0;
$stmt_data->close();

$from_data = $total_rows > 0 ? $start + 1 : 0;
$to_data = $start + $current_rows;
?>

<!doctype html>
<html lang="id" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AdminLTE 4 | Rekap Nilai Mahasiswa</title>

    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-beta2/dist/css/adminlte.min.css">
</head>

<body class="bg-body-tertiary p-3">
<main class="app-main m-0 p-0">

    <div class="app-content-header mb-3">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h1 class="mb-0 fs-3">Rekap Nilai Mahasiswa</h1>
                </div>
                <div class="col-sm-6">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb float-sm-end">
                            <li class="breadcrumb-item"><a href="#">Beranda</a></li>
                            <li class="breadcrumb-item active">Nilai</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">

                    <div class="card mb-4">
                        <div class="card-header">
                            <form method="GET" action="" id="formSearch" class="row g-2 align-items-center">
                                <div class="col-12 col-md-4">
                                    <h3 class="card-title">Direktori Nilai</h3>
                                </div>
                                <div class="col-12 col-md-8">
                                    <div class="d-flex flex-wrap justify-content-md-end gap-2">

                                        <input type="hidden" name="limit" value="<?= $limit ?>">

                                        <div class="input-group input-group-sm w-auto">
                                            <span class="input-group-text">
                                                <i class="bi bi-search"></i>
                                            </span>
                                            <input type="search" name="cari"
                                                 class="form-control"
                                                 placeholder="Cari berdasarkan nama"
                                                 value="<?= htmlspecialchars($cari) ?>"
                                                 style="width:200px">
                                        </div>

                                        <select name="predikat"
                                             class="form-select form-select-sm w-auto"
                                             onchange="document.getElementById('formSearch').submit()">
                                            <option value="all" <?= ($filter_predikat === 'all') ? 'selected' : '' ?>>Semua Predikat</option>
                                            <option value="Sangat Memuaskan" <?= ($filter_predikat === 'Sangat Memuaskan') ? 'selected' : '' ?>>Sangat Memuaskan</option>
                                            <option value="Memuaskan" <?= ($filter_predikat === 'Memuaskan') ? 'selected' : '' ?>>Memuaskan</option>
                                            <option value="Cukup" <?= ($filter_predikat === 'Cukup') ? 'selected' : '' ?>>Cukup</option>
                                            <option value="Kurang" <?= ($filter_predikat === 'Kurang') ? 'selected' : '' ?>>Kurang</option>
                                            <option value="Tidak Lulus" <?= ($filter_predikat === 'Tidak Lulus') ? 'selected' : '' ?>>Tidak Lulus</option>
                                        </select>

                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Cari</button>

                                        <button type="button"
                                             class="btn btn-sm btn-primary"
                                             onclick="bukaTambah()">
                                            <i class="bi bi-person-plus-fill me-1"></i>
                                            Tambah Data Nilai
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="card-body p-0">

                            <?php if ($pesan !== ""): ?>
                                <div class="alert alert-<?php echo htmlspecialchars($tipe); ?> alert-dismissible m-3"
                                     role="alert">
                                    <?php echo htmlspecialchars($pesan); ?>
                                    <button type="button" class="btn-close"
                                      data-bs-dismiss="alert" aria-label="Tutup"></button>
                                </div>
                            <?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle m-0"
                                  id="tabel-nilai">
                                    <thead>
                                        <tr>
                                            <th>NIM</th>
                                            <th>Nama Lengkap</th>
                                            <th>Nilai Akhir</th>
                                            <th>Nilai Huruf</th>
                                            <th>Predikat</th>
                                            <th>Tgl Dibuat</th>
                                            <th class="text-end">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($result && $current_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row["nim"]) ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <img
                                                          src="https://adminlte.io/themes/v3/dist/img/user1-128x128.jpg"
                                                          alt="Avatar"
                                                          class="rounded-circle me-2"
                                                          style="width:32px;height:32px;object-fit:cover">
                                                        <span class="fw-medium">
                                                            <?= htmlspecialchars($row["namalengkap"]) ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($row["nilaiakhir"]) ?></td>
                                                <td><?= htmlspecialchars($row["nilaihuruf"]) ?></td>
                                                <td class="predikat-cell">
                                                    <?= htmlspecialchars($row["predikat"]) ?>
                                                </td>
                                                <td><?= htmlspecialchars($row["reg_date"]) ?></td>
                                                <td class="text-end">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button"
                                                            class="btn btn-outline-secondary"
                                                            style="width: 40px; height: 40px;"
                                                            title="Edit"
                                                            onclick='bukaEdit(
                                                                <?= json_encode($row["nim"]) ?>,
                                                                <?= json_encode($row["namalengkap"]) ?>,
                                                                <?= json_encode($row["nilaiakhir"]) ?>
                                                            )'>
                                                            <i class="bi bi-pencil"></i>
                                                        </button>

                                                        <form method="post" class="d-inline"
                                                            onsubmit="return confirm('Apakah Anda yakin ingin menghapus data ini?')">
                                                            <input type="hidden" name="aksi" value="hapus">
                                                            <input type="hidden" name="nim"
                                                                value="<?= htmlspecialchars($row["nim"], ENT_QUOTES) ?>">
                                                            <button type="submit"
                                                                class="btn btn-outline-danger"
                                                                style="width: 40px; height: 40px;"
                                                                title="Hapus">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-3">
                                                Data tidak ditemukan!
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Card Footer dengan Paging & Info -->
                        <div class="card-footer clearfix d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="fs-7 text-body-secondary">
                                Menampilkan <?= $from_data ?> sampai <?= $to_data ?> dari <?= $total_rows ?> nilai
                            </div>

                            <div class="d-flex align-items-center gap-3 ms-auto flex-wrap">
                                <form method="GET" action="" class="d-flex align-items-center gap-1 m-0">
                                    <input type="hidden" name="cari" value="<?= htmlspecialchars($cari) ?>">
                                    <input type="hidden" name="predikat" value="<?= htmlspecialchars($filter_predikat) ?>">
                                    <select name="limit" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach ($allowed_limits as $opt): ?>
                                            <option value="<?= $opt ?>" <?= ($limit == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="fs-7 text-body-secondary text-nowrap ms-1">baris</span>
                                </form>

                                <?php if ($total_pages > 1): ?>
                                <ul class="pagination pagination-sm m-0">
                                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>&cari=<?= urlencode($cari) ?>&predikat=<?= urlencode($filter_predikat) ?>">&laquo;</a>
                                    </li>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>&cari=<?= urlencode($cari) ?>&predikat=<?= urlencode($filter_predikat) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>&cari=<?= urlencode($cari) ?>&predikat=<?= urlencode($filter_predikat) ?>">&raquo;</a>
                                    </li>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-3 text-body-secondary fs-7 pb-3 d-flex justify-content-between">
        <span>Hak cipta &copy; pada Prodi TI - 2026. Seluruh hak cipta dilindungi.</span>
        <span>Pemrograman Web - Semester 5 - Desain menggunakan AdminLTE v4</span>
    </div>
</main>

<!-- MODAL TAMBAH / EDIT -->
<div class="modal fade" id="modalNilai" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="formNilai">
                <div class="modal-header">
                    <h5 class="modal-title" id="judulModal">Tambah Data Nilai</h5>
                    <button type="button" class="btn-close"
                      data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="aksi" id="aksi" value="tambah">

                    <div class="mb-3">
                        <label for="nim" class="form-label">NIM</label>
                        <input type="text" class="form-control" name="nim"
                          id="nim" required>
                        <small class="text-danger" id="errorNim"></small>
                    </div>

                    <div class="mb-3">
                        <label for="namalengkap" class="form-label">Nama Lengkap</label>
                        <input type="text" class="form-control" name="namalengkap"
                          id="namalengkap" required>
                        <small class="text-danger" id="errorNama"></small>
                    </div>

                    <div class="mb-3">
                        <label for="nilaiakhir" class="form-label">Nilai Akhir</label>
                        <input type="number" class="form-control" name="nilaiakhir"
                          id="nilaiakhir" min="0" max="100" step="0.01" required>
                        <small class="text-danger" id="errorNilai"></small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                      data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="tombolSimpan">
                      Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-beta2/dist/js/adminlte.min.js"></script>

<script>
const modalNilai = new bootstrap.Modal(document.getElementById('modalNilai'));

function bukaTambah() {
    document.getElementById('formNilai').reset();
    document.getElementById('aksi').value = 'tambah';
    document.getElementById('nim').readOnly = false;
    document.getElementById('judulModal').textContent = 'Tambah Data Nilai';
    document.getElementById('tombolSimpan').textContent = 'Simpan';
    bersihkanError();
    modalNilai.show();
}

function bukaEdit(nim, nama, nilai) {
    document.getElementById('formNilai').reset();
    document.getElementById('aksi').value = 'edit';
    document.getElementById('nim').value = nim;
    document.getElementById('nim').readOnly = true;
    document.getElementById('namalengkap').value = nama;
    document.getElementById('nilaiakhir').value = nilai;
    document.getElementById('judulModal').textContent = 'Edit Data Nilai';
    document.getElementById('tombolSimpan').textContent = 'Update';
    bersihkanError();
    modalNilai.show();
}

function bersihkanError() {
    document.getElementById('errorNim').textContent = '';
    document.getElementById('errorNama').textContent = '';
    document.getElementById('errorNilai').textContent = '';
}

document.getElementById('formNilai').addEventListener('submit', function(e) {
    bersihkanError();

    const nim = document.getElementById('nim').value.trim();
    const nama = document.getElementById('namalengkap').value.trim();
    const nilai = document.getElementById('nilaiakhir').value;
    let valid = true;

    if (!nim) {
        document.getElementById('errorNim').textContent = '*NIM tidak boleh kosong';
        valid = false;
    } else if (!/^\d+$/.test(nim)) {
        document.getElementById('errorNim').textContent = '*NIM harus berupa angka';
        valid = false;
    }

    if (!nama) {
        document.getElementById('errorNama').textContent = '*Nama tidak boleh kosong';
        valid = false;
    }

    if (nilai === '' || Number(nilai) < 0 || Number(nilai) > 100) {
        document.getElementById('errorNilai').textContent = '*Nilai harus antara 0 sampai 100';
        valid = false;
    }

    if (!valid) e.preventDefault();
});
</script>
</body>
</html>

<?php
mysqli_close($conn);
?>