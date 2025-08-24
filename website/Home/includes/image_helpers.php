<?php
// includes/image_helpers.php

/**
 * อัปโหลดหลายรูป + บันทึก DB (product_images) + ตั้งรูปปกอัตโนมัติ
 * @param ?array        $files      มาจาก $_FILES['images'] (อาจเป็น null ได้)
 * @param string        $uploadDir  โฟลเดอร์ปลายทาง (ไม่มี / ท้ายก็ได้)
 * @param mysqli        $conn
 * @param int           $product_id
 * @param int           $max        จำกัดจำนวนรูปที่จะรับ (เช่น 10)
 * @return string[]                 รายชื่อไฟล์ที่บันทึกสำเร็จ (ตามลำดับที่รับ)
 */
function save_product_images(?array $files, string $uploadDir, mysqli $conn, int $product_id, int $max = 10): array {
    $saved = [];
    // ไม่ได้ส่งไฟล์มาเลย
    if (empty($files) || empty($files['name'])) {
        return $saved;
    }

    // เตรียมโฟลเดอร์
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

    // ทำให้เป็น array เสมอ
    $names = (array)$files['name'];
    $tmps  = (array)$files['tmp_name'];
    $errs  = (array)$files['error'];

    // เช็คว่ามีรูปปกอยู่แล้วหรือยัง
    $hasCover = product_has_cover($conn, $product_id);

    $allowed = ['jpg','jpeg','png','gif','webp'];
    for ($i = 0; $i < count($names) && count($saved) < $max; $i++) {
        if ($errs[$i] !== UPLOAD_ERR_OK) continue;

        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $fname = 'pd_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $dest  = rtrim($uploadDir, '/').'/'.$fname;

        if (move_uploaded_file($tmps[$i], $dest)) {
            $saved[] = $fname;

            // is_cover = 1 เฉพาะกรณียังไม่มีปก
            $flag = $hasCover ? 0 : 1;
            if (!$hasCover) { $hasCover = true; }

            $st = $conn->prepare("INSERT INTO product_images (product_id, filename, is_cover) VALUES (?,?,?)");
            $st->bind_param('isi', $product_id, $fname, $flag);
            $st->execute();
            $st->close();

            // ถ้าเพิ่งตั้งเป็นปก → sync ไป products.image
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
 * ลบไฟล์รูป (และลบแถว DB ถ้าต้องการ) แล้วจัดรูปปกใหม่ให้ถูก
 * @param mysqli  $conn
 * @param int     $product_id
 * @param ?int    $image_id        ถ้า null = ลบทั้งหมดของสินค้านี้
 * @param bool    $delete_rows     true = ลบแถวใน DB ด้วย (ปกติให้ true)
 * @return int                     จำนวนรูปที่ลบได้
 */
function delete_product_images(mysqli $conn, int $product_id, ?int $image_id = null, bool $delete_rows = true): int {
    $where  = "product_id=?";
    $types  = "i";
    $params = [$product_id];
    if ($image_id) { $where .= " AND id=?"; $types .= "i"; $params[] = $image_id; }

    // ดึงรายการไฟล์ที่จะลบ
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
        if ((int)$row['is_cover'] === 1) { $wasCover = true; }
    }
    $st->close();

    if ($delete_rows) {
        $del = $conn->prepare("DELETE FROM product_images WHERE $where");
        $del->bind_param($types, ...$params);
        $del->execute();
        $del->close();
    }

    // ถ้าลบรูปปก → ตั้งปกใหม่เป็นรูปแรกที่เหลืออยู่, ถ้าไม่เหลือเลย → เคลียร์ products.image
    if ($wasCover) {
        $st2 = $conn->prepare("SELECT id, filename FROM product_images WHERE product_id=? ORDER BY id ASC LIMIT 1");
        $st2->bind_param('i', $product_id);
        $st2->execute();
        $rowFirst = $st2->get_result()->fetch_assoc();
        $st2->close();

        if ($rowFirst) {
            // set cover ใหม่
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
            // ไม่มีรูปเหลือ
            $conn->query("UPDATE products SET image=NULL WHERE id=".(int)$product_id);
        }
    }

    return $deleted;
}

/**
 * ตั้งรูปใดรูปหนึ่งเป็นรูปปก และ sync ไป products.image
 */
function set_cover_image(mysqli $conn, int $product_id, int $image_id): bool {
    // ตรวจสอบว่ารูปนี้อยู่กับสินค้าจริง
    $chk = $conn->prepare("SELECT filename FROM product_images WHERE id=? AND product_id=?");
    $chk->bind_param('ii', $image_id, $product_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) return false;

    // reset + set cover
    $conn->query("UPDATE product_images SET is_cover=0 WHERE product_id=".(int)$product_id);
    $up = $conn->prepare("UPDATE product_images SET is_cover=1 WHERE id=?");
    $up->bind_param('i', $image_id);
    $up->execute();
    $up->close();

    // sync products.image
    $up2 = $conn->prepare("UPDATE products SET image=? WHERE id=?");
    $up2->bind_param('si', $row['filename'], $product_id);
    $up2->execute();
    $up2->close();
    return true;
}

/**
 * ลบรูปทั้งหมดของสินค้า แล้วอัปโหลดชุดใหม่ (ใช้ในหน้าแก้ไข)
 */
function replace_product_images(mysqli $conn, int $product_id, ?array $files, string $uploadDir, int $max = 10): array {
    // ลบทั้งหมด + ลบแถว DB
    delete_product_images($conn, $product_id, null, true);
    // อัปโหลดใหม่
    return save_product_images($files, $uploadDir, $conn, $product_id, $max);
}

/**
 * เช็คว่ามีรูปปกอยู่หรือยัง
 */
function product_has_cover(mysqli $conn, int $product_id): bool {
    $st = $conn->prepare("SELECT 1 FROM product_images WHERE product_id=? AND is_cover=1 LIMIT 1");
    $st->bind_param('i', $product_id);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}
