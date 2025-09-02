<?php
// includes/image_helpers.php

/**
 * อัปโหลดหลายรูป + บันทึก DB (product_images) + ตั้งรูปปกอัตโนมัติ
 * @param ?array $files  $_FILES['images']
 * @param string $uploadDir โฟลเดอร์ปลายทาง
 * @param mysqli $conn
 * @param int    $product_id
 * @param int    $max   จำนวนสูงสุด
 * @return string[] รายชื่อไฟล์ที่บันทึกได้
 */
function save_product_images(?array $files, string $uploadDir, mysqli $conn, int $product_id, int $max = 10): array {
    $saved = [];
    if (empty($files) || empty($files['name'])) return $saved;

    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

    $names = (array)$files['name'];
    $tmps  = (array)$files['tmp_name'];
    $errs  = (array)$files['error'];

    $hasCover = product_has_cover($conn, $product_id);

    $allowed = ['jpg','jpeg','png','gif','webp'];
    for ($i = 0; $i < count($names) && count($saved) < $max; $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $fname = 'pd_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $dest  = rtrim($uploadDir, '/').'/'.$fname;

        if (@move_uploaded_file($tmps[$i], $dest)) {
            $saved[] = $fname;

            $flag = $hasCover ? 0 : 1; // ตั้งปกเฉพาะถ้ายังไม่มี
            if (!$hasCover) $hasCover = true;

            $st = $conn->prepare("INSERT INTO product_images (product_id, filename, is_cover) VALUES (?,?,?)");
            $st->bind_param('isi', $product_id, $fname, $flag);
            $st->execute();
            $st->close();

            if ($flag === 1) {
                $up = $conn->prepare("UPDATE products SET image=? WHERE id=?");
                $up->bind_param('si', $fname, $product_id);
                $up->execute();
                $up->close();
            }
        }
    }
    return $saved;
}

/**
 * ลบไฟล์รูป (และลบ row DB ถ้าต้องการ) แล้วจัดรูปปกใหม่
 */
function delete_product_images(mysqli $conn, int $product_id, ?int $image_id = null, bool $delete_rows = true): int {
    $where  = "product_id=?";
    $types  = "i";
    $params = [$product_id];
    if ($image_id) { $where .= " AND id=?"; $types .= "i"; $params[] = $image_id; }

    $st = $conn->prepare("SELECT id, filename, is_cover FROM product_images WHERE $where");
    $st->bind_param($types, ...$params);
    $st->execute();
    $rs = $st->get_result();

    $deleted  = 0;
    $wasCover = false;
    while ($row = $rs->fetch_assoc()) {
        $path = __DIR__."/../assets/img/".$row['filename'];
        if (is_file($path)) { @chmod($path, 0666); @unlink($path); }
        $deleted++;
        if ((int)$row['is_cover'] === 1) $wasCover = true;
    }
    $st->close();

    if ($delete_rows) {
        $del = $conn->prepare("DELETE FROM product_images WHERE $where");
        $del->bind_param($types, ...$params);
        $del->execute();
        $del->close();
    }

    if ($wasCover) {
        $st2 = $conn->prepare("SELECT id, filename FROM product_images WHERE product_id=? ORDER BY id ASC LIMIT 1");
        $st2->bind_param('i', $product_id);
        $st2->execute();
        $rowFirst = $st2->get_result()->fetch_assoc();
        $st2->close();

        if ($rowFirst) {
            $conn->query("UPDATE product_images SET is_cover=0 WHERE product_id=".(int)$product_id);
            $up = $conn->prepare("UPDATE product_images SET is_cover=1 WHERE id=?");
            $up->bind_param('i', $rowFirst['id']);
            $up->execute();
            $up->close();

            $up2 = $conn->prepare("UPDATE products SET image=? WHERE id=?");
            $up2->bind_param('si', $rowFirst['filename'], $product_id);
            $up2->execute();
            $up2->close();
        } else {
            $conn->query("UPDATE products SET image=NULL WHERE id=".(int)$product_id);
        }
    }
    return $deleted;
}

/** ตั้งรูปใดรูปหนึ่งเป็นปกของสินค้า */
function set_cover_image(mysqli $conn, int $product_id, int $image_id): bool {
    $chk = $conn->prepare("SELECT filename FROM product_images WHERE id=? AND product_id=?");
    $chk->bind_param('ii', $image_id, $product_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) return false;

    $conn->query("UPDATE product_images SET is_cover=0 WHERE product_id=".(int)$product_id);
    $up = $conn->prepare("UPDATE product_images SET is_cover=1 WHERE id=?");
    $up->bind_param('i', $image_id);
    $up->execute();
    $up->close();

    $up2 = $conn->prepare("UPDATE products SET image=? WHERE id=?");
    $up2->bind_param('si', $row['filename'], $product_id);
    $up2->execute();
    $up2->close();
    return true;
}

/** แทนที่รูปทั้งหมดของสินค้า (ใช้ในหน้าแก้ไข) */
function replace_product_images(mysqli $conn, int $product_id, ?array $files, string $uploadDir, int $max = 10): array {
    delete_product_images($conn, $product_id, null, true);
    return save_product_images($files, $uploadDir, $conn, $product_id, $max);
}

/** มีรูปปกแล้วหรือยัง */
function product_has_cover(mysqli $conn, int $product_id): bool {
    $st = $conn->prepare("SELECT 1 FROM product_images WHERE product_id=? AND is_cover=1 LIMIT 1");
    $st->bind_param('i', $product_id);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}

/* ===================== TRADE-IN MULTI-IMAGES ===================== */

/**
 * บันทึกรูปหลายไฟล์ของ trade-in
 * รูปแรกจะถูกตั้งเป็นปก (is_cover=1) และ sync ไปยัง tradein_requests.image_path
 * @return string[] รายชื่อไฟล์ที่บันทึกได้
 */
function save_tradein_images(?array $files, string $uploadDir, mysqli $conn, int $requestId, int $max = 10): array {
  if (!$files || empty($files['name'])) return [];

  if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
  $allowedExt = ['jpg','jpeg','png','webp','gif'];
  $okMimes    = ['image/jpeg','image/png','image/webp','image/gif'];
  $maxSize    = 5 * 1024 * 1024;

  $saved = [];
  $N = is_array($files['name']) ? count($files['name']) : 0;
  $N = min($N, $max);

  $useFinfo = class_exists('finfo');
  $finfo = $useFinfo ? new finfo(FILEINFO_MIME_TYPE) : null;

  for ($i=0; $i<$N; $i++) {
    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    $size = (int)($files['size'][$i] ?? 0);
    if ($size <= 0 || $size > $maxSize) continue;

    $ext  = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) continue;

    $tmp  = $files['tmp_name'][$i];
    $mime = $useFinfo ? $finfo->file($tmp) : (mime_content_type($tmp) ?: '');
    if (!in_array($mime, $okMimes, true)) continue;

    $rand = bin2hex(random_bytes(4));
    $fname = "tradein_{$requestId}_" . time() . "_{$rand}." . $ext;
    $dest  = rtrim($uploadDir,'/').'/'.$fname;

    if (@move_uploaded_file($tmp, $dest)) {
      $is_cover = (int)(count($saved) === 0); // รูปแรกเป็นปก
      $st = $conn->prepare("INSERT INTO tradein_images (request_id, filename, is_cover) VALUES (?,?,?)");
      $st->bind_param('isi', $requestId, $fname, $is_cover);
      $st->execute();
      $st->close();
      $saved[] = $fname;
    }
  }

  if ($saved) {
    $cover = $saved[0];
    $u = $conn->prepare("UPDATE tradein_requests SET image_path=? WHERE id=? LIMIT 1");
    $u->bind_param('si', $cover, $requestId);
    $u->execute();
    $u->close();
  }
  return $saved;
}

/** ดึงแกลเลอรี่ของคำขอเทิร์น */
function load_tradein_gallery(mysqli $conn, int $requestId): array {
  $st = $conn->prepare("SELECT id, filename, is_cover FROM tradein_images WHERE request_id=? ORDER BY is_cover DESC, id ASC");
  $st->bind_param('i', $requestId);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
  return $rows ?: [];
}
